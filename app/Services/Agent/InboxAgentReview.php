<?php

namespace App\Services\Agent;

use App\Models\BotActionLog;
use App\Models\InboxItem;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class InboxAgentReview
{
    public const MISSING_FACTS = 'missing_facts';

    /** Contexte servi plusieurs fois sans réponse ni review : l'agent s'abstient sans le dire. */
    public const NO_DECISION = 'agent_no_decision';

    public function eligible(Builder $query, CarbonInterface $now): Builder
    {
        return $query->where(fn (Builder $review) => $review
            ->whereNull('agent_retry_at')
            ->orWhere('agent_retry_at', '<=', $now)
            ->orWhere(fn (Builder $updated) => $updated->where('agent_review_reason', self::MISSING_FACTS)
                ->whereHas('socialAccount.persona', fn (Builder $persona) => $persona->where('is_active', true)
                    ->whereColumn('personas.updated_at', '>', 'inbox_items.agent_reviewed_at'))));
    }

    public function waitingForFacts(InboxItem $item): bool
    {
        $now = now();

        return $item->agent_review_reason === self::MISSING_FACTS
            && $item->agent_retry_at?->gt($now)
            && ! $this->eligible(InboxItem::whereKey($item->id), $now)->exists();
    }

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
        // Deux fois introuvable dans Studio : chaque recherche coûte environ 20 s de file partagée.
        $minutes = $reason === 'tiktok_target_not_found' && $item->agent_review_reason === $reason ? 10080 : match ($reason) {
            'already_handled', 'not_relevant' => 10080,
            'tiktok_target_not_found', 'x_target_not_found', 'x_context_changed', self::MISSING_FACTS, self::NO_DECISION => 360,
            'context_changed' => 30,
            default => 15,
        };
        $reviewedAt = now();
        $item->update(['agent_review_reason' => $reason, 'agent_reviewed_at' => $reviewedAt, 'agent_retry_at' => $reviewedAt->copy()->addMinutes($minutes)]);
        BotActionLog::create(['social_account_id' => $item->social_account_id, 'action_type' => 'agent.inbox.deferred', 'source' => 'agent',
            'target_uri' => app(AutomationJournal::class)->url($item->post_url ?? ''), 'target_text' => app(AutomationJournal::class)->text($item->content, 2000),
            'success' => true, 'metadata' => ['status' => 'completed', 'steps' => [['id' => (string) \Illuminate\Support\Str::uuid(),
                'stage' => 'agent.item.deferred', 'details' => ['message' => $reason.' ; prochaine sélection après '.$item->agent_retry_at->toIso8601String()]]]], 'performed_at' => now()]);
    }
}
