<?php

namespace App\Console\Commands;

use App\Models\PostPlatform;
use App\Services\Stats\StatsSyncService;
use Illuminate\Console\Command;

class SyncPostStats extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stats:sync
                            {--post-id= : Sync stats for a specific post}
                            {--platform= : Sync only for a specific platform (facebook, instagram, twitter, youtube)}
                            {--force : Force sync even if recently synced}
                            {--days= : Only sync posts published within the last X days (default: 30)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync publication statistics from social media platforms';

    /**
     * Execute the console command.
     */
    public function handle(StatsSyncService $syncService): int
    {
        $this->info('Starting stats sync...');

        $query = PostPlatform::with(['platform', 'socialAccount', 'post'])
            ->where('status', 'published')
            ->whereNotNull('external_id')
            ->whereNotNull('published_at');

        // Filter by post ID
        if ($postId = $this->option('post-id')) {
            $query->where('post_id', $postId);
        }

        // Filter by platform
        if ($platform = $this->option('platform')) {
            $query->whereHas('platform', fn ($q) => $q->where('slug', $platform));
        }

        // Filter by age
        $days = (int) ($this->option('days') ?: 30);
        $query->where('published_at', '>=', now()->subDays($days));

        $postPlatforms = $query->get();

        if ($postPlatforms->isEmpty()) {
            $this->warn('No post platforms found to sync.');

            return self::SUCCESS;
        }

        $this->info("Found {$postPlatforms->count()} post platform(s) to process.");

        if ($this->option('force')) {
            $this->info('Force mode: ignoring last sync times');
        }

        $progressBar = $this->output->createProgressBar($postPlatforms->count());
        $progressBar->start();

        $results = ['synced' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($postPlatforms as $postPlatform) {
            // Skip if shouldn't sync (unless force)
            if (! $this->option('force') && ! $syncService->shouldSync($postPlatform)) {
                $results['skipped']++;
                $progressBar->advance();
                continue;
            }

            try {
                if ($syncService->syncPostPlatform($postPlatform)) {
                    $results['synced']++;
                } else {
                    $results['failed']++;
                }
            } catch (\Throwable $e) {
                $results['failed']++;
                $this->error("\nError syncing post platform {$postPlatform->id}: {$e->getMessage()}");
            }

            $progressBar->advance();
            usleep(100000); // 100ms delay to avoid rate limiting
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info("Synced: {$results['synced']}");
        $this->info("Skipped: {$results['skipped']}");

        if ($results['failed'] > 0) {
            $this->error("Failed: {$results['failed']}");
        }

        $this->newLine();
        $this->info('Sync complete!');

        return self::SUCCESS;
    }
}
