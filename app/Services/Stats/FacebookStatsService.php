<?php

namespace App\Services\Stats;

use App\Models\PostPlatform;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookStatsService implements PlatformStatsInterface
{
    private const GRAPH_API_VERSION = 'v21.0';
    private const GRAPH_API_BASE = 'https://graph.facebook.com';

    public function fetchMetrics(PostPlatform $postPlatform): ?array
    {
        try {
            $externalId = $postPlatform->external_id;
            $accessToken = $postPlatform->socialAccount->credentials['access_token'] ?? null;

            if (! $externalId || ! $accessToken) {
                return null;
            }

            // Fetch post metrics (likes + comments only; shares/reactions require
            // additional permissions that may not be available for all post types)
            $response = Http::get(self::GRAPH_API_BASE.'/'.self::GRAPH_API_VERSION."/{$externalId}", [
                'fields' => 'likes.summary(true),comments.summary(true)',
                'access_token' => $accessToken,
            ]);

            if (! $response->successful()) {
                Log::error('FacebookStatsService: Failed to fetch metrics', [
                    'post_platform_id' => $postPlatform->id,
                    'external_id' => $externalId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $data = $response->json();

            // Try to fetch post insights (views, shares) - may fail depending on post type/permissions
            $views = null;
            $shares = null;
            $insightsResponse = Http::get(self::GRAPH_API_BASE.'/'.self::GRAPH_API_VERSION."/{$externalId}/insights", [
                'metric' => 'post_impressions,post_engaged_users',
                'access_token' => $accessToken,
            ]);

            if ($insightsResponse->successful()) {
                $insights = $insightsResponse->json('data', []);
                foreach ($insights as $insight) {
                    if ($insight['name'] === 'post_impressions') {
                        $views = $insight['values'][0]['value'] ?? null;
                    }
                }
            }

            // Fallback for videos: post insights don't work on video objects,
            // but the 'views' field is available directly on the video object.
            if ($views === null) {
                $videoResponse = Http::get(self::GRAPH_API_BASE.'/'.self::GRAPH_API_VERSION."/{$externalId}", [
                    'fields' => 'views',
                    'access_token' => $accessToken,
                ]);

                if ($videoResponse->successful()) {
                    $views = $videoResponse->json('views');
                }
            }

            // Fetch page followers count
            $pageId = $postPlatform->socialAccount->platform_account_id;
            $followersCount = null;

            if ($pageId) {
                $pageResponse = Http::get(self::GRAPH_API_BASE.'/'.self::GRAPH_API_VERSION."/{$pageId}", [
                    'fields' => 'followers_count',
                    'access_token' => $accessToken,
                ]);

                if ($pageResponse->successful()) {
                    $followersCount = $pageResponse->json('followers_count');
                }
            }

            return [
                'views' => $views,
                'likes' => $data['likes']['summary']['total_count'] ?? 0,
                'comments' => $data['comments']['summary']['total_count'] ?? 0,
                'shares' => $shares,
                'followers' => $followersCount,
            ];
        } catch (\Throwable $e) {
            Log::error('FacebookStatsService: Exception', [
                'post_platform_id' => $postPlatform->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
