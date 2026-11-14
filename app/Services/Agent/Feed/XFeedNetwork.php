<?php

namespace App\Services\Agent\Feed;

use App\Models\SocialAccount;
use App\Models\XEngagement;
use App\Services\Agent\CommentFreshness;
use App\Services\Agent\XEngagementService;
use App\Services\Agent\XReplyDraft;

final class XFeedNetwork implements FeedNetwork
{
    /** Codes de langue spéciaux de X : lien, mention, hashtag ou emoji seuls, texte trop court, aucun texte. */
    private const NON_LANGUAGE = ['qme', 'qam', 'qht', 'qct', 'qst', 'zxx', 'und', 'art'];

    public function __construct(private XEngagementService $engagements) {}

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
}
