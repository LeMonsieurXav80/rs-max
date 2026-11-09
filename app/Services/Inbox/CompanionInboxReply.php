<?php

namespace App\Services\Inbox;

use App\Models\InboxItem;
use App\Models\SocialAccount;

/** Projette une réponse confirmée dans l’inbox, même si la cible n’a pas encore été importée. */
class CompanionInboxReply
{
    public function record(SocialAccount $account, string $externalId, array $target, string $text, bool $liked): void
    {
        $key = ['platform_id' => $account->platform_id, 'external_id' => $externalId];
        $now = now();
        InboxItem::upsert([$key + [
            'social_account_id' => $account->id,
            'type' => 'comment',
            'status' => 'read',
        ] + $target], ['platform_id', 'external_id'], ['external_id']);

        $item = InboxItem::where($key)->where('social_account_id', $account->id)->lockForUpdate()->first();
        if (! $item) {
            return;
        }

        $updates = [];
        if (! $item->replied_at && ! $item->reply_external_id && $item->status !== 'replied') {
            $updates = [
                'status' => 'replied',
                'reply_content' => $text,
                'replied_at' => $now,
                'reply_draft' => null,
                'reply_scheduled_at' => null,
                'reply_activity_window' => null,
                'agent_review_reason' => null,
                'agent_reviewed_at' => null,
                'agent_retry_at' => null,
            ];
        }
        if ($liked && ! $item->liked_at) {
            $updates['liked_at'] = $now;
        }
        if ($updates) {
            $item->update($updates);
        }
    }
}
