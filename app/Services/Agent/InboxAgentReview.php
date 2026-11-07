<?php

namespace App\Services\Agent;

use App\Models\BotActionLog;
use App\Models\InboxItem;

class InboxAgentReview
{
    public function defer(InboxItem $item, string $reason): void
    {
        $minutes = match ($reason) {
            'already_handled', 'not_relevant' => 10080, 'context_changed' => 30, default => 15
        };
        $item->update(['agent_review_reason' => $reason, 'agent_reviewed_at' => now(), 'agent_retry_at' => now()->addMinutes($minutes)]);
        BotActionLog::create(['social_account_id' => $item->social_account_id, 'action_type' => 'agent.inbox.deferred', 'source' => 'agent',
            'target_uri' => app(AutomationJournal::class)->url($item->post_url ?? ''), 'target_text' => app(AutomationJournal::class)->text($item->content, 2000),
            'success' => true, 'metadata' => ['status' => 'completed', 'steps' => [['id' => (string) \Illuminate\Support\Str::uuid(),
                'stage' => 'agent.item.deferred', 'details' => ['message' => $reason.' ; prochaine sélection après '.$item->agent_retry_at->toIso8601String()]]]], 'performed_at' => now()]);
    }
}
