<?php

namespace App\Console\Commands;

use App\Models\InboxItem;
use App\Services\Inbox\InboxSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendScheduledInboxReplies extends Command
{
    protected $signature = 'inbox:send-scheduled';

    protected $description = 'Send inbox replies that are scheduled for now or earlier';

    public function handle(InboxSyncService $syncService): int
    {
        $items = InboxItem::whereNotNull('reply_scheduled_at')
            ->whereNotNull('reply_content')
            ->whereNull('replied_at')
            ->where('reply_scheduled_at', '<=', now())
            ->with('socialAccount.platform')
            ->orderBy('reply_scheduled_at', 'asc')
            ->get();

        if ($items->isEmpty()) {
            return self::SUCCESS;
        }

        $this->info("Sending {$items->count()} scheduled replies...");

        $sent = 0;
        $failed = 0;

        foreach ($items as $item) {
            $account = $item->socialAccount;
            $service = $syncService->getServiceForPlatform($account->platform->slug);

            if (! $service) {
                Log::warning("SendScheduledInboxReplies: no service for platform {$account->platform->slug}");
                $failed++;

                continue;
            }

            $result = $service->sendReply($account, $item, $item->reply_content);

            if ($result['success']) {
                $item->update([
                    'status' => 'replied',
                    'reply_external_id' => $result['external_id'],
                    'replied_at' => now(),
                    'reply_scheduled_at' => null,
                ]);
                $sent++;
                $this->line("  Sent reply for item #{$item->id} ({$account->platform->slug})");
            } else {
                Log::error("SendScheduledInboxReplies: failed for item #{$item->id}", ['error' => $result['error'] ?? 'unknown']);
                $failed++;
                $this->error("  Failed for item #{$item->id}: " . ($result['error'] ?? 'unknown'));
            }

            usleep(1000000); // 1s between sends to respect rate limits
        }

        $this->info("Done: {$sent} sent, {$failed} failed.");

        return self::SUCCESS;
    }
}
