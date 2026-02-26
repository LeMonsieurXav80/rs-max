<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Display the dashboard with stats, upcoming scheduled posts,
     * and recently published posts.
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        $isAdmin = $user->is_admin;

        // Build base queries - admin sees all, regular user sees own data
        $postQuery = Post::query();

        if (! $isAdmin) {
            $postQuery->where('user_id', $user->id);
        }

        $activeAccountsCount = $isAdmin
            ? SocialAccount::where('is_active', true)->count()
            : $user->activeSocialAccounts()->count();

        // Stats
        $scheduledCount = (clone $postQuery)->where('status', 'scheduled')->count();
        $publishedCount = (clone $postQuery)->where('status', 'published')->count();
        $failedCount = (clone $postQuery)->where('status', 'failed')->count();
        $draftCount = (clone $postQuery)->where('status', 'draft')->count();
        // $activeAccountsCount already computed above

        // Only load postPlatforms whose social account is active
        $activePostPlatforms = fn ($q) => $q->whereHas('socialAccount', fn ($sq) => $sq->where('is_active', true));

        // Next 5 scheduled posts (upcoming)
        $upcomingPosts = (clone $postQuery)
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '>', now())
            ->with(['postPlatforms' => $activePostPlatforms, 'postPlatforms.platform'])
            ->orderBy('scheduled_at', 'asc')
            ->limit(5)
            ->get();

        // Last 5 published posts (recent)
        $recentPosts = (clone $postQuery)
            ->where('status', 'published')
            ->with(['postPlatforms' => $activePostPlatforms, 'postPlatforms.platform'])
            ->orderBy('published_at', 'desc')
            ->limit(5)
            ->get();

        return view('dashboard', compact(
            'scheduledCount',
            'publishedCount',
            'failedCount',
            'draftCount',
            'activeAccountsCount',
            'upcomingPosts',
            'recentPosts',
            'isAdmin',
        ));
    }
}
