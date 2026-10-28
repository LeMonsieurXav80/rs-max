<?php

namespace App\Console\Commands;

use App\Models\InboxItem;
use App\Services\Inbox\InboxReplyService;
use Illuminate\Console\Command;

class SendScheduledInboxReplies extends Command
{
    protected $signature = 'inbox:send-scheduled';

    protected $description = 'Send inbox replies that are scheduled for now or earlier';

    public function handle(InboxReplyService $replyService): int
    {
        $items = InboxItem::whereNotNull('reply_scheduled_at')
            ->whereNotNull('reply_content')
            ->whereNull('replied_at')
            ->where('status', '!=', 'replied')
            ->where('reply_attempts', '<', 3)
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
            $result = $replyService->send($item, $item->reply_content, scheduled: true);
            if ($result['success']) {
                $sent++;
                $this->line("  Sent reply for item #{$item->id}");
            } else {
                $failed++;
                $this->error("  Failed for item #{$item->id}: ".($result['error'] ?? 'unknown'));
            }

            usleep(1000000); // 1s between sends to respect rate limits
        }

        $this->info("Done: {$sent} sent, {$failed} failed.");

        return self::SUCCESS;
    }
}
