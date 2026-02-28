<?php

namespace App\Services\Stats;

use App\Models\PostPlatform;
use App\Models\Setting;
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
     * Determine if a post platform should be synced based on configurable intervals.
     */
    public function shouldSync(PostPlatform $postPlatform): bool
    {
        $slug = $postPlatform->platform->slug;
        $publishedAt = $postPlatform->published_at;
        $lastSync = $postPlatform->metrics_synced_at;

        if (! $publishedAt) {
            return false;
        }

        // Telegram: No stats available
        if ($slug === 'telegram') {
            return false;
        }

        $configuredInterval = (int) Setting::get("stats_{$slug}_interval", 24);
        $maxDays = (int) Setting::get("stats_{$slug}_max_days", 30);

        $daysSincePublished = now()->diffInDays($publishedAt);

        // Posts older than max age: manual sync only
        if ($daysSincePublished > $maxDays) {
            return false;
        }

        // Adaptive intervals: fresh posts need more frequent syncing
        if ($daysSincePublished < 2) {
            $intervalHours = 1;          // < 48h: every hour
        } elseif ($daysSincePublished < 7) {
            $intervalHours = 6;          // 2-7 days: every 6 hours
        } else {
            $intervalHours = $configuredInterval; // 7+ days: use configured interval
        }

        // Sync if never synced or if enough time has passed
        return ! $lastSync || $lastSync->diffInHours(now()) >= $intervalHours;
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
            'threads' => new ThreadsStatsService,
            default => null,
        };
    }
}
