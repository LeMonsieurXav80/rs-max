<?php

namespace App\Http\Controllers;

use App\Models\Post;
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
        $userId = $user->id;

        // Build base queries - admin sees all, regular user sees own data
        $postQuery = Post::query();

        if (! $isAdmin) {
            $postQuery->where('user_id', $userId);
        }

        $activeAccountsCount = $user->activeSocialAccounts()->count();

        // Stats
        $scheduledCount = (clone $postQuery)->where('status', 'scheduled')->count();
        $publishedCount = (clone $postQuery)->where('status', 'published')->count();
        $failedCount = (clone $postQuery)->where('status', 'failed')->count();
        $draftCount = (clone $postQuery)->where('status', 'draft')->count();

        // Only load postPlatforms whose social account is active for THIS USER
        $activePostPlatforms = fn ($q) => $q->whereHas('socialAccount', function ($sq) use ($userId) {
            $sq->whereHas('users', fn ($uq) => $uq->where('social_account_user.user_id', $userId)->where('social_account_user.is_active', true));
        });

        // Only show posts with at least one active account for this user
        $hasActiveAccount = fn ($q) => $q->whereHas('postPlatforms', function ($ppq) use ($userId) {
            $ppq->whereHas('socialAccount', function ($sq) use ($userId) {
                $sq->whereHas('users', fn ($uq) => $uq->where('social_account_user.user_id', $userId)->where('social_account_user.is_active', true));
            });
        });

        // Next 5 scheduled posts (upcoming)
        $upcomingQuery = (clone $postQuery)
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '>', now())
            ->with(['postPlatforms' => $activePostPlatforms, 'postPlatforms.platform']);
        $hasActiveAccount($upcomingQuery);
        $upcomingPosts = $upcomingQuery->orderBy('scheduled_at', 'asc')->limit(5)->get();

        // Last 5 published posts (recent)
        $recentQuery = (clone $postQuery)
            ->where('status', 'published')
            ->with(['postPlatforms' => $activePostPlatforms, 'postPlatforms.platform']);
        $hasActiveAccount($recentQuery);
        $recentPosts = $recentQuery->orderBy('published_at', 'desc')->limit(5)->get();

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
