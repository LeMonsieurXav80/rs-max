<?php

namespace App\Services\Agent;

use App\Models\BrowserTask;
use App\Models\MetaEngagement;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Un ordre par passage, sans modèle ; les gestes suivent le même circuit que Companion local. */
class InstagramCommentLikes
{
    public const ORIGIN = 'instagram_comment_likes';

    public function tick(SocialAccount $account, bool $commit = false): array
    {
        $lock = Cache::lock(self::ORIGIN.':'.$account->browser_profile, 30);
        if (! $lock->get()) {
            return ['status' => 'busy'];
        }
        try {
            return $this->next($account->fresh(['platform']), $commit);
        } finally {
            $lock->release();
        }
    }

    private function next(SocialAccount $account, bool $commit): array
    {
        if ($account->platform->slug !== 'instagram' || ! $account->usesBrowser()
            || $account->browser_session_status !== 'connected' || ! $account->browser_profile
            || ! ($account->agent_strategy['instagram_comment_likes_enabled'] ?? false)
            || ! app(CommunityManagerWindow::class)->isOpen()) {
            return ['status' => 'disabled'];
        }
        if ($until = app(MetaBrowserAgent::class)->platformPauseUntil($account)) {
            return ['status' => 'platform_pause', 'until' => $until];
        }
        $catalog = app(AgentCatalog::class)->forAccount($account);
        foreach (['search.read', 'thread.read', 'comment.like'] as $action) {
            $state = $catalog->get($action);
            if (! $state || ! $state['enabled'] || $state['left'] < 1) {
                return ['status' => 'quota_or_disabled', 'action' => $action];
            }
        }
        $usage = app(AccountActionBudget::class)->usage($account);
        if ($usage['blocked'] || $usage['next_allowed_at']) {
            return ['status' => 'cadence', 'next_allowed_at' => $usage['next_allowed_at']];
        }
        if (BrowserTask::where(function ($q) use ($account) {
            $q->where('profile', $account->browser_profile)->orWhere('social_account_id', $account->id);
        })->whereIn('status', ['pending', 'running'])->exists()) {
            return ['status' => 'busy'];
        }
        $tasks = BrowserTask::where('social_account_id', $account->id)->where('profile', $account->browser_profile)
            ->where('payload->origin', self::ORIGIN);
        if ((clone $tasks)->whereIn('status', ['failed', 'uncertain'])->where('finished_at', '>', now()->subMinutes(15))->exists()) {
            return ['status' => 'incident_pause'];
        }
        $reads = (clone $tasks)->where('kind', 'instagram.agent.read')->where('payload->action', 'thread.read')
            ->where('status', 'done')->where('finished_at', '>', now()->subMinutes(10))->latest('id')->get();
        foreach ($reads as $read) {
            $url = $read->result['target']['url'] ?? '';
            if (($read->result['account_id'] ?? '') !== (string) $account->platform_account_id || ! $url
                || $this->excluded($account, $read->result['target'] ?? [])) {
                continue;
            }
            // Cinq commentaires au plus sur une publication et par jour, gestes locaux compris.
            if (MetaEngagement::where('social_account_id', $account->id)->where('action', 'comment.like')
                ->where('payload->url', $url)->where('created_at', '>=', app(AccountActionBudget::class)->dayStart())->count() >= 5) {
                continue;
            }
            foreach ($read->result['comments'] ?? [] as $comment) {
                if (($comment['liked'] ?? null) !== false || ! filled($comment['id'] ?? null)
                    || ! filled($comment['author'] ?? null) || $this->excluded($account, $comment)
                    || strtolower($comment['author']) === strtolower($read->result['target']['author'] ?? '')
                    || in_array(strtolower($comment['author']), [strtolower($account->browser_username), (string) $account->platform_account_id], true)
                    || MetaEngagement::where('social_account_id', $account->id)->where('action', 'comment.like')
                        ->where('target_key', hash('sha256', 'comment:'.$comment['id']))->exists()) {
                    continue;
                }
                $plan = ['status' => 'planned', 'action' => 'comment.like', 'read_task_id' => $read->id, 'target_id' => $comment['id']];
                if (! $commit) {
                    return $plan;
                }
                $result = app(MetaBrowserAgent::class)->submit($account, $read, ['action' => 'comment.like',
                    'target_id' => $comment['id'], 'dry_run' => false, 'request_key' => (string) Str::uuid()]);
                if (! ($result['duplicate'] ?? false)) {
                    $this->tag(BrowserTask::findOrFail($result['task_id']));
                }

                return $result;
            }
        }
        $hashtags = array_values(array_filter(array_map(fn ($v) => ltrim($v, '#'), $account->agent_strategy['hashtags'] ?? []),
            fn ($v) => preg_match('/^[\p{L}\p{N}_]{1,100}$/uD', $v)));
        if (! $hashtags) {
            return ['status' => 'no_hashtags'];
        }
        $search = (clone $tasks)->where('kind', 'instagram.agent.read')->where('payload->action', 'search.read')
            ->where('status', 'done')->where('finished_at', '>', now()->subHour())->latest('id')->first();
        if ($search && ($search->result['account_id'] ?? '') === (string) $account->platform_account_id) {
            foreach ($search->result['items'] ?? [] as $item) {
                if (empty($item['url']) || (clone $tasks)->where('payload->action', 'thread.read')->where('payload->url', $item['url'])
                    ->where('created_at', '>', now()->subDay())->exists()) {
                    continue;
                }

                return $this->read($account, ['action' => 'thread.read', 'url' => $item['url'], 'limit' => 30], $commit);
            }
        }
        $lastSearch = (clone $tasks)->where('payload->action', 'search.read')->latest('id')->first();
        if ($lastSearch?->created_at->gt(now()->subMinutes(15))) {
            return ['status' => 'discovery_wait'];
        }
        $n = (clone $tasks)->where('payload->action', 'search.read')->count();

        return $this->read($account, ['action' => 'search.read', 'hashtag' => $hashtags[$n % count($hashtags)], 'limit' => 12], $commit);
    }

    private function excluded(SocialAccount $account, array $target): bool
    {
        $text = Str::lower(($target['author'] ?? '').' '.($target['text'] ?? ''));
        foreach ($account->agent_strategy['exclusions'] ?? [] as $word) {
            if (trim($word) !== '' && Str::contains($text, Str::lower(trim($word, " @#\t\n\r")))) {
                return true;
            }
        }

        return false;
    }

    private function read(SocialAccount $account, array $data, bool $commit): array
    {
        if (! $commit) {
            return ['status' => 'planned'] + $data;
        }
        $task = app(MetaBrowserAgent::class)->read($account, $data + ['origin' => self::ORIGIN]);

        return ['task_id' => $task->id, 'status' => $task->status, 'action' => $data['action']];
    }

    private function tag(BrowserTask $task): void
    {
        $task->update(['payload' => $task->payload + ['origin' => self::ORIGIN]]);
    }
}
