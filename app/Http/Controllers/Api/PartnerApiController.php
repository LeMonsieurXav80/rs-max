<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\Partner;
use App\Models\Post;
use App\Models\Thread;
use App\Services\Media\MediaSelectionFilter;
use App\Services\PartnerTagService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PartnerApiController extends Controller
{
    /**
     * GET /api/partners — liste complete (peu de fiches, pas de pagination).
     * ?active=1 pour ne garder que les partenaires actifs.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Partner::withCount(['mediaFiles', 'posts', 'threads'])->orderBy('name');

        if ($request->boolean('active')) {
            $query->active();
        }

        return response()->json([
            'partners' => $query->get()->map(fn (Partner $p) => $this->formatPartner($p)),
        ]);
    }

    public function show(Partner $partner): JsonResponse
    {
        $partner->loadCount(['mediaFiles', 'posts', 'threads']);

        return response()->json(['partner' => $this->formatPartner($partner, true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $validated['origin'] = 'manual';

        $partner = Partner::create($validated);

        return response()->json(['partner' => $this->formatPartner($partner)], 201);
    }

    public function update(Request $request, Partner $partner): JsonResponse
    {
        $validated = $request->validate($this->rules($partner));

        $partner->update($validated);

        // Le nom fait foi dans le miroir denormalise des photos.
        $partner->mediaFiles()->chunkById(200, function ($files) {
            foreach ($files as $media) {
                $media->update(['brands' => $media->partners()->orderBy('name')->pluck('name')->all()]);
            }
        }, 'media_files.id');

        return response()->json(['partner' => $this->formatPartner($partner->fresh())]);
    }

    public function destroy(Partner $partner): JsonResponse
    {
        $mediaFiles = $partner->mediaFiles()->get();
        $partner->delete();

        foreach ($mediaFiles as $media) {
            $media->update(['brands' => $media->partners()->orderBy('name')->pluck('name')->all()]);
        }

        return response()->json(['success' => true, 'message' => 'Partenaire supprimé.']);
    }

    /**
     * GET /api/partners/{id}/posts — publications taguees, pour les comptes rendus.
     * Filtres : ?status=published&source=auto&from=2026-01-01&to=2026-06-30&per_page=50
     */
    public function posts(Request $request, Partner $partner): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'scheduled', 'publishing', 'published', 'failed'])],
            'source' => ['nullable', Rule::in(['auto', 'manual'])],
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $query = $partner->posts()->with(['postPlatforms.platform', 'postPlatforms.socialAccount']);

        if (! empty($filters['status'])) {
            $query->where('posts.status', $filters['status']);
        }

        if (! empty($filters['source'])) {
            $query->where('partner_post.source', $filters['source']);
        }

        if (! empty($filters['from'])) {
            $from = Carbon::parse($filters['from'])->startOfDay();
            $query->where(fn ($q) => $q->where('posts.published_at', '>=', $from)
                ->orWhere('posts.scheduled_at', '>=', $from)
                ->orWhere('posts.created_at', '>=', $from));
        }

        if (! empty($filters['to'])) {
            $to = Carbon::parse($filters['to'])->endOfDay();
            $query->where(fn ($q) => $q->where('posts.published_at', '<=', $to)
                ->orWhere('posts.scheduled_at', '<=', $to)
                ->orWhere('posts.created_at', '<=', $to));
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $posts = $query
            ->orderByRaw('COALESCE(posts.published_at, posts.scheduled_at, posts.created_at) DESC')
            ->paginate($perPage);

        return response()->json([
            'partner' => ['id' => $partner->id, 'name' => $partner->name, 'slug' => $partner->slug],
            'posts' => $posts->getCollection()->map(fn (Post $p) => [
                'id' => $p->id,
                'content_preview' => $p->content_preview,
                'status' => $p->status,
                'tag_source' => $p->pivot->source,
                'media_count' => is_array($p->media) ? count($p->media) : 0,
                'scheduled_at' => $p->scheduled_at?->toIso8601String(),
                'published_at' => $p->published_at?->toIso8601String(),
                'created_at' => $p->created_at->toIso8601String(),
                'accounts' => $p->postPlatforms->map(fn ($pp) => [
                    'id' => $pp->social_account_id,
                    'name' => $pp->socialAccount?->name,
                    'platform' => $pp->platform?->slug,
                    'status' => $pp->status,
                    'external_id' => $pp->external_id,
                    'published_at' => $pp->published_at?->toIso8601String(),
                ]),
            ]),
            'pagination' => [
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
            ],
        ]);
    }

    /**
     * GET /api/partners/{id}/threads — fils de discussion tagués, pour les comptes rendus.
     * Mêmes filtres que /posts : `status`, `source`, `from`, `to`, `per_page`.
     */
    public function threads(Request $request, Partner $partner): JsonResponse
    {
        $filters = $request->validate([
            'status' => 'nullable|string|max:20',
            'source' => ['nullable', Rule::in(['auto', 'manual'])],
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $query = $partner->threads()->with(['socialAccounts.platform', 'segments']);

        if (! empty($filters['status'])) {
            $query->where('threads.status', $filters['status']);
        }

        if (! empty($filters['source'])) {
            $query->where('partner_thread.source', $filters['source']);
        }

        if (! empty($filters['from'])) {
            $from = Carbon::parse($filters['from'])->startOfDay();
            $query->where(fn ($q) => $q->where('threads.published_at', '>=', $from)
                ->orWhere('threads.scheduled_at', '>=', $from)
                ->orWhere('threads.created_at', '>=', $from));
        }

        if (! empty($filters['to'])) {
            $to = Carbon::parse($filters['to'])->endOfDay();
            $query->where(fn ($q) => $q->where('threads.published_at', '<=', $to)
                ->orWhere('threads.scheduled_at', '<=', $to)
                ->orWhere('threads.created_at', '<=', $to));
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $threads = $query
            ->orderByRaw('COALESCE(threads.published_at, threads.scheduled_at, threads.created_at) DESC')
            ->paginate($perPage);

        return response()->json([
            'partner' => ['id' => $partner->id, 'name' => $partner->name, 'slug' => $partner->slug],
            'threads' => $threads->getCollection()->map(fn (Thread $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'content_preview' => $t->content_preview,
                'status' => $t->status,
                'tag_source' => $t->pivot->source,
                'segments_count' => $t->segments->count(),
                'scheduled_at' => $t->scheduled_at?->toIso8601String(),
                'published_at' => $t->published_at?->toIso8601String(),
                'created_at' => $t->created_at->toIso8601String(),
                'accounts' => $t->socialAccounts->map(fn ($a) => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'platform' => $a->platform?->slug,
                    'status' => $a->pivot->status,
                ]),
            ]),
            'pagination' => [
                'current_page' => $threads->currentPage(),
                'last_page' => $threads->lastPage(),
                'per_page' => $threads->perPage(),
                'total' => $threads->total(),
            ],
        ]);
    }

    /**
     * POST /api/partners/{partner}/media/detach — retire un partenaire d'un lot de photos.
     * POST /api/partners/{partner}/media/attach  — l'ajoute au même titre.
     *
     * Ne touche QUE des pivots : la fiche partenaire n'est jamais supprimée.
     *
     * Le point délicat est le report : le tag 'auto' d'une publication est
     * recalculé à l'enregistrement du POST, jamais à celui de la PHOTO. Détacher
     * les photos sans plus laisserait les publications déjà en base taguées
     * jusqu'à leur prochain save — qui n'arrivera jamais pour du contenu publié.
     * D'où le rattrapage systématique via resyncContentUsingMedia().
     */
    public function detachMedia(Request $request, string $partner): JsonResponse
    {
        return $this->amendMedia($request, $partner, attach: false);
    }

    public function attachMedia(Request $request, string $partner): JsonResponse
    {
        return $this->amendMedia($request, $partner, attach: true);
    }

    private function amendMedia(Request $request, string $partnerKey, bool $attach): JsonResponse
    {
        $partner = $this->resolvePartner($partnerKey);
        if (! $partner) {
            return response()->json(['error' => 'partner not found', 'partner' => $partnerKey], 404);
        }

        // Les filtres sont attendus groupés sous `filters`. On accepte aussi les
        // clés à plat : même vocabulaire que la query string de /api/media/search,
        // où elles sont forcément à plat.
        $payload = $request->all();
        $nested = $request->input('filters');
        if (is_array($nested)) {
            $payload = array_merge($payload, $nested);
        }
        unset($payload['filters']);

        $validated = validator($payload, MediaSelectionFilter::rules() + [
            'media_ids' => 'nullable|array|min:1|max:1000',
            'media_ids.*' => 'integer',
            // Défaut à true : la route touche potentiellement des centaines de
            // photos et une douzaine de publications, l'écriture se demande.
            'dry_run' => 'nullable|boolean',
        ])->validate();

        $dryRun = array_key_exists('dry_run', $validated)
            ? filter_var($validated['dry_run'], FILTER_VALIDATE_BOOLEAN)
            : true;

        $hasIds = ! empty($validated['media_ids']);
        $filterKeys = ['folder', 'city', 'region', 'country', 'event', 'taken_at_from', 'taken_at_to'];
        $hasFilters = (bool) array_filter(
            $filterKeys,
            fn ($k) => ! empty($validated[$k])
        );

        // Ids OU filtres, jamais les deux : sinon la sélection effective devient
        // ambiguë (intersection ? union ?) et le dry-run cesse d'être lisible.
        if ($hasIds === $hasFilters) {
            return response()->json([
                'error' => $hasIds
                    ? 'media_ids et filters sont exclusifs : fournir l\'un ou l\'autre'
                    : 'fournir media_ids ou au moins un filtre de sélection',
                'filters_supported' => $filterKeys,
            ], 422);
        }

        $query = MediaFile::query();
        $skippedPrivate = 0;

        if ($hasIds) {
            $query->whereIn('id', $validated['media_ids']);
        } else {
            if (! empty($validated['folder'])) {
                $folder = MediaFolder::where('slug', $validated['folder'])->firstOrFail();
                // Dossier privé : on ne fait pas échouer tout le lot, on n'y descend
                // simplement pas (les photos sont comptées en skipped ci-dessous).
                $folderIds = MediaSelectionFilter::publicDescendantIds($folder);
                $query->whereIn('folder_id', $folderIds ?: [0]);
            }
            MediaSelectionFilter::apply($query, $validated);
        }

        // Ne garder que les photos réellement concernées : au détachement celles
        // qui portent le tag, à l'attachement celles qui ne l'ont pas encore.
        // C'est ce qui rend l'opération idempotente et les compteurs honnêtes.
        $matched = $query->with('folder')->get();

        $eligible = $matched->filter(function (MediaFile $media) use ($partner, $attach, &$skippedPrivate) {
            // Un dossier privé (ou sous un ancêtre privé) est hors de portée de
            // l'API, y compris quand la photo est désignée par son id.
            if ($media->folder && $media->folder->isEffectivelyPrivate()) {
                $skippedPrivate++;

                return false;
            }

            $has = $media->partners()->where('partners.id', $partner->id)->exists();

            return $attach ? ! $has : $has;
        })->values();

        // Le travail est TOUJOURS exécuté pour de vrai, puis annulé en dry-run.
        // C'est la seule façon d'annoncer exactement ce que fera le run réel :
        // tant que les photos portent encore le tag, aucune dérive n'est visible
        // sur les publications, et un dry-run « simulé » répondrait 0 partout.
        DB::beginTransaction();
        try {
            $tags = app(PartnerTagService::class);

            foreach ($eligible as $media) {
                $attach
                    ? $tags->amendMediaNames($media, [$partner->name], [])
                    : $tags->amendMediaNames($media, [], [$partner->name]);
            }

            $touched = $eligible->isEmpty()
                ? ['posts' => [], 'threads' => []]
                : $tags->resyncContentUsingMedia($eligible);

            $outcome = [
                'touched' => $touched,
                // Contenus qui, après recalcul, ne portent plus du tout le partenaire.
                'posts_now_untagged' => $this->untaggedAmong($partner, Post::class, $touched['posts']),
                'threads_now_untagged' => $this->untaggedAmong($partner, Thread::class, $touched['threads']),
            ];

            // Dry-run : on a mesuré sur un état réellement écrit, on rembobine.
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $countKey = $attach ? 'media_attached' : 'media_detached';

        return response()->json([
            'partner' => ['id' => $partner->id, 'name' => $partner->name, 'slug' => $partner->slug],
            'action' => $attach ? 'attach' : 'detach',
            'dry_run' => $dryRun,
            'media_matched' => $matched->count(),
            $countKey => $eligible->count(),
            'media_skipped_private' => $skippedPrivate,
            'posts_recalculated' => count($outcome['touched']['posts']),
            'threads_recalculated' => count($outcome['touched']['threads']),
            'posts_now_untagged' => $outcome['posts_now_untagged'],
            'threads_now_untagged' => $outcome['threads_now_untagged'],
        ]);
    }

    /**
     * Parmi $ids, ceux qui ne portent plus aucun tag de ce partenaire.
     *
     * @param  class-string<Post|Thread>  $model
     * @param  array<int,int>  $ids
     * @return array<int,int>
     */
    private function untaggedAmong(Partner $partner, string $model, array $ids): array
    {
        if (! $ids) {
            return [];
        }

        $table = $model === Post::class ? 'posts' : 'threads';

        $encore = $model::query()
            ->whereIn($table.'.id', $ids)
            ->whereHas('partners', fn ($q) => $q->where('partners.id', $partner->id))
            ->pluck($table.'.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_diff($ids, $encore));
    }

    /**
     * Résolution id OU slug, comme le filtre `partners` de /api/media/search.
     * Les autres routes partenaires restent en binding implicite (id seul).
     */
    private function resolvePartner(string $key): ?Partner
    {
        return ctype_digit($key)
            ? Partner::find((int) $key)
            : Partner::where('slug', Partner::slugFor($key))->first();
    }

    /**
     * @return array<string,mixed>
     */
    private function rules(?Partner $partner = null): array
    {
        $required = $partner ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:80', Rule::unique('partners', 'name')->ignore($partner?->id)],
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'website' => 'nullable|url|max:2048',
            'notes' => 'nullable|string|max:5000',
            'color' => 'nullable|string|max:7',
            'is_active' => 'nullable|boolean',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function formatPartner(Partner $partner, bool $detailed = false): array
    {
        $data = [
            'id' => $partner->id,
            'name' => $partner->name,
            'slug' => $partner->slug,
            'color' => $partner->color,
            'is_active' => $partner->is_active,
            'origin' => $partner->origin,
            'media_count' => $partner->media_files_count,
            'posts_count' => $partner->posts_count,
            'threads_count' => $partner->threads_count,
        ];

        if ($detailed) {
            $data['contact_name'] = $partner->contact_name;
            $data['contact_email'] = $partner->contact_email;
            $data['website'] = $partner->website;
            $data['notes'] = $partner->notes;
            $data['created_at'] = $partner->created_at->toIso8601String();
        }

        return $data;
    }
}
