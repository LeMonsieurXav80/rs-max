<?php

namespace App\Services\Import;

use App\Models\ExternalPost;
use App\Models\Platform;
use App\Models\SocialAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookImportService implements PlatformImportInterface
{
    private const API_VERSION = 'v21.0';

    private const API_BASE = 'https://graph.facebook.com/'.self::API_VERSION;

    /**
     * Import historical posts from a Facebook page.
     */
    public function importHistory(SocialAccount $account, int $limit = 50): Collection
    {
        $credentials = $account->credentials;
        $accessToken = $credentials['access_token'] ?? null;
        $pageId = $account->platform_account_id;

        if (! $accessToken) {
            throw new \Exception('No access token found for Facebook account');
        }

        $posts = $this->fetchPosts($pageId, $accessToken, $limit);
        $videoViews = $this->fetchVideoViews($pageId, $accessToken);

        return $this->storeExternalPosts($account, $posts, $videoViews);
    }

    /**
     * Fetch posts from Facebook page with engagement counts.
     * Falls back to basic fields if pages_read_engagement is not available.
     */
    private function fetchPosts(string $pageId, string $accessToken, int $limit): Collection
    {
        // Try with engagement fields first
        $posts = $this->fetchPostsWithFields(
            $pageId,
            $accessToken,
            'id,message,full_picture,permalink_url,created_time,likes.summary(true),comments.summary(true),shares',
            $limit
        );

        if ($posts !== null) {
            return $posts;
        }

        // Fallback: fetch without engagement fields
        Log::warning('Facebook: pages_read_engagement unavailable, importing without engagement data');

        return $this->fetchPostsWithFields(
            $pageId,
            $accessToken,
            'id,message,full_picture,permalink_url,created_time',
            $limit
        ) ?? collect();
    }

    private function fetchPostsWithFields(string $pageId, string $accessToken, string $fields, int $limit): ?Collection
    {
        $posts = collect();

        try {
            $url = self::API_BASE."/{$pageId}/posts";
            $params = [
                'fields' => $fields,
                'limit' => min(100, $limit),
                'access_token' => $accessToken,
            ];

            do {
                $response = Http::get($url, $params);

                if (! $response->successful()) {
                    Log::error('Facebook API error fetching posts', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return $posts->isEmpty() ? null : $posts;
                }

                $data = $response->json();

                foreach ($data['data'] ?? [] as $post) {
                    $posts->push($post);

                    if ($posts->count() >= $limit) {
                        break 2;
                    }
                }

                $url = $data['paging']['next'] ?? null;
                $params = [];

            } while ($url && $posts->count() < $limit);

            return $posts;
        } catch (\Exception $e) {
            Log::error('Exception fetching Facebook posts', ['error' => $e->getMessage()]);

            return $posts->isEmpty() ? null : $posts;
        }
    }

    /**
     * Fetch video views for a page. Returns [videoId => views] map.
     */
    private function fetchVideoViews(string $pageId, string $accessToken): array
    {
        $videoViews = [];

        try {
            $url = self::API_BASE."/{$pageId}/videos";
            $params = [
                'fields' => 'id,views',
                'limit' => 100,
                'access_token' => $accessToken,
            ];

            do {
                $response = Http::get($url, $params);

                if (! $response->successful()) {
                    break;
                }

                $data = $response->json();

                foreach ($data['data'] ?? [] as $video) {
                    $videoViews[$video['id']] = (int) ($video['views'] ?? 0);
                }

                $url = $data['paging']['next'] ?? null;
                $params = [];
            } while ($url);
        } catch (\Exception $e) {
            Log::warning('Facebook: failed to fetch video views', ['error' => $e->getMessage()]);
        }

        return $videoViews;
    }

    /**
     * Extract video ID from a Facebook permalink URL.
     * E.g. https://www.facebook.com/reel/1966502867317951/ -> 1966502867317951
     */
    private function extractVideoIdFromPermalink(?string $permalink): ?string
    {
        if (! $permalink) {
            return null;
        }

        // Match /reel/{id} or /videos/{id}
        if (preg_match('#/(?:reel|videos)/(\d+)#', $permalink, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Store posts as ExternalPost records with deduplication.
     */
    private function storeExternalPosts(SocialAccount $account, Collection $posts, array $videoViews = []): Collection
    {
        $platform = Platform::where('slug', 'facebook')->first();
        $imported = collect();

        foreach ($posts as $post) {
            $postId = $post['id'];

            $exists = ExternalPost::where('platform_id', $platform->id)
                ->where('external_id', $postId)
                ->exists();

            if ($exists) {
                continue;
            }

            // Try to get views from video data
            $views = 0;
            $videoId = $this->extractVideoIdFromPermalink($post['permalink_url'] ?? null);
            if ($videoId && isset($videoViews[$videoId])) {
                $views = $videoViews[$videoId];
            }

            $metrics = [
                'views' => $views,
                'likes' => (int) ($post['likes']['summary']['total_count'] ?? 0),
                'comments' => (int) ($post['comments']['summary']['total_count'] ?? 0),
                'shares' => (int) ($post['shares']['count'] ?? 0),
            ];

            $externalPost = ExternalPost::create([
                'social_account_id' => $account->id,
                'platform_id' => $platform->id,
                'external_id' => $postId,
                'content' => $post['message'] ?? null,
                'media_url' => $post['full_picture'] ?? null,
                'post_url' => $post['permalink_url'] ?? null,
                'published_at' => $post['created_time'] ?? null,
                'metrics' => $metrics,
                'metrics_synced_at' => now(),
            ]);

            $imported->push($externalPost);
        }

        if ($imported->isNotEmpty()) {
            $account->update(['last_history_import_at' => now()]);
        }

        return $imported;
    }

    /**
     * Facebook has generous rate limits for page posts.
     */
    public function getQuotaCost(int $postCount): array
    {
        return [
            'cost' => 0,
            'description' => "Import de {$postCount} posts Facebook (pas de quota strict)",
        ];
    }
}
