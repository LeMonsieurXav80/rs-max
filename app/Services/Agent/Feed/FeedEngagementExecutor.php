<?php

namespace App\Services\Agent\Feed;

use App\Models\BotActionLog;
use App\Models\SocialAccount;
use App\Services\Agent\AccountActionBudget;
use App\Services\Agent\AgentCatalog;
use App\Services\Agent\CommunityManagerWindow;
use App\Services\Browser\BrowserException;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Exécute ce que décide `FeedEngagementPlanner`, un geste à la fois : d'abord les commentaires rédigés par Hermès
 * et validés, puis les likes et les commentaires spintax, enfin la réservation des rédactions pour Hermès.
 * Même circuit que les gestes d'Hermès (reçu, cadence, tâche Companion, preuve après rechargement), et le script
 * attend lui-même la preuve : un résultat incertain n'est jamais renvoyé. Les reçus portent `origin=feed_script` et
 * restent en base au-delà de la purge des journaux : ce sont eux qui comptent les plafonds et le bilan à J+7.
 */
class FeedEngagementExecutor
{
    public const ORIGIN = 'feed_script';

    public const SPIN_SOURCE = 'script_spintax';

    /** Attente maximale du délai minimal entre deux gestes avant d'arrêter le passage. */
    public const MAX_WAIT_SECONDS = 120;

    /** @var callable(int): void */
    private $sleep;

    public function __construct(private AccountActionBudget $budget, ?callable $sleep = null, private ?FeedNetwork $network = null)
    {
        $this->sleep = $sleep ?? fn (int $seconds) => sleep($seconds);
    }

    private function network(SocialAccount $account): FeedNetwork
    {
        return $this->network ?? FeedNetworks::for($account);
    }

    /**
     * Gestes du script depuis minuit à Lisbonne : envoyés, ou rédactions demandées à Hermès et pas encore parties.
     *
     * @return array{likes:int, spin:int, llm:int}
     */
    public function usedToday(SocialAccount $account): array
    {
        return $this->network($account)->usedToday($account, $this->budget->dayStart());
    }

    /**
     * Gestes d'un passage, dans l'ordre : commentaires rédigés par Hermès et validés, puis pour chaque publication
     * retenue le like, le commentaire spintax et la réservation d'une rédaction. Commun au passage du NAS (`run`) et
     * au passage lancé depuis le Mac (`LocalFeedRun`).
     *
     * @return list<array{type:string, decision:array<string, mixed>, action?:string, text?:?string, receipt?:Model}>
     */
    public function steps(SocialAccount $account, array $plan): array
    {
        $steps = [];
        foreach ($this->network($account)->readyDrafts($account) as $row) {
            $steps[] = ['type' => 'draft', 'receipt' => $row, 'action' => 'reply', 'text' => $row->payload['text'] ?? null,
                'decision' => ['id' => (string) ($row->payload['target']['id'] ?? ''), 'url' => $row->payload['url'] ?? null,
                    'author' => $row->payload['target']['author'] ?? null]];
        }
        foreach ($plan['items'] as $decision) {
            if ($decision['like']) {
                $steps[] = ['type' => 'gesture', 'action' => 'like', 'text' => null, 'decision' => $decision];
            }
            if ($decision['comment'] === 'spin') {
                $steps[] = ['type' => 'gesture', 'action' => 'reply', 'text' => $decision['comment_text'], 'decision' => $decision];
            }
            if ($decision['comment'] === 'llm') {
                $steps[] = ['type' => 'request', 'decision' => $decision];
            }
        }

        return $steps;
    }

    /** Type de journal d'une étape : `feed.llm`, `feed.like` ou `feed.spin`. */
    public static function logType(array $step): string
    {
        return $step['type'] === 'gesture' ? ($step['action'] === 'like' ? 'feed.like' : 'feed.spin') : 'feed.llm';
    }

    /** Ligne de `done` pour un geste parti. */
    public static function doneEntry(array $step, Model $row): array
    {
        return ['id' => (string) ($step['decision']['id'] ?? ''), 'action' => $step['action'],
            'source' => $step['type'] === 'draft' ? 'llm' : ($step['text'] === null ? 'script' : 'spin'),
            'status' => $row->status, 'engagement_id' => $row->id];
    }

    /** Réserve ou reprend le reçu d'une étape ; `null` si le même geste est déjà parti. */
    public function receipt(SocialAccount $account, array $step, array $posts, string $snapshotId, string $trigger): ?Model
    {
        if ($step['type'] === 'draft') {
            return $step['receipt'];
        }
        $post = $posts[$step['decision']['id']] ?? null;
        abort_unless($post && isset($post['id'], $post['url']), 422, 'Publication absente de la lecture.');

        return $this->network($account)->reserve($account, $step['action'], $post, $snapshotId, $trigger, $step['text']);
    }

    /**
     * @param  array{items: list<array<string, mixed>>}  $plan
     * @param  array<string, array<string, mixed>>  $posts  publications lues, par identifiant
     * @return array{done: list<array<string, mixed>>, requested: int, stopped: ?string}
     */
    public function run(SocialAccount $account, array $plan, array $posts, string $snapshotId, string $trigger): array
    {
        $network = $this->network($account);
        $max = FeedEngagementSettings::for($account)['pass_max_actions'];
        $done = [];
        $requested = 0;
        $skip = [];

        foreach ($this->steps($account, $plan) as $step) {
            $id = (string) $step['decision']['id'];
            if ($step['type'] === 'request') {
                if (isset($posts[$id]) && $network->requestDraft($account, $posts[$id], $snapshotId, $trigger)) {
                    $requested++;
                    $this->journal($account, 'feed.llm', $step['decision'], $trigger, 'requested', null, null, null);
                }

                continue;
            }
            if ($step['type'] === 'gesture' && isset($skip[$id])) {
                continue; // incertain ou échoué : ne pas enchaîner un second geste sur la même publication
            }
            if ($why = $this->blocked($account, count($done), $max)) {
                return ['done' => $done, 'requested' => $requested, 'stopped' => $why];
            }
            $row = null;
            try {
                $row = $this->receipt($account, $step, $posts, $snapshotId, $trigger);
                $row = $row ? $this->send($network, $row) : null;
            } catch (HttpException|BrowserException $e) {
                // Quota, cadence, réservation ou navigateur : on s'arrête, sans contourner ni renvoyer.
                $this->journal($account, self::logType($step), $step['decision'], $trigger, 'refused', $step['text'],
                    $step['type'] === 'draft' ? $step['receipt']->id : null, $e->getMessage());

                return ['done' => $done, 'requested' => $requested, 'stopped' => $e->getMessage()];
            }
            if ($row === null) {
                continue; // déjà réservé ou fait par ailleurs : jamais de second geste
            }
            $done[] = self::doneEntry($step, $row);
            $this->journal($account, self::logType($step), $step['decision'], $trigger, $row->status, $step['text'], $row->id, $row->error);
            if ($row->status !== 'confirmed') {
                $skip[$id] = true;
            }
        }

        return ['done' => $done, 'requested' => $requested, 'stopped' => null];
    }

    /**
     * Ce qui retient le geste suivant : un motif d'arrêt, ou une attente en secondes avant la cadence (0 : rien).
     *
     * @return array{stop: ?string, wait: int}
     */
    public function hold(SocialAccount $account, int $done, int $max): array
    {
        if ($done >= $max) {
            return ['stop' => 'plafond du passage ('.$max.' gestes)', 'wait' => 0];
        }
        if (! app(CommunityManagerWindow::class)->isOpen()) {
            return ['stop' => 'fenêtre 06 h–22 h fermée', 'wait' => 0];
        }
        $usage = $this->budget->usage($account->fresh());
        if (! empty($usage['blocked'])) {
            return ['stop' => 'cadence du compte en pause', 'wait' => 0];
        }
        if (($usage['remaining'] ?? null) === 0 || ($usage['daily']['remaining'] ?? null) === 0) {
            return ['stop' => 'cadence du compte épuisée', 'wait' => 0];
        }
        $next = $usage['next_allowed_at'] ?? null;
        $wait = $next ? (int) ceil(now()->diffInSeconds(\Illuminate\Support\Carbon::parse($next), false)) : 0;
        if ($wait > self::MAX_WAIT_SECONDS) {
            return ['stop' => 'prochain geste autorisé dans plus de '.self::MAX_WAIT_SECONDS.' s', 'wait' => 0];
        }

        return ['stop' => null, 'wait' => max(0, $wait > 0 ? $wait + 1 : 0)];
    }

    private function blocked(SocialAccount $account, int $done, int $max): ?string
    {
        $hold = $this->hold($account, $done, $max);
        if ($hold['wait'] > 0) {
            ($this->sleep)($hold['wait']);
        }

        return $hold['stop'];
    }

    /** Demande le geste puis attend sa preuve : le modèle n'a rien à surveiller. */
    private function send(FeedNetwork $network, Model $row): Model
    {
        try {
            $row = $network->dispatch($row);
        } catch (HttpException $e) {
            // Un autre geste du compte vient de partir (passage manuel, mention d'Hermès) : attendre le délai minimal,
            // une seule fois. Le 14/11/2026, le passage de 14 h 40 s'est arrêté là sans aucun geste.
            if ($e->getStatusCode() !== 429 || ! str_starts_with($e->getMessage(), 'Délai minimal') || $this->blocked(SocialAccount::findOrFail($row->social_account_id), 0, PHP_INT_MAX)) {
                throw $e;
            }
            $row = $network->dispatch($row->fresh());
        }
        $deadline = time() + $network->resultTimeout();
        do {
            ($this->sleep)(5);
            $row = $network->refresh($row);
        } while (in_array($row->status, ['queued', 'running'], true) && time() < $deadline);

        return $row;
    }

    public function journal(SocialAccount $account, string $type, array $decision, string $trigger, string $status, ?string $text, ?int $engagementId, ?string $error): void
    {
        BotActionLog::create(['social_account_id' => $account->id, 'action_type' => $type,
            'source' => AgentCatalog::SOURCE, 'target_uri' => $decision['url'] ?? null, 'target_text' => $text,
            'success' => $status === 'confirmed', 'performed_at' => now(),
            'metadata' => ['origin' => 'script', 'trigger' => $trigger, 'status' => $status, 'engagement_id' => $engagementId,
                'author' => $decision['author'] ?? null, 'hits' => $decision['hits'] ?? [], 'language' => $decision['language'] ?? null,
                ...($error ? ['error' => mb_substr($error, 0, 300)] : [])]]);
    }
}
