<?php

namespace App\Services\Threads;

use App\Models\BrowserTask;
use App\Models\SocialAccount;
use App\Models\ThreadsEngagement;
use App\Services\Agent\AccountActionBudget;
use App\Services\Agent\AgentCatalog;
use App\Services\Agent\CommentFreshness;
use App\Services\Agent\CommunityManagerWindow;
use Illuminate\Support\Facades\DB;

/**
 * Envoie un geste du script de fil par le même circuit que ceux d'Hermès (`threads.agent.act` : contrôle à la prise,
 * autorisation avant le clic, preuve après rechargement), puis lit son issue. Un geste demandé n'est jamais renvoyé.
 */
class ThreadsEngagementService
{
    /** Délai de prise par le NAS, partagé avec TikTok et Planète de Caro. */
    public const DEADLINE_MINUTES = 6;

    /** `$profile` : le Chrome du Mac pour un passage lancé à la main, sinon le Chromium du compte. */
    public function execute(ThreadsEngagement $row, ?string $profile = null): ThreadsEngagement
    {
        return DB::transaction(function () use ($row, $profile) {
            $account = SocialAccount::with('platform')->whereKey($row->social_account_id)->lockForUpdate()->firstOrFail();
            $row = ThreadsEngagement::whereKey($row->id)->lockForUpdate()->firstOrFail();
            abort_unless($row->status === 'prepared' && $row->browser_task_id === null, 409, 'Action déjà demandée : consulter son résultat, sans renvoi.');
            abort_unless($account->platform->slug === 'threads' && filled($account->browser_profile)
                && $account->browser_session_status === 'connected' && $account->browser_username === $row->payload['username'],
                409, 'Compte, routage ou session modifié : aucun geste.');
            app(CommunityManagerWindow::class)->assertOpen($row->payload['activity_window']);
            $state = app(AgentCatalog::class)->forAccount($account)->get($row->action);
            abort_unless($state && $state['status'] === 'ready' && $state['enabled'], 403, 'Action désactivée.');
            abort_if($state['left'] < 1, 429, 'Quota quotidien atteint.');
            $cadence = app(AccountActionBudget::class)->usage($account);
            abort_if($cadence['remaining'] === 0, 429, 'Cadence horaire atteinte.');
            abort_if($cadence['next_allowed_at'] && now()->lessThan($cadence['next_allowed_at']), 429, 'Délai minimal non écoulé. Prochaine action : '.$cadence['next_allowed_at']);
            if ($row->action === 'comment') {
                abort_unless(CommentFreshness::allowsPublishedAt($row->payload['target']['published_at'] ?? null), 409, CommentFreshness::REJECTION);
            }
            // Hermès a pu agir sur la même publication entre la lecture et l'envoi.
            abort_if(BrowserTask::where('social_account_id', $account->id)->where('kind', 'threads.agent.act')
                ->where('payload->action', $row->action)->where('payload->target->url', $row->payload['url'])
                ->whereIn('status', ['pending', 'running', 'done', 'uncertain'])->exists(), 409, 'Geste déjà demandé sur cette publication.');
            $task = BrowserTask::create(['social_account_id' => $account->id, 'profile' => $profile ?? $account->browser_profile,
                'kind' => 'threads.agent.act', 'status' => BrowserTask::PENDING, 'idempotency_key' => 'threads-feed:'.$row->id,
                'payload' => $row->payload + ['engagement_id' => $row->id, 'deadline' => now()->addMinutes(self::DEADLINE_MINUTES)->toIso8601String()]]);
            $row->update(['status' => 'queued', 'browser_task_id' => $task->id, 'requested_at' => now()]);

            return $row;
        });
    }

    /** Recopie l'issue de la tâche : confirmé seulement avec la preuve après rechargement. */
    public function result(ThreadsEngagement $row): ThreadsEngagement
    {
        $task = $row->browserTask;
        if (! $task || ! in_array($row->status, ['queued', 'running'], true)) {
            return $row->fresh();
        }
        $state = app(ThreadsAgent::class)->state($task);
        $status = match ($state['status']) {
            'pending' => 'queued',
            'running' => 'running',
            'done' => $state['confirmed'] ? 'confirmed' : 'uncertain',
            'failed', 'cancelled' => 'failed',
            default => 'uncertain',
        };
        $row->update(['status' => $status, 'result' => $state['result'],
            'error' => $status === 'confirmed' ? null : ($state['error'] ?? ($status === 'uncertain' ? 'Absence de preuve après rechargement.' : null))]);

        return $status === 'uncertain' ? $this->reconcile($row->fresh()) : $row->fresh();
    }

    /**
     * Un commentaire cliqué mais non retrouvé dans la page dans les 20 s est relu une fois après rechargement
     * (`threads.agent.check`), sans second envoi. Le 14/11/2026, les deux premiers commentaires spintax étaient en
     * ligne alors que la tâche les donnait incertains.
     */
    public function reconcile(ThreadsEngagement $row): ThreadsEngagement
    {
        $task = $row->browserTask;
        if ($row->status !== 'uncertain' || $row->action !== 'comment' || ! $task
            || ($task->result['clicked'] ?? false) !== true || ($row->result['reload_checked'] ?? false)) {
            return $row;
        }
        try {
            app(ThreadsAgent::class)->verify($task, false);
        } catch (\Throwable $e) {
            $row->update(['result' => ($row->result ?? []) + ['reload_checked' => true],
                'error' => mb_substr('Relecture impossible : '.$e->getMessage(), 0, 1000)]);

            return $row->fresh();
        }
        $task->refresh();
        $confirmed = $task->status === BrowserTask::DONE && ($task->result['confirmed'] ?? false) === true;
        $row->update(['status' => $confirmed ? 'confirmed' : 'uncertain', 'result' => ($task->result ?? []) + ['reload_checked' => true],
            'error' => $confirmed ? null : 'Commentaire absent après rechargement : aucun renvoi.']);

        return $row->fresh();
    }
}
