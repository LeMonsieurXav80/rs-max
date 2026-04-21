<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Services\PublishingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PostApiController extends Controller
{
    /**
     * GET /api/posts — Liste paginée des posts.
     * Filtres: ?status=scheduled&account_id=3&from=2026-04-01&to=2026-05-01&per_page=25
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Post::with(['postPlatforms.platform', 'postPlatforms.socialAccount'])
            ->where('user_id', $user->id)
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('account_id')) {
            $accountId = $request->input('account_id');
            $query->whereHas('postPlatforms', fn ($q) => $q->where('social_account_id', $accountId));
        }

        if ($request->filled('from')) {
            $query->where(function ($q) use ($request) {
                $from = Carbon::parse($request->input('from'))->startOfDay();
                $q->where('scheduled_at', '>=', $from)->orWhere('published_at', '>=', $from);
            });
        }

        if ($request->filled('to')) {
            $query->where(function ($q) use ($request) {
                $to = Carbon::parse($request->input('to'))->endOfDay();
                $q->where('scheduled_at', '<=', $to)->orWhere('published_at', '<=', $to);
            });
        }

        if ($request->filled('source_type')) {
            $query->where('source_type', $request->input('source_type'));
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $posts = $query->paginate($perPage);

        return response()->json([
            'posts' => $posts->getCollection()->map(fn (Post $p) => $this->formatPost($p)),
            'pagination' => [
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
            ],
        ]);
    }

    /**
     * GET /api/posts/{id} — Détail d'un post.
     */
    public function show(Request $request, Post $post): JsonResponse
    {
        $this->authorizePost($request, $post);
        $post->load(['postPlatforms.platform', 'postPlatforms.socialAccount', 'postPlatforms.logs']);

        return response()->json(['post' => $this->formatPost($post, true)]);
    }

    /**
     * POST /api/posts — Créer un post (contenu fourni, sans IA).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'content_fr' => 'required|string|max:10000',
            'content_en' => 'nullable|string|max:10000',
            'platform_contents' => 'nullable|array',
            'platform_contents.*' => 'nullable|string|max:10000',
            'hashtags' => 'nullable|string|max:1000',
            'media' => 'nullable|array',
            'link_url' => 'nullable|url|max:2048',
            'status' => 'required|in:draft,scheduled',
            'scheduled_at' => 'required_if:status,scheduled|nullable|date|after_or_equal:now',
            'accounts' => 'required|array|min:1',
            'accounts.*' => 'integer|exists:social_accounts,id',
        ]);

        $user = $request->user();
        $validAccounts = $user->activeSocialAccounts()
            ->whereIn('social_accounts.id', $validated['accounts'])
            ->get();

        if ($validAccounts->count() !== count($validated['accounts'])) {
            return response()->json(['error' => 'Un ou plusieurs comptes invalides.'], 422);
        }

        $post = DB::transaction(function () use ($validated, $user, $validAccounts) {
            $platformContents = array_filter($validated['platform_contents'] ?? [], fn ($v) => ! empty($v));

            $post = Post::create([
                'user_id' => $user->id,
                'content_fr' => $validated['content_fr'],
                'content_en' => $validated['content_en'] ?? null,
                'platform_contents' => ! empty($platformContents) ? $platformContents : null,
                'hashtags' => $validated['hashtags'] ?? null,
                'auto_translate' => true,
                'media' => $validated['media'] ?? null,
                'link_url' => $validated['link_url'] ?? null,
                'status' => $validated['status'],
                'source_type' => 'manual',
                'scheduled_at' => $validated['scheduled_at'] ?? null,
            ]);

            foreach ($validAccounts as $account) {
                PostPlatform::create([
                    'post_id' => $post->id,
                    'social_account_id' => $account->id,
                    'platform_id' => $account->platform_id,
                    'status' => 'pending',
                ]);
            }

            return $post;
        });

        $post->load(['postPlatforms.platform', 'postPlatforms.socialAccount']);

        return response()->json(['post' => $this->formatPost($post)], 201);
    }

    /**
     * PUT /api/posts/{id} — Modifier un post (draft ou scheduled uniquement).
     */
    public function update(Request $request, Post $post): JsonResponse
    {
        $this->authorizePost($request, $post);

        if (! in_array($post->status, ['draft', 'scheduled'])) {
            return response()->json(['error' => 'Seuls les posts draft ou scheduled sont modifiables.'], 422);
        }

        $validated = $request->validate([
            'content_fr' => 'sometimes|string|max:10000',
            'content_en' => 'nullable|string|max:10000',
            'platform_contents' => 'nullable|array',
            'platform_contents.*' => 'nullable|string|max:10000',
            'hashtags' => 'nullable|string|max:1000',
            'media' => 'nullable|array',
            'link_url' => 'nullable|url|max:2048',
            'status' => 'sometimes|in:draft,scheduled',
            'scheduled_at' => 'nullable|date|after_or_equal:now',
            'accounts' => 'sometimes|array|min:1',
            'accounts.*' => 'integer|exists:social_accounts,id',
        ]);

        DB::transaction(function () use ($post, $validated, $request) {
            $updateData = array_intersect_key($validated, array_flip([
                'content_fr', 'content_en', 'hashtags', 'media', 'link_url', 'status', 'scheduled_at',
            ]));

            if (isset($validated['platform_contents'])) {
                $updateData['platform_contents'] = array_filter($validated['platform_contents'], fn ($v) => ! empty($v)) ?: null;
            }

            $post->update($updateData);

            // Mettre à jour les comptes si fournis
            if (isset($validated['accounts'])) {
                $user = $request->user();
                $validAccounts = $user->activeSocialAccounts()
                    ->whereIn('social_accounts.id', $validated['accounts'])
                    ->get();

                // Supprimer les anciens pending et recréer
                $post->postPlatforms()->where('status', 'pending')->delete();

                foreach ($validAccounts as $account) {
                    PostPlatform::create([
                        'post_id' => $post->id,
                        'social_account_id' => $account->id,
                        'platform_id' => $account->platform_id,
                        'status' => 'pending',
                    ]);
                }
            }
        });

        $post->refresh()->load(['postPlatforms.platform', 'postPlatforms.socialAccount']);

        return response()->json(['post' => $this->formatPost($post)]);
    }

    /**
     * DELETE /api/posts/{id} — Supprimer un post.
     */
    public function destroy(Request $request, Post $post): JsonResponse
    {
        $this->authorizePost($request, $post);

        if ($post->status === 'publishing') {
            return response()->json(['error' => 'Impossible de supprimer un post en cours de publication.'], 422);
        }

        $post->postPlatforms()->each(fn ($pp) => $pp->logs()->delete());
        $post->postPlatforms()->delete();
        $post->delete();

        return response()->json(['success' => true, 'message' => 'Post supprimé.']);
    }

    /**
     * POST /api/posts/{id}/publish — Forcer la publication immédiate.
     */
    public function publish(Request $request, Post $post): JsonResponse
    {
        $this->authorizePost($request, $post);

        if (! in_array($post->status, ['draft', 'scheduled', 'failed'])) {
            return response()->json(['error' => 'Ce post ne peut pas être publié.'], 422);
        }

        app(PublishingService::class)->publish($post);

        return response()->json([
            'success' => true,
            'message' => 'Publication lancée.',
            'post_id' => $post->id,
        ]);
    }

    /**
     * POST /api/bulk-cancel — Annuler les posts scheduled d'une période.
     */
    public function bulkCancel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'account_id' => 'nullable|integer|exists:social_accounts,id',
            'delete' => 'nullable|boolean',
        ]);

        $user = $request->user();
        $from = Carbon::parse($validated['from'])->startOfDay();
        $to = Carbon::parse($validated['to'])->endOfDay();

        $query = Post::where('user_id', $user->id)
            ->where('status', 'scheduled')
            ->whereBetween('scheduled_at', [$from, $to]);

        if (! empty($validated['account_id'])) {
            $query->whereHas('postPlatforms', fn ($q) => $q->where('social_account_id', $validated['account_id']));
        }

        $posts = $query->get();
        $count = $posts->count();

        $shouldDelete = $validated['delete'] ?? false;

        foreach ($posts as $post) {
            if ($shouldDelete) {
                $post->postPlatforms()->each(fn ($pp) => $pp->logs()->delete());
                $post->postPlatforms()->delete();
                $post->delete();
            } else {
                $post->update(['status' => 'draft', 'scheduled_at' => null]);
            }
        }

        return response()->json([
            'success' => true,
            'action' => $shouldDelete ? 'deleted' : 'cancelled',
            'count' => $count,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);
    }

    private function authorizePost(Request $request, Post $post): void
    {
        $user = $request->user();
        if (! $user->isAdmin() && $post->user_id !== $user->id) {
            abort(403, 'Accès refusé.');
        }
    }

    private function formatPost(Post $post, bool $detailed = false): array
    {
        $data = [
            'id' => $post->id,
            'content_fr' => $post->content_fr,
            'content_preview' => $post->content_preview,
            'hashtags' => $post->hashtags,
            'status' => $post->status,
            'source_type' => $post->source_type,
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
            'published_at' => $post->published_at?->toIso8601String(),
            'created_at' => $post->created_at->toIso8601String(),
            'accounts' => $post->postPlatforms->map(fn ($pp) => [
                'id' => $pp->social_account_id,
                'name' => $pp->socialAccount?->name,
                'platform' => $pp->platform?->slug,
                'status' => $pp->status,
                'external_id' => $pp->external_id,
                'metrics' => $pp->metrics,
                'published_at' => $pp->published_at?->toIso8601String(),
            ]),
        ];

        if ($detailed) {
            $data['content_en'] = $post->content_en;
            $data['platform_contents'] = $post->platform_contents;
            $data['media'] = $post->media;
            $data['link_url'] = $post->link_url;
            $data['auto_translate'] = $post->auto_translate;
            $data['logs'] = $post->postPlatforms->flatMap(fn ($pp) => $pp->logs->map(fn ($log) => [
                'action' => $log->action,
                'details' => $log->details,
                'created_at' => $log->created_at->toIso8601String(),
            ]));
        }

        return $data;
    }
}
