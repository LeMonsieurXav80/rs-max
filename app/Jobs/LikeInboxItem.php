<?php

namespace App\Jobs;

use App\Models\InboxItem;
use App\Services\Inbox\TikTokInboxService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/** Un seul essai : un like non confirmé a pu partir. */
class LikeInboxItem implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public int $itemId) {}

    public function handle(TikTokInboxService $tiktok): void
    {
        $item = InboxItem::with('socialAccount.platform')->find($this->itemId);
        if (! $item || $item->liked_at || ! in_array($item->socialAccount->platform->slug, ['tiktok', 'twitter', 'facebook'], true)) {
            return;
        }

        $account = $item->socialAccount;
        if ($account->platform->slug === 'facebook') {
            app(\App\Services\Agent\ApiInboxActions::class)->likeFacebook($item, false);

            return;
        }
        if ($account->platform->slug === 'twitter' && ! $account->usesBrowser()) {
            return;
        }
        $service = $account->platform->slug === 'twitter' ? app(\App\Services\Inbox\TwitterBrowserInboxService::class) : $tiktok;
        $result = $service->like($account, $item);
        if ($result['success']) {
            $item->update(['liked_at' => now()]);
        } else {
            Log::warning('Like inbox non confirmé', ['item_id' => $item->id, 'error' => $result['error']]);
        }
    }
}
