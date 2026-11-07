<?php

namespace App\Services\Agent;

use App\Models\AccountActionEvent;
use App\Models\BrowserTask;
use App\Models\SocialAccount;
use App\Services\Browser\BrowserException;
use Illuminate\Support\Facades\DB;

/** Réservation durable avant le geste. Les échecs et résultats incertains consomment aussi la cadence. */
class AccountActionBudget
{
    public function reserve(SocialAccount $account, string $action, string $executor, ?string $target = null, ?int $taskId = null): AccountActionEvent
    {
        return DB::transaction(function () use ($account, $action, $executor, $target, $taskId) {
            $account = SocialAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $events = AccountActionEvent::where('social_account_id', $account->id);
            if ($account->platform->slug === 'twitter' && str_starts_with($action, 'x.')) {
                $key = substr($action, 2);
                $setting = $account->agentActions()->where('action', $key)->first();
                if ($setting && (clone $events)->where('action', $action)->where('created_at', '>=', $this->dayStart())->count() >= $setting->daily_limit) {
                    throw new BrowserException('Quota quotidien atteint : aucun geste effectué.');
                }
            }
            $usage = $this->usage($account);
            if (($usage['adaptation']['state']['cooldown_until'] ?? null) && \Illuminate\Support\Carbon::parse($usage['adaptation']['state']['cooldown_until'])->isFuture()) {
                throw new BrowserException('Pause adaptative RS-Max : aucun geste effectué.');
            }
            if ($account->agent_daily_limit !== null && $usage['daily']['attempted'] >= $account->agent_daily_limit) {
                throw new BrowserException('Plafond quotidien RS-Max atteint : aucun geste effectué.');
            }
            $last = (clone $events)->latest('id')->first();
            $used = (clone $events)->where('created_at', '>', now()->subHour())->count();
            if ($account->agent_hourly_limit !== null && $used >= $account->agent_hourly_limit) {
                throw new BrowserException('Cadence horaire atteinte : aucun geste effectué.');
            }
            if ($last && $account->agent_min_interval_seconds > 0 && $last->created_at->copy()->addSeconds($account->agent_min_interval_seconds)->isFuture()) {
                throw new BrowserException('Délai minimal entre actions non écoulé : aucun geste effectué.');
            }

            return AccountActionEvent::create(['social_account_id' => $account->id, 'browser_task_id' => $taskId,
                'action' => $action, 'executor' => $executor, 'target' => mb_substr((string) $target, 0, 255)]);
        });
    }

    public function forTask(BrowserTask $task): void
    {
        if (($task->payload['dry'] ?? false) === true) {
            return;
        }
        if ($task->kind === 'x.agent.act') {
            $this->reserve($task->socialAccount, 'x.'.$task->payload['action'], 'browser', $task->payload['url'], $task->id);

            return;
        }
        if (! preg_match('/^(tiktok\.(reply|like|video\.like|comment\.like|video\.comment|publish.*|upload\.(stage|draft))|x\.(post|repost|publish|reply|like|delete).*)$/', $task->kind)) {
            return;
        }
        $action = $task->kind === 'x.post' && str_contains($task->payload['url'] ?? '', 'in_reply_to=') ? 'x.reply' : $task->kind;
        $this->reserve($task->socialAccount, $action, 'browser', $task->payload['url'] ?? null, $task->id);
    }

    public function finish(BrowserTask $task): void
    {
        $status = $task->status === 'done' ? (($task->result['confirmed'] ?? null) === true ? (($task->result['unchanged'] ?? false) ? 'unchanged' : 'confirmed') : 'completed') : $task->status;
        AccountActionEvent::where('browser_task_id', $task->id)->where('status', '!=', $status)->update(['status' => $status, 'error' => $task->error, 'updated_at' => now()]);
        if ($adjustment = app(AdaptiveAccountLimits::class)->evaluate($task->socialAccount)) {
            \App\Jobs\NotifyAccountLimitAdjustment::dispatch($adjustment->id)->afterCommit();
        }
    }

    public function usage(SocialAccount $account): array
    {
        $events = AccountActionEvent::where('social_account_id', $account->id)->where('created_at', '>', now()->subHour())->get();

        $last = AccountActionEvent::where('social_account_id', $account->id)->latest('created_at')->first();
        $next = $last && $account->agent_min_interval_seconds > 0 ? $last->created_at->copy()->addSeconds($account->agent_min_interval_seconds) : null;
        if ($account->agent_hourly_limit !== null && $account->agent_hourly_limit > 0 && $events->count() >= $account->agent_hourly_limit) {
            $old = $events->sortBy('created_at')->values()->get($events->count() - $account->agent_hourly_limit)->created_at->copy()->addHour();
            if (! $next || $old->greaterThan($next)) {
                $next = $old;
            }
        }

        $daily = AccountActionEvent::where('social_account_id', $account->id)->where('created_at', '>=', $this->dayStart())->count();
        if ($account->agent_daily_limit !== null && $daily >= $account->agent_daily_limit) {
            $reset = now('Europe/Lisbon')->addDay()->startOfDay()->setTimezone(config('app.timezone'));
            if (! $next || $reset->greaterThan($next)) {
                $next = $reset;
            }
        }
        $cooldown = ($account->agent_adaptation['enabled'] ?? false) ? ($account->agent_adaptation_state['cooldown_until'] ?? null) : null;
        if ($cooldown && \Illuminate\Support\Carbon::parse($cooldown)->isFuture() && (! $next || \Illuminate\Support\Carbon::parse($cooldown)->greaterThan($next))) {
            $next = \Illuminate\Support\Carbon::parse($cooldown);
        }

        return ['daily' => ['timezone' => 'Europe/Lisbon', 'limit' => $account->agent_daily_limit, 'attempted' => $daily,
            'remaining' => $account->agent_daily_limit === null ? null : max(0, $account->agent_daily_limit - $daily)],
            'adaptation' => app(AdaptiveAccountLimits::class)->describe($account), 'next_allowed_at' => $next?->isFuture() ? $next->toIso8601String() : null, 'blocked' => $account->agent_hourly_limit === 0 || $account->agent_daily_limit === 0 || ($cooldown && \Illuminate\Support\Carbon::parse($cooldown)->isFuture()),
            'window' => 'rolling_60_minutes', 'limit' => $account->agent_hourly_limit, 'min_interval_seconds' => $account->agent_min_interval_seconds,
            'attempted' => $events->count(), 'confirmed' => $events->where('status', 'confirmed')->count(),
            'remaining' => $account->agent_hourly_limit === null ? null : max(0, $account->agent_hourly_limit - $events->count())];
    }

    public function dayStart(): \Illuminate\Support\Carbon
    {
        return now('Europe/Lisbon')->startOfDay()->setTimezone(config('app.timezone'));
    }

    public function history(SocialAccount $account): array
    {
        return AccountActionEvent::where('social_account_id', $account->id)->where('created_at', '>=', now()->subDay())->orderByDesc('id')->get()
            ->groupBy(fn ($e) => $e->created_at->timezone('Europe/Lisbon')->format('Y-m-d H:00 P'))
            ->map(fn ($rows, $hour) => ['hour' => $hour, 'attempted' => $rows->count(), 'confirmed' => $rows->where('status', 'confirmed')->count(),
                'completed' => $rows->where('status', 'completed')->count(), 'failed' => $rows->where('status', 'failed')->count(),
                'in_progress' => $rows->where('status', 'attempted')->count(), 'uncertain' => $rows->where('status', 'uncertain')->count()])->values()->all();
    }
}
