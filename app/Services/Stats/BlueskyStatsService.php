<?php

namespace App\Services\Stats;

use App\Models\PostPlatform;
use App\Services\Adapters\BlueskyAdapter;
use Illuminate\Support\Facades\Log;

class BlueskyStatsService implements PlatformStatsInterface
{
    public function fetchMetrics(PostPlatform $postPlatform): ?array
    {
        try {
            $externalId = $postPlatform->external_id;

            if (! $externalId) {
                return null;
            }

            // External ID format: "uri|cid" — we only need the URI.
            [$uri] = explode('|', $externalId, 2);

            $raw = BlueskyAdapter::fetchPostMetrics($uri);

            if ($raw === null) {
                Log::error('BlueskyStatsService: Failed to fetch metrics', [
                    'post_platform_id' => $postPlatform->id,
                    'uri' => $uri,
                ]);

                return null;
            }

            return [
                'views' => null, // Bluesky doesn't expose view counts
                'likes' => $raw['like_count'],
                'comments' => $raw['reply_count'],
                'shares' => $raw['repost_count'] + $raw['quote_count'],
                'followers' => null,
            ];
        } catch (\Throwable $e) {
            Log::error('BlueskyStatsService: Exception', [
                'post_platform_id' => $postPlatform->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
