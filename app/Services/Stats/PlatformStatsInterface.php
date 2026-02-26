<?php

namespace App\Services\Stats;

use App\Models\PostPlatform;

interface PlatformStatsInterface
{
    /**
     * Fetch metrics for a published post platform.
     *
     * @param  PostPlatform  $postPlatform  Post platform with external_id and social account
     * @return array{views: int|null, likes: int, comments: int, shares: int|null, followers: int|null}|null
     */
    public function fetchMetrics(PostPlatform $postPlatform): ?array;
}
