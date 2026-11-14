<?php

namespace App\Services\Agent;

use App\Jobs\VerifyXEngagementReply;
use App\Models\BrowserTask;
use App\Models\InboxItem;
use App\Models\SocialAccount;
use App\Models\SocialContact;
use App\Models\TikTokEngagement;
use App\Models\XEngagement;
use App\Services\Browser\BrowserException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class XEngagementService
{
    public function reserveCompanionReaction(SocialAccount $account, string $username, array $target, string $action): XEngagement
    {
        $key = hash('sha256', $target['handle'] ?? $target['id']);

        return Cache::lock('x-prepare:'.$account->id.':'.$action.':'.$key, 30)->block(2, function () use ($account, $username, $target, $action, $key) {
            return DB::transaction(function () use ($account, $username, $target, $action, $key) {
                SocialAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
                $row = XEngagement::where('social_account_id', $account->id)->where('action', $action)->where('target_key', $key)->first();
                abort_if($row && $row->status !== 'prepared', 409, 'Ce geste est déjà enregistré ou réservé pour cette cible.');
                if ($action === 'like') {
                    $this->assertLikeAvailable($account, $target['id']);
                }
                $destination = $action === 'follow'
                    ? ['url' => 'https://x.com/'.$target['handle'], 'handle' => $target['handle']]
                    : ['url' => 'https://x.com/'.$target['author'].'/status/'.$target['id'], 'target' => $target];
                $row ??= new XEngagement;
                $row->fill(['social_account_id' => $account->id, 'action' => $action, 'target_key' => $key,
                    'status' => 'running', 'requested_at' => now(),
                    'payload' => ['source' => 'companion', 'action' => $action, 'username' => strtolower($username)] + $destination])->save();
                app(AutomationJournal::class)->xCompanionReaction($row);

                return $row;
            });
        });
    }

    public function finishCompanionReaction(SocialAccount $account, string $username, int $id, array $outcome): XEngagement
    {
        return DB::transaction(function () use ($account, $username, $id, $outcome) {
            SocialAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $row = XEngagement::where('social_account_id', $account->id)->lockForUpdate()->findOrFail($id);
            abort_unless(($row->payload['source'] ?? null) === 'companion' && $row->payload['username'] === strtolower($username), 404);
            $confirmed = in_array($outcome['status'], ['confirmed', 'unchanged'], true);
            $proof = $outcome['proof'] ?? null;
            $follow = $row->action === 'follow';
            if ($follow) {
                $handle = $row->payload['handle'];
                abort_if($confirmed && (($outcome['handle'] ?? null) !== $handle || ($outcome['following'] ?? null) !== true || ! $proof),
                    422, 'Preuve d’abonnement absente ou portant sur un autre profil.');
                $target = ['profile' => ['handle' => $handle, 'following' => $confirmed ? true : null]];
            } else {
                $target = ['target_id' => $row->payload['target']['id']];
                abort_if($confirmed && ($outcome['target_id'] ?? null) !== $target['target_id'], 422, 'Preuve portant sur une autre publication.');
            }
            abort_if(in_array($row->action, ['bookmark', 'follow'], true) && $outcome['status'] === 'confirmed'
                && $proof !== 'after_reload', 422, 'Recharger la cible pour confirmer le geste.');
            $result = ['confirmed' => $confirmed, 'unchanged' => $outcome['status'] === 'unchanged', 'proof' => $proof] + $target;
            if ($row->status !== 'running') {
                abort_unless($row->result == $result, 409, 'Geste déjà clôturé.');

                return $row;
            }
            $row->update(['status' => $confirmed ? 'confirmed' : $outcome['status'], 'result' => $result,
                'error' => $outcome['error'] ?? null]);
            if ($confirmed && $row->action === 'like') {
                InboxItem::where('social_account_id', $account->id)->where('external_id', $result['target_id'])
                    ->whereNull('liked_at')->update(['liked_at' => now()]);
            }
            if ($follow && $outcome['status'] === 'confirmed') {
                $this->recordFollow($row, 'extension');
            }
            app(AutomationJournal::class)->xCompanionReaction($row);

            return $row;
        });
    }

    public function recordedLike(SocialAccount $account, string $tweetId): ?XEngagement
    {
        return XEngagement::where('social_account_id', $account->id)->where('action', 'like')
            ->where('target_key', hash('sha256', $tweetId))
            ->whereIn('status', ['queued', 'running', 'confirmed', 'uncertain'])->first();
    }

    public function assertLikeAvailable(SocialAccount $account, string $tweetId, ?int $ignoreEngagement = null): void
    {
        abort_if($this->blockedLikeIds($account, [$tweetId], $ignoreEngagement), 409,
            'J’aime déjà relevé, réservé ou incertain pour cette publication.');
    }

    /**
     * @param  list<string>  $tweetIds
     * @return list<string>
     */
    public function blockedLikeIds(SocialAccount $account, array $tweetIds, ?int $ignoreEngagement = null): array
    {
        if ($tweetIds === []) {
            return [];
        }

        $keys = array_combine(array_map(fn (string $id) => hash('sha256', $id), $tweetIds), $tweetIds);
        $reservations = XEngagement::where('social_account_id', $account->id)->where('action', 'like')
            ->whereIn('target_key', array_keys($keys))->where('status', '!=', 'prepared')
            ->when($ignoreEngagement, fn ($q) => $q->where('id', '!=', $ignoreEngagement))
            ->pluck('target_key')->map(fn ($key) => $keys[$key])->all();
        $liked = InboxItem::where('social_account_id', $account->id)->whereIn('external_id', $tweetIds)
            ->whereNotNull('liked_at')->pluck('external_id')->all();
        $pending = BrowserTask::where('social_account_id', $account->id)->where('kind', 'x.like')
            ->whereIn('status', ['pending', 'running', 'uncertain'])->whereIn('payload->target->id', $tweetIds)
            ->get(['payload'])->map(fn (BrowserTask $task) => $task->payload['target']['id'])->all();

        return array_values(array_intersect($tweetIds, [...$reservations, ...$liked, ...$pending]));
    }

    /** `$profile` : le Chrome du Mac pour un passage lancé à la main, sinon le Chromium du compte. */
    public function execute(XEngagement $row, ?string $profile = null): XEngagement
    {
        return DB::transaction(function () use ($row, $profile) {
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
            $task = BrowserTask::create(['social_account_id' => $account->id, 'profile' => $profile ?? $account->browser_profile,
                'kind' => 'x.agent.act', 'payload' => $payload]);
            $row->update(['status' => 'queued', 'browser_task_id' => $task->id, 'requested_at' => now()]);

            return $row;
        });
    }

    public function assertReplyAvailable(SocialAccount $account, string $tweetId, ?int $ignoreEngagement = null, ?int $ignoreInbox = null): void
    {
        abort_if($this->blockedReplyIds($account, [$tweetId], $ignoreEngagement, $ignoreInbox), 409,
            'Une réponse est déjà traitée, réservée ou incertaine pour ce post. Aucun nouvel envoi.');
    }

    /**
     * @param  list<string>  $tweetIds
     * @return list<string>
     */
    public function blockedReplyIds(SocialAccount $account, array $tweetIds, ?int $ignoreEngagement = null, ?int $ignoreInbox = null): array
    {
        if ($tweetIds === []) {
            return [];
        }

        $keys = [];
        foreach ($tweetIds as $id) {
            $keys[hash('sha256', $id)] = $id;
        }
        $extension = DB::table('extension_x_replies')->where('social_account_id', $account->id)
            ->whereIn('tweet_id', $tweetIds)->where('status', '!=', 'prepared')->pluck('tweet_id')->all();
        $inbox = InboxItem::where('social_account_id', $account->id)->whereIn('external_id', $tweetIds)
            ->when($ignoreInbox, fn ($q) => $q->where('id', '!=', $ignoreInbox))
            ->where(fn ($q) => $q->whereNotNull('replied_at')->orWhereNotNull('reply_external_id')->orWhereNotNull('reply_scheduled_at')
                ->orWhere('reply_attempts', '>', 0)->orWhereIn('status', ['replied', 'reply_failed']))
            ->pluck('external_id')->all();
        $reservations = XEngagement::where('social_account_id', $account->id)->where('action', 'reply')->whereIn('target_key', array_keys($keys))
            ->when($ignoreEngagement, fn ($q) => $q->where('id', '!=', $ignoreEngagement))->where('status', '!=', 'prepared')
            ->pluck('target_key')->map(fn ($key) => $keys[$key])->all();
        $pending = BrowserTask::where('social_account_id', $account->id)->where('kind', 'x.post')->whereIn('status', ['pending', 'running'])
            ->get(['payload'])->map(function (BrowserTask $task) {
                parse_str(parse_url($task->payload['url'] ?? '', PHP_URL_QUERY) ?? '', $query);

                return $query['in_reply_to'] ?? null;
            })->filter(fn ($id) => is_string($id))->all();

        return array_values(array_intersect($tweetIds, [...$extension, ...$inbox, ...$reservations, ...$pending]));
    }

    /** Écarter avant lecture les réponses déjà traitées ou différées pour ce compte. */
    public function replyPrefilterIds(SocialAccount $account, array $tweetIds): array
    {
        if ($tweetIds === []) {
            return [];
        }

        $review = app(InboxAgentReview::class);
        $reviewed = InboxItem::where('social_account_id', $account->id)->whereIn('external_id', $tweetIds)
            ->whereIn('type', ['comment', 'reply'])
            ->where(fn ($q) => $q->where(fn ($unavailable) => $unavailable->where('status', 'ignored')
                ->whereIn('agent_review_reason', ['x_account_suspended', 'x_post_deleted']))
                ->orWhereNot(fn ($eligible) => $review->eligible($eligible, now())))
            ->pluck('external_id')->all();

        $seen = array_keys(array_filter(\Illuminate\Support\Facades\Cache::many(
            array_map(fn ($id) => $this->ownReplyKey($account, $id), $tweetIds))));
        $seen = array_map(fn ($key) => substr($key, strrpos($key, ':') + 1), $seen);

        return array_values(array_intersect($tweetIds, [...$this->blockedReplyIds($account, $tweetIds), ...$reviewed, ...$seen]));
    }

    /** Une réponse du compte vue sous la cible, même postée hors RS-Max, interdit d’y répondre à nouveau. */
    public function rememberOwnReply(SocialAccount $account, array $result): void
    {
        $target = (string) ($result['target']['id'] ?? '');
        $own = strtolower((string) $account->browser_username);
        if ($own !== '' && ctype_digit($target) && collect($result['visible_replies'] ?? [])
            ->contains(fn ($p) => strtolower($p['author'] ?? '') === $own)) {
            \Illuminate\Support\Facades\Cache::put($this->ownReplyKey($account, $target), true, now()->addDays(15));
        }
    }

    /** Relire un fil déjà commenté et aimé par le compte n’apporte rien : aucun onglet n’est ouvert. */
    public function assertThreadWorthReading(SocialAccount $account, ?string $targetId): void
    {
        if (! $targetId || ! \Illuminate\Support\Facades\Cache::get($this->ownReplyKey($account, $targetId))) {
            return;
        }
        $like = app(AgentCatalog::class)->forAccount($account)->get('like');
        $likeDone = ! ($like['enabled'] ?? false) || $this->blockedLikeIds($account, [$targetId]) !== [];
        $uncertain = XEngagement::where('social_account_id', $account->id)->where('action', 'reply')
            ->where('target_key', hash('sha256', $targetId))->where('status', 'uncertain')->exists();
        if ($likeDone && ! $uncertain) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json(['message' => 'Fil déjà commenté et aimé par le compte : passer à la cible suivante.',
                'skip_item' => true, 'target_id' => $targetId], 409));
        }
    }

    private function ownReplyKey(SocialAccount $account, string $tweetId): string
    {
        return 'x-own-reply:'.$account->id.':'.$tweetId;
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
        if ($row->action === 'like') {
            $this->assertLikeAvailable($account, $row->payload['target']['id'], $row->id);
        }
        if ($row->action === 'unfollow') {
            $contact = SocialContact::where('social_account_id', $account->id)->where('handle', $row->payload['handle'])->first();
            $days = (int) ($account->agent_strategy['unfollow_after_days'] ?? 7);
            abort_unless($contact?->followed_at && $contact->followed_at->copy()->addDays($days)->isPast()
                && ! $contact->unfollowed_at && $contact->follow_back === 'no'
                && ($contact->metadata['follow_source'] ?? 'agent') === 'agent'
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
        if ($status === 'uncertain' && VerifyXEngagementReply::verifiable($task)) {
            VerifyXEngagementReply::dispatch($row->id)->delay(now()->addSeconds(45))->afterCommit();
        }
        if ($confirmed && in_array($row->action, ['like', 'unlike'], true)) {
            \App\Models\InboxItem::where('social_account_id', $row->social_account_id)->where('external_id', $expected['id'])
                ->update(['liked_at' => $row->action === 'like' ? now() : null]);
        }
        if ($confirmed && ! ($proof['unchanged'] ?? false) && in_array($row->action, ['follow', 'unfollow'], true)) {
            $this->recordFollow($row, 'agent');
        }

    }

    private function recordFollow(XEngagement $row, string $source): void
    {
        $contact = SocialContact::firstOrCreate(['social_account_id' => $row->social_account_id, 'handle' => $row->payload['handle']]);
        if ($row->action === 'follow') {
            $contact->update(['followed_at' => now(), 'unfollowed_at' => null, 'last_read_at' => now(),
                'metadata' => array_replace($contact->metadata ?? [], ['follow_source' => $source, 'x_following' => true])]);
        } else {
            $contact->update(['unfollowed_at' => now(),
                'metadata' => array_replace($contact->metadata ?? [], ['x_following' => false])]);
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
