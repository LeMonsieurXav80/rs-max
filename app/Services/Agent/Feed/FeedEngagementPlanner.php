<?php

namespace App\Services\Agent\Feed;

use App\Models\SocialAccount;
use App\Services\Agent\XAddressing;
use App\Services\Inbox\InboxFollowup;

/**
 * Décide, sans modèle de langue, ce que le script fait de chaque publication du fil « Pour vous » :
 * like, commentaire spintax, rédaction confiée au modèle, ou rien, toujours avec le motif.
 * Voir docs/flux-engagement-scripte.md.
 */
class FeedEngagementPlanner
{
    public const AUTHOR_COOLDOWN_DAYS = 7;

    public function __construct(private InboxFollowup $followup) {}

    /**
     * @param  list<array<string, mixed>>  $items  publications lues par Companion
     * @param  array{likes?:int, spin?:int, llm?:int}  $used  gestes déjà faits aujourd'hui par le script
     * @return array{items: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function plan(SocialAccount $account, array $items, array $used = []): array
    {
        $settings = FeedEngagementSettings::for($account);
        $network = FeedNetworks::for($account);
        $matcher = new FeedMatcher($account->agent_strategy ?? []);
        $own = strtolower((string) $account->browser_username);
        $ids = array_values(array_filter(array_map(fn ($i) => (string) ($i['id'] ?? ''), $items), $network->validId(...)));
        $likeBlocked = array_flip($network->blockedLikeIds($account, $ids));
        $replyBlocked = array_flip($network->blockedCommentIds($account, $ids));
        $recentAuthors = $network->recentlyCommentedAuthors($account, self::AUTHOR_COOLDOWN_DAYS);
        $left = ['likes' => max(0, $settings['daily_likes'] - ($used['likes'] ?? 0)),
            'spin' => max(0, $settings['daily_spin_comments'] - ($used['spin'] ?? 0)),
            'llm' => max(0, $settings['daily_llm_comments'] - ($used['llm'] ?? 0))];
        $seen = [];
        $decisions = [];

        foreach ($items as $item) {
            $id = (string) ($item['id'] ?? '');
            $author = strtolower((string) ($item['author'] ?? ''));
            $text = trim((string) ($item['text'] ?? ''));
            // Le sujet choisi par l'auteur sur Threads (« Fitness Threads ») compte comme son texte.
            $matched = implode("\n", array_filter([$text, $item['quote'] ?? null, $item['topic'] ?? null]));
            $decision = ['id' => $id, 'author' => $author, 'url' => $item['url'] ?? null, 'text' => mb_substr($text, 0, 140),
                'language' => $network->language($item), 'hits' => [], 'like' => false, 'comment' => null, 'comment_text' => null, 'reasons' => []];
            $skip = match (true) {
                ! $network->validId($id) || $author === '' => 'illisible',
                isset($seen[$id]) => 'doublon',
                $author === $own => 'compte lui-même',
                ! empty($item['promoted']) => 'publicité',
                ! empty($item['reply_hint']) => 'réponse',
                ! empty($item['reposted_by']) => 'repost',
                ! $network->fresh($item) => 'plus de 14 jours',
                default => null,
            };
            $seen[$id] = true;
            if ($skip === null && ($excluded = $matcher->excludedBy($matched, $author))) {
                $skip = 'exclu : '.$excluded;
            }
            if ($skip !== null) {
                $decisions[] = array_replace($decision, ['retained' => false, 'reasons' => [$skip]]);

                continue;
            }

            $decision['hits'] = $matcher->keywordHits($matched);
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

            $decision = array_replace($decision, $this->comment($network, $decision, $item, $relevant, $settings, $left, $replyBlocked, $recentAuthors));
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
    private function comment(FeedNetwork $network, array $decision, array $item, bool $relevant, array $settings, array &$left, array $replyBlocked, array $recentAuthors): array
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

            $text = $this->spin($network, $templates);
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
     * Tire un modèle qui passe les règles serveur d'une réponse : longueur du réseau, et pas de « merci » en tête,
     * puisque le compte n'est pas interpellé sous une publication du fil (`XAddressing::OUTSIDER`).
     */
    private function spin(FeedNetwork $network, array $templates): ?string
    {
        for ($try = 0; $try < 8; $try++) {
            $text = Spintax::expand($templates[random_int(0, count($templates) - 1)]);
            if ($network->fitsComment($text) && XAddressing::fits(XAddressing::OUTSIDER, $text)) {
                return $text;
            }
        }

        return null;
    }
}
