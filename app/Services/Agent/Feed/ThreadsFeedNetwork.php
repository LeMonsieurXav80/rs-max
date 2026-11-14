<?php

namespace App\Services\Agent\Feed;

use App\Models\BrowserTask;
use App\Models\SocialAccount;
use App\Models\ThreadsEngagement;
use App\Services\Agent\CommentFreshness;
use App\Services\Agent\CommunityManagerWindow;
use App\Services\Threads\ThreadsAgent;
use App\Services\Threads\ThreadsEngagementService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Threads ne marque pas la langue. Companion signale le lien « Translate » (texte dans une autre langue que
 * l'interface) et la langue de l'interface. Le 14/11/2026 sur le NAS, l'interface était en anglais et le lien
 * figurait sous presque toutes les publications françaises : il ne dit donc rien du français, sauf sous une
 * interface en français, où il signale une autre langue. `FrenchText` décide.
 *
 * Les gestes sont des tâches `threads.agent.act` ; leur reçu durable est `ThreadsEngagement` (jamais purgé, à la
 * différence de `bot_action_logs`), qui compte les plafonds du jour et sert au bilan à J+7.
 */
final class ThreadsFeedNetwork implements FeedNetwork
{
    /** Limite de `ThreadsAgentController::submit`, en unités UTF-16. */
    public const COMMENT_LIMIT = 500;

    private const SENT = [BrowserTask::PENDING, BrowserTask::RUNNING, BrowserTask::DONE, BrowserTask::UNCERTAIN];

    private const RECEIPTS_SENT = ['queued', 'running', 'confirmed', 'uncertain'];

    public function __construct(private ThreadsEngagementService $engagements, private FeedDraftService $drafts) {}

    public function validId(string $id): bool
    {
        return preg_match('/^[\w-]{5,20}$/', $id) === 1;
    }

    public function fresh(array $item): bool
    {
        return CommentFreshness::allowsPublishedAt($item['published_at'] ?? null);
    }

    public function language(array $item): string
    {
        $language = FrenchText::language((string) ($item['text'] ?? ''));

        return $language === 'fr' && ($item['ui_lang'] ?? null) === 'fr' && ($item['translatable'] ?? false) === true ? 'other' : $language;
    }

    public function fitsComment(string $text): bool
    {
        return trim($text) !== '' && strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2 <= self::COMMENT_LIMIT;
    }

    public function blockedLikeIds(SocialAccount $account, array $ids): array
    {
        return $this->blocked($account, 'like', $ids);
    }

    public function blockedCommentIds(SocialAccount $account, array $ids): array
    {
        return $this->blocked($account, 'comment', $ids);
    }

    public function recentlyCommentedAuthors(SocialAccount $account, int $days): array
    {
        return $this->gestures($account, 'comment')->where('created_at', '>=', now()->subDays($days))->get(['payload'])
            ->map(fn (BrowserTask $t) => strtolower((string) ($t->payload['target']['author'] ?? '')))
            ->filter()->flip()->map(fn () => true)->all();
    }

    public function usedToday(SocialAccount $account, Carbon $since): array
    {
        $rows = ThreadsEngagement::where('social_account_id', $account->id)->where('payload->origin', FeedEngagementExecutor::ORIGIN)
            ->where(fn ($q) => $q->where(fn ($sent) => $sent->whereIn('status', self::RECEIPTS_SENT)->where('requested_at', '>=', $since))
                ->orWhere(fn ($asked) => $asked->where('status', 'prepared')->where('action', 'comment')
                    ->where('created_at', '>=', $since)->whereIn('payload->draft_state', ['awaiting', 'ready'])))
            ->get(['action', 'payload']);
        $spin = fn ($r) => ($r->payload['draft_source'] ?? null) === FeedEngagementExecutor::SPIN_SOURCE;

        return ['likes' => $rows->where('action', 'like')->count(),
            'spin' => $rows->filter(fn ($r) => $r->action === 'comment' && $spin($r))->count(),
            'llm' => $rows->filter(fn ($r) => $r->action === 'comment' && ! $spin($r))->count()];
    }

    public function reserve(SocialAccount $account, string $action, array $post, string $snapshot, string $trigger, ?string $text): ?Model
    {
        $action = $action === 'reply' ? 'comment' : $action;
        abort_if($this->blocked($account, $action, [(string) $post['id']]) !== [], 409, 'Geste déjà demandé sur cette publication.');
        $key = hash('sha256', (string) $post['id']);
        if (ThreadsEngagement::where('social_account_id', $account->id)->where('action', $action)->where('target_key', $key)
            ->where('status', '!=', 'prepared')->exists()) {
            return null;
        }
        $url = app(ThreadsAgent::class)->canonical($post['url']);
        // Même forme que les gestes d'Hermès (`ThreadsAgent::submit`) : contrôle à la prise, autorisation et preuve inchangés.
        $payload = ['action' => $action, 'target' => ['url' => $url] + $post, 'url' => $url, 'username' => $account->browser_username,
            'text' => $text, 'activity_window' => app(CommunityManagerWindow::class)->specification(), 'snapshot_id' => $snapshot,
            'origin' => FeedEngagementExecutor::ORIGIN, 'trigger' => $trigger,
            ...($text !== null ? ['draft_source' => FeedEngagementExecutor::SPIN_SOURCE] : [])];

        return ThreadsEngagement::updateOrCreate(['social_account_id' => $account->id, 'action' => $action, 'target_key' => $key],
            ['payload' => $payload, 'status' => 'prepared', 'persona_hash' => null]);
    }

    public function dispatch(Model $receipt, ?string $profile = null): Model
    {
        return $this->engagements->execute($receipt, $profile);
    }

    public function refresh(Model $receipt): Model
    {
        return $this->engagements->result($receipt->fresh());
    }

    public function resultTimeout(): int
    {
        // Délai de prise, puis 3 minutes d'exécution avant que la tâche passe en incertain (`ThreadsAgent::state`).
        return ThreadsEngagementService::DEADLINE_MINUTES * 60 + 200;
    }

    public function readyDrafts(SocialAccount $account): iterable
    {
        return $this->drafts->ready($account);
    }

    public function requestDraft(SocialAccount $account, array $post, string $snapshot, string $trigger): bool
    {
        return $this->drafts->request($account, $post, $snapshot, $trigger);
    }

    /** Gestes Threads déjà demandés par Hermès ou par le script : un échec franc seul laisse réessayer. */
    private function blocked(SocialAccount $account, string $action, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $done = $this->gestures($account, $action)->whereIn('payload->target->id', $ids)->get(['payload'])
            ->map(fn (BrowserTask $t) => (string) ($t->payload['target']['id'] ?? ''))->all();

        return array_values(array_intersect($ids, $done));
    }

    private function gestures(SocialAccount $account, string $action)
    {
        return BrowserTask::where('social_account_id', $account->id)->where('kind', 'threads.agent.act')
            ->where('payload->action', $action)->whereIn('status', self::SENT);
    }
}
