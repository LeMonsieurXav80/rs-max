<?php

namespace App\Services\Agent\Feed;

use App\Models\SocialAccount;

/**
 * Ce qui distingue X et Threads pour le tri du fil par script : forme des identifiants, ancienneté, langue,
 * longueur d'un commentaire et reçus déjà enregistrés. Les règles de tri restent dans `FeedEngagementPlanner`.
 */
interface FeedNetwork
{
    public function validId(string $id): bool;

    public function fresh(array $item): bool;

    /** `fr`, une autre langue, ou `none` pour une publication sans texte à lire. */
    public function language(array $item): string;

    /** Un commentaire tient-il dans la limite du réseau ? */
    public function fitsComment(string $text): bool;

    /** @param  list<string>  $ids @return list<string> publications déjà aimées, réservées ou incertaines */
    public function blockedLikeIds(SocialAccount $account, array $ids): array;

    /** @param  list<string>  $ids @return list<string> publications déjà commentées, réservées ou incertaines */
    public function blockedCommentIds(SocialAccount $account, array $ids): array;

    /** @return array<string, true> auteurs commentés depuis `$days` jours, en minuscules */
    public function recentlyCommentedAuthors(SocialAccount $account, int $days): array;
}
