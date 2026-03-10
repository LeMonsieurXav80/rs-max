<?php

namespace App\Http\Controllers;

use App\Models\ExternalPost;
use App\Models\PostPlatform;
use App\Models\SocialAccountSnapshot;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StatsController extends Controller
{
    /**
     * Vue d'ensemble — KPIs + filtres.
     */
    public function overview(Request $request): View
    {
        $user = $request->user();
        $socialAccounts = $user->activeSocialAccounts()->with('platform')->orderBy('name')->get();

        [$selectedAccounts, $period, $startDate, $endDate] = $this->getFilters($request);
        $allPosts = $this->getFilteredPosts($user, $selectedAccounts, $period, $startDate, $endDate);

        $stats = $this->calculateAggregateStats($allPosts);
        $statsByPlatform = $this->getStatsByPlatform($allPosts);
        $statsByAccount = $this->getStatsByAccount($allPosts);

        return view('stats.overview', compact(
            'socialAccounts',
            'selectedAccounts',
            'period',
            'startDate',
            'endDate',
            'stats',
            'statsByPlatform',
            'statsByAccount',
        ));
    }

    /**
     * Audience — évolution followers par compte.
     */
    public function audience(Request $request): View
    {
        $user = $request->user();
        $socialAccounts = $user->activeSocialAccounts()->with('platform')->orderBy('name')->get();

        $selectedAccounts = $request->input('accounts', []);
        if (empty($selectedAccounts)) {
            $selectedAccounts = $user->default_accounts ?? [];
        }
        $period = $request->input('period', '90');
        $accountIds = ! empty($selectedAccounts)
            ? $selectedAccounts
            : $socialAccounts->pluck('id')->toArray();

        // Determine date range for snapshots
        if ($period === 'all') {
            $fromDate = null;
        } else {
            $fromDate = now()->subDays((int) $period)->toDateString();
        }

        // Fetch snapshots
        $snapshotsQuery = SocialAccountSnapshot::whereIn('social_account_id', $accountIds)
            ->orderBy('date');

        if ($fromDate) {
            $snapshotsQuery->where('date', '>=', $fromDate);
        }

        $snapshots = $snapshotsQuery->get();

        // Prepare chart data: group by account
        $chartData = [];
        foreach ($socialAccounts as $account) {
            if (! empty($selectedAccounts) && ! in_array($account->id, $selectedAccounts)) {
                continue;
            }

            $accountSnapshots = $snapshots->where('social_account_id', $account->id)->values();
            $chartData[] = [
                'account' => $account,
                'labels' => $accountSnapshots->pluck('date')->map(fn ($d) => $d->format('Y-m-d'))->toArray(),
                'data' => $accountSnapshots->pluck('followers_count')->toArray(),
            ];
        }

        $totalFollowers = $socialAccounts->sum('followers_count');

        // Compute follower deltas for each account (vs 1d, 7d, 14d, 28d ago)
        $deltas = [];
        $deltaPeriods = [1, 7, 14, 28];
        foreach ($socialAccounts as $account) {
            if (! empty($selectedAccounts) && ! in_array($account->id, $selectedAccounts)) {
                continue;
            }
            $accountDeltas = [];
            foreach ($deltaPeriods as $days) {
                $pastSnapshot = SocialAccountSnapshot::where('social_account_id', $account->id)
                    ->where('date', '<=', now()->subDays($days)->toDateString())
                    ->orderByDesc('date')
                    ->first();
                $accountDeltas[$days] = $pastSnapshot ? ($account->followers_count - $pastSnapshot->followers_count) : null;
            }
            $deltas[$account->id] = $accountDeltas;
        }

        // Compute total deltas across all selected accounts
        $totalDeltas = [];
        foreach ($deltaPeriods as $days) {
            $values = collect($deltas)->pluck($days)->filter(fn ($v) => $v !== null);
            $totalDeltas[$days] = $values->isNotEmpty() ? $values->sum() : null;
        }

        return view('stats.audience', compact(
            'socialAccounts',
            'selectedAccounts',
            'period',
            'chartData',
            'totalFollowers',
            'deltas',
            'totalDeltas',
        ));
    }

    /**
     * Publications — top posts + tendances engagement.
     */
    public function publications(Request $request): View
    {
        $user = $request->user();
        $socialAccounts = $user->activeSocialAccounts()->with('platform')->orderBy('name')->get();

        [$selectedAccounts, $period, $startDate, $endDate] = $this->getFilters($request);
        $allPosts = $this->getFilteredPosts($user, $selectedAccounts, $period, $startDate, $endDate);

        $stats = $this->calculateAggregateStats($allPosts);
        $topPosts = $this->getTopPosts($allPosts, 10);
        $timeline = $this->getTimelineData($allPosts);

        return view('stats.publications', compact(
            'socialAccounts',
            'selectedAccounts',
            'period',
            'startDate',
            'endDate',
            'stats',
            'topPosts',
            'timeline',
        ));
    }

    /**
     * Plateformes — comparaison cross-platform.
     */
    public function platforms(Request $request): View
    {
        $user = $request->user();
        $socialAccounts = $user->activeSocialAccounts()->with('platform')->orderBy('name')->get();

        [$selectedAccounts, $period, $startDate, $endDate] = $this->getFilters($request);
        $allPosts = $this->getFilteredPosts($user, $selectedAccounts, $period, $startDate, $endDate);

        $statsByPlatform = $this->getStatsByPlatform($allPosts);
        $statsByAccount = $this->getStatsByAccount($allPosts);

        return view('stats.platforms', compact(
            'socialAccounts',
            'selectedAccounts',
            'period',
            'startDate',
            'endDate',
            'statsByPlatform',
            'statsByAccount',
        ));
    }

    // ── Shared helpers ───────────────────────────────────────

    private function getFilters(Request $request): array
    {
        $accounts = $request->input('accounts', []);
        if (empty($accounts)) {
            $accounts = $request->user()->default_accounts ?? [];
        }

        return [
            $accounts,
            $request->input('period', '30'),
            $request->input('start_date'),
            $request->input('end_date'),
        ];
    }

    private function getFilteredPosts($user, array $selectedAccounts, string $period, ?string $startDate, ?string $endDate)
    {
        $ppQuery = PostPlatform::with(['post', 'platform', 'socialAccount'])
            ->where('status', 'published')
            ->whereNotNull('external_id')
            ->whereNotNull('metrics');

        $epQuery = ExternalPost::with(['platform', 'socialAccount'])
            ->whereNotNull('metrics');

        if (! empty($selectedAccounts)) {
            $ppQuery->whereIn('social_account_id', $selectedAccounts);
            $epQuery->whereIn('social_account_id', $selectedAccounts);
        } else {
            $accountIds = $user->activeSocialAccounts()->pluck('social_accounts.id');
            $ppQuery->whereIn('social_account_id', $accountIds);
            $epQuery->whereIn('social_account_id', $accountIds);
        }

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

        $ppExternalIds = $postPlatforms->map(fn ($pp) => $pp->platform_id . '_' . $pp->external_id)->filter()->toArray();
        $externalPosts = $externalPosts->reject(fn ($ep) => in_array($ep->platform_id . '_' . $ep->external_id, $ppExternalIds));

        return $postPlatforms->concat($externalPosts);
    }

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

    private function getTopPosts($posts, int $limit = 10): array
    {
        $postStats = [];

        foreach ($posts as $item) {
            $metrics = $item->metrics ?? [];

            if ($item instanceof ExternalPost) {
                $key = 'ext_' . $item->id;
                $postStats[$key] = [
                    'content' => $item->content,
                    'url' => $item->post_url,
                    'post' => null,
                    'is_external' => true,
                    'thumbnail' => $item->media_url,
                    'views' => $metrics['views'] ?? 0,
                    'likes' => $metrics['likes'] ?? 0,
                    'comments' => $metrics['comments'] ?? 0,
                    'shares' => $metrics['shares'] ?? 0,
                    'engagement' => 0,
                ];
            } else {
                $postId = $item->post_id;
                if (! isset($postStats[$postId])) {
                    $thumbnail = null;
                    $isVideo = false;
                    $media = $item->post->media ?? [];
                    if (! empty($media) && isset($media[0]['url'])) {
                        $filename = basename($media[0]['url']);
                        $mimetype = $media[0]['mimetype'] ?? '';
                        $isVideo = str_starts_with($mimetype, 'video/');
                        $thumbnail = $isVideo
                            ? "/media/thumbnail/{$filename}"
                            : $media[0]['url'];
                    }

                    $postStats[$postId] = [
                        'content' => $item->post->content_fr ?? $item->post->content_en ?? '',
                        'url' => null,
                        'post' => $item->post,
                        'is_external' => false,
                        'thumbnail' => $thumbnail,
                        'is_video' => $isVideo,
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

        foreach ($postStats as &$stat) {
            $stat['engagement'] = $stat['likes'] + $stat['comments'] + $stat['shares'];
        }

        usort($postStats, fn ($a, $b) => $b['engagement'] <=> $a['engagement']);

        return array_slice($postStats, 0, $limit);
    }

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

        usort($accountStats, function ($a, $b) {
            $engagementA = $a['likes'] + $a['comments'] + $a['shares'];
            $engagementB = $b['likes'] + $b['comments'] + $b['shares'];

            return $engagementB <=> $engagementA;
        });

        return $accountStats;
    }

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
