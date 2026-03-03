<?php

namespace App\Services\Stats;

use App\Models\PostPlatform;
use App\Services\Adapters\RedditAdapter;
use Illuminate\Support\Facades\Log;

class RedditStatsService implements PlatformStatsInterface
{
    public function fetchMetrics(PostPlatform $postPlatform): ?array
    {
        try {
            $externalId = $postPlatform->external_id;
            $credentials = $postPlatform->socialAccount->credentials ?? [];

            if (! $externalId || empty($credentials)) {
                return null;
            }

            $postData = RedditAdapter::fetchPostInfo($credentials, $externalId);

            if ($postData === null) {
                Log::error('RedditStatsService: Failed to fetch metrics', [
                    'post_platform_id' => $postPlatform->id,
                    'external_id' => $externalId,
                ]);

                return null;
            }

            return [
                'views' => null,
                'likes' => (int) ($postData['score'] ?? 0),
                'comments' => (int) ($postData['num_comments'] ?? 0),
                'shares' => null,
                'followers' => null,
            ];
        } catch (\Throwable $e) {
            Log::error('RedditStatsService: Exception', [
                'post_platform_id' => $postPlatform->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
