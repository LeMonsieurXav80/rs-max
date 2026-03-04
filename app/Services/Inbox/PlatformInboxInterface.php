<?php

namespace App\Services\Inbox;

use App\Models\InboxItem;
use App\Models\SocialAccount;
use Carbon\Carbon;
use Illuminate\Support\Collection;

interface PlatformInboxInterface
{
    /**
     * Fetch comments, replies, and/or DMs for a social account.
     * Returns a Collection of arrays with InboxItem field data (not yet persisted).
     */
    public function fetchInbox(SocialAccount $account, ?Carbon $since = null): Collection;

    /**
     * Send a reply to a specific inbox item.
     *
     * @return array{success: bool, external_id: ?string, error: ?string}
     */
    public function sendReply(SocialAccount $account, InboxItem $item, string $replyText): array;
}
