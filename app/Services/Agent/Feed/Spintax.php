<?php

namespace App\Services\Agent\Feed;

/**
 * Modèles « {a|b|c} » des commentaires automatiques du fil, tirés sans modèle de langue.
 * Un seul niveau d'accolades : un modèle imbriqué est refusé plutôt que mal compté.
 */
final class Spintax
{
    public const MAX_LENGTH = 280;

    private const GROUP = '/\{([^{}]*)\}/u';

    /** Motif de refus lisible, ou `null` si le modèle est utilisable. */
    public static function error(string $template): ?string
    {
        $template = trim($template);
        if ($template === '') {
            return 'Modèle vide.';
        }
        if (preg_match('/[\r\n]/', $template)) {
            return 'Un modèle tient sur une ligne.';
        }
        if (mb_strlen($template) > self::MAX_LENGTH) {
            return 'Modèle de plus de '.self::MAX_LENGTH.' caractères.';
        }
        $depth = 0;
        foreach (mb_str_split($template) as $char) {
            if ($char === '{' && ++$depth > 1) {
                return 'Accolades imbriquées : un seul niveau {a|b} est accepté.';
            }
            if ($char === '}' && --$depth < 0) {
                return 'Accolade fermante sans ouvrante.';
            }
        }
        if ($depth !== 0) {
            return 'Accolade ouvrante non fermée.';
        }
        if (trim(self::flatten($template, fn (array $options) => implode('', $options))) === '') {
            return 'Le modèle ne produit aucun texte.';
        }

        return null;
    }

    public static function expand(string $template): string
    {
        return self::flatten($template, fn (array $options) => $options[random_int(0, count($options) - 1)]);
    }

    public static function combinations(string $template): int
    {
        preg_match_all(self::GROUP, $template, $groups);

        return array_product(array_map(fn (string $group) => count(explode('|', $group)), $groups[1]));
    }

    /** @param  callable(list<string>): string  $pick */
    private static function flatten(string $template, callable $pick): string
    {
        $text = preg_replace_callback(self::GROUP, fn (array $m) => $pick(explode('|', $m[1])), $template);

        return trim(preg_replace('/[ \t]{2,}/u', ' ', $text));
    }
}
