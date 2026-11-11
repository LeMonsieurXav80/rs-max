<?php

namespace App\Services\Agent;

use App\Models\BrowserTask;
use App\Models\SocialAccount;
use App\Models\SocialContact;
use App\Models\TikTokEngagement;
use App\Models\XEngagement;
use App\Services\Browser\BrowserException;
use Illuminate\Support\Facades\DB;

class XEngagementService
{
    public function execute(XEngagement $row): XEngagement
    {
        return DB::transaction(function () use ($row) {
            $account = SocialAccount::whereKey($row->social_account_id)->lockForUpdate()->firstOrFail();
            $row = XEngagement::whereKey($row->id)->lockForUpdate()->firstOrFail();
            abort_unless($row->status === 'prepared' && $row->browser_task_id === null, 409, 'Action déjà demandée : consulter son résultat, sans renvoi.');
            $this->assertAccount($row, $account);
            $state = app(AgentCatalog::class)->forAccount($account)->get($row->action);
            abort_unless($state && $state['status'] === 'ready' && $state['enabled'], 403, 'Action désactivée.');
            abort_if($state['left'] < 1, 429, 'Quota quotidien atteint.');
            $cadence = app(AccountActionBudget::class)->usage($account);
            abort_if($cadence['remaining'] === 0, 429, 'Cadence horaire atteinte.');
            abort_if($cadence['next_allowed_at'] && now()->lessThan($cadence['next_allowed_at']), 429, 'Délai minimal non écoulé. Prochaine action : '.$cadence['next_allowed_at']);
            $payload = $row->payload + ['engagement_id' => $row->id, 'deadline' => now()->addMinutes(5)->toIso8601String()];
            $task = BrowserTask::create(['social_account_id' => $account->id, 'profile' => $account->browser_profile,
                'kind' => 'x.agent.act', 'payload' => $payload]);
            $row->update(['status' => 'queued', 'browser_task_id' => $task->id, 'requested_at' => now()]);

            return $row;
        });
    }

    public function assertReplyAvailable(SocialAccount $account, string $tweetId, ?int $ignoreEngagement = null, ?int $ignoreInbox = null): void
    {
        $old = DB::table('extension_x_replies')->where('social_account_id', $account->id)->where('tweet_id', $tweetId)->where('status', '!=', 'prepared')->exists();
        $inbox = \App\Models\InboxItem::where('social_account_id', $account->id)->where('external_id', $tweetId)
            ->when($ignoreInbox, fn ($q) => $q->where('id', '!=', $ignoreInbox))
            ->where(fn ($q) => $q->whereNotNull('replied_at')->orWhereNotNull('reply_external_id')->orWhereNotNull('reply_scheduled_at')->orWhere('reply_attempts', '>', 0)->orWhereIn('status', ['replied', 'reply_failed']))->exists();
        $reserved = XEngagement::where('social_account_id', $account->id)->where('action', 'reply')->where('target_key', hash('sha256', $tweetId))
            ->when($ignoreEngagement, fn ($q) => $q->where('id', '!=', $ignoreEngagement))->where('status', '!=', 'prepared')->exists();
        $pending = BrowserTask::where('social_account_id', $account->id)->where('kind', 'x.post')->whereIn('status', ['pending', 'running'])
            ->get()->contains(function ($t) use ($tweetId) {
                parse_str(parse_url($t->payload['url'] ?? '', PHP_URL_QUERY) ?? '', $query);

                return (string) ($query['in_reply_to'] ?? '') === $tweetId;
            });
        abort_if($old || $inbox || $reserved || $pending, 409, 'Une réponse est déjà traitée, réservée ou incertaine pour ce post. Aucun nouvel envoi.');
    }

    private function assertAccount(XEngagement $row, SocialAccount $account): void
    {
        abort_unless($account->platform->slug === 'twitter' && $account->usesBrowser() && filled($account->browser_profile)
            && $account->browser_session_status === 'connected' && $account->browser_username === $row->payload['username'],
            409, 'Compte, routage ou session modifié : aucun geste.');
        app(CommunityManagerWindow::class)->assertOpen($row->payload['activity_window']);
        abort_if($row->persona_hash && (! $account->persona?->is_active
            || TikTokEngagement::personaHash($account) !== $row->persona_hash), 409, 'Persona modifié : refaire la lecture et le brouillon.');
        if ($row->action === 'reply') {
            abort_unless(CommentFreshness::allowsPublishedAt($row->payload['target']['published_at'] ?? null), 409, CommentFreshness::REJECTION);
            $this->assertReplyAvailable($account, $row->payload['target']['id'], $row->id, $row->payload['inbox_item_id'] ?? null);
        }
        if ($row->action === 'unfollow') {
            $contact = SocialContact::where('social_account_id', $account->id)->where('handle', $row->payload['handle'])->first();
            $days = (int) ($account->agent_strategy['unfollow_after_days'] ?? 7);
            abort_unless($contact?->followed_at && $contact->followed_at->copy()->addDays($days)->isPast()
                && ! $contact->unfollowed_at && $contact->follow_back === 'no'
                && $contact->follow_back_checked_at?->greaterThan(now()->subDay()), 409,
                'Désabonnement refusé : délai et absence de retour récente doivent être prouvés.');
        }
    }

    /** Revalider les conditions au moment où Companion prend la tâche. */
    public function claim(BrowserTask $task): void
    {
        if ($task->kind !== 'x.agent.act') {
            return;
        }
        $row = XEngagement::where('browser_task_id', $task->id)->firstOrFail();
        try {
            abort_unless($row->status === 'queued', 409);
            abort_if(now()->greaterThan($task->payload['deadline']), 409, 'Action périmée.');
            $this->assertAccount($row, $row->socialAccount);
            $state = app(AgentCatalog::class)->forAccount($row->socialAccount)->get($row->action);
            abort_unless($state && $state['enabled'] && $state['status'] === 'ready', 403, 'Action désactivée.');
            $row->update(['status' => 'running']);
        } catch (\Throwable $e) {
            throw new BrowserException($e->getMessage());
        }
    }

    public function finish(BrowserTask $task): void
    {
        if ($task->kind !== 'x.agent.act') {
            return;
        }
        $row = XEngagement::where('browser_task_id', $task->id)->first();
        if (! $row || ! in_array($row->status, ['queued', 'running'], true)) {
            return;
        }
        $proof = $task->result ?? [];
        $expected = $row->payload['target'] ?? [];
        $identityProof = match ($row->action) {
            'reply' => preg_match('/^[0-9]+$/', (string) ($proof['reply_id'] ?? '')) === 1
                && strtolower((string) ($proof['reply_url'] ?? '')) === 'https://x.com/'.strtolower($row->payload['username']).'/status/'.$proof['reply_id'] && ($proof['parent_id'] ?? null) === ($expected['id'] ?? null),
            'quote' => preg_match('/^[0-9]+$/', (string) ($proof['post_id'] ?? '')) === 1
                && strtolower((string) ($proof['post_url'] ?? '')) === 'https://x.com/'.strtolower($row->payload['username']).'/status/'.$proof['post_id']
                && ($proof['quote_id'] ?? null) === ($expected['id'] ?? null),
            'follow','unfollow' => ($proof['profile']['handle'] ?? null) === $row->payload['handle']
                && ($proof['profile']['following'] ?? null) === ($row->action === 'follow'),
            default => isset($expected['id'],$expected['author'],$expected['text']) && ($proof['target']['id'] ?? null) === ($expected['id'] ?? null)
                && ($proof['target']['author'] ?? null) === ($expected['author'] ?? null)
                && ($proof['target']['text'] ?? null) === ($expected['text'] ?? null),
        };
        $confirmed = $task->status === BrowserTask::DONE && ($proof['confirmed'] ?? false) === true
            && ($proof['proof'] ?? null) === 'after_reload' && $identityProof;
        if ($task->status === BrowserTask::DONE && ! $confirmed) {
            $task->update(['status' => BrowserTask::UNCERTAIN, 'error' => 'Preuve X après rechargement absente ou portant sur une autre cible.']);
        }
        $status = $confirmed ? 'confirmed' : (in_array($task->status, [BrowserTask::CANCELLED, BrowserTask::FAILED], true) ? 'failed' : 'uncertain');
        $row->update(['status' => $status, 'result' => $task->result, 'error' => $confirmed ? null : ($task->error ?? 'Absence de preuve après rechargement.')]);
        if ($confirmed && in_array($row->action, ['like', 'unlike'], true)) {
            \App\Models\InboxItem::where('social_account_id', $row->social_account_id)->where('external_id', $expected['id'])
                ->update(['liked_at' => $row->action === 'like' ? now() : null]);
        }
        if ($confirmed && ! ($proof['unchanged'] ?? false) && in_array($row->action, ['follow', 'unfollow'], true)) {
            $contact = SocialContact::firstOrCreate(['social_account_id' => $row->social_account_id, 'handle' => $row->payload['handle']]);
            if ($row->action === 'follow') {
                $contact->update(['followed_at' => now(), 'unfollowed_at' => null]);
            } else {
                $contact->update(['unfollowed_at' => now()]);
            }
        }

    }

    public function result(XEngagement $row): XEngagement
    {
        $task = $row->browserTask;
        if ($task && in_array($task->status, ['pending', 'running'], true) && now()->greaterThan($task->payload['deadline'])) {
            $task->update(['status' => $task->status === 'pending' ? 'cancelled' : 'uncertain', 'finished_at' => now(),
                'error' => 'Délai dépassé : ne pas relancer un geste potentiellement exécuté.']);
            app(AccountActionBudget::class)->finish($task);
        }
        if ($task && in_array($task->status, ['done', 'failed', 'cancelled', 'uncertain'], true)) {
            $this->finish($task);
        }

        return $row->fresh();
    }
}
