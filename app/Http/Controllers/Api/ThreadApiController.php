<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Models\ThreadSegment;
use App\Models\ThreadSegmentPlatform;
use App\Services\ThreadPublishingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ThreadApiController extends Controller
{
    private const THREAD_PLATFORM_SLUGS = ['twitter', 'threads', 'bluesky'];

    /**
     * GET /api/threads — Liste paginée des threads.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Thread::with(['segments', 'socialAccounts.platform'])
            ->where('user_id', $user->id)
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('account_id')) {
            $accountId = $request->input('account_id');
            $query->whereHas('socialAccounts', fn ($q) => $q->where('social_accounts.id', $accountId));
        }

        if ($request->filled('from')) {
            $from = Carbon::parse($request->input('from'))->startOfDay();
            $query->where(fn ($q) => $q->where('scheduled_at', '>=', $from)->orWhere('published_at', '>=', $from));
        }

        if ($request->filled('to')) {
            $to = Carbon::parse($request->input('to'))->endOfDay();
            $query->where(fn ($q) => $q->where('scheduled_at', '<=', $to)->orWhere('published_at', '<=', $to));
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $threads = $query->paginate($perPage);

        return response()->json([
            'threads' => $threads->getCollection()->map(fn (Thread $t) => $this->formatThread($t)),
            'pagination' => [
                'current_page' => $threads->currentPage(),
                'last_page' => $threads->lastPage(),
                'per_page' => $threads->perPage(),
                'total' => $threads->total(),
            ],
        ]);
    }

    /**
     * GET /api/threads/{id} — Détail d'un thread avec segments.
     */
    public function show(Request $request, Thread $thread): JsonResponse
    {
        $this->authorizeThread($request, $thread);
        $thread->load(['segments.segmentPlatforms.socialAccount.platform', 'socialAccounts.platform']);

        return response()->json(['thread' => $this->formatThread($thread, true)]);
    }

    /**
     * POST /api/threads — Créer un thread (segments fournis, sans IA).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'source_url' => 'nullable|url|max:2048',
            'accounts' => 'required|array|min:1',
            'accounts.*' => 'integer|exists:social_accounts,id',
            'segments' => 'required|array|min:1',
            'segments.*.content_fr' => 'required|string|max:10000',
            'segments.*.platform_contents' => 'nullable|array',
            'segments.*.platform_contents.*' => 'nullable|string|max:10000',
            'segments.*.media' => 'nullable|array',
            'status' => 'required|in:draft,scheduled',
            'scheduled_at' => 'required_if:status,scheduled|nullable|date|after_or_equal:now',
        ]);

        $user = $request->user();
        $validAccounts = $user->activeSocialAccounts()
            ->with('platform')
            ->whereIn('social_accounts.id', $validated['accounts'])
            ->get();

        if ($validAccounts->count() !== count($validated['accounts'])) {
            return response()->json(['error' => 'Un ou plusieurs comptes invalides.'], 422);
        }

        $thread = DB::transaction(function () use ($validated, $user, $validAccounts) {
            $thread = Thread::create([
                'user_id' => $user->id,
                'title' => $validated['title'] ?? null,
                'source_url' => $validated['source_url'] ?? null,
                'source_type' => 'manual',
                'status' => $validated['status'],
                'scheduled_at' => $validated['scheduled_at'] ?? null,
            ]);

            foreach ($validated['segments'] as $index => $segData) {
                $platformContents = array_filter($segData['platform_contents'] ?? [], fn ($v) => ! empty($v));

                $segment = ThreadSegment::create([
                    'thread_id' => $thread->id,
                    'position' => $index + 1,
                    'content_fr' => $segData['content_fr'],
                    'platform_contents' => ! empty($platformContents) ? $platformContents : null,
                    'media' => $segData['media'] ?? null,
                ]);

                foreach ($validAccounts as $account) {
                    ThreadSegmentPlatform::create([
                        'thread_segment_id' => $segment->id,
                        'social_account_id' => $account->id,
                        'platform_id' => $account->platform_id,
                        'status' => 'pending',
                    ]);
                }
            }

            foreach ($validAccounts as $account) {
                $publishMode = in_array($account->platform->slug, self::THREAD_PLATFORM_SLUGS) ? 'thread' : 'compiled';
                $thread->socialAccounts()->attach($account->id, [
                    'platform_id' => $account->platform_id,
                    'publish_mode' => $publishMode,
                    'status' => 'pending',
                ]);
            }

            return $thread;
        });

        $thread->load(['segments', 'socialAccounts.platform']);

        return response()->json(['thread' => $this->formatThread($thread)], 201);
    }

    /**
     * PUT /api/threads/{id} — Modifier un thread (draft ou scheduled uniquement).
     */
    public function update(Request $request, Thread $thread): JsonResponse
    {
        $this->authorizeThread($request, $thread);

        if (! in_array($thread->status, ['draft', 'scheduled'])) {
            return response()->json(['error' => 'Seuls les threads draft ou scheduled sont modifiables.'], 422);
        }

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'status' => 'sometimes|in:draft,scheduled',
            'scheduled_at' => 'nullable|date|after_or_equal:now',
            'segments' => 'sometimes|array|min:1',
            'segments.*.content_fr' => 'required|string|max:10000',
            'segments.*.platform_contents' => 'nullable|array',
            'segments.*.platform_contents.*' => 'nullable|string|max:10000',
            'segments.*.media' => 'nullable|array',
        ]);

        DB::transaction(function () use ($thread, $validated) {
            $updateData = array_intersect_key($validated, array_flip(['title', 'status', 'scheduled_at']));
            $thread->update($updateData);

            // Recréer les segments si fournis
            if (isset($validated['segments'])) {
                // Supprimer anciens segments et leurs platforms
                foreach ($thread->segments as $segment) {
                    $segment->segmentPlatforms()->delete();
                }
                $thread->segments()->delete();

                $accounts = $thread->socialAccounts()->with('platform')->get();

                foreach ($validated['segments'] as $index => $segData) {
                    $platformContents = array_filter($segData['platform_contents'] ?? [], fn ($v) => ! empty($v));

                    $segment = ThreadSegment::create([
                        'thread_id' => $thread->id,
                        'position' => $index + 1,
                        'content_fr' => $segData['content_fr'],
                        'platform_contents' => ! empty($platformContents) ? $platformContents : null,
                        'media' => $segData['media'] ?? null,
                    ]);

                    foreach ($accounts as $account) {
                        ThreadSegmentPlatform::create([
                            'thread_segment_id' => $segment->id,
                            'social_account_id' => $account->id,
                            'platform_id' => $account->platform_id,
                            'status' => 'pending',
                        ]);
                    }
                }
            }
        });

        $thread->refresh()->load(['segments', 'socialAccounts.platform']);

        return response()->json(['thread' => $this->formatThread($thread)]);
    }

    /**
     * DELETE /api/threads/{id} — Supprimer un thread.
     */
    public function destroy(Request $request, Thread $thread): JsonResponse
    {
        $this->authorizeThread($request, $thread);

        if ($thread->status === 'publishing') {
            return response()->json(['error' => 'Impossible de supprimer un thread en cours de publication.'], 422);
        }

        DB::transaction(function () use ($thread) {
            foreach ($thread->segments as $segment) {
                $segment->segmentPlatforms()->delete();
            }
            $thread->segments()->delete();
            $thread->socialAccounts()->detach();
            $thread->delete();
        });

        return response()->json(['success' => true, 'message' => 'Thread supprimé.']);
    }

    /**
     * POST /api/threads/{id}/publish — Forcer la publication.
     */
    public function publish(Request $request, Thread $thread): JsonResponse
    {
        $this->authorizeThread($request, $thread);

        if (! in_array($thread->status, ['draft', 'scheduled', 'failed', 'partial'])) {
            return response()->json(['error' => 'Ce thread ne peut pas être publié.'], 422);
        }

        $thread->load(['segments.segmentPlatforms', 'socialAccounts.platform']);

        foreach ($thread->socialAccounts as $account) {
            app(ThreadPublishingService::class)->publishToAccount($thread, $account);
        }

        return response()->json([
            'success' => true,
            'message' => 'Publication lancée.',
            'thread_id' => $thread->id,
        ]);
    }

    private function authorizeThread(Request $request, Thread $thread): void
    {
        $user = $request->user();
        if (! $user->isAdmin() && $thread->user_id !== $user->id) {
            abort(403, 'Accès refusé.');
        }
    }

    private function formatThread(Thread $thread, bool $detailed = false): array
    {
        $data = [
            'id' => $thread->id,
            'title' => $thread->title,
            'status' => $thread->status,
            'source_type' => $thread->source_type,
            'source_url' => $thread->source_url,
            'segments_count' => $thread->segments->count(),
            'content_preview' => $thread->content_preview,
            'scheduled_at' => $thread->scheduled_at?->toIso8601String(),
            'published_at' => $thread->published_at?->toIso8601String(),
            'created_at' => $thread->created_at->toIso8601String(),
            'accounts' => $thread->socialAccounts->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'platform' => $a->platform->slug,
                'publish_mode' => $a->pivot->publish_mode,
                'status' => $a->pivot->status,
            ]),
        ];

        if ($detailed) {
            $data['segments'] = $thread->segments->map(fn (ThreadSegment $s) => [
                'position' => $s->position,
                'content_fr' => $s->content_fr,
                'platform_contents' => $s->platform_contents,
                'media' => $s->media,
                'platforms' => $s->segmentPlatforms->map(fn ($sp) => [
                    'account_id' => $sp->social_account_id,
                    'account_name' => $sp->socialAccount?->name,
                    'platform' => $sp->socialAccount?->platform?->slug,
                    'status' => $sp->status,
                    'external_id' => $sp->external_id,
                    'error_message' => $sp->error_message,
                ]),
            ]);
        }

        return $data;
    }
}
