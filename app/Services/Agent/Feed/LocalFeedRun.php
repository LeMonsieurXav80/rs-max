<?php

namespace App\Services\Agent\Feed;

use App\Models\BotActionLog;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Agent\AgentCatalog;
use App\Services\Agent\CommunityManagerWindow;
use App\Services\Browser\BrowserException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * « Engager mon fil » lancé à la main depuis Companion sur le Mac, pour X et Threads : Companion lit le fil dans le
 * Chrome de Xavier, RS-Max décide avec les mêmes règles, plafonds et journal que le passage du NAS
 * (`trigger=local`), puis crée les gestes un par un. Chaque geste est une tâche normale (`x.agent.act`,
 * `threads.agent.act`) sur le profil `mac-local`, qu'aucun worker ne prend : le Mac la prend par son identifiant,
 * l'exécute et la clôt par les routes habituelles. Le Mac n'exécute donc que ce que Xavier lance.
 * Les rédactions confiées au modèle rejoignent la file qu'Hermès traite.
 */
class LocalFeedRun
{
    public const PROFILE = 'mac-local';

    public const TRIGGER = 'local';

    public const MAX_ITEMS = 40;

    private const TTL_MINUTES = 90;

    public function __construct(private FeedEngagementPlanner $planner, private FeedEngagementExecutor $executor) {}

    public function account(User $user, string $platform, string $username): SocialAccount
    {
        $slug = $platform === 'x' ? 'twitter' : $platform;
        $account = $user->connectedSocialAccounts()->with(['platform', 'persona'])
            ->whereHas('platform', fn ($q) => $q->where('slug', $slug))
            ->whereRaw('LOWER(browser_username) = ?', [strtolower(ltrim($username, '@'))])->first();
        abort_unless($account, 404, 'Aucun compte RS-Max pour @'.ltrim($username, '@').' sur ce réseau.');
        abort_unless($slug === 'threads' ? filled($account->browser_profile) : $account->usesBrowser(), 422,
            'Compte sans navigateur Companion : rien à engager.');

        return $account;
    }

    /** Décide sur la lecture du Mac ; avec `$commit`, ouvre un passage dont `next()` livre les gestes un par un. */
    public function start(SocialAccount $account, array $items, bool $commit): array
    {
        $settings = FeedEngagementSettings::for($account);
        abort_if($commit && ! $settings['enabled'], 403, 'Engagement du fil coupé sur la fiche du compte : relevé à blanc seulement.');
        abort_unless(app(CommunityManagerWindow::class)->isOpen(), 423, 'Community Manager travaille de 06 h à 22 h à Lisbonne.');
        $read = app(AgentCatalog::class)->forAccount($account)->get('feed.read');
        abort_unless($read && $read['status'] === 'ready' && $read['enabled'] && $read['left'] > 0, 403,
            'Lecture du fil désactivée ou quota du jour atteint pour ce compte.');

        $items = array_slice(array_values($items), 0, self::MAX_ITEMS);
        $plan = $this->planner->plan($account, $items, $this->executor->usedToday($account));
        $run = (string) Str::uuid();
        $log = BotActionLog::create(['social_account_id' => $account->id, 'action_type' => 'agent.feed.read', 'source' => AgentCatalog::SOURCE,
            'target_uri' => $account->platform->slug === 'threads' ? 'https://www.threads.com/' : 'https://x.com/home',
            'success' => true, 'performed_at' => now(),
            'metadata' => ['origin' => 'script', 'trigger' => self::TRIGGER, 'dry_run' => ! $commit, 'summary' => $plan['summary'],
                'done' => 0, 'requested' => 0, 'stopped' => null, 'run' => $run]]);
        if (! $commit) {
            return ['dry_run' => true, 'plan' => $plan];
        }

        $steps = array_map(function (array $step) {
            if (isset($step['receipt'])) {
                $step['receipt'] = ['model' => $step['receipt']::class, 'id' => $step['receipt']->id];
            }

            return $step;
        }, $this->executor->steps($account, $plan));
        $this->save($run, ['account_id' => $account->id, 'log_id' => $log->id, 'snapshot' => $run, 'steps' => $steps, 'cursor' => 0,
            'posts' => collect($items)->keyBy(fn ($i) => (string) ($i['id'] ?? ''))->all(), 'done' => [], 'requested' => 0,
            'skip' => [], 'current' => null]);

        return ['dry_run' => false, 'plan' => $plan, 'run' => $run];
    }

    /**
     * Étape suivante : clôt le geste précédent d'après sa tâche, puis renvoie le geste à exécuter (`task`),
     * une attente de cadence (`wait`), un geste encore en cours (`pending`) ou le bilan (`done`). Avec `$stop`
     * (bouton Stop de Companion), clôt le geste précédent et termine sans en créer d'autre.
     */
    public function next(SocialAccount $account, string $run, bool $stop = false): array
    {
        return Cache::lock('feed-local-run-lock:'.$run, 60)->block(10, function () use ($account, $run, $stop) {
            $state = Cache::get('feed-local-run:'.$run);
            abort_unless($state && $state['account_id'] === $account->id, 410, 'Passage expiré ou autre compte : relancer la recette.');
            $network = FeedNetworks::for($account);
            $max = FeedEngagementSettings::for($account)['pass_max_actions'];

            if ($state['current']) {
                $step = $state['steps'][$state['current']['step']];
                $row = $network->refresh($state['current']['model']::findOrFail($state['current']['id']));
                if (in_array($row->status, ['queued', 'running'], true)) {
                    // Le Mac n'a pas encore clos la tâche : attendre son issue, jamais la recréer.
                    return ['state' => 'pending', 'task_id' => $row->browser_task_id];
                }
                $state['done'][] = FeedEngagementExecutor::doneEntry($step, $row);
                $this->executor->journal($account, FeedEngagementExecutor::logType($step), $step['decision'], self::TRIGGER,
                    $row->status, $step['text'] ?? null, $row->id, $row->error);
                if ($row->status !== 'confirmed') {
                    $state['skip'][(string) $step['decision']['id']] = true;
                }
                $state['current'] = null;
            }
            if ($stop) {
                return $this->finish($run, $state, 'arrêt demandé depuis Companion');
            }

            while ($state['cursor'] < count($state['steps'])) {
                $step = $state['steps'][$state['cursor']];
                $id = (string) $step['decision']['id'];
                if ($step['type'] === 'request') {
                    if (isset($state['posts'][$id]) && $network->requestDraft($account, $state['posts'][$id], $state['snapshot'], self::TRIGGER)) {
                        $state['requested']++;
                        $this->executor->journal($account, 'feed.llm', $step['decision'], self::TRIGGER, 'requested', null, null, null);
                    }
                    $state['cursor']++;

                    continue;
                }
                if ($step['type'] === 'gesture' && isset($state['skip'][$id])) {
                    $state['cursor']++;

                    continue;
                }
                $hold = $this->executor->hold($account, count($state['done']), $max);
                if ($hold['stop']) {
                    return $this->finish($run, $state, $hold['stop']);
                }
                if ($hold['wait'] > 0) {
                    $this->save($run, $state);

                    return ['state' => 'wait', 'seconds' => $hold['wait']];
                }
                try {
                    if ($step['type'] === 'draft') {
                        $row = $step['receipt']['model']::find($step['receipt']['id']);
                        $row = $row && $row->status === 'prepared' && ($row->payload['draft_state'] ?? null) === 'ready' ? $row : null;
                    } else {
                        $row = $this->executor->receipt($account, $step, $state['posts'], $state['snapshot'], self::TRIGGER);
                    }
                    if ($row === null) {
                        $state['cursor']++;

                        continue; // déjà réservé ou fait par ailleurs : jamais de second geste
                    }
                    $row = $network->dispatch($row, self::PROFILE);
                } catch (HttpException $e) {
                    if ($e->getStatusCode() === 429 && str_starts_with($e->getMessage(), 'Délai minimal')) {
                        $this->save($run, $state);

                        return ['state' => 'wait', 'seconds' => max(5, $this->executor->hold($account, 0, PHP_INT_MAX)['wait'])];
                    }

                    return $this->refuse($account, $run, $state, $step, $e->getMessage());
                } catch (BrowserException $e) {
                    return $this->refuse($account, $run, $state, $step, $e->getMessage());
                }
                $state['current'] = ['step' => $state['cursor'], 'model' => $row::class, 'id' => $row->id];
                $state['cursor']++;
                $this->save($run, $state);

                return ['state' => 'task', 'task_id' => $row->browser_task_id, 'action' => $step['action'],
                    'author' => $step['decision']['author'] ?? null, 'text' => $step['text'] ?? null, 'source' => $step['type']];
            }

            return $this->finish($run, $state, null);
        });
    }

    private function refuse(SocialAccount $account, string $run, array $state, array $step, string $message): array
    {
        // Quota, cadence, réservation ou navigateur : on s'arrête, sans contourner ni renvoyer.
        $this->executor->journal($account, FeedEngagementExecutor::logType($step), $step['decision'], self::TRIGGER, 'refused',
            $step['text'] ?? null, $step['type'] === 'draft' ? $step['receipt']['id'] : null, $message);

        return $this->finish($run, $state, $message);
    }

    private function finish(string $run, array $state, ?string $stopped): array
    {
        $log = BotActionLog::find($state['log_id']);
        $log?->update(['metadata' => array_replace($log->metadata ?? [], ['done' => count($state['done']),
            'requested' => $state['requested'], 'stopped' => $stopped])]);
        Cache::forget('feed-local-run:'.$run);

        return ['state' => 'done', 'done' => $state['done'], 'requested' => $state['requested'], 'stopped' => $stopped];
    }

    private function save(string $run, array $state): void
    {
        Cache::put('feed-local-run:'.$run, $state, now()->addMinutes(self::TTL_MINUTES));
    }
}
