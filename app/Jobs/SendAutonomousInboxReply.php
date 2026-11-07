<?php

namespace App\Jobs;

use App\Models\InboxItem;
use App\Services\Inbox\InboxReplyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendAutonomousInboxReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 960;

    public function __construct(public int $itemId) {}

    public function handle(InboxReplyService $service): void
    {
        $item = InboxItem::find($this->itemId);
        if (! $item || $item->replied_at || ! $item->reply_scheduled_at || $item->reply_attempts > 0) {
            return;
        }
        if ($item->reply_scheduled_at->isFuture()) {
            self::dispatch($this->itemId)->delay($item->reply_scheduled_at)->afterCommit();

            return;
        }
        $service->send($item, $item->reply_content, scheduled: true);
    }
}
