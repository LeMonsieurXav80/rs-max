<?php

namespace App\Console\Commands;

use App\Models\BotActionLog;
use App\Models\SocialAccount;
use App\Services\Agent\AgentCatalog;
use App\Services\Agent\CommunityManagerWindow;
use App\Services\Agent\Feed\FeedEngagementPlanner;
use App\Services\Agent\Feed\FeedEngagementSettings;
use App\Services\Agent\XEngagementService;
use App\Services\Browser\BrowserException;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Console\Command;

/** Relevé à blanc de l'engagement du fil « Pour vous » : lit le fil réel, n'envoie rien. */
class XFeedEngageCommand extends Command
{
    protected $signature = 'x:feed-engage {account : Compte X en mode navigateur} {--json : Sortie JSON}';

    protected $description = 'Relevé à blanc : ce que le script aimerait, commenterait ou confierait au modèle dans le fil « Pour vous »';

    public function handle(BrowserTaskQueue $browser, FeedEngagementPlanner $planner, XEngagementService $engagements): int
    {
        $account = SocialAccount::with('platform')->find((int) $this->argument('account'));
        if (! $account || $account->platform?->slug !== 'twitter' || ! $account->usesBrowser()) {
            $this->error('Compte X en mode navigateur introuvable.');

            return self::FAILURE;
        }
        $window = app(CommunityManagerWindow::class);
        if (! $window->isOpen()) {
            $this->error('Lecture X autorisée de 06 h à 22 h à Lisbonne.');

            return self::FAILURE;
        }
        $read = app(AgentCatalog::class)->forAccount($account)->get('feed.read');
        if (! $read || $read['status'] !== 'ready' || ! $read['enabled'] || $read['left'] < 1) {
            $this->error('Lecture du fil désactivée ou quota du jour atteint pour ce compte.');

            return self::FAILURE;
        }

        $settings = FeedEngagementSettings::for($account);
        $payload = ['url' => 'https://x.com/home', 'action' => 'feed.read', 'username' => $account->browser_username,
            'limit' => $settings['read_limit'], 'screens' => 12, 'feed' => 'foryou', 'handle' => '', 'target_id' => null,
            'activity_window' => $window->specification()];
        try {
            $result = $browser->run($account, 'x.agent.read', $payload, 110);
        } catch (BrowserException $e) {
            $this->error('Lecture du fil impossible : '.$e->getMessage());

            return self::FAILURE;
        }
        $engagements->rememberOwnReply($account, $result);
        $plan = $planner->plan($account, $result['items'] ?? []);

        BotActionLog::create(['social_account_id' => $account->id, 'action_type' => 'agent.feed.read', 'source' => AgentCatalog::SOURCE,
            'target_uri' => $payload['url'], 'success' => true, 'performed_at' => now(),
            'metadata' => ['origin' => 'script', 'dry_run' => true, 'summary' => $plan['summary']]]);

        if ($this->option('json')) {
            $this->line(json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }
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
        $this->info("Relevé à blanc, rien n'a été envoyé : {$s['read']} lues, {$s['retained']} retenues, {$s['likes']} likes, {$s['spin']} spintax, {$s['llm']} pour le modèle.");

        return self::SUCCESS;
    }
}
