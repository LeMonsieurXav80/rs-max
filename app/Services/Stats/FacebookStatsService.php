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

            // Fetch post insights
            $response = Http::get(self::GRAPH_API_BASE.'/'.self::GRAPH_API_VERSION."/{$externalId}", [
                'fields' => 'likes.summary(true),comments.summary(true),shares,reactions.summary(true)',
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

            // Fetch page followers count (page info)
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
                'views' => null, // Facebook doesn't provide view count for regular posts
                'likes' => $data['likes']['summary']['total_count'] ?? 0,
                'comments' => $data['comments']['summary']['total_count'] ?? 0,
                'shares' => $data['shares']['count'] ?? 0,
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
