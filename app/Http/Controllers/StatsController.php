<?php

namespace App\Http\Controllers;

use App\Models\ExternalPost;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StatsController extends Controller
{
    /**
     * Display the statistics dashboard with filters.
     */
    public function dashboard(Request $request): View
    {
        $user = $request->user();

        // Get user's active social accounts for filter
        if ($user->is_admin) {
            $socialAccounts = SocialAccount::with('platform')
                ->where('is_active', true)
                ->orderBy('name')
                ->get();
        } else {
            $socialAccounts = $user->activeSocialAccounts()->with('platform')->orderBy('name')->get();
        }

        // Get filters
        $selectedAccounts = $request->input('accounts', []);
        $period = $request->input('period', '30');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        // Build PostPlatform query (posts published via the app)
        $ppQuery = PostPlatform::with(['post', 'platform', 'socialAccount'])
            ->where('status', 'published')
            ->whereNotNull('external_id')
            ->whereNotNull('metrics');

        // Build ExternalPost query (imported historical posts)
        $epQuery = ExternalPost::with(['platform', 'socialAccount'])
            ->whereNotNull('metrics');

        // Filter by accounts (only show data for active accounts)
        if (! empty($selectedAccounts)) {
            $ppQuery->whereIn('social_account_id', $selectedAccounts);
            $epQuery->whereIn('social_account_id', $selectedAccounts);
        } else {
            $accountIds = $user->is_admin
                ? SocialAccount::where('is_active', true)->pluck('id')
                : $user->activeSocialAccounts()->pluck('social_accounts.id');
            $ppQuery->whereIn('social_account_id', $accountIds);
            $epQuery->whereIn('social_account_id', $accountIds);
        }

        // Filter by date range
        if ($startDate && $endDate) {
            $ppQuery->whereBetween('published_at', [$startDate, $endDate]);
            $epQuery->whereBetween('published_at', [$startDate, $endDate]);
        } elseif ($period !== 'all') {
            $days = now()->subDays((int) $period);
            $ppQuery->where('published_at', '>=', $days);
            $epQuery->where('published_at', '>=', $days);
        }

        $postPlatforms = $ppQuery->orderBy('published_at', 'desc')->get();
        $externalPosts = $epQuery->orderBy('published_at', 'desc')->get();

        // Deduplicate: remove external posts that already exist as PostPlatform
        // (same platform + same external_id)
        $ppExternalIds = $postPlatforms->map(fn ($pp) => $pp->platform_id.'_'.$pp->external_id)->filter()->toArray();
        $externalPosts = $externalPosts->reject(fn ($ep) => in_array($ep->platform_id.'_'.$ep->external_id, $ppExternalIds));

        // Merge into unified collection
        $allPosts = $postPlatforms->concat($externalPosts);

        // Calculate aggregate stats
        $stats = $this->calculateAggregateStats($allPosts);

        // Get top posts (by engagement)
        $topPosts = $this->getTopPosts($allPosts, 10);

        // Get stats by platform
        $statsByPlatform = $this->getStatsByPlatform($allPosts);

        // Get stats by account
        $statsByAccount = $this->getStatsByAccount($allPosts);

        // Get timeline data for chart (daily aggregates)
        $timeline = $this->getTimelineData($allPosts);

        return view('stats.dashboard', compact(
            'socialAccounts',
            'selectedAccounts',
            'period',
            'startDate',
            'endDate',
            'stats',
            'topPosts',
            'statsByPlatform',
            'statsByAccount',
            'timeline',
            'postPlatforms'
        ));
    }

    /**
     * Calculate aggregate statistics.
     */
    private function calculateAggregateStats($posts): array
    {
        $totalViews = 0;
        $totalLikes = 0;
        $totalComments = 0;
        $totalShares = 0;
        $postsCount = $posts->count();

        foreach ($posts as $post) {
            $metrics = $post->metrics ?? [];
            $totalViews += $metrics['views'] ?? 0;
            $totalLikes += $metrics['likes'] ?? 0;
            $totalComments += $metrics['comments'] ?? 0;
            $totalShares += $metrics['shares'] ?? 0;
        }

        $totalEngagement = $totalLikes + $totalComments + $totalShares;
        $engagementRate = $totalViews > 0 ? ($totalEngagement / $totalViews) * 100 : 0;

        return [
            'posts_count' => $postsCount,
            'total_views' => $totalViews,
            'total_likes' => $totalLikes,
            'total_comments' => $totalComments,
            'total_shares' => $totalShares,
            'total_engagement' => $totalEngagement,
            'engagement_rate' => round($engagementRate, 2),
            'avg_views_per_post' => $postsCount > 0 ? round($totalViews / $postsCount) : 0,
            'avg_likes_per_post' => $postsCount > 0 ? round($totalLikes / $postsCount) : 0,
        ];
    }

    /**
     * Get top posts by engagement.
     */
    private function getTopPosts($posts, int $limit = 10): array
    {
        $postStats = [];

        foreach ($posts as $item) {
            $metrics = $item->metrics ?? [];

            if ($item instanceof ExternalPost) {
                // External posts: each is unique, use 'ext_{id}' as key
                $key = 'ext_'.$item->id;
                $postStats[$key] = [
                    'content' => $item->content,
                    'url' => $item->post_url,
                    'post' => null,
                    'is_external' => true,
                    'views' => $metrics['views'] ?? 0,
                    'likes' => $metrics['likes'] ?? 0,
                    'comments' => $metrics['comments'] ?? 0,
                    'shares' => $metrics['shares'] ?? 0,
                    'engagement' => 0,
                ];
            } else {
                // PostPlatform: group by post_id
                $postId = $item->post_id;
                if (! isset($postStats[$postId])) {
                    $postStats[$postId] = [
                        'content' => $item->post->content_fr ?? $item->post->content_en ?? '',
                        'url' => null,
                        'post' => $item->post,
                        'is_external' => false,
                        'views' => 0,
                        'likes' => 0,
                        'comments' => 0,
                        'shares' => 0,
                        'engagement' => 0,
                    ];
                }

                $postStats[$postId]['views'] += $metrics['views'] ?? 0;
                $postStats[$postId]['likes'] += $metrics['likes'] ?? 0;
                $postStats[$postId]['comments'] += $metrics['comments'] ?? 0;
                $postStats[$postId]['shares'] += $metrics['shares'] ?? 0;
            }
        }

        // Calculate engagement for each post
        foreach ($postStats as &$stat) {
            $stat['engagement'] = $stat['likes'] + $stat['comments'] + $stat['shares'];
        }

        // Sort by engagement
        usort($postStats, fn ($a, $b) => $b['engagement'] <=> $a['engagement']);

        return array_slice($postStats, 0, $limit);
    }

    /**
     * Get stats grouped by platform.
     */
    private function getStatsByPlatform($posts): array
    {
        $platformStats = [];

        foreach ($posts as $item) {
            $slug = $item->platform->slug;
            $metrics = $item->metrics ?? [];

            if (! isset($platformStats[$slug])) {
                $platformStats[$slug] = [
                    'name' => $item->platform->name,
                    'slug' => $slug,
                    'count' => 0,
                    'views' => 0,
                    'likes' => 0,
                    'comments' => 0,
                    'shares' => 0,
                ];
            }

            $platformStats[$slug]['count']++;
            $platformStats[$slug]['views'] += $metrics['views'] ?? 0;
            $platformStats[$slug]['likes'] += $metrics['likes'] ?? 0;
            $platformStats[$slug]['comments'] += $metrics['comments'] ?? 0;
            $platformStats[$slug]['shares'] += $metrics['shares'] ?? 0;
        }

        return array_values($platformStats);
    }

    /**
     * Get stats grouped by account.
     */
    private function getStatsByAccount($posts): array
    {
        $accountStats = [];

        foreach ($posts as $item) {
            $accountId = $item->social_account_id;
            $metrics = $item->metrics ?? [];

            if (! isset($accountStats[$accountId])) {
                $accountStats[$accountId] = [
                    'account' => $item->socialAccount,
                    'platform' => $item->platform,
                    'count' => 0,
                    'views' => 0,
                    'likes' => 0,
                    'comments' => 0,
                    'shares' => 0,
                ];
            }

            $accountStats[$accountId]['count']++;
            $accountStats[$accountId]['views'] += $metrics['views'] ?? 0;
            $accountStats[$accountId]['likes'] += $metrics['likes'] ?? 0;
            $accountStats[$accountId]['comments'] += $metrics['comments'] ?? 0;
            $accountStats[$accountId]['shares'] += $metrics['shares'] ?? 0;
        }

        // Sort by total engagement
        usort($accountStats, function ($a, $b) {
            $engagementA = $a['likes'] + $a['comments'] + $a['shares'];
            $engagementB = $b['likes'] + $b['comments'] + $b['shares'];

            return $engagementB <=> $engagementA;
        });

        return $accountStats;
    }

    /**
     * Get timeline data for charts (daily aggregates).
     */
    private function getTimelineData($posts): array
    {
        $timeline = [];

        foreach ($posts as $item) {
            if (! $item->published_at) {
                continue;
            }

            $date = $item->published_at->format('Y-m-d');
            $metrics = $item->metrics ?? [];

            if (! isset($timeline[$date])) {
                $timeline[$date] = [
                    'date' => $date,
                    'posts' => 0,
                    'views' => 0,
                    'likes' => 0,
                    'comments' => 0,
                    'shares' => 0,
                ];
            }

            $timeline[$date]['posts']++;
            $timeline[$date]['views'] += $metrics['views'] ?? 0;
            $timeline[$date]['likes'] += $metrics['likes'] ?? 0;
            $timeline[$date]['comments'] += $metrics['comments'] ?? 0;
            $timeline[$date]['shares'] += $metrics['shares'] ?? 0;
        }

        ksort($timeline);

        return array_values($timeline);
    }
}
