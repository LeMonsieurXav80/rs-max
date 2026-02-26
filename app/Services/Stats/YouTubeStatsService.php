<?php

namespace App\Services\Stats;

use App\Models\PostPlatform;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YouTubeStatsService implements PlatformStatsInterface
{
    private const API_BASE = 'https://www.googleapis.com/youtube/v3';

    public function fetchMetrics(PostPlatform $postPlatform): ?array
    {
        try {
            $videoId = $postPlatform->external_id;
            $accessToken = $postPlatform->socialAccount->credentials['access_token'] ?? null;

            if (! $videoId || ! $accessToken) {
                return null;
            }

            // Fetch video statistics
            $response = Http::withToken($accessToken)->get(self::API_BASE.'/videos', [
                'part' => 'statistics',
                'id' => $videoId,
            ]);

            if (! $response->successful()) {
                Log::error('YouTubeStatsService: Failed to fetch video metrics', [
                    'post_platform_id' => $postPlatform->id,
                    'external_id' => $videoId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $data = $response->json();
            $video = $data['items'][0] ?? null;

            if (! $video) {
                return null;
            }

            $stats = $video['statistics'] ?? [];

            // Fetch channel subscriber count
            $channelId = $postPlatform->socialAccount->platform_account_id;
            $subscriberCount = null;

            if ($channelId) {
                $channelResponse = Http::withToken($accessToken)->get(self::API_BASE.'/channels', [
                    'part' => 'statistics',
                    'id' => $channelId,
                ]);

                if ($channelResponse->successful()) {
                    $channelData = $channelResponse->json();
                    $channel = $channelData['items'][0] ?? null;
                    $subscriberCount = $channel['statistics']['subscriberCount'] ?? null;
                }
            }

            return [
                'views' => (int) ($stats['viewCount'] ?? 0),
                'likes' => (int) ($stats['likeCount'] ?? 0),
                'comments' => (int) ($stats['commentCount'] ?? 0),
                'shares' => null, // YouTube doesn't provide share count via API
                'followers' => $subscriberCount ? (int) $subscriberCount : null,
            ];
        } catch (\Throwable $e) {
            Log::error('YouTubeStatsService: Exception', [
                'post_platform_id' => $postPlatform->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
