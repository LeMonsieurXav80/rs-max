<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Models\SocialAccountSnapshot;
use App\Services\FollowersService;
use Illuminate\Console\Command;

class SyncFollowersCommand extends Command
{
    protected $signature = 'followers:sync
                            {--account= : Sync followers for a specific social account ID}
                            {--no-snapshot : Skip snapshot creation (useful for manual updates)}';

    protected $description = 'Sync follower counts from all social media platforms';

    public function handle(FollowersService $followersService): int
    {
        $this->info('Starting followers sync...');
        $shouldSnapshot = ! $this->option('no-snapshot');

        if ($accountId = $this->option('account')) {
            $account = SocialAccount::with('platform')->find($accountId);

            if (! $account) {
                $this->error("Account #{$accountId} not found.");

                return self::FAILURE;
            }

            $count = $followersService->syncFollowers($account);
            $this->info("{$account->name} ({$account->platform->slug}): " . ($count !== null ? number_format($count) : 'failed'));

            if ($shouldSnapshot && $count !== null) {
                $this->createSnapshot($account, $count);
            }

            return self::SUCCESS;
        }

        $accounts = SocialAccount::with('platform')->get();

        if ($accounts->isEmpty()) {
            $this->warn('No social accounts found.');

            return self::SUCCESS;
        }

        $this->info("Found {$accounts->count()} account(s) to process.");

        $progressBar = $this->output->createProgressBar($accounts->count());
        $progressBar->start();

        $synced = 0;
        $failed = 0;

        foreach ($accounts as $account) {
            $count = $followersService->syncFollowers($account);

            if ($count !== null) {
                $synced++;
                if ($shouldSnapshot) {
                    $this->createSnapshot($account, $count);
                }
            } else {
                $failed++;
            }

            $progressBar->advance();
            usleep(100000); // 100ms delay to avoid rate limiting
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info("Synced: {$synced}");

        if ($failed > 0) {
            $this->error("Failed: {$failed}");
        }

        $this->newLine();
        $this->info('Followers sync complete!');

        return self::SUCCESS;
    }

    private function createSnapshot(SocialAccount $account, int $count): void
    {
        SocialAccountSnapshot::updateOrCreate(
            [
                'social_account_id' => $account->id,
                'date' => now()->toDateString(),
                'granularity' => 'daily',
            ],
            [
                'followers_count' => $count,
                'created_at' => now(),
            ]
        );
    }
}
