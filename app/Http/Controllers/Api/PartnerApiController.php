<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\Post;
use App\Models\Thread;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
