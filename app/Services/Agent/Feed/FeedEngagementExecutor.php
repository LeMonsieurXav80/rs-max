<?php

namespace App\Services\Agent\Feed;

use App\Models\BotActionLog;
use App\Models\SocialAccount;
use App\Models\XEngagement;
use App\Services\Agent\AccountActionBudget;
use App\Services\Agent\AgentCatalog;
use App\Services\Agent\CommunityManagerWindow;
use App\Services\Agent\XEngagementService;
use App\Services\Browser\BrowserException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Exécute ce que décide `FeedEngagementPlanner`, un geste à la fois : d'abord les commentaires rédigés par Hermès
 * et validés, puis les likes et les commentaires spintax, enfin la réservation des rédactions pour Hermès.
 * Même circuit que les gestes d'Hermès (`XEngagement`, cadence, tâche Companion, preuve après rechargement) :
 * un résultat incertain n'est jamais renvoyé. Les reçus `XEngagement` portent `origin=feed_script` et restent
 * en base au-delà de la purge des journaux : ce sont eux qui comptent les plafonds et le bilan à J+7.
 */
class FeedEngagementExecutor
{
    public const ORIGIN = 'feed_script';

    public const SPIN_SOURCE = 'script_spintax';

    /** Attente maximale du délai minimal entre deux gestes avant d'arrêter le passage. */
    public const MAX_WAIT_SECONDS = 120;

    /** Attente maximale de la preuve d'un geste, comme le client d'Hermès. */
    public const RESULT_TIMEOUT_SECONDS = 330;

    private const SENT = ['queued', 'running', 'confirmed', 'uncertain'];

    /** @var callable(int): void */
    private $sleep;

    public function __construct(private XEngagementService $engagements, private AccountActionBudget $budget,
        private FeedDraftService $drafts, ?callable $sleep = null)
    {
        $this->sleep = $sleep ?? fn (int $seconds) => sleep($seconds);
    }

    /**
     * Gestes du script depuis minuit à Lisbonne : envoyés, ou rédactions demandées à Hermès et pas encore parties.
     *
     * @return array{likes:int, spin:int, llm:int}
     */
    public function usedToday(SocialAccount $account): array
    {
        $day = $this->budget->dayStart();
        $rows = XEngagement::where('social_account_id', $account->id)->where('payload->origin', self::ORIGIN)
            ->where(fn ($q) => $q->where(fn ($sent) => $sent->whereIn('status', self::SENT)->where('requested_at', '>=', $day))
                ->orWhere(fn ($asked) => $asked->where('status', 'prepared')->where('action', 'reply')
                    ->where('created_at', '>=', $day)->whereIn('payload->draft_state', ['awaiting', 'ready'])))
            ->get(['action', 'payload']);
        $spin = fn ($r) => ($r->payload['draft_source'] ?? null) === self::SPIN_SOURCE;

        return ['likes' => $rows->where('action', 'like')->count(),
            'spin' => $rows->filter(fn ($r) => $r->action === 'reply' && $spin($r))->count(),
            'llm' => $rows->filter(fn ($r) => $r->action === 'reply' && ! $spin($r))->count()];
    }

    /**
     * @param  array{items: list<array<string, mixed>>}  $plan
     * @param  array<string, array<string, mixed>>  $posts  publications lues, par identifiant
     * @return array{done: list<array<string, mixed>>, requested: int, stopped: ?string}
     */
    public function run(SocialAccount $account, array $plan, array $posts, string $snapshotId, string $trigger): array
    {
        $max = FeedEngagementSettings::for($account)['pass_max_actions'];
        $done = [];

        foreach ($this->drafts->ready($account) as $row) {
            if ($why = $this->blocked($account, count($done), $max)) {
                return ['done' => $done, 'requested' => 0, 'stopped' => $why];
            }
            $decision = ['id' => (string) ($row->payload['target']['id'] ?? ''), 'url' => $row->payload['url'] ?? null,
                'author' => $row->payload['target']['author'] ?? null];
            try {
                $row = $this->send($row);
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
                    $row = $this->gesture($account, $action, $posts[$decision['id']] ?? null, $snapshotId, $trigger, $text);
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
                && $this->drafts->request($account, $posts[$decision['id']], $snapshotId, $trigger)) {
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

    private function gesture(SocialAccount $account, string $action, ?array $post, string $snapshotId, string $trigger, ?string $text): ?XEngagement
    {
        abort_unless($post && isset($post['id'], $post['url']), 422, 'Publication absente de la lecture.');
        $action === 'like' ? $this->engagements->assertLikeAvailable($account, $post['id'])
            : $this->engagements->assertReplyAvailable($account, $post['id']);
        $key = hash('sha256', $post['id']);
        if (XEngagement::where('social_account_id', $account->id)->where('action', $action)->where('target_key', $key)
            ->where('status', '!=', 'prepared')->exists()) {
            return null;
        }
        $payload = ['action' => $action, 'username' => $account->browser_username, 'url' => $post['url'],
            'activity_window' => app(CommunityManagerWindow::class)->specification(), 'target' => $post,
            'handle' => null, 'require_non_followback' => false, 'snapshot_id' => $snapshotId,
            'origin' => self::ORIGIN, 'trigger' => $trigger,
            ...($text !== null ? ['text' => $text, 'draft_source' => self::SPIN_SOURCE] : [])];

        return $this->send(XEngagement::updateOrCreate(['social_account_id' => $account->id, 'action' => $action, 'target_key' => $key],
            ['payload' => $payload, 'status' => 'prepared', 'persona_hash' => null]));
    }

    /** Demande le geste puis attend sa preuve, comme le client d'Hermès. */
    private function send(XEngagement $row): XEngagement
    {
        $row = $this->engagements->execute($row);
        $deadline = time() + self::RESULT_TIMEOUT_SECONDS;
        do {
            ($this->sleep)(5);
            $row = $this->engagements->result($row->fresh());
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
