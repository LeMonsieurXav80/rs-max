<?php

namespace App\Http\Controllers;

use App\Models\Hashtag;
use App\Models\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Stats\StatsSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PostController extends Controller
{
    /**
     * Save the user's default account selection for post creation.
     */
    public function saveDefaultAccounts(Request $request)
    {
        $validated = $request->validate([
            'accounts' => 'required|array',
            'accounts.*' => 'integer|exists:social_accounts,id',
        ]);

        $request->user()->update(['default_accounts' => $validated['accounts']]);

        return response()->json(['success' => true]);
    }

    /**
     * Display a paginated list of posts, optionally filtered by status.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        $query = Post::query()->with('postPlatforms.platform', 'user');

        // Admin sees all posts, regular user sees only own posts
        if (! $user->is_admin) {
            $query->where('user_id', $user->id);
        }

        // Filter by status if provided
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // List view: paginated
        $posts = (clone $query)->orderByDesc('created_at')->paginate(15)->withQueryString();

        // Calendar view: posts for the selected month
        $month = $request->input('month', now()->format('Y-m'));
        $startOfMonth = \Carbon\Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $endOfMonth = $startOfMonth->copy()->endOfMonth();

        $calendarQuery = Post::query()->with('postPlatforms.platform', 'user');
        if (! $user->is_admin) {
            $calendarQuery->where('user_id', $user->id);
        }
        if ($request->filled('status')) {
            $calendarQuery->where('status', $request->input('status'));
        }

        $calendarPosts = $calendarQuery
            ->where(function ($q) use ($startOfMonth, $endOfMonth) {
                $q->whereBetween('scheduled_at', [$startOfMonth, $endOfMonth])
                  ->orWhereBetween('published_at', [$startOfMonth, $endOfMonth]);
            })
            ->orderByRaw('COALESCE(scheduled_at, published_at) ASC')
            ->get()
            ->groupBy(fn (Post $p) => ($p->scheduled_at ?? $p->published_at)->format('Y-m-d'));

        $prevMonth = $startOfMonth->copy()->subMonth()->format('Y-m');
        $nextMonth = $startOfMonth->copy()->addMonth()->format('Y-m');

        return view('posts.index', compact(
            'posts', 'calendarPosts', 'startOfMonth', 'endOfMonth', 'month', 'prevMonth', 'nextMonth'
        ));
    }

    /**
     * Show the form for creating a new post.
     */
    public function create(Request $request): View
    {
        $user = $request->user();

        // Admin sees all active accounts, regular user sees only their own
        if ($user->is_admin) {
            $accounts = SocialAccount::with('platform')
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->groupBy(fn (SocialAccount $account) => $account->platform->slug);
        } else {
            $accounts = $user->activeSocialAccounts()
                ->with('platform')
                ->get()
                ->groupBy(fn (SocialAccount $account) => $account->platform->slug);
        }

        $platforms = Platform::where('is_active', true)->get();
        $defaultAccountIds = $user->default_accounts ?? [];

        return view('posts.create', compact('accounts', 'platforms', 'defaultAccountIds'));
    }

    /**
     * Validate and store a newly created post.
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'content_fr'        => 'required|string|max:10000',
            'content_en'        => 'nullable|string|max:10000',
            'hashtags'          => 'nullable|string|max:1000',
            'auto_translate'    => 'nullable|boolean',
            'media'             => 'nullable|array',
            'media.*'           => 'nullable|string|max:2000',
            'link_url'          => 'nullable|url|max:2048',
            'location_name'     => 'nullable|string|max:255',
            'location_id'       => 'nullable|string|max:255',
            'status'            => 'required|in:draft,scheduled',
            'publish_now'       => 'nullable|boolean',
            'scheduled_at'      => [$request->boolean('publish_now') ? 'nullable' : 'required_if:status,scheduled', 'nullable', 'date', 'after_or_equal:now'],
            'accounts'          => 'required|array|min:1',
            'accounts.*'        => 'integer|exists:social_accounts,id',
        ]);

        $user = $request->user();
        $publishNow = ! empty($validated['publish_now']);

        // Verify all selected accounts are accessible to the user (unless admin)
        $accountIds = $validated['accounts'];
        if ($user->is_admin) {
            $validAccounts = SocialAccount::whereIn('id', $accountIds)->get();
        } else {
            $validAccounts = $user->socialAccounts()->whereIn('social_accounts.id', $accountIds)->get();
        }

        if ($validAccounts->count() !== count($accountIds)) {
            if ($request->expectsJson()) {
                return response()->json(['errors' => ['accounts' => ['One or more selected accounts are invalid.']]], 422);
            }
            return back()->withErrors(['accounts' => 'One or more selected accounts are invalid.'])->withInput();
        }

        $post = DB::transaction(function () use ($validated, $user, $validAccounts, $publishNow) {
            $status = $validated['status'];
            $scheduledAt = $validated['scheduled_at'] ?? null;

            if ($publishNow) {
                $status = 'draft';
                $scheduledAt = null;
            }

            // Decode media JSON strings into arrays
            $media = null;
            if (! empty($validated['media'])) {
                $media = array_values(array_filter(array_map(function ($item) {
                    $decoded = is_string($item) ? json_decode($item, true) : $item;

                    return is_array($decoded) && isset($decoded['url']) ? $decoded : null;
                }, $validated['media'])));
                if (empty($media)) {
                    $media = null;
                }
            }

            $post = Post::create([
                'user_id'          => $user->id,
                'content_fr'       => $validated['content_fr'],
                'content_en'       => $validated['content_en'] ?? null,
                'hashtags'         => $validated['hashtags'] ?? null,
                'auto_translate'   => $validated['auto_translate'] ?? false,
                'media'            => $media,
                'link_url'         => $validated['link_url'] ?? null,
                'location_name'    => $validated['location_name'] ?? null,
                'location_id'      => $validated['location_id'] ?? null,
                'status'           => $status,
                'scheduled_at'     => $scheduledAt,
            ]);

            foreach ($validAccounts as $account) {
                PostPlatform::create([
                    'post_id'           => $post->id,
                    'social_account_id' => $account->id,
                    'platform_id'       => $account->platform_id,
                    'status'            => 'pending',
                ]);
            }

            return $post;
        });

        // Record hashtag usage
        if (! empty($validated['hashtags'])) {
            $this->recordHashtagsUsage($user->id, $validated['hashtags']);
        }

        // Return JSON for publish-now AJAX requests
        if ($publishNow && $request->expectsJson()) {
            $post->load('postPlatforms.socialAccount.platform');

            return response()->json([
                'success' => true,
                'post_id' => $post->id,
                'show_url' => route('posts.show', $post),
                'post_platforms' => $post->postPlatforms->map(fn ($pp) => [
                    'id' => $pp->id,
                    'account_name' => $pp->socialAccount->name,
                    'platform_slug' => $pp->socialAccount->platform->slug,
                    'publish_url' => route('posts.publishOne', $pp),
                ]),
            ]);
        }

        return redirect()->route('posts.index')
            ->with('success', 'Post created successfully.');
    }

    /**
     * Display the specified post with its platform details and logs.
     */
    public function show(Request $request, int $id): View
    {
        $post = Post::with([
            'postPlatforms.platform',
            'postPlatforms.socialAccount',
            'postPlatforms.logs',
            'user',
        ])->findOrFail($id);

        // Regular users can only view their own posts
        if (! $request->user()->is_admin && $post->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized.');
        }

        return view('posts.show', compact('post'));
    }

    /**
     * Show the form for editing the specified post.
     */
    public function edit(Request $request, int $id): View
    {
        $post = Post::with('postPlatforms')->findOrFail($id);

        // Regular users can only edit their own posts
        if (! $request->user()->is_admin && $post->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized.');
        }

        $user = $request->user();

        // Admin sees all active accounts, regular user sees only their own
        if ($user->is_admin) {
            $accounts = SocialAccount::with('platform')
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->groupBy(fn (SocialAccount $account) => $account->platform->slug);
        } else {
            $accounts = $user->activeSocialAccounts()
                ->with('platform')
                ->get()
                ->groupBy(fn (SocialAccount $account) => $account->platform->slug);
        }

        $platforms = Platform::where('is_active', true)->get();

        // IDs of currently selected accounts
        $selectedAccountIds = $post->postPlatforms->pluck('social_account_id')->toArray();

        return view('posts.edit', compact('post', 'accounts', 'platforms', 'selectedAccountIds'));
    }

    /**
     * Update the specified post and sync PostPlatform entries.
     */
    public function update(Request $request, int $id): RedirectResponse
    {
        $post = Post::with('postPlatforms')->findOrFail($id);

        // Regular users can only update their own posts
        if (! $request->user()->is_admin && $post->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized.');
        }

        // Prevent editing posts that are already publishing or published
        if (in_array($post->status, ['publishing', 'published'])) {
            return back()->withErrors(['status' => 'Cannot edit a post that is already publishing or published.']);
        }

        $validated = $request->validate([
            'content_fr'        => 'required|string|max:10000',
            'content_en'        => 'nullable|string|max:10000',
            'hashtags'          => 'nullable|string|max:1000',
            'auto_translate'    => 'nullable|boolean',
            'media'             => 'nullable|array',
            'media.*'           => 'nullable|string|max:2000',
            'link_url'          => 'nullable|url|max:2048',
            'location_name'     => 'nullable|string|max:255',
            'location_id'       => 'nullable|string|max:255',
            'status'            => 'required|in:draft,scheduled',
            'publish_now'       => 'nullable|boolean',
            'scheduled_at'      => [$request->boolean('publish_now') ? 'nullable' : 'required_if:status,scheduled', 'nullable', 'date', 'after_or_equal:now'],
            'accounts'          => 'required|array|min:1',
            'accounts.*'        => 'integer|exists:social_accounts,id',
        ]);

        $user = $request->user();
        $publishNow = ! empty($validated['publish_now']);

        // Verify all selected accounts are accessible to the user (unless admin)
        $accountIds = $validated['accounts'];
        if ($user->is_admin) {
            $validAccounts = SocialAccount::whereIn('id', $accountIds)->get();
        } else {
            $validAccounts = $user->socialAccounts()->whereIn('social_accounts.id', $accountIds)->get();
        }

        if ($validAccounts->count() !== count($accountIds)) {
            return back()->withErrors(['accounts' => 'One or more selected accounts are invalid.'])->withInput();
        }

        DB::transaction(function () use ($post, $validated, $validAccounts, $publishNow) {
            // If "publish now" is selected, override status and scheduled_at
            $status = $validated['status'];
            $scheduledAt = $validated['scheduled_at'] ?? null;

            if ($publishNow) {
                $status = 'scheduled';
                $scheduledAt = now();
            }

            // Decode media JSON strings into arrays
            $media = null;
            if (! empty($validated['media'])) {
                $media = array_values(array_filter(array_map(function ($item) {
                    $decoded = is_string($item) ? json_decode($item, true) : $item;

                    return is_array($decoded) && isset($decoded['url']) ? $decoded : null;
                }, $validated['media'])));
                if (empty($media)) {
                    $media = null;
                }
            }

            // Update the post
            $post->update([
                'content_fr'       => $validated['content_fr'],
                'content_en'       => $validated['content_en'] ?? null,
                'hashtags'         => $validated['hashtags'] ?? null,
                'auto_translate'   => $validated['auto_translate'] ?? false,
                'media'            => $media,
                'link_url'         => $validated['link_url'] ?? null,
                'location_name'    => $validated['location_name'] ?? null,
                'location_id'      => $validated['location_id'] ?? null,
                'status'           => $status,
                'scheduled_at'     => $scheduledAt,
            ]);

            // Sync PostPlatform entries: remove old ones and create new ones
            $post->postPlatforms()->delete();

            foreach ($validAccounts as $account) {
                PostPlatform::create([
                    'post_id'           => $post->id,
                    'social_account_id' => $account->id,
                    'platform_id'       => $account->platform_id,
                    'status'            => 'pending',
                ]);
            }
        });

        // Record hashtag usage
        if (! empty($validated['hashtags'])) {
            $this->recordHashtagsUsage($user->id, $validated['hashtags']);
        }

        return redirect()->route('posts.show', $post->id)
            ->with('success', 'Post updated successfully.');
    }

    /**
     * Delete the specified post and cascade its related records.
     */
    public function destroy(Request $request, int $id): RedirectResponse
    {
        $post = Post::findOrFail($id);

        // Regular users can only delete their own posts
        if (! $request->user()->is_admin && $post->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized.');
        }

        // Prevent deleting posts that are currently publishing
        if ($post->status === 'publishing') {
            return back()->withErrors(['status' => 'Cannot delete a post that is currently being published.']);
        }

        DB::transaction(function () use ($post) {
            // Delete logs for all post platforms
            foreach ($post->postPlatforms as $postPlatform) {
                $postPlatform->logs()->delete();
            }

            // Delete post platforms
            $post->postPlatforms()->delete();

            // Delete the post
            $post->delete();
        });

        return redirect()->route('posts.index')
            ->with('success', 'Post deleted successfully.');
    }

    /**
     * Manually sync stats for a post (all platforms or specific platform).
     */
    public function syncStats(Request $request, int $id, StatsSyncService $syncService): JsonResponse
    {
        $post = Post::with('postPlatforms.platform')->findOrFail($id);

        // Authorization check
        if (! $request->user()->is_admin && $post->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 403);
        }

        // Get platform filter if specified
        $platformSlug = $request->input('platform');

        $postPlatforms = $post->postPlatforms()
            ->with(['platform', 'socialAccount'])
            ->where('status', 'published')
            ->whereNotNull('external_id')
            ->when($platformSlug, fn ($q) => $q->whereHas('platform', fn ($pq) => $pq->where('slug', $platformSlug)))
            ->get();

        if ($postPlatforms->isEmpty()) {
            return response()->json([
                'success' => false,
                'error' => 'No published platforms found for this post.',
            ], 400);
        }

        $synced = 0;
        $failed = 0;

        foreach ($postPlatforms as $postPlatform) {
            try {
                if ($syncService->syncPostPlatform($postPlatform)) {
                    $synced++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        return response()->json([
            'success' => true,
            'synced' => $synced,
            'failed' => $failed,
            'message' => "Synchronized {$synced} platform(s).",
        ]);
    }

    /**
     * Parse and record hashtag usage for the user
     */
    private function recordHashtagsUsage(int $userId, string $hashtagsString): void
    {
        // Parse hashtags string (can be space or comma separated)
        $hashtags = preg_split('/[\s,]+/', $hashtagsString, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($hashtags as $tag) {
            Hashtag::recordUsage($userId, $tag);
        }
    }
}
