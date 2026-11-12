<?php

namespace App\Services\Agent;

use App\Services\Inbox\InboxFollowup;

/**
 * Ce qu’une réponse publique X ne fait jamais, quel que soit le persona : séduire et relancer.
 * Le 12/11/2026, malgré le persona, Wildy Caro a répondu « …un petit côté irrésistible ! 🥰 » et « …hein ? 😄 ».
 */
class XReplyStyle
{
    private const LOVE = '/[\x{1F970}\x{1F60D}\x{1F618}\x{1F617}\x{1F619}\x{1F61A}\x{1F48B}\x{1F48C}\x{1F48D}\x{1F491}\x{1F48F}\x{1F498}-\x{1F49F}\x{2764}\x{2665}\x{1F5A4}\x{1F90D}\x{1F90E}\x{1F9E1}\x{1F49A}\x{1F499}\x{1F49C}\x{1F49B}\x{1F63B}\x{1F48E}\x{1F339}]/u';

    /** null si la réponse convient, sinon la consigne de reformulation. */
    public static function violation(string $reply, ?string $target): ?string
    {
        if (preg_match(self::LOVE, $reply)) {
            return 'Aucun emoji de cœur, de bisou, d’amour ou de rose.';
        }
        $end = trim(preg_replace('/[\p{So}\p{Sk}\x{FE0F}\x{200D}\s]+$/u', '', $reply));
        if (preg_match('/[?？]$/u', $end) && ! app(InboxFollowup::class)->isQuestion($target)) {
            return 'Ne termine pas par une question : la réponse clôt l’échange.';
        }

        return null;
    }

    public const REJECTION = 'Réponse refusée : ni emoji de séduction, ni question finale quand la personne n’en pose pas. Reformule.';
}
