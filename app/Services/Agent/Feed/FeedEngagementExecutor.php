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
     * @param  array{items: list<array<string, mixed>>}  $plan
     * @param  array<string, array<string, mixed>>  $posts  publications lues, par identifiant
     * @return array{done: list<array<string, mixed>>, requested: int, stopped: ?string}
     */
    public function run(SocialAccount $account, array $plan, array $posts, string $snapshotId, string $trigger): array
    {
        $network = $this->network($account);
        $max = FeedEngagementSettings::for($account)['pass_max_actions'];
        $done = [];

        foreach ($network->readyDrafts($account) as $row) {
            if ($why = $this->blocked($account, count($done), $max)) {
                return ['done' => $done, 'requested' => 0, 'stopped' => $why];
            }
            $decision = ['id' => (string) ($row->payload['target']['id'] ?? ''), 'url' => $row->payload['url'] ?? null,
                'author' => $row->payload['target']['author'] ?? null];
            try {
                $row = $this->send($network, $row);
            } catch (HttpException|BrowserException $e) {
                $this->log($account, 'feed.llm', $decision, $trigger, 'refused', $row->payload['text'] ?? null, $row->id, $e->getMessage());

                return ['done' => $done, 'requested' => 0, 'stopped' => $e->getMessage()];
            }
            $done[] = ['id' => $decision['id'], 'action' => 'reply', 'source' => 'llm', 'status' => $row->status, 'engagement_id' => $row->id];
            $this->log($account, 'feed.llm', $decision, $trigger, $row->status, $row->payload['text'] ?? null, $row->id, $row->error);
        }

        $requested = 0;
        foreach ($plan['items'] as $decision) {
            $gestures = array_filter([$decision['like'] ? ['like', null] : null,
                $decision['comment'] === 'spin' ? ['reply', $decision['comment_text']] : null]);
            foreach ($gestures as [$action, $text]) {
                if ($why = $this->blocked($account, count($done), $max)) {
                    return ['done' => $done, 'requested' => $requested, 'stopped' => $why];
                }
                try {
                    $post = $posts[$decision['id']] ?? null;
                    abort_unless($post && isset($post['id'], $post['url']), 422, 'Publication absente de la lecture.');
                    $row = $network->reserve($account, $action, $post, $snapshotId, $trigger, $text);
                    $row = $row ? $this->send($network, $row) : null;
                } catch (HttpException|BrowserException $e) {
                    // Quota, cadence, réservation ou navigateur : on s'arrête, sans contourner ni renvoyer.
                    $this->log($account, $action === 'like' ? 'feed.like' : 'feed.spin', $decision, $trigger, 'refused', $text, null, $e->getMessage());

                    return ['done' => $done, 'requested' => $requested, 'stopped' => $e->getMessage()];
                }
                if ($row === null) {
                    continue; // déjà réservé ou fait par ailleurs : jamais de second geste
                }
                $done[] = ['id' => $decision['id'], 'action' => $action, 'source' => $text === null ? 'script' : 'spin',
                    'status' => $row->status, 'engagement_id' => $row->id];
                $this->log($account, $action === 'like' ? 'feed.like' : 'feed.spin', $decision, $trigger, $row->status, $text, $row->id, $row->error);
                if ($row->status !== 'confirmed') {
                    break; // incertain ou échoué : ne pas enchaîner un second geste sur la même publication
                }
            }
            if ($decision['comment'] === 'llm' && isset($posts[$decision['id']])
                && $network->requestDraft($account, $posts[$decision['id']], $snapshotId, $trigger)) {
                $requested++;
                $this->log($account, 'feed.llm', $decision, $trigger, 'requested', null, null, null);
            }
        }

        return ['done' => $done, 'requested' => $requested, 'stopped' => null];
    }

    private function blocked(SocialAccount $account, int $done, int $max): ?string
    {
        if ($done >= $max) {
            return 'plafond du passage ('.$max.' gestes)';
        }
        if (! app(CommunityManagerWindow::class)->isOpen()) {
            return 'fenêtre 06 h–22 h fermée';
        }

        return $this->waitForCadence($account);
    }

    /** Demande le geste puis attend sa preuve : le modèle n'a rien à surveiller. */
    private function send(FeedNetwork $network, Model $row): Model
    {
        try {
            $row = $network->dispatch($row);
        } catch (HttpException $e) {
            // Un autre geste du compte vient de partir (passage manuel, mention d'Hermès) : attendre le délai minimal,
            // une seule fois. Le 14/11/2026, le passage de 14 h 40 s'est arrêté là sans aucun geste.
            if ($e->getStatusCode() !== 429 || ! str_starts_with($e->getMessage(), 'Délai minimal') || $this->waitForCadence(SocialAccount::findOrFail($row->social_account_id))) {
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

    private function waitForCadence(SocialAccount $account): ?string
    {
        $usage = $this->budget->usage($account->fresh());
        if (! empty($usage['blocked'])) {
            return 'cadence du compte en pause';
        }
        if (($usage['remaining'] ?? null) === 0 || ($usage['daily']['remaining'] ?? null) === 0) {
            return 'cadence du compte épuisée';
        }
        $next = $usage['next_allowed_at'] ?? null;
        $wait = $next ? now()->diffInSeconds(\Illuminate\Support\Carbon::parse($next), false) : 0;
        if ($wait > self::MAX_WAIT_SECONDS) {
            return 'prochain geste autorisé dans plus de '.self::MAX_WAIT_SECONDS.' s';
        }
        if ($wait > 0) {
            ($this->sleep)((int) ceil($wait) + 1);
        }

        return null;
    }

    private function log(SocialAccount $account, string $type, array $decision, string $trigger, string $status, ?string $text, ?int $engagementId, ?string $error): void
    {
        BotActionLog::create(['social_account_id' => $account->id, 'action_type' => $type,
            'source' => AgentCatalog::SOURCE, 'target_uri' => $decision['url'] ?? null, 'target_text' => $text,
            'success' => $status === 'confirmed', 'performed_at' => now(),
            'metadata' => ['origin' => 'script', 'trigger' => $trigger, 'status' => $status, 'engagement_id' => $engagementId,
                'author' => $decision['author'] ?? null, 'hits' => $decision['hits'] ?? [], 'language' => $decision['language'] ?? null,
                ...($error ? ['error' => mb_substr($error, 0, 300)] : [])]]);
    }
}
