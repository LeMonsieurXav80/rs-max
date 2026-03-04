<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Services\Inbox\InboxSyncService;
use Illuminate\Console\Command;

class SyncInbox extends Command
{
    protected $signature = 'inbox:sync
                            {--account= : Sync only a specific account ID}
                            {--platform= : Sync only accounts for this platform slug}';

    protected $description = 'Fetch new comments, replies, and DMs from social platforms';

    public function handle(InboxSyncService $syncService): int
    {
        $this->info('Starting inbox sync...');

        if ($accountId = $this->option('account')) {
            $account = SocialAccount::with('platform')->findOrFail($accountId);
            $result = $syncService->syncAccount($account);
            $this->info("Fetched {$result['fetched']} items for {$account->name}");

            if ($result['error']) {
                $this->error($result['error']);
            }

            return self::SUCCESS;
        }

        if ($platformSlug = $this->option('platform')) {
            $accounts = SocialAccount::whereHas('platform', fn ($q) => $q->where('slug', $platformSlug))
                ->with('platform')
                ->get();

            $totalFetched = 0;

            foreach ($accounts as $account) {
                $result = $syncService->syncAccount($account);
                $totalFetched += $result['fetched'];
                $this->line("  {$account->name}: {$result['fetched']} items");
            }

            $this->info("Total fetched: {$totalFetched}");

            return self::SUCCESS;
        }

        $result = $syncService->syncAll();
        $this->info("Accounts synced: {$result['accounts']}");
        $this->info("Items fetched: {$result['fetched']}");

        if ($result['failed'] > 0) {
            $this->error("Failed: {$result['failed']}");
        }

        return self::SUCCESS;
    }
}
