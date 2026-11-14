<?php

namespace App\Services\Agent\Feed;

use App\Models\SocialAccount;
use App\Models\XEngagement;
use App\Services\Agent\CommentFreshness;
use App\Services\Agent\CommunityManagerWindow;
use App\Services\Agent\XEngagementService;
use App\Services\Agent\XReplyDraft;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

final class XFeedNetwork implements FeedNetwork
{
    /** Codes de langue spéciaux de X : lien, mention, hashtag ou emoji seuls, texte trop court, aucun texte. */
    private const NON_LANGUAGE = ['qme', 'qam', 'qht', 'qct', 'qst', 'zxx', 'und', 'art'];

    /** Attente maximale de la preuve d'un geste, comme le client d'Hermès. */
    public const RESULT_TIMEOUT_SECONDS = 330;

    private const SENT = ['queued', 'running', 'confirmed', 'uncertain'];

    public function __construct(private XEngagementService $engagements, private FeedDraftService $drafts) {}

    public function validId(string $id): bool
    {
        return ctype_digit($id);
    }

    public function fresh(array $item): bool
    {
        return filled($item['published_at'] ?? null)
            ? CommentFreshness::allowsPublishedAt($item['published_at'])
            : CommentFreshness::allows(CommentFreshness::xStatusDate((string) ($item['id'] ?? '')));
    }

    public function language(array $item): string
    {
        $lang = strtolower((string) ($item['lang'] ?? ''));
        if ($lang !== '' && ! in_array($lang, self::NON_LANGUAGE, true)) {
            return explode('-', $lang)[0];
        }

        return FrenchText::language((string) ($item['text'] ?? ''));
    }

    public function fitsComment(string $text): bool
    {
        return XReplyDraft::fits($text, XReplyDraft::LIMIT);
    }

    public function blockedLikeIds(SocialAccount $account, array $ids): array
    {
        return $this->engagements->blockedLikeIds($account, $ids);
    }

    public function blockedCommentIds(SocialAccount $account, array $ids): array
    {
        return $this->engagements->replyPrefilterIds($account, $ids);
    }

    public function recentlyCommentedAuthors(SocialAccount $account, int $days): array
    {
        return XEngagement::where('social_account_id', $account->id)->where('action', 'reply')
            ->whereIn('status', ['queued', 'running', 'confirmed', 'uncertain'])
            ->where('requested_at', '>=', now()->subDays($days))
            ->get(['payload'])->map(fn (XEngagement $e) => strtolower((string) ($e->payload['target']['author'] ?? '')))
            ->filter()->flip()->map(fn () => true)->all();
    }

    public function usedToday(SocialAccount $account, Carbon $since): array
    {
        $rows = XEngagement::where('social_account_id', $account->id)->where('payload->origin', FeedEngagementExecutor::ORIGIN)
            ->where(fn ($q) => $q->where(fn ($sent) => $sent->whereIn('status', self::SENT)->where('requested_at', '>=', $since))
                ->orWhere(fn ($asked) => $asked->where('status', 'prepared')->where('action', 'reply')
                    ->where('created_at', '>=', $since)->whereIn('payload->draft_state', ['awaiting', 'ready'])))
            ->get(['action', 'payload']);
        $spin = fn ($r) => ($r->payload['draft_source'] ?? null) === FeedEngagementExecutor::SPIN_SOURCE;

        return ['likes' => $rows->where('action', 'like')->count(),
            'spin' => $rows->filter(fn ($r) => $r->action === 'reply' && $spin($r))->count(),
            'llm' => $rows->filter(fn ($r) => $r->action === 'reply' && ! $spin($r))->count()];
    }

    public function reserve(SocialAccount $account, string $action, array $post, string $snapshot, string $trigger, ?string $text): ?Model
    {
        $action === 'like' ? $this->engagements->assertLikeAvailable($account, $post['id'])
            : $this->engagements->assertReplyAvailable($account, $post['id']);
        $key = hash('sha256', $post['id']);
        if (XEngagement::where('social_account_id', $account->id)->where('action', $action)->where('target_key', $key)
            ->where('status', '!=', 'prepared')->exists()) {
            return null;
        }
        $payload = ['action' => $action, 'username' => $account->browser_username, 'url' => $post['url'],
            'activity_window' => app(CommunityManagerWindow::class)->specification(), 'target' => $post,
            'handle' => null, 'require_non_followback' => false, 'snapshot_id' => $snapshot,
            'origin' => FeedEngagementExecutor::ORIGIN, 'trigger' => $trigger,
            ...($text !== null ? ['text' => $text, 'draft_source' => FeedEngagementExecutor::SPIN_SOURCE] : [])];

        return XEngagement::updateOrCreate(['social_account_id' => $account->id, 'action' => $action, 'target_key' => $key],
            ['payload' => $payload, 'status' => 'prepared', 'persona_hash' => null]);
    }

    public function dispatch(Model $receipt): Model
    {
        return $this->engagements->execute($receipt);
    }

    public function refresh(Model $receipt): Model
    {
        return $this->engagements->result($receipt->fresh());
    }

    public function resultTimeout(): int
    {
        return self::RESULT_TIMEOUT_SECONDS;
    }

    public function readyDrafts(SocialAccount $account): iterable
    {
        return $this->drafts->ready($account);
    }

    public function requestDraft(SocialAccount $account, array $post, string $snapshot, string $trigger): bool
    {
        return $this->drafts->request($account, $post, $snapshot, $trigger);
    }
}
