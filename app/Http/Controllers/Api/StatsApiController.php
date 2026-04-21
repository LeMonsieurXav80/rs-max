<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExternalPost;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccountSnapshot;
use App\Models\Thread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatsApiController extends Controller
{
    /**
     * GET /api/stats/overview — KPIs globaux.
     * ?period=30&accounts[]=3&accounts[]=5
     */
    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();
        $period = $request->input('period', '30');
        $selectedAccounts = $request->input('accounts', []);

        // Compteurs posts
        $postQuery = Post::where('user_id', $user->id);
        $scheduledCount = (clone $postQuery)->where('status', 'scheduled')->count();
        $publishedCount = (clone $postQuery)->where('status', 'published')->count();
        $failedCount = (clone $postQuery)->where('status', 'failed')->count();
        $draftCount = (clone $postQuery)->where('status', 'draft')->count();

        // Compteurs threads
        $threadQuery = Thread::where('user_id', $user->id);
        $threadsScheduled = (clone $threadQuery)->where('status', 'scheduled')->count();
        $threadsPublished = (clone $threadQuery)->where('status', 'published')->count();

        // Engagement
        $allPosts = $this->getFilteredPosts($user, $selectedAccounts, $period);
        $stats = $this->calculateAggregateStats($allPosts);

        // Followers
        $accounts = $user->activeSocialAccounts()->with('platform')->get();
        $totalFollowers = $accounts
            ->when(! empty($selectedAccounts), fn ($c) => $c->whereIn('id', $selectedAccounts))
            ->sum('followers_count');

        $activeAccountsCount = $accounts->count();

        return response()->json([
            'posts' => [
                'scheduled' => $scheduledCount,
                'published' => $publishedCount,
                'failed' => $failedCount,
                'draft' => $draftCount,
            ],
            'threads' => [
                'scheduled' => $threadsScheduled,
                'published' => $threadsPublished,
            ],
            'engagement' => $stats,
            'followers' => [
                'total' => $totalFollowers,
                'accounts_count' => $activeAccountsCount,
            ],
        ]);
    }

    /**
     * GET /api/stats/audience — Évolution followers.
     * ?period=90&accounts[]=3
     */
    public function audience(Request $request): JsonResponse
    {
        $user = $request->user();
        $accounts = $user->activeSocialAccounts()->with('platform')->get();
        $selectedAccounts = $request->input('accounts', []);
        $period = $request->input('period', '90');

        $accountIds = ! empty($selectedAccounts)
            ? $selectedAccounts
            : $accounts->pluck('id')->toArray();

        $fromDate = $period === 'all' ? null : now()->subDays((int) $period)->toDateString();

        $snapshotsQuery = SocialAccountSnapshot::whereIn('social_account_id', $accountIds)->orderBy('date');
        if ($fromDate) {
            $snapshotsQuery->where('date', '>=', $fromDate);
        }
        $snapshots = $snapshotsQuery->get();

        $chartData = [];
        $deltaPeriods = [1, 7, 14, 28];

        foreach ($accounts->whereIn('id', $accountIds) as $account) {
            $accountSnapshots = $snapshots->where('social_account_id', $account->id)->values();

            $deltas = [];
            foreach ($deltaPeriods as $days) {
                $pastSnapshot = SocialAccountSnapshot::where('social_account_id', $account->id)
                    ->where('date', '<=', now()->subDays($days)->toDateString())
                    ->orderByDesc('date')
                    ->first();
                $deltas["delta_{$days}d"] = $pastSnapshot ? ($account->followers_count - $pastSnapshot->followers_count) : null;
            }

            $chartData[] = [
                'account_id' => $account->id,
                'account_name' => $account->name,
                'platform' => $account->platform->slug,
                'followers_current' => $account->followers_count,
                'deltas' => $deltas,
                'history' => $accountSnapshots->map(fn ($s) => [
                    'date' => $s->date->format('Y-m-d'),
                    'followers' => $s->followers_count,
                ]),
            ];
        }

        return response()->json(['audience' => $chartData]);
    }

    /**
     * GET /api/stats/top-posts — Top posts par engagement.
     * ?period=30&limit=10&accounts[]=3
     */
    public function topPosts(Request $request): JsonResponse
    {
        $user = $request->user();
        $period = $request->input('period', '30');
        $limit = min((int) $request->input('limit', 10), 50);
        $selectedAccounts = $request->input('accounts', []);

        $allPosts = $this->getFilteredPosts($user, $selectedAccounts, $period);
        $topPosts = $this->getTopPosts($allPosts, $limit);

        return response()->json(['top_posts' => $topPosts]);
    }

    /**
     * GET /api/stats/platforms — Stats par plateforme.
     */
    public function platforms(Request $request): JsonResponse
    {
        $user = $request->user();
        $period = $request->input('period', '30');
        $selectedAccounts = $request->input('accounts', []);

        $allPosts = $this->getFilteredPosts($user, $selectedAccounts, $period);

        return response()->json([
            'by_platform' => $this->getStatsByPlatform($allPosts),
            'by_account' => $this->getStatsByAccount($allPosts),
        ]);
    }

    /**
     * GET /api/calendar — Publications planifiées / publiées par jour.
     * ?month=2026-04
     */
    public function calendar(Request $request): JsonResponse
    {
        $user = $request->user();
        $month = $request->input('month', now()->format('Y-m'));

        $startOfMonth = \Carbon\Carbon::parse($month)->startOfMonth();
        $endOfMonth = $startOfMonth->copy()->endOfMonth();

        $posts = Post::where('user_id', $user->id)
            ->with(['postPlatforms.platform', 'postPlatforms.socialAccount'])
            ->where(function ($q) use ($startOfMonth, $endOfMonth) {
                $q->whereBetween('scheduled_at', [$startOfMonth, $endOfMonth])
                    ->orWhereBetween('published_at', [$startOfMonth, $endOfMonth]);
            })
            ->orderByRaw('COALESCE(scheduled_at, published_at) ASC')
            ->get();

        $threads = Thread::where('user_id', $user->id)
            ->with(['segments', 'socialAccounts.platform'])
            ->where(function ($q) use ($startOfMonth, $endOfMonth) {
                $q->whereBetween('scheduled_at', [$startOfMonth, $endOfMonth])
                    ->orWhereBetween('published_at', [$startOfMonth, $endOfMonth]);
            })
            ->orderByRaw('COALESCE(scheduled_at, published_at) ASC')
            ->get();

        $calendar = [];

        foreach ($posts as $post) {
            $date = ($post->scheduled_at ?? $post->published_at)->format('Y-m-d');
            $calendar[$date][] = [
                'type' => 'post',
                'id' => $post->id,
                'content_preview' => $post->content_preview,
                'status' => $post->status,
                'time' => ($post->scheduled_at ?? $post->published_at)->format('H:i'),
                'accounts' => $post->postPlatforms->map(fn ($pp) => [
                    'name' => $pp->socialAccount?->name,
                    'platform' => $pp->platform?->slug,
                ]),
            ];
        }

        foreach ($threads as $thread) {
            $date = ($thread->scheduled_at ?? $thread->published_at)->format('Y-m-d');
            $calendar[$date][] = [
                'type' => 'thread',
                'id' => $thread->id,
                'title' => $thread->title,
                'segments_count' => $thread->segments->count(),
                'status' => $thread->status,
                'time' => ($thread->scheduled_at ?? $thread->published_at)->format('H:i'),
                'accounts' => $thread->socialAccounts->map(fn ($a) => [
                    'name' => $a->name,
                    'platform' => $a->platform->slug,
                ]),
            ];
        }

        ksort($calendar);

        return response()->json([
            'month' => $month,
            'calendar' => $calendar,
        ]);
    }

    // ── Helpers réutilisés du StatsController ──

    private function getFilteredPosts($user, array $selectedAccounts, string $period)
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

        if ($period !== 'all') {
            $days = now()->subDays((int) $period);
            $ppQuery->where('published_at', '>=', $days);
            $epQuery->where('published_at', '>=', $days);
        }

        $postPlatforms = $ppQuery->orderByDesc('published_at')->get();
        $externalPosts = $epQuery->orderByDesc('published_at')->get();

        $ppExternalIds = $postPlatforms->map(fn ($pp) => $pp->platform_id.'_'.$pp->external_id)->filter()->toArray();
        $externalPosts = $externalPosts->reject(fn ($ep) => in_array($ep->platform_id.'_'.$ep->external_id, $ppExternalIds));

        return $postPlatforms->concat($externalPosts);
    }

    private function calculateAggregateStats($posts): array
    {
        $totalViews = $totalLikes = $totalComments = $totalShares = 0;

        foreach ($posts as $post) {
            $metrics = $post->metrics ?? [];
            $totalViews += $metrics['views'] ?? 0;
            $totalLikes += $metrics['likes'] ?? 0;
            $totalComments += $metrics['comments'] ?? 0;
            $totalShares += $metrics['shares'] ?? 0;
        }

        $totalEngagement = $totalLikes + $totalComments + $totalShares;
        $count = $posts->count();

        return [
            'posts_count' => $count,
            'total_views' => $totalViews,
            'total_likes' => $totalLikes,
            'total_comments' => $totalComments,
            'total_shares' => $totalShares,
            'total_engagement' => $totalEngagement,
            'engagement_rate' => $totalViews > 0 ? round(($totalEngagement / $totalViews) * 100, 2) : 0,
            'avg_views_per_post' => $count > 0 ? round($totalViews / $count) : 0,
            'avg_likes_per_post' => $count > 0 ? round($totalLikes / $count) : 0,
        ];
    }

    private function getTopPosts($posts, int $limit): array
    {
        $postStats = [];

        foreach ($posts as $item) {
            $metrics = $item->metrics ?? [];

            if ($item instanceof ExternalPost) {
                $postStats[] = [
                    'type' => 'external',
                    'content_preview' => \Illuminate\Support\Str::limit($item->content, 100),
                    'url' => $item->post_url,
                    'platform' => $item->platform->slug,
                    'account' => $item->socialAccount?->name,
                    'published_at' => $item->published_at?->toIso8601String(),
                    'views' => $metrics['views'] ?? 0,
                    'likes' => $metrics['likes'] ?? 0,
                    'comments' => $metrics['comments'] ?? 0,
                    'shares' => $metrics['shares'] ?? 0,
                    'engagement' => ($metrics['likes'] ?? 0) + ($metrics['comments'] ?? 0) + ($metrics['shares'] ?? 0),
                ];
            } else {
                $postStats[] = [
                    'type' => 'post',
                    'post_id' => $item->post_id,
                    'content_preview' => \Illuminate\Support\Str::limit($item->post?->content_fr, 100),
                    'platform' => $item->platform->slug,
                    'account' => $item->socialAccount?->name,
                    'published_at' => $item->published_at?->toIso8601String(),
                    'views' => $metrics['views'] ?? 0,
                    'likes' => $metrics['likes'] ?? 0,
                    'comments' => $metrics['comments'] ?? 0,
                    'shares' => $metrics['shares'] ?? 0,
                    'engagement' => ($metrics['likes'] ?? 0) + ($metrics['comments'] ?? 0) + ($metrics['shares'] ?? 0),
                ];
            }
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
                    'platform' => $slug,
                    'name' => $item->platform->name,
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
                    'account_id' => $accountId,
                    'account_name' => $item->socialAccount?->name,
                    'platform' => $item->platform->slug,
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

        return array_values($accountStats);
    }
}
