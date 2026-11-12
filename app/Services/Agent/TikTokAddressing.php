<?php

namespace App\Services\Agent;

use App\Models\InboxItem;

/**
 * Place du compte sous sa propre vidéo TikTok, au format de XAddressing.
 * Un commentaire de premier niveau s’adresse à l’auteur de la vidéo ; une réponse à une autre personne, non.
 */
class TikTokAddressing
{
    public static function describe(string $username, InboxItem $item): array
    {
        $own = strtolower(ltrim($username, '@'));
        $author = strtolower(ltrim((string) $item->author_username, '@'));
        $to = $item->reply_to_username ? strtolower(ltrim($item->reply_to_username, '@')) : null;
        $role = $to === null || $to === $own ? XAddressing::ADDRESSED : XAddressing::PARTICIPANT;

        return [
            'compte' => '@'.$own,
            'role' => $role,
            'auteur_cible' => '@'.$author,
            'repond_a' => $to ? '@'.$to : null,
            'consigne' => $role === XAddressing::ADDRESSED
                ? "Le commentaire de @{$author} s’adresse à @{$own}, sous sa vidéo : réponds-lui directement."
                : "Sous ta vidéo, @{$author} répond à @{$to}, pas à toi. Ne réponds pas comme s’il t’était adressé : "
                    .'pas de remerciement, ni de réponse à un compliment ou une question destinés à quelqu’un d’autre. '
                    .'Interviens seulement avec un apport utile, sinon passe au suivant.',
        ];
    }
}
