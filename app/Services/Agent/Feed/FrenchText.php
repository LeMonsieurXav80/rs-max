<?php

namespace App\Services\Agent\Feed;

/**
 * Repli quand le réseau ne marque pas la langue (Threads, ou X sans attribut `lang`) :
 * mots courants du français contre ceux de l'anglais. Dans le doute, ce n'est pas du français.
 */
final class FrenchText
{
    private const FRENCH = ['le', 'la', 'les', 'de', 'des', 'du', 'et', 'est', 'un', 'une', 'pour', 'pas', 'que', 'qui', 'dans',
        'sur', 'avec', 'je', 'tu', 'il', 'elle', 'on', 'nous', 'vous', 'mon', 'ma', 'mes', 'ce', 'cette', 'ca', 'au', 'aux',
        'tres', 'trop', 'plus', 'mais', 'ou', 'comme', 'fait', 'faire', 'bien', 'jour', 'tout', 'quand', 'sans', 'meme'];

    private const ENGLISH = ['the', 'and', 'is', 'to', 'of', 'in', 'for', 'with', 'my', 'your', 'this', 'that', 'it', 'you',
        'i', 'on', 'at', 'be', 'are', 'was', 'what', 'just', 'day', 'get', 'got', 'do', 'not', 'but', 'so', 'all'];

    public static function looksFrench(string $text): bool
    {
        $words = explode(' ', FeedMatcher::normalize($text));
        $french = count(array_intersect($words, self::FRENCH));
        $english = count(array_intersect($words, self::ENGLISH));
        $accents = preg_match('/[éèêàçùâîôûœ]/iu', $text) === 1;

        return $french > $english && ($french >= 2 || ($french >= 1 && $accents));
    }
}
