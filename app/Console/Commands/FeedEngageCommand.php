<?php

namespace App\Console\Commands;

use App\Models\BotActionLog;
use App\Models\SocialAccount;
use App\Services\Agent\AgentCatalog;
use App\Services\Agent\CommunityManagerWindow;
use App\Services\Agent\Feed\FeedEngagementExecutor;
use App\Services\Agent\Feed\FeedEngagementPlanner;
use App\Services\Agent\Feed\FeedEngagementSettings;
use App\Services\Browser\BrowserException;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Engagement du fil « Pour vous » par script, commun à X et Threads : relevé à blanc par défaut,
 * exécution avec --commit. Voir docs/flux-engagement-scripte.md.
 */
abstract class FeedEngageCommand extends Command
{
    /** Nom du réseau dans les messages. */
    abstract protected function network(): string;

    abstract protected function accounts(): Builder;

    /** Motif de refus propre au réseau (session, routage), ou `null`. */
    abstract protected function unavailable(SocialAccount $account): ?string;

    /** @return array{0: string, 1: array<string, mixed>} type de tâche Companion et sa charge */
    abstract protected function readTask(SocialAccount $account, array $settings): array;

    /** Garde la lecture pour les gestes qui suivent ; renvoie l'identifiant de l'instantané. */
    abstract protected function remember(SocialAccount $account, array $payload, array $result): string;

    public function handle(BrowserTaskQueue $browser, FeedEngagementPlanner $planner): int
    {
        $accounts = $this->option('all') ? $this->accounts()->get()->filter(fn ($a) => FeedEngagementSettings::for($a)['enabled'])
            : $this->accounts()->whereKey((int) $this->argument('account'))->get();
        if ($accounts->isEmpty()) {
            if (! $this->option('all')) {
                $this->error('Compte '.$this->network().' introuvable ou sans navigateur.');
            }

            return $this->option('all') ? self::SUCCESS : self::FAILURE;
        }
        $status = self::SUCCESS;
        foreach ($accounts as $account) {
            $status = max($status, $this->engage($account, $browser, $planner));
        }

        return $status;
    }

    private function engage(SocialAccount $account, BrowserTaskQueue $browser, FeedEngagementPlanner $planner): int
    {
        $commit = (bool) $this->option('commit');
        $settings = FeedEngagementSettings::for($account);
        $refusal = match (true) {
            $commit && ! $settings['enabled'] => 'Engagement du fil coupé sur la fiche du compte : relevé à blanc seulement.',
            ! app(CommunityManagerWindow::class)->isOpen() => 'Lecture '.$this->network().' autorisée de 06 h à 22 h à Lisbonne.',
            default => $this->unavailable($account),
        };
        $read = app(AgentCatalog::class)->forAccount($account)->get('feed.read');
        if ($refusal === null && (! $read || $read['status'] !== 'ready' || ! $read['enabled'] || $read['left'] < 1)) {
            $refusal = 'Lecture du fil désactivée ou quota du jour atteint pour ce compte.';
        }
        if ($refusal !== null) {
            $this->warn("{$account->name} : $refusal");

            return $this->option('all') ? self::SUCCESS : self::FAILURE;
        }

        [$kind, $payload] = $this->readTask($account, $settings);
        try {
            $result = $browser->run($account, $kind, $payload, 110);
        } catch (BrowserException $e) {
            $this->error("{$account->name} : lecture du fil impossible : ".$e->getMessage());

            return self::FAILURE;
        }
        $snapshot = $this->remember($account, $payload, $result);
        $items = $result['items'] ?? [];
        $executor = app(FeedEngagementExecutor::class);
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

    protected function snapshotId(): string
    {
        return (string) Str::uuid();
    }
}
