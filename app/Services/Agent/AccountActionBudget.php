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
            $last = (clone $events)->latest('id')->first();
            $used = (clone $events)->where('created_at', '>', now()->subHour())->count();
            if ($account->agent_hourly_limit !== null && $used >= $account->agent_hourly_limit) {
                throw new BrowserException('Cadence horaire atteinte : aucun geste effectué.');
            }
            if ($last && $last->created_at->copy()->addSeconds($account->agent_min_interval_seconds)->isFuture()) {
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
        if (! preg_match('/^(tiktok\.(reply|like|video\.like|comment\.like|video\.comment|publish.*|upload\.(stage|draft))|x\.(post|repost|publish|reply|like|delete).*)$/', $task->kind)) {
            return;
        }
        $this->reserve($task->socialAccount, $task->kind, 'browser', $task->payload['url'] ?? null, $task->id);
    }

    public function finish(BrowserTask $task): void
    {
        $status = $task->status === 'done' ? (($task->result['confirmed'] ?? null) === true ? 'confirmed' : 'completed') : $task->status;
        AccountActionEvent::where('browser_task_id', $task->id)->update(['status' => $status, 'error' => $task->error, 'updated_at' => now()]);
    }

    public function usage(SocialAccount $account): array
    {
        $events = AccountActionEvent::where('social_account_id', $account->id)->where('created_at', '>', now()->subHour())->get();

        return ['window' => 'rolling_60_minutes', 'limit' => $account->agent_hourly_limit, 'min_interval_seconds' => $account->agent_min_interval_seconds,
            'attempted' => $events->count(), 'confirmed' => $events->where('status', 'confirmed')->count(),
            'remaining' => $account->agent_hourly_limit === null ? null : max(0, $account->agent_hourly_limit - $events->count())];
    }

    public function history(SocialAccount $account): array
    {
        return AccountActionEvent::where('social_account_id', $account->id)->where('created_at', '>=', now()->subDay())->orderByDesc('id')->get()
            ->groupBy(fn ($e) => $e->created_at->timezone('Europe/Lisbon')->format('Y-m-d H:00 P'))
            ->map(fn ($rows, $hour) => ['hour' => $hour, 'attempted' => $rows->count(), 'confirmed' => $rows->where('status', 'confirmed')->count(),
                'completed' => $rows->where('status', 'completed')->count(), 'failed' => $rows->where('status', 'failed')->count(),
                'uncertain' => $rows->whereIn('status', ['attempted', 'uncertain'])->count()])->values()->all();
    }
}
