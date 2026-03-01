<?php

namespace App\Http\Controllers;

use App\Models\HookCategory;
use App\Models\Persona;
use App\Models\Platform;
use App\Models\RedditSource;
use App\Models\RssFeed;
use App\Models\SocialAccount;
use App\Models\WpSource;
use App\Models\YtSource;
use App\Models\Thread;
use App\Models\ThreadSegment;
use App\Models\ThreadSegmentPlatform;
use App\Services\ThreadContentGenerationService;
use App\Services\ThreadPublishingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ThreadController extends Controller
{
    private const THREAD_PLATFORM_SLUGS = ['twitter', 'threads'];

    private const COMPILED_PLATFORM_SLUGS = ['facebook', 'telegram'];

    /**
     * Display a list of threads.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        $query = Thread::query()
            ->with(['segments', 'socialAccounts.platform', 'user'])
            ->withCount('segments');

        if (! $user->is_admin) {
            $query->where('user_id', $user->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $threads = $query->orderByDesc('created_at')->paginate(15)->withQueryString();

        return view('threads.index', compact('threads'));
    }

    /**
     * Show the thread creation form.
     */
    public function create(Request $request): View
    {
        $user = $request->user();

        $accounts = $user->activeSocialAccounts()
            ->with(['platform', 'persona'])
            ->orderBy('name')
            ->get()
            ->groupBy(fn (SocialAccount $account) => $account->platform->slug);

        $platforms = Platform::where('is_active', true)->get();
        $personas = Persona::where('is_active', true)->orderBy('name')->get();
        $hookCategories = HookCategory::active()->ordered()->withCount('activeHooks')->get();

        $sourceTypeCounts = [];
        if ($user->is_admin) {
            $sourceTypeCounts = [
                'wordpress' => WpSource::where('is_active', true)->count(),
                'rss' => RssFeed::where('is_active', true)->count(),
                'youtube' => YtSource::where('is_active', true)->count(),
                'reddit' => RedditSource::where('is_active', true)->count(),
            ];
        }

        return view('threads.create', compact('accounts', 'platforms', 'personas', 'hookCategories', 'sourceTypeCounts'));
    }

    /**
     * Store a new thread with its segments.
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'source_url' => 'nullable|url|max:2048',
            'source_type' => 'nullable|string|in:manual,rss,wordpress,youtube,reddit',
            'accounts' => 'required|array|min:1',
            'accounts.*' => 'integer|exists:social_accounts,id',
            'segments' => 'required|array|min:1',
            'segments.*.content_fr' => 'required|string|max:10000',
            'segments.*.platform_contents' => 'nullable|array',
            'segments.*.platform_contents.*' => 'nullable|string|max:10000',
            'segments.*.media_json' => 'nullable|string',
            'status' => 'required|in:draft,scheduled',
            'scheduled_at' => 'nullable|date|after_or_equal:now',
            'publish_now' => 'nullable|boolean',
        ]);

        $publishNow = $request->boolean('publish_now');
        $user = $request->user();

        $thread = DB::transaction(function () use ($validated, $user, $publishNow) {
            $thread = Thread::create([
                'user_id' => $user->id,
                'title' => $validated['title'] ?? null,
                'source_url' => $validated['source_url'] ?? null,
                'source_type' => $validated['source_type'] ?? 'manual',
                'status' => $publishNow ? 'draft' : ($validated['status'] ?? 'draft'),
                'scheduled_at' => $publishNow ? null : ($validated['scheduled_at'] ?? null),
            ]);

            // Create segments.
            foreach ($validated['segments'] as $index => $segmentData) {
                $platformContents = $segmentData['platform_contents'] ?? [];
                $platformContents = array_filter($platformContents, fn ($v) => ! empty($v));

                $media = null;
                if (! empty($segmentData['media_json'])) {
                    $media = json_decode($segmentData['media_json'], true);
                }

                $segment = ThreadSegment::create([
                    'thread_id' => $thread->id,
                    'position' => $index + 1,
                    'content_fr' => $segmentData['content_fr'],
                    'platform_contents' => ! empty($platformContents) ? $platformContents : null,
                    'media' => ! empty($media) ? $media : null,
                ]);

                // Create segment platform entries for each account.
                foreach ($validated['accounts'] as $accountId) {
                    $account = SocialAccount::with('platform')->find($accountId);
                    if ($account) {
                        ThreadSegmentPlatform::create([
                            'thread_segment_id' => $segment->id,
                            'social_account_id' => $account->id,
                            'platform_id' => $account->platform_id,
                            'status' => 'pending',
                        ]);
                    }
                }
            }

            // Create thread <-> social account pivot entries.
            foreach ($validated['accounts'] as $accountId) {
                $account = SocialAccount::with('platform')->find($accountId);
                if ($account) {
                    $publishMode = in_array($account->platform->slug, self::THREAD_PLATFORM_SLUGS)
                        ? 'thread'
                        : 'compiled';

                    $thread->socialAccounts()->attach($account->id, [
                        'platform_id' => $account->platform_id,
                        'publish_mode' => $publishMode,
                        'status' => 'pending',
                    ]);
                }
            }

            return $thread;
        });

        if ($publishNow && $request->expectsJson()) {
            return response()->json([
                'success' => true,
                'thread_id' => $thread->id,
                'show_url' => route('threads.show', $thread),
                'accounts' => $thread->socialAccounts->map(fn ($account) => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'platform_slug' => $account->platform->slug,
                    'publish_mode' => $account->pivot->publish_mode,
                    'publish_url' => route('threads.publishOne', [$thread, $account]),
                ]),
            ]);
        }

        return redirect()->route('threads.show', $thread)
            ->with('success', 'Fil de discussion créé.');
    }

    /**
     * Display thread details with segments and statuses.
     */
    public function show(Request $request, Thread $thread): View
    {
        $user = $request->user();

        if (! $user->is_admin && $thread->user_id !== $user->id) {
            abort(403);
        }

        $thread->load([
            'segments.segmentPlatforms.socialAccount.platform',
            'socialAccounts.platform',
            'user',
        ]);

        return view('threads.show', compact('thread'));
    }

    /**
     * Show the edit form for a thread.
     */
    public function edit(Request $request, Thread $thread): View
    {
        $user = $request->user();

        if (! $user->is_admin && $thread->user_id !== $user->id) {
            abort(403);
        }

        if (in_array($thread->status, ['publishing', 'published'])) {
            return redirect()->route('threads.show', $thread)
                ->with('error', 'Impossible de modifier un fil déjà publié.');
        }

        $thread->load(['segments', 'socialAccounts.platform']);

        $accounts = $user->activeSocialAccounts()
            ->with(['platform', 'persona'])
            ->orderBy('name')
            ->get()
            ->groupBy(fn (SocialAccount $account) => $account->platform->slug);

        $platforms = Platform::where('is_active', true)->get();
        $personas = Persona::where('is_active', true)->orderBy('name')->get();
        $selectedAccountIds = $thread->socialAccounts->pluck('id')->toArray();

        return view('threads.edit', compact('thread', 'accounts', 'platforms', 'personas', 'selectedAccountIds'));
    }

    /**
     * Update a thread and its segments.
     */
    public function update(Request $request, Thread $thread): RedirectResponse
    {
        $user = $request->user();

        if (! $user->is_admin && $thread->user_id !== $user->id) {
            abort(403);
        }

        if (in_array($thread->status, ['publishing', 'published'])) {
            return redirect()->route('threads.show', $thread)
                ->with('error', 'Impossible de modifier un fil déjà publié.');
        }

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'source_url' => 'nullable|url|max:2048',
            'accounts' => 'required|array|min:1',
            'accounts.*' => 'integer|exists:social_accounts,id',
            'segments' => 'required|array|min:1',
            'segments.*.content_fr' => 'required|string|max:10000',
            'segments.*.platform_contents' => 'nullable|array',
            'segments.*.platform_contents.*' => 'nullable|string|max:10000',
            'segments.*.media_json' => 'nullable|string',
            'status' => 'required|in:draft,scheduled',
            'scheduled_at' => 'nullable|date|after_or_equal:now',
        ]);

        DB::transaction(function () use ($thread, $validated) {
            $thread->update([
                'title' => $validated['title'] ?? null,
                'source_url' => $validated['source_url'] ?? null,
                'status' => $validated['status'],
                'scheduled_at' => $validated['scheduled_at'] ?? null,
            ]);

            // Delete old segments (cascade deletes segment platforms).
            $thread->segments()->delete();

            // Detach old accounts.
            $thread->socialAccounts()->detach();

            // Recreate segments and pivots.
            foreach ($validated['segments'] as $index => $segmentData) {
                $platformContents = $segmentData['platform_contents'] ?? [];
                $platformContents = array_filter($platformContents, fn ($v) => ! empty($v));

                $media = null;
                if (! empty($segmentData['media_json'])) {
                    $media = json_decode($segmentData['media_json'], true);
                }

                $segment = ThreadSegment::create([
                    'thread_id' => $thread->id,
                    'position' => $index + 1,
                    'content_fr' => $segmentData['content_fr'],
                    'platform_contents' => ! empty($platformContents) ? $platformContents : null,
                    'media' => ! empty($media) ? $media : null,
                ]);

                foreach ($validated['accounts'] as $accountId) {
                    $account = SocialAccount::with('platform')->find($accountId);
                    if ($account) {
                        ThreadSegmentPlatform::create([
                            'thread_segment_id' => $segment->id,
                            'social_account_id' => $account->id,
                            'platform_id' => $account->platform_id,
                            'status' => 'pending',
                        ]);
                    }
                }
            }

            foreach ($validated['accounts'] as $accountId) {
                $account = SocialAccount::with('platform')->find($accountId);
                if ($account) {
                    $publishMode = in_array($account->platform->slug, self::THREAD_PLATFORM_SLUGS)
                        ? 'thread'
                        : 'compiled';

                    $thread->socialAccounts()->attach($account->id, [
                        'platform_id' => $account->platform_id,
                        'publish_mode' => $publishMode,
                        'status' => 'pending',
                    ]);
                }
            }
        });

        return redirect()->route('threads.show', $thread)
            ->with('success', 'Fil de discussion mis à jour.');
    }

    /**
     * Delete a thread.
     */
    public function destroy(Request $request, Thread $thread): RedirectResponse
    {
        $user = $request->user();

        if (! $user->is_admin && $thread->user_id !== $user->id) {
            abort(403);
        }

        $thread->delete();

        return redirect()->route('threads.index')
            ->with('success', 'Fil de discussion supprimé.');
    }

    /**
     * AJAX: Generate thread segments from a URL via AI.
     */
    public function generateFromUrl(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_url' => 'required|url|max:2048',
            'persona_id' => 'required|exists:personas,id',
            'hook_category_id' => 'nullable|integer|exists:hook_categories,id',
            'accounts' => 'required|array|min:1',
            'accounts.*' => 'integer|exists:social_accounts,id',
        ]);

        // Determine platform slugs from selected accounts.
        $platformSlugs = SocialAccount::with('platform')
            ->whereIn('id', $validated['accounts'])
            ->get()
            ->pluck('platform.slug')
            ->unique()
            ->values()
            ->toArray();

        $persona = Persona::findOrFail($validated['persona_id']);
        $service = new ThreadContentGenerationService;

        $hookCategoryId = $validated['hook_category_id'] ?? null;
        $result = $service->generate($validated['source_url'], $persona, $platformSlugs, $hookCategoryId);

        if (! $result) {
            return response()->json([
                'success' => false,
                'error' => 'Impossible de générer le fil de discussion. Vérifiez l\'URL et réessayez.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * AJAX: Regenerate a single segment.
     */
    public function regenerateSegment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_url' => 'required|url|max:2048',
            'persona_id' => 'required|exists:personas,id',
            'position' => 'required|integer|min:1',
            'total_segments' => 'required|integer|min:1',
            'previous_content' => 'nullable|string',
            'next_content' => 'nullable|string',
            'accounts' => 'required|array|min:1',
            'accounts.*' => 'integer|exists:social_accounts,id',
        ]);

        $platformSlugs = SocialAccount::with('platform')
            ->whereIn('id', $validated['accounts'])
            ->get()
            ->pluck('platform.slug')
            ->unique()
            ->values()
            ->toArray();

        $persona = Persona::findOrFail($validated['persona_id']);
        $service = new ThreadContentGenerationService;

        $result = $service->regenerateSegment(
            $validated['source_url'],
            $persona,
            $validated['position'],
            $validated['total_segments'],
            $validated['previous_content'] ?? '',
            $validated['next_content'] ?? '',
            $platformSlugs,
        );

        if (! $result) {
            return response()->json([
                'success' => false,
                'error' => 'Impossible de régénérer le segment.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * AJAX: Publish a thread to all linked accounts.
     */
    public function publishAll(Request $request, Thread $thread): JsonResponse
    {
        // Threads requires 35s between segments — extend timeout accordingly.
        set_time_limit(600);

        $user = $request->user();

        if (! $user->is_admin && $thread->user_id !== $user->id) {
            return response()->json(['success' => false, 'error' => 'Non autorisé.'], 403);
        }

        $service = new ThreadPublishingService;
        $results = $service->publishAll($thread);

        $thread->refresh();

        return response()->json([
            'success' => in_array($thread->status, ['published', 'partial']),
            'status' => $thread->status,
            'results' => $results,
        ]);
    }

    /**
     * AJAX: Publish a thread to a single account.
     */
    public function publishOne(Request $request, Thread $thread, SocialAccount $socialAccount): JsonResponse
    {
        // Threads requires 35s between segments — extend timeout accordingly.
        set_time_limit(600);

        $user = $request->user();

        if (! $user->is_admin && $thread->user_id !== $user->id) {
            return response()->json(['success' => false, 'error' => 'Non autorisé.'], 403);
        }

        $service = new ThreadPublishingService;
        $results = $service->publishToAccount($thread, $socialAccount);

        $thread->refresh();

        $allSuccess = collect($results)->every(fn ($r) => $r['success'] ?? false);

        return response()->json([
            'success' => $allSuccess,
            'account' => $socialAccount->name,
            'results' => $results,
        ]);
    }

    /**
     * AJAX: Reset a thread's account status for retry.
     */
    public function resetAccount(Request $request, Thread $thread, SocialAccount $socialAccount): JsonResponse
    {
        $user = $request->user();

        if (! $user->is_admin && $thread->user_id !== $user->id) {
            return response()->json(['success' => false, 'error' => 'Non autorisé.'], 403);
        }

        $service = new ThreadPublishingService;
        $service->resetAccount($thread, $socialAccount);

        if (in_array($thread->status, ['published', 'failed', 'partial'])) {
            $thread->update(['status' => 'draft', 'published_at' => null]);
        }

        return response()->json(['success' => true, 'message' => 'Remis en attente.']);
    }
}
