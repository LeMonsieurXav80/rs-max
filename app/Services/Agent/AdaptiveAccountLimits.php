<?php

namespace App\Services\Agent;

use App\Models\AccountActionEvent;
use App\Models\AgentLimitAdjustment;
use App\Models\BrowserTask;
use App\Models\SocialAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Une politique et une mémoire par compte ; aucun plafond partagé entre comptes. */
class AdaptiveAccountLimits
{
    public function evaluate(SocialAccount $account): ?AgentLimitAdjustment
    {
        return DB::transaction(function () use ($account) {
            $account = SocialAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $policy = $account->agent_adaptation ?? [];
            if (! ($policy['enabled'] ?? false)) {
                return null;
            }
            $state = $account->agent_adaptation_state ?? [];
            $cooldown = $this->cooldownUntil($account);
            $started = Carbon::parse($state['enabled_at'] ?? now()->toIso8601String());
            $since = Carbon::parse($state['window_started_at'] ?? $started);
            $handled = $state['handled_problem_ids'] ?? [];
            $problem = AccountActionEvent::where('social_account_id', $account->id)
                ->where('updated_at', '>=', $started)->whereIn('status', ['failed', 'uncertain'])
                ->whereNotIn('id', $handled)->orderBy('id')->first();
            if ($problem) {
                $task = $problem->browser_task_id ? BrowserTask::find($problem->browser_task_id) : null;
                $limit = $task?->result['platform_limit'] ?? null;
                // Seul un message explicite de l'interface, transmis par Companion, prouve une limite du réseau.
                $platform = is_array($limit) && ($limit['source'] ?? null) === 'platform_ui'
                    && in_array($limit['scope'] ?? null, ['daily', 'temporary'], true);
                $state['handled_problem_ids'] = [...$handled, $problem->id];
                $state['window_started_at'] = now()->toIso8601String();
                if ($platform) {
                    $until = now()->addMinutes($limit['scope'] === 'daily' ? 1440 : 60);
                    $cooldown = $cooldown && $cooldown->gt($until) ? $cooldown : $until;
                }
                $state['cooldown_until'] = $cooldown?->toIso8601String();
                $state['cooldown_source'] = $cooldown ? 'platform_ui' : null;
                unset($state['publication_cooldown_until']);
                $state['reason'] = $platform ? 'Limitation explicite du réseau : cadence réduite et pause.' : 'Incident technique enregistré. Nouvelle fenêtre de mesure de la cadence.';
                $old = $this->values($account);
                if ($platform) {
                    $account->agent_hourly_limit = max(1, (int) floor($account->agent_hourly_limit / 2));
                    $account->agent_daily_limit = max(2, (int) floor($account->agent_daily_limit / 2));
                    $account->agent_min_interval_seconds = $this->interval($account->agent_hourly_limit);
                    $this->scaleActions($account);
                }
                $account->agent_adaptation_state = $state;
                $account->save();

                return $this->record($account, $old, $state['reason'], ['event_id' => $problem->id, 'source' => $platform ? 'platform_ui' : 'technical', 'cooldown_until' => $state['cooldown_until'], 'platform_message' => $platform ? mb_substr((string) ($limit['message'] ?? ''), 0, 500) : null]);
            }
            if ($cooldown !== null) {
                return null;
            }
            $events = AccountActionEvent::where('social_account_id', $account->id)->where('created_at', '>=', $since)->whereNotIn('id', $handled)->get();
            if ($since->copy()->addMinutes(30)->isFuture() || $events->where('status', 'confirmed')->count() < 10
                || $events->contains(fn ($e) => ! in_array($e->status, ['confirmed', 'unchanged', 'completed'], true))) {
                return null;
            }
            $old = $this->values($account);
            $account->agent_hourly_limit = min($policy['max_hourly'], (int) (ceil($account->agent_hourly_limit * 1.25 / 10) * 10));
            $account->agent_daily_limit = min($policy['max_daily'], (int) (ceil($account->agent_daily_limit * 1.25 / 10) * 10));
            $account->agent_min_interval_seconds = $this->interval($account->agent_hourly_limit);
            if ($old === $this->values($account)) {
                return null;
            }
            unset($state['publication_cooldown_until']);
            $account->agent_adaptation_state = array_replace($state, ['window_started_at' => now()->toIso8601String(), 'cooldown_until' => null, 'cooldown_source' => null, 'reason' => 'Au moins 10 gestes confirmés sur une fenêtre stable de 30 minutes.']);
            $account->save();
            $this->scaleActions($account);

            return $this->record($account, $old, $account->agent_adaptation_state['reason'], ['confirmed' => $events->where('status', 'confirmed')->count()]);
        });
    }

    public function cooldownUntil(SocialAccount $account): ?Carbon
    {
        if (! ($account->agent_adaptation['enabled'] ?? false)) {
            return null;
        }

        $state = $account->agent_adaptation_state ?? [];
        if (empty($state['cooldown_until'])) {
            return null;
        }
        if (array_key_exists('cooldown_source', $state)) {
            $until = Carbon::parse($state['cooldown_until']);

            return $state['cooldown_source'] === 'platform_ui' && $until->isFuture() ? $until : null;
        }

        // Dans l’ancien état, une pause technique pouvait masquer une limite réseau plus longue.
        $cutoff = now()->subDay();
        if (! empty($state['enabled_at'])) {
            $enabledAt = Carbon::parse($state['enabled_at']);
            if ($enabledAt->gt($cutoff)) {
                $cutoff = $enabledAt;
            }
        }

        return AgentLimitAdjustment::where('social_account_id', $account->id)
            ->where('created_at', '>=', $cutoff)->where('context->source', 'platform_ui')->get()
            ->map(fn ($row) => isset($row->context['cooldown_until']) ? Carbon::parse($row->context['cooldown_until']) : null)
            ->filter(fn ($until) => $until?->isFuture())->max();
    }

    public function interval(int $hourly): int
    {
        return max(12, (int) ceil(3600 / max(1, $hourly)));
    }

    public function scaleActions(SocialAccount $account): void
    {
        // Ne change aucune activation, ni les autres catégories d'actions.
        $account->agentActions()->where('enabled', true)->where('action', 'reply')->update(['daily_limit' => (int) floor($account->agent_daily_limit / 2)]);
        $account->agentActions()->where('enabled', true)->where('action', 'like')->update(['daily_limit' => $account->agent_daily_limit]);
    }

    public function values(SocialAccount $account): array
    {
        return ['hourly' => $account->agent_hourly_limit, 'daily' => $account->agent_daily_limit, 'interval' => $account->agent_min_interval_seconds];
    }

    public function record(SocialAccount $account, array $old, string $reason, array $context = []): AgentLimitAdjustment
    {
        return AgentLimitAdjustment::create(['social_account_id' => $account->id, 'old_hourly' => $old['hourly'], 'new_hourly' => $account->agent_hourly_limit,
            'old_daily' => $old['daily'], 'new_daily' => $account->agent_daily_limit, 'old_interval' => $old['interval'], 'new_interval' => $account->agent_min_interval_seconds,
            'reason' => $reason, 'context' => $context]);
    }

    public function describe(SocialAccount $account): array
    {
        $state = $account->agent_adaptation_state ?? [];
        $state['cooldown_until'] = $this->cooldownUntil($account)?->toIso8601String();

        return ['policy' => $account->agent_adaptation ?? ['enabled' => false], 'state' => $state,
            'history' => AgentLimitAdjustment::where('social_account_id', $account->id)->latest('id')->limit(10)->get()->map(fn ($row) => $row->only(['id', 'old_hourly', 'new_hourly', 'old_daily', 'new_daily', 'old_interval', 'new_interval', 'reason', 'context', 'created_at']))];
    }
}
