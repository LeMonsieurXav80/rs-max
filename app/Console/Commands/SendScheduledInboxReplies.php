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

    private const MAX_ATTEMPTS = 3;

    public function handle(InboxSyncService $syncService): int
    {
        $items = InboxItem::whereNotNull('reply_scheduled_at')
            ->whereNotNull('reply_content')
            ->whereNull('replied_at')
            ->where('status', '!=', 'replied')
            ->where('reply_attempts', '<', self::MAX_ATTEMPTS)
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
            $item->increment('reply_attempts');

            $account = $item->socialAccount;
            $service = $syncService->getServiceForPlatform($account->platform->slug);

            if (! $service) {
                Log::warning("SendScheduledInboxReplies: no service for platform {$account->platform->slug}");
                $this->markFailedIfExhausted($item);
                $failed++;

                continue;
            }

            try {
                $result = $service->sendReply($account, $item, $item->reply_content);
            } catch (\Throwable $e) {
                Log::error("SendScheduledInboxReplies: exception for item #{$item->id}", [
                    'error' => $e->getMessage(),
                ]);
                $this->markFailedIfExhausted($item);
                $failed++;

                continue;
            }

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
                $this->markFailedIfExhausted($item);
                $failed++;
                $this->error("  Failed for item #{$item->id}: " . ($result['error'] ?? 'unknown'));
            }

            usleep(1000000); // 1s between sends to respect rate limits
        }

        $this->info("Done: {$sent} sent, {$failed} failed.");

        return self::SUCCESS;
    }

    private function markFailedIfExhausted(InboxItem $item): void
    {
        if ($item->reply_attempts >= self::MAX_ATTEMPTS) {
            $item->update([
                'status' => 'reply_failed',
                'reply_scheduled_at' => null,
            ]);
            Log::warning("SendScheduledInboxReplies: item #{$item->id} permanently failed after " . self::MAX_ATTEMPTS . ' attempts');
        }
    }
}
