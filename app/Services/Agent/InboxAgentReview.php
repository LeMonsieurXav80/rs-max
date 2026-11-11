<?php

namespace App\Services\Agent;

use App\Models\BotActionLog;
use App\Models\InboxItem;

class InboxAgentReview
{
    public function dismissUnavailableXTarget(InboxItem $item, string $reason): void
    {
        \Illuminate\Support\Facades\Cache::lock('inbox-reply:'.$item->id, 120)->get(function () use ($item, $reason) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($item, $reason) {
                $updated = InboxItem::whereKey($item->id)->whereIn('status', ['unread', 'read'])
                    ->whereNull('replied_at')->whereNull('reply_external_id')->whereNull('reply_scheduled_at')
                    ->where('reply_attempts', 0)->update([
                        'status' => 'ignored',
                        'agent_review_reason' => $reason,
                        'agent_reviewed_at' => now(),
                        'agent_retry_at' => null,
                    ]);

                if ($updated) {
                    BotActionLog::create([
                        'social_account_id' => $item->social_account_id,
                        'action_type' => 'agent.inbox.dismissed',
                        'source' => 'agent',
                        'target_uri' => app(AutomationJournal::class)->url($item->post_url ?? ''),
                        'success' => true,
                        'metadata' => ['status' => 'completed', 'steps' => [[
                            'id' => (string) \Illuminate\Support\Str::uuid(),
                            'stage' => 'agent.item.dismissed',
                            'details' => ['message' => $reason],
                        ]]],
                        'performed_at' => now(),
                    ]);
                }
            });
        });
        $item->refresh();
    }

    public function defer(InboxItem $item, string $reason): void
    {
        $minutes = match ($reason) {
            'already_handled', 'not_relevant' => 10080,
            'tiktok_target_not_found', 'x_target_not_found', 'x_context_changed' => 360,
            'context_changed' => 30,
            default => 15,
        };
        $item->update(['agent_review_reason' => $reason, 'agent_reviewed_at' => now(), 'agent_retry_at' => now()->addMinutes($minutes)]);
        BotActionLog::create(['social_account_id' => $item->social_account_id, 'action_type' => 'agent.inbox.deferred', 'source' => 'agent',
            'target_uri' => app(AutomationJournal::class)->url($item->post_url ?? ''), 'target_text' => app(AutomationJournal::class)->text($item->content, 2000),
            'success' => true, 'metadata' => ['status' => 'completed', 'steps' => [['id' => (string) \Illuminate\Support\Str::uuid(),
                'stage' => 'agent.item.deferred', 'details' => ['message' => $reason.' ; prochaine sélection après '.$item->agent_retry_at->toIso8601String()]]]], 'performed_at' => now()]);
    }
}
