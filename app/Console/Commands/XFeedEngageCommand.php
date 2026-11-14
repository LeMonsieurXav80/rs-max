<?php

namespace App\Console\Commands;

use App\Models\BotActionLog;
use App\Models\SocialAccount;
use App\Services\Agent\AgentCatalog;
use App\Services\Agent\CommunityManagerWindow;
use App\Services\Agent\Feed\FeedEngagementExecutor;
use App\Services\Agent\Feed\FeedEngagementPlanner;
use App\Services\Agent\Feed\FeedEngagementSettings;
use App\Services\Agent\XEngagementService;
use App\Services\Browser\BrowserException;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Engagement du fil « Pour vous » par script : relevé à blanc par défaut, exécution avec --commit.
 * Voir docs/flux-engagement-scripte.md.
 */
class XFeedEngageCommand extends Command
{
    protected $signature = 'x:feed-engage {account? : Compte X en mode navigateur} {--all : Tous les comptes dont l’engagement est allumé}
        {--commit : Aimer et commenter pour de vrai (interrupteur du compte requis)} {--json : Sortie JSON}';

    protected $description = 'Lit le fil « Pour vous » et décide sans modèle de langue des likes et commentaires spintax';

    public function handle(BrowserTaskQueue $browser, FeedEngagementPlanner $planner, FeedEngagementExecutor $executor, XEngagementService $engagements): int
    {
        $query = SocialAccount::with('platform')->where('driver', 'browser')->whereHas('platform', fn ($q) => $q->where('slug', 'twitter'));
        $accounts = $this->option('all') ? $query->get()->filter(fn ($a) => FeedEngagementSettings::for($a)['enabled'])
            : $query->whereKey((int) $this->argument('account'))->get();
        if ($accounts->isEmpty()) {
            if (! $this->option('all')) {
                $this->error('Compte X en mode navigateur introuvable.');
            }

            return $this->option('all') ? self::SUCCESS : self::FAILURE;
        }
        $status = self::SUCCESS;
        foreach ($accounts as $account) {
            $status = max($status, $this->engage($account, $browser, $planner, $executor, $engagements));
        }

        return $status;
    }

    private function engage(SocialAccount $account, BrowserTaskQueue $browser, FeedEngagementPlanner $planner, FeedEngagementExecutor $executor, XEngagementService $engagements): int
    {
        $commit = (bool) $this->option('commit');
        $settings = FeedEngagementSettings::for($account);
        $window = app(CommunityManagerWindow::class);
        $refusal = match (true) {
            $commit && ! $settings['enabled'] => 'Engagement du fil coupé sur la fiche du compte : relevé à blanc seulement.',
            ! $window->isOpen() => 'Lecture X autorisée de 06 h à 22 h à Lisbonne.',
            $account->browser_session_status !== 'connected' => 'Session X du navigateur non connectée.',
            default => null,
        };
        $read = app(AgentCatalog::class)->forAccount($account)->get('feed.read');
        if ($refusal === null && (! $read || $read['status'] !== 'ready' || ! $read['enabled'] || $read['left'] < 1)) {
            $refusal = 'Lecture du fil désactivée ou quota du jour atteint pour ce compte.';
        }
        if ($refusal !== null) {
            $this->warn("{$account->name} : $refusal");

            return $this->option('all') ? self::SUCCESS : self::FAILURE;
        }

        $payload = ['url' => 'https://x.com/home', 'action' => 'feed.read', 'username' => $account->browser_username,
            'limit' => $settings['read_limit'], 'screens' => 12, 'feed' => 'foryou', 'handle' => '', 'target_id' => null,
            'activity_window' => $window->specification()];
        try {
            $result = $browser->run($account, 'x.agent.read', $payload, 110);
        } catch (BrowserException $e) {
            $this->error("{$account->name} : lecture du fil impossible : ".$e->getMessage());

            return self::FAILURE;
        }
        $engagements->rememberOwnReply($account, $result);
        $snapshot = (string) Str::uuid();
        Cache::put('x-agent-snapshot:'.$snapshot, ['account_id' => $account->id, 'result' => $result, 'source' => $payload,
            'read_at' => now()->toIso8601String()], now()->addHour());
        $items = $result['items'] ?? [];
        $plan = $planner->plan($account, $items, $executor->usedToday($account));

        $run = $commit ? $executor->run($account, $plan, collect($items)->keyBy(fn ($i) => (string) ($i['id'] ?? ''))->all(), $snapshot, 'server')
            : ['done' => [], 'requested' => 0, 'stopped' => null];
        BotActionLog::create(['social_account_id' => $account->id, 'action_type' => 'agent.feed.read', 'source' => AgentCatalog::SOURCE,
            'target_uri' => $payload['url'], 'success' => true, 'performed_at' => now(),
            'metadata' => ['origin' => 'script', 'trigger' => 'server', 'dry_run' => ! $commit, 'summary' => $plan['summary'],
                'done' => count($run['done']), 'requested' => $run['requested'], 'stopped' => $run['stopped']]]);

        if ($this->option('json')) {
            $this->line(json_encode($plan + ['run' => $run], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }
        $this->line("== {$account->name}");
        foreach ($plan['items'] as $d) {
            $actions = array_filter([$d['like'] ? 'like' : null,
                $d['comment'] === 'spin' ? 'spintax « '.$d['comment_text'].' »' : null,
                $d['comment'] === 'llm' ? 'rédaction par le modèle' : null]);
            $this->line(sprintf('%s @%s [%s] %s', $actions ? '✓' : '·', $d['author'], $d['language'], $actions ? implode(' + ', $actions) : 'rien'));
            $this->line('    '.str_replace("\n", ' ', $d['text']));
            if ($d['hits'] || $d['reasons']) {
                $this->line('    '.implode(' · ', array_filter([$d['hits'] ? 'mots-clés : '.implode(', ', $d['hits']) : null, ...$d['reasons']])));
            }
        }
        $s = $plan['summary'];
        if ($commit) {
            $confirmed = collect($run['done'])->where('status', 'confirmed')->count();
            $this->info("{$s['read']} lues, {$s['retained']} retenues : ".count($run['done'])." gestes demandés, $confirmed confirmés"
                .($run['stopped'] ? ' ; arrêt : '.$run['stopped'] : '').", {$run['requested']} rédactions demandées à Hermès.");
        } else {
            $this->info("Relevé à blanc, rien n'a été envoyé : {$s['read']} lues, {$s['retained']} retenues, {$s['likes']} likes, {$s['spin']} spintax, {$s['llm']} pour le modèle.");
        }

        return self::SUCCESS;
    }
}
