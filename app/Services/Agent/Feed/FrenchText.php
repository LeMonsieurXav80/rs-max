<?php

namespace App\Services\Agent\Feed;

/**
 * Repli quand le réseau ne marque pas la langue (Threads, ou X sans attribut `lang`) :
 * mots courants du français contre ceux de l'anglais, de l'espagnol et du portugais. Dans le doute, ce n'est pas du français.
 */
final class FrenchText
{
    private const FRENCH = ['le', 'la', 'les', 'de', 'des', 'du', 'et', 'est', 'un', 'une', 'pour', 'pas', 'que', 'qui', 'dans',
        'sur', 'avec', 'je', 'tu', 'il', 'elle', 'on', 'nous', 'vous', 'mon', 'ma', 'mes', 'ce', 'cette', 'ca', 'au', 'aux',
        'tres', 'trop', 'plus', 'mais', 'ou', 'comme', 'fait', 'faire', 'bien', 'jour', 'tout', 'quand', 'sans', 'meme'];

    private const ENGLISH = ['the', 'and', 'is', 'to', 'of', 'in', 'for', 'with', 'my', 'your', 'this', 'that', 'it', 'you',
        'i', 'on', 'at', 'be', 'are', 'was', 'what', 'just', 'day', 'get', 'got', 'do', 'not', 'but', 'so', 'all'];

    /**
     * Espagnol et portugais, sans les mots qu'ils partagent avec le français (« es », « y », « con », « mais »…) :
     * le 14/11/2026, « Ni antes ni después, todo llega cuando tiene que llegar » passait pour du français.
     */
    private const IBERIAN = ['el', 'los', 'las', 'del', 'por', 'para', 'pero', 'muy', 'todo', 'toda', 'cuando', 'tiene', 'ni',
        'hay', 'esta', 'este', 'esto', 'mucho', 'nao', 'em', 'com', 'uma', 'os', 'dos', 'das', 'voce', 'isso', 'hoje', 'bom', 'dia', 'eu'];

    public static function looksFrench(string $text): bool
    {
        $words = explode(' ', FeedMatcher::normalize($text));
        $french = count(array_intersect($words, self::FRENCH));
        $english = count(array_intersect($words, self::ENGLISH)) + count(array_intersect($words, self::IBERIAN));
        $accents = preg_match('/[éèêàçùâîôûœ]/iu', $text) === 1;

        return $french > $english && ($french >= 2 || ($french >= 1 && $accents));
    }

    /** `fr`, `other`, ou `none` quand il ne reste rien à lire une fois liens, mentions et hashtags retirés. */
    public static function language(string $text): string
    {
        if (FeedMatcher::normalize(preg_replace(['~https?://\S+~', '/[@#][\w.]+/u'], ' ', $text)) === '') {
            return 'none';
        }

        return self::looksFrench($text) ? 'fr' : 'other';
    }
}
