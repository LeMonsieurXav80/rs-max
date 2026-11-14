<?php

namespace App\Services\Agent\Feed;

use App\Models\SocialAccount;
use App\Models\XEngagement;
use App\Services\Agent\CommentFreshness;
use App\Services\Agent\XAddressing;
use App\Services\Agent\XEngagementService;
use App\Services\Agent\XReplyDraft;
use App\Services\Inbox\InboxFollowup;

/**
 * Décide, sans modèle de langue, ce que le script fait de chaque publication du fil « Pour vous » :
 * like, commentaire spintax, rédaction confiée au modèle, ou rien, toujours avec le motif.
 * Voir docs/flux-engagement-scripte.md.
 */
class FeedEngagementPlanner
{
    public const AUTHOR_COOLDOWN_DAYS = 7;

    /** Codes de langue spéciaux de X : lien, mention, hashtag ou emoji seuls, texte trop court, aucun texte. */
    private const NON_LANGUAGE = ['qme', 'qam', 'qht', 'qct', 'qst', 'zxx', 'und', 'art'];

    public function __construct(private XEngagementService $engagements, private InboxFollowup $followup) {}

    /**
     * @param  list<array<string, mixed>>  $items  publications lues par Companion
     * @param  array{likes?:int, spin?:int, llm?:int}  $used  gestes déjà faits aujourd'hui par le script
     * @return array{items: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function plan(SocialAccount $account, array $items, array $used = []): array
    {
        $settings = FeedEngagementSettings::for($account);
        $matcher = new FeedMatcher($account->agent_strategy ?? []);
        $own = strtolower((string) $account->browser_username);
        $ids = array_values(array_filter(array_map(fn ($i) => (string) ($i['id'] ?? ''), $items), 'ctype_digit'));
        $likeBlocked = array_flip($this->engagements->blockedLikeIds($account, $ids));
        $replyBlocked = array_flip($this->engagements->replyPrefilterIds($account, $ids));
        $recentAuthors = $this->recentlyCommentedAuthors($account);
        $left = ['likes' => max(0, $settings['daily_likes'] - ($used['likes'] ?? 0)),
            'spin' => max(0, $settings['daily_spin_comments'] - ($used['spin'] ?? 0)),
            'llm' => max(0, $settings['daily_llm_comments'] - ($used['llm'] ?? 0))];
        $seen = [];
        $decisions = [];

        foreach ($items as $item) {
            $id = (string) ($item['id'] ?? '');
            $author = strtolower((string) ($item['author'] ?? ''));
            $text = trim((string) ($item['text'] ?? ''));
            $decision = ['id' => $id, 'author' => $author, 'url' => $item['url'] ?? null, 'text' => mb_substr($text, 0, 140),
                'language' => $this->language($item), 'hits' => [], 'like' => false, 'comment' => null, 'comment_text' => null, 'reasons' => []];
            $skip = match (true) {
                ! ctype_digit($id) || $author === '' => 'illisible',
                isset($seen[$id]) => 'doublon',
                $author === $own => 'compte lui-même',
                ! empty($item['promoted']) => 'publicité',
                ! empty($item['reply_hint']) => 'réponse',
                ! empty($item['reposted_by']) => 'repost',
                ! $this->fresh($item) => 'plus de 14 jours',
                default => null,
            };
            $seen[$id] = true;
            if ($skip === null && ($excluded = $matcher->excludedBy($text."\n".($item['quote'] ?? ''), $author))) {
                $skip = 'exclu : '.$excluded;
            }
            if ($skip !== null) {
                $decisions[] = array_replace($decision, ['retained' => false, 'reasons' => [$skip]]);

                continue;
            }

            $decision['hits'] = $matcher->keywordHits($text."\n".($item['quote'] ?? ''));
            $target = $matcher->isTarget($author);
            $relevant = $decision['hits'] !== [] || $target;
            $decision['retained'] = $relevant || $settings['like_scope'] === 'all';
            if (! $decision['retained']) {
                $decisions[] = array_replace($decision, ['reasons' => ['aucun mot-clé']]);

                continue;
            }

            if (($item['liked'] ?? null) === true || isset($likeBlocked[$id])) {
                $decision['reasons'][] = 'déjà aimée';
            } elseif ($left['likes'] < 1) {
                $decision['reasons'][] = 'plafond de likes atteint';
            } else {
                $decision['like'] = true;
                $left['likes']--;
            }

            $decision = array_replace($decision, $this->comment($decision, $item, $relevant, $settings, $left, $replyBlocked, $recentAuthors));
            if ($decision['comment']) {
                $recentAuthors[$author] = true;
            }
            $decisions[] = $decision;
        }

        $summary = ['read' => count($items), 'retained' => 0, 'likes' => 0, 'spin' => 0, 'llm' => 0];
        foreach ($decisions as $d) {
            $summary['retained'] += (int) $d['retained'];
            $summary['likes'] += (int) $d['like'];
            $summary['spin'] += (int) ($d['comment'] === 'spin');
            $summary['llm'] += (int) ($d['comment'] === 'llm');
        }

        return ['items' => $decisions, 'summary' => $summary];
    }

    /** @return array{comment: ?string, comment_text: ?string, reasons: list<string>} */
    private function comment(array $decision, array $item, bool $relevant, array $settings, array &$left, array $replyBlocked, array $recentAuthors): array
    {
        $reasons = $decision['reasons'];
        $none = fn (string $why) => ['comment' => null, 'comment_text' => null, 'reasons' => [...$reasons, $why]];
        if (! $relevant) {
            return $none('commentaire réservé aux mots-clés');
        }
        if (isset($replyBlocked[$decision['id']])) {
            return $none('déjà commentée');
        }
        if (isset($recentAuthors[$decision['author']])) {
            return $none('auteur commenté depuis moins de '.self::AUTHOR_COOLDOWN_DAYS.' jours');
        }
        $french = $decision['language'] === 'fr';
        $question = $this->followup->isQuestion($item['text'] ?? '');
        $templates = $french ? $settings['spin_templates'] : $settings['emoji_templates'];
        $spin = ! $question && $templates !== [] && random_int(1, 100) <= $settings['spin_percent'];
        if ($spin) {
            if ($left['spin'] < 1) {
                return $none('plafond de commentaires spintax atteint');
            }
            $left['spin']--;

            $text = $this->spin($templates);
            if ($text === null) {
                $left['spin']++;

                return $none('aucun modèle spintax ne passe les règles de réponse');
            }

            return ['comment' => 'spin', 'comment_text' => $text, 'reasons' => $reasons];
        }
        if (! $french) {
            // Le modèle répond dans la langue du compte : il ne rédige que sous une publication en français.
            return $none($question ? 'question dans une autre langue' : 'autre langue ou sans texte');
        }
        if ($left['llm'] < 1) {
            return $none('plafond de commentaires rédigés atteint');
        }
        $left['llm']--;

        return ['comment' => 'llm', 'comment_text' => null, 'reasons' => [...$reasons, ...($question ? ['question : rédaction'] : [])]];
    }

    /**
     * Tire un modèle qui passe les règles serveur d'une réponse : longueur X, et pas de « merci » en tête,
     * puisque le compte n'est pas interpellé sous une publication du fil (`XAddressing::OUTSIDER`).
     */
    private function spin(array $templates): ?string
    {
        for ($try = 0; $try < 8; $try++) {
            $text = Spintax::expand($templates[random_int(0, count($templates) - 1)]);
            if (XReplyDraft::fits($text, XReplyDraft::LIMIT) && XAddressing::fits(XAddressing::OUTSIDER, $text)) {
                return $text;
            }
        }

        return null;
    }

    /** `fr`, une autre langue, ou `none` pour une publication sans texte à lire. */
    public function language(array $item): string
    {
        $lang = strtolower((string) ($item['lang'] ?? ''));
        if ($lang !== '' && ! in_array($lang, self::NON_LANGUAGE, true)) {
            return explode('-', $lang)[0];
        }
        $words = FeedMatcher::normalize(preg_replace(['~https?://\S+~', '/[@#][\w.]+/u'], ' ', (string) ($item['text'] ?? '')));
        if ($words === '') {
            return 'none';
        }

        return FrenchText::looksFrench((string) $item['text']) ? 'fr' : 'other';
    }

    private function fresh(array $item): bool
    {
        return filled($item['published_at'] ?? null)
            ? CommentFreshness::allowsPublishedAt($item['published_at'])
            : CommentFreshness::allows(CommentFreshness::xStatusDate((string) ($item['id'] ?? '')));
    }

    /** @return array<string, true> */
    private function recentlyCommentedAuthors(SocialAccount $account): array
    {
        return XEngagement::where('social_account_id', $account->id)->where('action', 'reply')
            ->whereIn('status', ['queued', 'running', 'confirmed', 'uncertain'])
            ->where('requested_at', '>=', now()->subDays(self::AUTHOR_COOLDOWN_DAYS))
            ->get(['payload'])->map(fn (XEngagement $e) => strtolower((string) ($e->payload['target']['author'] ?? '')))
            ->filter()->flip()->map(fn () => true)->all();
    }
}
