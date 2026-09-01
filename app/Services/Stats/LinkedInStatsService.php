<?php

namespace App\Services\Stats;

use App\Models\PostPlatform;
use App\Services\Adapters\LinkedInAdapter;
use Illuminate\Support\Facades\Log;

class LinkedInStatsService implements PlatformStatsInterface
{
    public function fetchMetrics(PostPlatform $postPlatform): ?array
    {
        try {
            $externalId = $postPlatform->external_id;
            $account = $postPlatform->socialAccount;

            if (! $externalId || ! $account) {
                return null;
            }

            $raw = (new LinkedInAdapter)->fetchPostMetrics($account, $externalId);

            if ($raw === null) {
                Log::error('LinkedInStatsService: Failed to fetch metrics', [
                    'post_platform_id' => $postPlatform->id,
                    'urn' => $externalId,
                ]);

                return null;
            }

            return [
                'views' => $raw['views'],
                'likes' => $raw['likes'] ?? 0,
                'comments' => $raw['comments'] ?? 0,
                'shares' => $raw['shares'],
                'bookmarks' => $raw['bookmarks'],
                'followers' => null, // pas exposé par memberCreatorPostAnalytics
            ];
        } catch (\Throwable $e) {
            Log::error('LinkedInStatsService: Exception', [
                'post_platform_id' => $postPlatform->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
