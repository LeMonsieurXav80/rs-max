<?php

namespace App\Http\Controllers;

use App\Models\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
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
            ->whereBetween('scheduled_at', [$startOfMonth, $endOfMonth])
            ->orderBy('scheduled_at')
            ->get()
            ->groupBy(fn (Post $p) => $p->scheduled_at->format('Y-m-d'));

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
    public function store(Request $request): RedirectResponse
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
            'telegram_channel'  => 'nullable|string|max:255',
            'status'            => 'required|in:draft,scheduled',
            'scheduled_at'      => 'required_if:status,scheduled|nullable|date|after_or_equal:now',
            'accounts'          => 'required|array|min:1',
            'accounts.*'        => 'integer|exists:social_accounts,id',
            'publish_now'       => 'nullable|boolean',
        ]);

        $user = $request->user();

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

        DB::transaction(function () use ($validated, $user, $validAccounts) {
            // If "publish now" is selected, override status and scheduled_at
            $status = $validated['status'];
            $scheduledAt = $validated['scheduled_at'] ?? null;

            if (! empty($validated['publish_now'])) {
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

            // Create the post
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
                'telegram_channel' => $validated['telegram_channel'] ?? null,
                'status'           => $status,
                'scheduled_at'     => $scheduledAt,
            ]);

            // Create PostPlatform entries for each selected account
            foreach ($validAccounts as $account) {
                PostPlatform::create([
                    'post_id'           => $post->id,
                    'social_account_id' => $account->id,
                    'platform_id'       => $account->platform_id,
                    'status'            => 'pending',
                ]);
            }
        });

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
            'telegram_channel'  => 'nullable|string|max:255',
            'status'            => 'required|in:draft,scheduled',
            'scheduled_at'      => 'required_if:status,scheduled|nullable|date|after_or_equal:now',
            'accounts'          => 'required|array|min:1',
            'accounts.*'        => 'integer|exists:social_accounts,id',
            'publish_now'       => 'nullable|boolean',
        ]);

        $user = $request->user();

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

        DB::transaction(function () use ($post, $validated, $validAccounts) {
            // If "publish now" is selected, override status and scheduled_at
            $status = $validated['status'];
            $scheduledAt = $validated['scheduled_at'] ?? null;

            if (! empty($validated['publish_now'])) {
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
                'telegram_channel' => $validated['telegram_channel'] ?? null,
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
}
