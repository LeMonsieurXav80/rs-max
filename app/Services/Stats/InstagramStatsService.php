<?php

namespace App\Services\Stats;

use App\Models\PostPlatform;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InstagramStatsService implements PlatformStatsInterface
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

            // Fetch media basic info (like_count, comments_count)
            $response = Http::get(self::GRAPH_API_BASE.'/'.self::GRAPH_API_VERSION."/{$externalId}", [
                'fields' => 'like_count,comments_count,media_type',
                'access_token' => $accessToken,
            ]);

            if (! $response->successful()) {
                Log::error('InstagramStatsService: Failed to fetch metrics', [
                    'post_platform_id' => $postPlatform->id,
                    'external_id' => $externalId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $data = $response->json();

            // Try to fetch insights (reach, impressions) - may require instagram_manage_insights permission
            $views = null;
            $insightsResponse = Http::get(self::GRAPH_API_BASE.'/'.self::GRAPH_API_VERSION."/{$externalId}/insights", [
                'metric' => 'reach,impressions',
                'access_token' => $accessToken,
            ]);

            if ($insightsResponse->successful()) {
                $insights = $insightsResponse->json('data', []);
                foreach ($insights as $insight) {
                    if ($insight['name'] === 'reach') {
                        $views = $insight['values'][0]['value'] ?? null;
                        break;
                    }
                }
            }

            // Fetch account followers count
            $accountId = $postPlatform->socialAccount->platform_account_id;
            $followersCount = null;

            if ($accountId) {
                $accountResponse = Http::get(self::GRAPH_API_BASE.'/'.self::GRAPH_API_VERSION."/{$accountId}", [
                    'fields' => 'followers_count',
                    'access_token' => $accessToken,
                ]);

                if ($accountResponse->successful()) {
                    $followersCount = $accountResponse->json('followers_count');
                }
            }

            return [
                'views' => $views,
                'likes' => $data['like_count'] ?? 0,
                'comments' => $data['comments_count'] ?? 0,
                'shares' => null, // Instagram doesn't provide share count
                'followers' => $followersCount,
            ];
        } catch (\Throwable $e) {
            Log::error('InstagramStatsService: Exception', [
                'post_platform_id' => $postPlatform->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
