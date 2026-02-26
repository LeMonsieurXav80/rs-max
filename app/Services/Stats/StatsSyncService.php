<?php

namespace App\Services\Stats;

use App\Models\PostPlatform;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class StatsSyncService
{
    /**
     * Sync metrics for a specific post platform.
     */
    public function syncPostPlatform(PostPlatform $postPlatform): bool
    {
        // Only sync published posts with external IDs
        if ($postPlatform->status !== 'published' || ! $postPlatform->external_id) {
            return false;
        }

        $service = $this->getStatsService($postPlatform->platform->slug);

        if (! $service) {
            return false;
        }

        $metrics = $service->fetchMetrics($postPlatform);

        if ($metrics === null) {
            return false;
        }

        $postPlatform->update([
            'metrics' => $metrics,
            'metrics_synced_at' => now(),
        ]);

        return true;
    }

    /**
     * Sync metrics for multiple post platforms with intelligent frequency.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<PostPlatform>  $postPlatforms
     */
    public function syncMultiple($postPlatforms): array
    {
        $synced = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($postPlatforms as $postPlatform) {
            // Check if we should sync based on platform and age
            if (! $this->shouldSync($postPlatform)) {
                $skipped++;
                continue;
            }

            try {
                if ($this->syncPostPlatform($postPlatform)) {
                    $synced++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                Log::error('StatsSyncService: Failed to sync', [
                    'post_platform_id' => $postPlatform->id,
                    'error' => $e->getMessage(),
                ]);
                $failed++;
            }

            // Small delay to avoid rate limiting
            usleep(100000); // 100ms
        }

        return compact('synced', 'skipped', 'failed');
    }

    /**
     * Determine if a post platform should be synced based on age and last sync.
     */
    private function shouldSync(PostPlatform $postPlatform): bool
    {
        $slug = $postPlatform->platform->slug;
        $publishedAt = $postPlatform->published_at;
        $lastSync = $postPlatform->metrics_synced_at;

        if (! $publishedAt) {
            return false;
        }

        $daysSincePublished = now()->diffInDays($publishedAt);

        // YouTube: More conservative due to quota limits
        if ($slug === 'youtube') {
            // Recent posts (< 7 days): sync daily
            if ($daysSincePublished < 7) {
                return ! $lastSync || $lastSync->diffInHours(now()) >= 24;
            }

            // Medium age (7-30 days): sync weekly
            if ($daysSincePublished < 30) {
                return ! $lastSync || $lastSync->diffInDays(now()) >= 7;
            }

            // Old posts (> 30 days): manual sync only
            return false;
        }

        // Telegram: No stats available
        if ($slug === 'telegram') {
            return false;
        }

        // Facebook, Instagram, Twitter, Threads: Daily sync for posts < 30 days
        if ($daysSincePublished < 30) {
            return ! $lastSync || $lastSync->diffInHours(now()) >= 24;
        }

        // Posts older than 30 days: manual sync only
        return false;
    }

    /**
     * Get the appropriate stats service for a platform.
     */
    private function getStatsService(string $platformSlug): ?PlatformStatsInterface
    {
        return match ($platformSlug) {
            'facebook' => new FacebookStatsService,
            'instagram' => new InstagramStatsService,
            'twitter' => new TwitterStatsService,
            'youtube' => new YouTubeStatsService,
            default => null,
        };
    }
}
