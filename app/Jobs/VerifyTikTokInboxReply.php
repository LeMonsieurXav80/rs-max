<?php

namespace App\Jobs;

use App\Models\InboxItem;
use App\Services\Inbox\TikTokReplyReconciler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** Deux relectures différées, aucun appel d'envoi ni remise à zéro des tentatives. */
class VerifyTikTokInboxReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 210;

    public function __construct(public int $itemId, public int $pass = 1) {}

    public function handle(TikTokReplyReconciler $reconciler): void
    {
        $lock = Cache::lock('tiktok-reply-proof:'.$this->itemId, 240);
        if (! $lock->get()) {
            return;
        }
        try {
            $item = InboxItem::with('socialAccount.platform')->find($this->itemId);
            if (! $item || $item->replied_at || $item->status !== 'reply_failed' || $item->reply_attempts < 1
                || $item->reply_scheduled_at || blank($item->reply_content)
                || $item->socialAccount->platform->slug !== 'tiktok' || ! $item->socialAccount->usesBrowser()) {
                return;
            }
            try {
                $proof = $reconciler->verify($item);
                if (($proof['result']['confirmed'] ?? false) === true) {
                    $reconciler->reconcile($item, $proof['verification_task_id'], false);

                    return;
                }
                Log::notice('TikTok : réponse toujours absente à la relecture différée, aucun renvoi', ['item_id' => $item->id, 'verification_task_id' => $proof['verification_task_id'], 'pass' => $this->pass]);
            } catch (\Throwable $e) {
                Log::warning('TikTok : relecture différée impossible, aucun renvoi', ['item_id' => $item->id, 'pass' => $this->pass, 'error' => mb_substr($e->getMessage(), 0, 500)]);
            }
            if ($this->pass === 1) {
                self::dispatch($item->id, 2)->delay(now()->addMinutes(25))->afterCommit();
            }
        } finally {
            $lock->release();
        }
    }
}
