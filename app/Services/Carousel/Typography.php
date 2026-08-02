<?php

namespace App\Services\Carousel;

/**
 * Échelle typographique du thème.
 *
 * Les briques dimensionnent leur texte en fraction de la hauteur du slide
 * (ex. un titre à 6,2 % de $h) : c'est ce qui rend un même gabarit lisible en
 * 1:1 comme en 9:16. Ces fractions restent le socle — `title_scale` et
 * `body_scale` viennent seulement les multiplier, pour qu'on puisse grossir ou
 * poser un texte sans réécrire une brique par goût typographique.
 *
 * Facteur neutre = 1.0 : un thème qui ne dit rien rend exactement comme avant.
 *
 * Les bornes ne sont pas cosmétiques. En dessous de MIN le texte devient
 * illisible sur mobile (une story se lit à bout de bras) ; au-dessus de MAX il
 * déborde du cadre, et un débordement ne se voit pas à la génération — il se
 * découvre une fois publié.
 */
final class Typography
{
    public const MIN = 0.6;

    public const MAX = 1.8;

    /** Facteur appliqué à tout ce qui est composé en police de titre. */
    public static function title(array $theme): float
    {
        return self::clamp($theme['title_scale'] ?? null);
    }

    /** Facteur appliqué à tout ce qui est composé en police de texte. */
    public static function body(array $theme): float
    {
        return self::clamp($theme['body_scale'] ?? null);
    }

    /**
     * Ramène une valeur quelconque (chaîne JSON, null, hors bornes) dans
     * l'intervalle utile. Le neutre est le repli de toute valeur non numérique.
     */
    public static function clamp(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 1.0;
        }

        return max(self::MIN, min(self::MAX, (float) $value));
    }
}
