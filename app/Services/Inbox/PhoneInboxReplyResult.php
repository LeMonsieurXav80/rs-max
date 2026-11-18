<?php

namespace App\Services\Inbox;

use App\Jobs\LikeInboxItem;
use App\Jobs\VerifyTikTokInboxReply;
use App\Models\BrowserTask;
use App\Models\InboxItem;

/**
 * Résultat d'une réponse TikTok mise en file pour un téléphone (`InboxReplyService::send`), appliqué quand la tâche
 * se termine : mêmes règles que l'envoi synchrone. Confirmée après rechargement ⇒ répondu (et aimé si le téléphone
 * l'a prouvé) ; incertaine ⇒ jamais renvoyée, relue 5 min après ; échouée ⇒ jamais relancée automatiquement.
 */
class PhoneInboxReplyResult
{
    /** Réponses en attente par compte sur téléphone : au-delà, la file grossirait plus vite qu'elle ne se vide. */
    public const MAX_IN_FLIGHT = 30;

    public static function inFlight(int $accountId): int
    {
        return BrowserTask::where('social_account_id', $accountId)->where('kind', 'tiktok.reply')
            ->whereIn('status', [BrowserTask::PENDING, BrowserTask::RUNNING])->count()
            + InboxItem::where('social_account_id', $accountId)->whereNotNull('reply_scheduled_at')->whereNull('replied_at')->count();
    }

    public function apply(BrowserTask $task): void
    {
        $id = $task->payload['inbox_item_id'] ?? null;
        $terminal = in_array($task->status, [BrowserTask::DONE, BrowserTask::FAILED, BrowserTask::UNCERTAIN, BrowserTask::CANCELLED], true);
        if ($task->kind === 'tiktok.like' && $id && $terminal) {
            // Like seul : marqué s'il est prouvé ; sinon rien (jamais relancé, comme le like synchrone).
            if ($task->status === BrowserTask::DONE && ($task->result['confirmed'] ?? false) === true) {
                InboxItem::whereKey($id)->whereNull('liked_at')->update(['liked_at' => now()]);
            }

            return;
        }
        $item = $id ? InboxItem::whereKey($id)->whereNull('replied_at')->first() : null;
        if ($task->kind !== 'tiktok.reply' || ! $item || ! $terminal) {
            return;
        }
        $result = $task->result ?? [];
        if ($task->status === BrowserTask::DONE && ($result['confirmed'] ?? false) === true) {
            $item->update(['status' => 'replied', 'reply_content' => $task->payload['text'], 'reply_draft' => null,
                'replied_at' => now(), 'reply_scheduled_at' => null]
                + (($result['liked'] ?? false) === true && ! $item->liked_at ? ['liked_at' => now()] : []));
            if (! $item->liked_at) {
                LikeInboxItem::dispatch($item->id)->afterCommit();
            }

            return;
        }
        if ($task->status === BrowserTask::CANCELLED) {
            // Jamais prise (échéance, cadence, plage horaire) : aucun geste, le commentaire redevient à traiter.
            $item->update(['status' => 'read', 'reply_content' => null, 'reply_scheduled_at' => null, 'reply_attempts' => 0]);

            return;
        }
        $item->update(['status' => 'reply_failed', 'reply_content' => $task->payload['text'], 'reply_scheduled_at' => null, 'reply_attempts' => 3]);
        if ($task->status === BrowserTask::UNCERTAIN) {
            VerifyTikTokInboxReply::dispatch($item->id)->delay(now()->addMinutes(5))->afterCommit();
        }
    }
}
