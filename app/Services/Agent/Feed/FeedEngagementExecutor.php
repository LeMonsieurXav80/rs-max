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
 * Exécute les likes et les commentaires spintax décidés par `FeedEngagementPlanner`, un geste à la fois.
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

    /** @var callable(int): void */
    private $sleep;

    public function __construct(private XEngagementService $engagements, private AccountActionBudget $budget, ?callable $sleep = null)
    {
        $this->sleep = $sleep ?? fn (int $seconds) => sleep($seconds);
    }

    /** @return array{likes:int, spin:int, llm:int} gestes du script réservés ou faits depuis minuit à Lisbonne */
    public function usedToday(SocialAccount $account): array
    {
        $rows = XEngagement::where('social_account_id', $account->id)->where('payload->origin', self::ORIGIN)
            ->whereIn('status', ['queued', 'running', 'confirmed', 'uncertain'])
            ->where('requested_at', '>=', $this->budget->dayStart())
            ->get(['action', 'payload']);

        return ['likes' => $rows->where('action', 'like')->count(),
            'spin' => $rows->filter(fn ($r) => $r->action === 'reply' && ($r->payload['draft_source'] ?? null) === self::SPIN_SOURCE)->count(),
            'llm' => $rows->filter(fn ($r) => $r->action === 'reply' && ($r->payload['draft_source'] ?? null) !== self::SPIN_SOURCE)->count()];
    }

    /**
     * @param  array{items: list<array<string, mixed>>}  $plan
     * @param  array<string, array<string, mixed>>  $posts  publications lues, par identifiant
     * @return array{done: list<array<string, mixed>>, stopped: ?string}
     */
    public function run(SocialAccount $account, array $plan, array $posts, string $snapshotId, string $trigger): array
    {
        $max = FeedEngagementSettings::for($account)['pass_max_actions'];
        $done = [];
        foreach ($plan['items'] as $decision) {
            $gestures = array_filter([$decision['like'] ? ['like', null] : null,
                $decision['comment'] === 'spin' ? ['reply', $decision['comment_text']] : null]);
            foreach ($gestures as [$action, $text]) {
                if (count($done) >= $max) {
                    return ['done' => $done, 'stopped' => 'plafond du passage ('.$max.' gestes)'];
                }
                if (! app(CommunityManagerWindow::class)->isOpen()) {
                    return ['done' => $done, 'stopped' => 'fenêtre 06 h–22 h fermée'];
                }
                if ($stop = $this->waitForCadence($account)) {
                    return ['done' => $done, 'stopped' => $stop];
                }
                try {
                    $row = $this->gesture($account, $action, $posts[$decision['id']] ?? null, $snapshotId, $trigger, $text);
                } catch (HttpException $e) {
                    // Quota, cadence ou réservation refusés par RS-Max : on s'arrête, sans contourner.
                    $this->log($account, $action, $decision, $trigger, 'refused', $text, null, $e->getMessage());

                    return ['done' => $done, 'stopped' => $e->getMessage()];
                } catch (BrowserException $e) {
                    $this->log($account, $action, $decision, $trigger, 'failed', $text, null, $e->getMessage());

                    return ['done' => $done, 'stopped' => 'navigateur : '.$e->getMessage()];
                }
                if ($row === null) {
                    continue; // déjà réservé ou fait par ailleurs : jamais de second geste
                }
                $done[] = ['id' => $decision['id'], 'action' => $action, 'status' => $row->status, 'engagement_id' => $row->id];
                $this->log($account, $action, $decision, $trigger, $row->status, $text, $row->id, $row->error);
                if ($row->status !== 'confirmed') {
                    break; // incertain ou échoué : ne pas enchaîner un second geste sur la même publication
                }
            }
        }

        return ['done' => $done, 'stopped' => null];
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
        $row = XEngagement::updateOrCreate(['social_account_id' => $account->id, 'action' => $action, 'target_key' => $key],
            ['payload' => $payload, 'status' => 'prepared', 'persona_hash' => null]);
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

    private function log(SocialAccount $account, string $action, array $decision, string $trigger, string $status, ?string $text, ?int $engagementId, ?string $error): void
    {
        BotActionLog::create(['social_account_id' => $account->id, 'action_type' => $action === 'like' ? 'feed.like' : 'feed.spin',
            'source' => AgentCatalog::SOURCE, 'target_uri' => $decision['url'] ?? null, 'target_text' => $text,
            'success' => $status === 'confirmed', 'performed_at' => now(),
            'metadata' => ['origin' => 'script', 'trigger' => $trigger, 'status' => $status, 'engagement_id' => $engagementId,
                'author' => $decision['author'] ?? null, 'hits' => $decision['hits'] ?? [], 'language' => $decision['language'] ?? null,
                ...($error ? ['error' => mb_substr($error, 0, 300)] : [])]]);
    }
}
