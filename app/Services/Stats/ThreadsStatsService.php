<?php

namespace App\Services\Stats;

use App\Models\PostPlatform;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ThreadsStatsService implements PlatformStatsInterface
{
    private const API_BASE = 'https://graph.threads.net/v1.0';

    public function fetchMetrics(PostPlatform $postPlatform): ?array
    {
        try {
            $externalId = $postPlatform->external_id;
            $accessToken = $postPlatform->socialAccount->credentials['access_token'] ?? null;

            if (! $externalId || ! $accessToken) {
                return null;
            }

            $response = Http::get(self::API_BASE . "/{$externalId}/insights", [
                'metric' => 'views,likes,replies,reposts,quotes',
                'access_token' => $accessToken,
            ]);

            if (! $response->successful()) {
                Log::error('ThreadsStatsService: Failed to fetch metrics', [
                    'post_platform_id' => $postPlatform->id,
                    'external_id' => $externalId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $insights = $response->json('data', []);

            $metrics = ['views' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0];

            foreach ($insights as $insight) {
                $name = $insight['name'] ?? '';
                $value = (int) ($insight['values'][0]['value'] ?? 0);

                switch ($name) {
                    case 'views':
                        $metrics['views'] = $value;
                        break;
                    case 'likes':
                        $metrics['likes'] = $value;
                        break;
                    case 'replies':
                        $metrics['comments'] = $value;
                        break;
                    case 'reposts':
                    case 'quotes':
                        $metrics['shares'] += $value;
                        break;
                }
            }

            return $metrics;
        } catch (\Throwable $e) {
            Log::error('ThreadsStatsService: Exception', [
                'post_platform_id' => $postPlatform->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
