<?php

namespace App\Services\Threads;

use App\Models\BrowserTask;
use App\Models\SocialAccount;
use App\Services\Agent\AgentCatalog;
use App\Services\Agent\ApiInboxActions;
use App\Services\Agent\CommentFreshness;
use App\Services\Agent\CommunityManagerWindow;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Découverte et interactions Threads hors inbox ; l'inbox conserve son API. */
class ThreadsAgent
{
    public function canonical(string $url): string
    {
        $p = parse_url($url);
        abort_unless(($p['scheme'] ?? '') === 'https' && in_array($p['host'] ?? '', ['www.threads.com', 'threads.com', 'www.threads.net', 'threads.net'], true) && preg_match('~^/@([\w.]+)/post/([\w-]+)/?$~', $p['path'] ?? '', $m), 422, 'Lien Threads natif requis.');

        return 'https://www.threads.com/@'.$m[1].'/post/'.$m[2];
    }

    public function ready(SocialAccount $a): void
    {
        abort_unless($a->browser_profile && $a->browser_username && $a->browser_session_status === 'connected', 422, 'Session Companion Threads sur le NAS requise. Les réponses API restent disponibles.');
        app(CommunityManagerWindow::class)->assertOpen();
    }

    public function read(SocialAccount $a, array $d): array
    {
        $this->ready($a);
        app(ApiInboxActions::class)->assertEnabled($a, $d['action']);
        $url = match ($d['action']) {
            'feed.read' => 'https://www.threads.com/following/',
            'search.read' => 'https://www.threads.com/search?q='.rawurlencode($d['query'] ?? '').'&serp_type=default',
            'thread.read' => $this->canonical($d['url'] ?? ''),
            'profile.read' => 'https://www.threads.com/@'.ltrim($d['handle'] ?? '', '@'),
        };
        $payload = array_merge($d, ['url' => $url, 'username' => $a->browser_username, 'activity_window' => app(CommunityManagerWindow::class)->specification()]);
        $result = app(BrowserTaskQueue::class)->run($a, 'threads.agent.read', $payload, 110);
        $id = (string) Str::uuid();
        Cache::put('threads-snapshot:'.$id, ['account_id' => $a->id, 'read_action' => $d['action'], 'result' => $result], now()->addMinutes(15));
        \App\Models\BotActionLog::create(['social_account_id' => $a->id, 'source' => 'agent', 'action_type' => app(AgentCatalog::class)->logType($d['action']), 'target_uri' => $url, 'success' => true, 'performed_at' => now()]);

        return $result + ['snapshot_id' => $id];
    }

    public function snapshot(SocialAccount $a, string $id): array
    {
        $s = Cache::get('threads-snapshot:'.$id);
        abort_unless($s && $s['account_id'] === $a->id, 422, 'Lecture Threads expirée ou autre compte.');

        return $s;
    }

    public function context(SocialAccount $a, string $id): array
    {
        $s = $this->snapshot($a, $id);
        abort_unless($s['read_action'] === 'thread.read' && ($s['result']['context_complete'] ?? false), 422, 'Lire d’abord le fil Threads ciblé.');
        abort_unless(CommentFreshness::allowsPublishedAt($s['result']['target']['published_at'] ?? null), 409, CommentFreshness::REJECTION);
        abort_unless(filled($s['result']['target']['text'] ?? null), 422, 'Texte de la publication indisponible.');
        abort_unless($a->persona?->is_active && filled($a->persona->system_prompt), 422, 'Persona actif requis.');
        $ctx = (string) Str::uuid();
        Cache::put('threads-context:'.$ctx, ['account_id' => $a->id, 'snapshot_id' => $id, 'persona_hash' => \App\Models\TikTokEngagement::personaHash($a)], now()->addMinutes(15));

        return ['context_id' => $ctx, 'target' => $s['result']['target'], 'conversation' => $s['result']['conversation'] ?? [], 'persona' => ['instructions' => $a->persona->system_prompt], 'context_complete' => true, 'discussion_complete' => $s['result']['complete'] ?? false,
            'instruction' => 'Lire la publication et le fil avant de rédiger. Si un média non consulté est nécessaire à la compréhension, passer au suivant.'];
    }

    public function submit(SocialAccount $a, array $d): array
    {
        $this->ready($a);
        app(ApiInboxActions::class)->assertEnabled($a, $d['action']);
        $s = $this->snapshot($a, $d['snapshot_id']);
        $targets = isset($s['result']['target']) ? [$s['result']['target']] : ($s['result']['items'] ?? []);
        abort_if(count($targets) > 1 && empty($d['target_id']), 422, 'Choisir explicitement une cible parmi les résultats.');
        $targets = array_values(array_filter($targets, fn ($t) => $t['id'] === ($d['target_id'] ?? $targets[0]['id'] ?? null)));
        abort_unless(count($targets) === 1, 422, 'Cible Threads absente ou ambiguë.');
        $target = $targets[0];
        $target['url'] = $this->canonical($target['url']);
        abort_if(strtolower($target['author']) === strtolower($a->browser_username), 422, 'Aucune interaction automatique avec le compte lui-même.');
        if ($d['action'] === 'comment') {
            abort_unless(CommentFreshness::allowsPublishedAt($target['published_at'] ?? null), 409, CommentFreshness::REJECTION);
            $ctx = Cache::get('threads-context:'.($d['context_id'] ?? ''));
            abort_unless($ctx && $ctx['account_id'] === $a->id && $ctx['snapshot_id'] === $d['snapshot_id'] && $a->persona?->is_active && $ctx['persona_hash'] === \App\Models\TikTokEngagement::personaHash($a), 409, 'Contexte ou persona modifié.');
            abort_unless($s['read_action'] === 'thread.read' && ! empty(trim($d['text'] ?? '')), 422, 'Texte et fil complets requis.');
        }
        $payload = ['action' => $d['action'], 'target' => $target, 'url' => $target['url'], 'username' => $a->browser_username, 'text' => $d['text'] ?? null, 'activity_window' => app(CommunityManagerWindow::class)->specification(), 'deadline' => now()->addMinutes(15)->toIso8601String()];
        if ($d['dry_run'] ?? true) {
            return ['dry_run' => true, 'plan' => $payload];
        }

        return DB::transaction(function () use ($a, $d, $payload) {
            SocialAccount::whereKey($a->id)->lockForUpdate()->firstOrFail();
            $key = 'threads:'.$d['request_key'];
            $old = BrowserTask::where('social_account_id', $a->id)->where('idempotency_key', $key)->first();
            if ($old) {
                abort_unless(array_intersect_key($old->payload, array_flip(['action', 'target', 'text'])) === array_intersect_key($payload, array_flip(['action', 'target', 'text'])), 409, 'Clé déjà utilisée pour une autre intention.');

                return $this->state($old);
            }
            $prior = BrowserTask::where('social_account_id', $a->id)->where('kind', 'threads.agent.act')->where('payload->action', $d['action'])->where('payload->target->url', $payload['url'])->whereIn('status', ['pending', 'running', 'done', 'uncertain'])->first();
            if ($prior) {
                return $this->state($prior);
            }

            return $this->state(BrowserTask::create(['social_account_id' => $a->id, 'profile' => $a->browser_profile, 'kind' => 'threads.agent.act', 'status' => 'pending', 'idempotency_key' => $key, 'payload' => $payload]));
        });
    }

    public function claim(BrowserTask $t): void
    {
        if ($t->kind !== 'threads.agent.act') {
            return;
        }$a = $t->socialAccount;
        $this->ready($a);
        app(ApiInboxActions::class)->assertEnabled($a, $t->payload['action']);
        abort_unless($a->browser_profile === $t->profile && $a->browser_username === $t->payload['username'] && now()->lte($t->payload['deadline']), 409, 'Identité Threads ou délai modifié.');
        if ($t->payload['action'] === 'comment') {
            abort_unless(CommentFreshness::allowsPublishedAt($t->payload['target']['published_at'] ?? null), 409, CommentFreshness::REJECTION);
        }
    }

    public function authorize(BrowserTask $t): void
    {
        abort_unless($t->kind === 'threads.agent.act' && $t->status === 'running', 409);
        $a = $t->socialAccount;
        $this->ready($a);
        $setting = app(AgentCatalog::class)->forAccount($a)->get($t->payload['action']);
        abort_unless($setting && $setting['enabled'] && $a->browser_profile === $t->profile && $a->browser_username === $t->payload['username'] && now()->lte($t->payload['deadline']), 409, 'Autorisation Threads modifiée.');
        if ($t->payload['action'] === 'comment') {
            abort_unless(CommentFreshness::allowsPublishedAt($t->payload['target']['published_at'] ?? null), 409, CommentFreshness::REJECTION);
        }
        abort_if(app(\App\Services\Agent\AccountActionBudget::class)->usage($a)['blocked'], 429, 'Compte en pause : aucun geste Threads.');
    }

    public function checkpoint(BrowserTask $t, array $r): void
    {
        abort_unless($t->kind === 'threads.agent.act' && $t->status === 'running' && ($r['clicked'] ?? false) === true, 409);
        abort_unless(($r['username'] ?? null) === $t->payload['username'] && ($r['target_url'] ?? null) === $t->payload['url'], 409, 'Reçu Threads différent.');
        $allowed = array_intersect_key($r, array_flip(['clicked', 'post_url', 'post_id', 'target_url', 'started_at', 'username']));
        if (isset($allowed['post_url'])) {
            $allowed['post_url'] = $this->canonical($allowed['post_url']);
        }
        $t->update(['result' => $allowed]);
    }

    public function finish(BrowserTask $t): void
    {
        if ($t->kind !== 'threads.agent.act' || $t->status !== 'done') {
            return;
        }$r = $t->result ?? [];
        $validAction = ($r['action'] ?? null) === $t->payload['action'] && ($t->payload['action'] === 'like' ? (($r['liked'] ?? null) === true) : (($r['text'] ?? null) === $t->payload['text'] && preg_match('~^https://www\.threads\.com/@'.preg_quote($t->payload['username'], '~').'/post/[\w-]+$~', $r['post_url'] ?? '')));
        if (! $validAction || ($r['confirmed'] ?? false) !== true || ($r['proof'] ?? null) !== 'after_reload' || ($r['username'] ?? null) !== $t->payload['username'] || ($r['target_url'] ?? null) !== $t->payload['url']) {
            $t->update(['status' => 'uncertain', 'error' => 'Preuve Threads après rechargement absente : aucun renvoi.']);
        }
    }

    public function verify(BrowserTask $t, bool $dry = true): array
    {
        abort_unless($t->kind === 'threads.agent.act' && in_array($t->status, ['done', 'uncertain'], true) && $t->claimed_at, 409, 'Aucun geste Threads à vérifier.');
        $this->ready($t->socialAccount);
        $p = array_merge($t->payload, ['post_url' => $t->result['post_url'] ?? null, 'started_at' => $t->result['started_at'] ?? $t->claimed_at->getTimestampMs(), 'activity_window' => app(CommunityManagerWindow::class)->specification(), 'deadline' => now()->addMinutes(5)->toIso8601String()]);
        $r = app(BrowserTaskQueue::class)->run($t->socialAccount, 'threads.agent.check', $p, 120);
        if ($dry || ($r['confirmed'] ?? false) !== true) {
            return ['dry_run' => $dry, 'result' => $r, 'sent' => false];
        }
        $t->update(['status' => 'done', 'result' => array_merge($t->result ?? [], $r), 'error' => null]);
        $this->finish($t);
        app(\App\Services\Agent\AccountActionBudget::class)->finish($t);

        return $this->state($t) + ['sent' => false];
    }

    public function state(BrowserTask $t): array
    {
        if (in_array($t->status, ['pending', 'running'], true) && now()->greaterThan(\Illuminate\Support\Carbon::parse($t->payload['deadline'])->addMinutes($t->status === 'running' ? 3 : 0))) {
            $t->update(['status' => $t->status === 'pending' ? 'cancelled' : 'uncertain', 'finished_at' => now(), 'error' => 'Délai Threads expiré : contrôler sans renvoyer.']);
            app(\App\Services\Agent\AccountActionBudget::class)->finish($t);
        }

        return ['id' => $t->id, 'status' => $t->status, 'confirmed' => $t->status === 'done' && ($t->result['confirmed'] ?? false), 'result' => $t->result, 'error' => $t->error];
    }
}
