<?php

namespace App\Services\Agent\Feed;

use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Ce qui distingue X et Threads pour le tri du fil par script : forme des identifiants, ancienneté, langue,
 * longueur d'un commentaire, reçus durables et envoi d'un geste. Les règles de tri restent dans
 * `FeedEngagementPlanner`, l'enchaînement des gestes dans `FeedEngagementExecutor`.
 *
 * Un reçu est un modèle avec `status` (prepared, queued, running, confirmed, uncertain, failed, cancelled), `payload`
 * et `error` : `XEngagement` sur X, `ThreadsEngagement` sur Threads. Les gestes se nomment `like` et `reply`.
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

    /** @return array{likes:int, spin:int, llm:int} gestes du script depuis `$since`, rédactions en attente comprises */
    public function usedToday(SocialAccount $account, Carbon $since): array;

    /** Reçu préparé pour un geste, ou `null` si le même geste est déjà parti pour cette publication. */
    public function reserve(SocialAccount $account, string $action, array $post, string $snapshot, string $trigger, ?string $text): ?Model;

    /** Demande le geste à Companion ; lève une `HttpException` (cadence, quota, réservation) sans rien envoyer. */
    public function dispatch(Model $receipt): Model;

    /** Issue connue du geste. */
    public function refresh(Model $receipt): Model;

    /** Attente maximale de la preuve, en secondes. */
    public function resultTimeout(): int;

    /** @return iterable<Model> rédactions d'Hermès validées, prêtes à partir */
    public function readyDrafts(SocialAccount $account): iterable;

    /** Réserve une rédaction pour Hermès ; `false` si elle existe déjà ou si le réseau n'en prend pas encore. */
    public function requestDraft(SocialAccount $account, array $post, string $snapshot, string $trigger): bool;
}
