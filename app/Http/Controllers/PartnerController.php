<?php

namespace App\Http\Controllers;

use App\Models\Partner;
use App\Models\Post;
use App\Services\PartnerTagService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PartnerController extends Controller
{
    public function __construct(private readonly PartnerTagService $partners) {}

    public function index(Request $request): View
    {
        $this->authorizeManager($request);

        $partners = Partner::withCount(['mediaFiles', 'posts', 'threads'])
            ->orderBy('name')
            ->get();

        return view('partners.index', compact('partners'));
    }

    public function create(Request $request): View
    {
        $this->authorizeManager($request);

        return view('partners.create');
    }

    public function store(Request $request)
    {
        $this->authorizeManager($request);

        $validated = $request->validate($this->validationRules());
        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['origin'] = 'manual';

        Partner::create($validated);

        return redirect()->route('partners.index')->with('status', 'partner-created');
    }

    public function edit(Request $request, Partner $partner): View
    {
        $this->authorizeManager($request);

        return view('partners.edit', compact('partner'));
    }

    public function update(Request $request, Partner $partner)
    {
        $this->authorizeManager($request);

        $validated = $request->validate($this->validationRules($partner));
        $validated['is_active'] = $request->boolean('is_active', true);

        $partner->update($validated);

        // Le nom a pu changer : reecrire le miroir denormalise des photos taguees.
        $this->refreshMediaBrands($partner);

        return redirect()->route('partners.index')->with('status', 'partner-updated');
    }

    public function destroy(Request $request, Partner $partner)
    {
        $this->authorizeManager($request);

        // Les pivots partent en cascade ; il reste a nettoyer le miroir `brands`.
        $mediaFiles = $partner->mediaFiles()->get();
        $partner->delete();

        foreach ($mediaFiles as $media) {
            $media->update([
                'brands' => $media->partners()->orderBy('name')->pluck('name')->all(),
            ]);
        }

        return redirect()->route('partners.index')->with('status', 'partner-deleted');
    }

    /**
     * Compte rendu : toutes les publications taguees d'un partenaire.
     */
    public function posts(Request $request, Partner $partner): View
    {
        $this->authorizeManager($request);

        $filters = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'status' => ['nullable', Rule::in(['draft', 'scheduled', 'publishing', 'published', 'failed'])],
            'source' => ['nullable', Rule::in(['auto', 'manual'])],
        ]);

        $posts = $this->postsQuery($partner, $filters)
            ->with(['postPlatforms.platform', 'postPlatforms.socialAccount', 'user'])
            ->orderByRaw('COALESCE(posts.published_at, posts.scheduled_at, posts.created_at) DESC')
            // Nom de page dédié : les deux tableaux paginent indépendamment.
            ->paginate(25, ['*'], 'posts_page')
            ->withQueryString();

        $threads = $this->threadsQuery($partner, $filters)
            ->with(['socialAccounts.platform', 'segments', 'user'])
            ->orderByRaw('COALESCE(threads.published_at, threads.scheduled_at, threads.created_at) DESC')
            ->paginate(25, ['*'], 'threads_page')
            ->withQueryString();

        $stats = $this->statsFor($partner, $filters);

        return view('partners.posts', compact('partner', 'posts', 'threads', 'stats', 'filters'));
    }

    /**
     * GET /partners/options — liste JSON pour les selecteurs (tous utilisateurs).
     */
    public function options(): JsonResponse
    {
        return response()->json(['partners' => $this->partners->options()]);
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    private function postsQuery(Partner $partner, array $filters)
    {
        // On garde la relation (et non son Builder) pour que le pivot soit hydrate.
        $query = $partner->posts();

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

        return $query;
    }

    /**
     * Meme filtrage que postsQuery(), applique aux fils de discussion.
     *
     * @param  array<string,mixed>  $filters
     */
    private function threadsQuery(Partner $partner, array $filters)
    {
        $query = $partner->threads();

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

        return $query;
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array{total:int,published:int,scheduled:int,threads:int,by_platform:array<string,int>}
     */
    private function statsFor(Partner $partner, array $filters): array
    {
        $ids = $this->postsQuery($partner, $filters)->pluck('posts.id');

        $byPlatform = [];
        if ($ids->isNotEmpty()) {
            $byPlatform = \App\Models\PostPlatform::query()
                ->join('platforms', 'platforms.id', '=', 'post_platforms.platform_id')
                ->whereIn('post_platforms.post_id', $ids)
                ->where('post_platforms.status', 'published')
                ->selectRaw('platforms.slug, COUNT(*) as total')
                ->groupBy('platforms.slug')
                ->pluck('total', 'slug')
                ->all();
        }

        $statuses = $ids->isEmpty() ? collect() : Post::whereIn('id', $ids)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'total' => $ids->count(),
            'published' => (int) $statuses->get('published', 0),
            'scheduled' => (int) $statuses->get('scheduled', 0),
            'threads' => $this->threadsQuery($partner, $filters)->count(),
            'by_platform' => $byPlatform,
        ];
    }

    private function refreshMediaBrands(Partner $partner): void
    {
        $partner->mediaFiles()->chunkById(200, function ($files) {
            foreach ($files as $media) {
                $media->update([
                    'brands' => $media->partners()->orderBy('name')->pluck('name')->all(),
                ]);
            }
        }, 'media_files.id');
    }

    /**
     * @return array<string,mixed>
     */
    private function validationRules(?Partner $partner = null): array
    {
        return [
            'name' => ['required', 'string', 'max:80', Rule::unique('partners', 'name')->ignore($partner?->id)],
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'website' => 'nullable|url|max:2048',
            'notes' => 'nullable|string|max:5000',
            'color' => 'nullable|string|max:7',
            'is_active' => 'boolean',
        ];
    }

    private function authorizeManager(Request $request): void
    {
        if (! $request->user()->isManager()) {
            abort(403);
        }
    }
}
