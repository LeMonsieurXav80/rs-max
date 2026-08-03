<?php

namespace App\Services\Carousel;

/**
 * Palette du thème : résout une couleur en suivant sa chaîne de repli.
 *
 * Le manifeste (`config/carousel.theme_colors`) déclare pour chaque couleur un
 * `fallback` : la couleur dont elle hérite tant qu'elle n'est pas réglée. Une
 * brique peut donc demander `text_secondary` alors que le thème ne connaît que
 * `text` — elle reçoit `text`, et le rendu ne change pas.
 *
 * C'est ce qui permet d'ENRICHIR la palette sans casser l'existant : les thèmes
 * déjà enregistrés (brouillons en cache, appels API) n'ont que les quatre
 * couleurs d'origine, et continuent de rendre à l'identique.
 *
 * Les briques doivent passer par ici plutôt que par `$theme['x'] ?? '#…'` : une
 * valeur en dur dans une brique échappe au thème, et ça ne se voit qu'à l'image.
 */
final class Palette
{
    /**
     * Couleur résolue : valeur du thème, sinon repli déclaré, sinon défaut du
     * manifeste. `$hard` sert de dernier recours si le manifeste ne dit rien.
     *
     * @param  array<string, mixed>  $theme
     */
    public static function color(array $theme, string $key, string $hard = '#ffffff'): string
    {
        $colors = config('carousel.theme_colors', []);
        $seen = [];

        while ($key !== '' && ! isset($seen[$key])) {
            $seen[$key] = true;

            $value = $theme[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }

            $declared = $colors[$key] ?? null;
            if ($declared === null) {
                break;
            }

            // Défaut du manifeste : on ne remonte la chaîne que s'il n'y en a pas.
            if (! empty($declared['default'])) {
                return (string) $declared['default'];
            }

            $key = (string) ($declared['fallback'] ?? '');
        }

        return $hard;
    }

    /** Texte courant. */
    public static function text(array $theme): string
    {
        return self::color($theme, 'text', '#ffffff');
    }

    /** Sous-titres, paragraphes, libellés. */
    public static function textSecondary(array $theme): string
    {
        return self::color($theme, 'text_secondary', '#ffffff');
    }

    /** Notes, sources, légendes. */
    public static function textMuted(array $theme): string
    {
        return self::color($theme, 'text_muted', '#ffffff');
    }

    /** Couleur de marque. */
    public static function accent(array $theme): string
    {
        return self::color($theme, 'accent', '#0083ff');
    }

    /** Seconde couleur de marque (dégradés, séries d'un graphique). */
    public static function accentSecondary(array $theme): string
    {
        return self::color($theme, 'accent_secondary', '#0083ff');
    }

    /** Fond des slides sans image. */
    public static function background(array $theme): string
    {
        return self::color($theme, 'background', '#0f0f1a');
    }

    /** Fond secondaire : cartes, filets, pistes des barres. */
    public static function backgroundAlt(array $theme): string
    {
        return self::color($theme, 'background_alt', '#0f0f1a');
    }

    /** Voile posé sur les photos. */
    public static function overlay(array $theme): string
    {
        return self::color($theme, 'overlay', '#000000');
    }

    /**
     * Opacité à appliquer au texte secondaire / discret.
     *
     * Sans couleur dédiée, les briques rendaient ces textes dans la couleur
     * principale atténuée à l'opacité. Dès qu'une couleur dédiée est réglée, la
     * nuance est portée par la couleur elle-même : l'atténuer une seconde fois
     * la délaverait.
     *
     * @param  array<string, mixed>  $theme
     */
    public static function fade(array $theme, string $key, float $whenInherited): float
    {
        return self::isSet($theme, $key) ? 1.0 : $whenInherited;
    }

    /** Cette couleur est-elle explicitement réglée dans le thème ? */
    public static function isSet(array $theme, string $key): bool
    {
        $value = $theme[$key] ?? null;

        return is_string($value) && $value !== '';
    }

    /**
     * Couleur hex + canal alpha, pour les fonds translucides (pistes de barres,
     * cartes) qui doivent rester lisibles quel que soit le fond choisi.
     */
    public static function alpha(string $hex, float $opacity): string
    {
        $channel = str_pad(dechex((int) round(max(0, min(1, $opacity)) * 255)), 2, '0', STR_PAD_LEFT);

        return $hex.$channel;
    }
}
