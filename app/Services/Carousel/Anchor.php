<?php

namespace App\Services\Carousel;

/**
 * Traduit une ancre de position (`bottom-left`, `top-center`, …) en propriétés CSS
 * pour les briques : alignement du bloc de texte, alignement typographique et sens
 * du dégradé de lisibilité.
 *
 * Mutualisé entre les briques pour que « remonter / redescendre le titre » soit un
 * SLOT et non une nouvelle brique par emplacement.
 */
class Anchor
{
    /**
     * @return array{vertical: string, horizontal: string, justify: string, align: string, text_align: string, scrim: string}
     */
    public static function resolve(?string $position, string $overlay = '#000000'): array
    {
        [$vertical, $horizontal] = self::split($position);

        return [
            'vertical' => $vertical,
            'horizontal' => $horizontal,
            // Conteneur en flex-direction:column => justify-content pilote la verticale.
            'justify' => match ($vertical) {
                'top' => 'flex-start',
                'middle' => 'center',
                default => 'flex-end',
            },
            'align' => match ($horizontal) {
                'center' => 'center',
                'right' => 'flex-end',
                default => 'flex-start',
            },
            'text_align' => match ($horizontal) {
                'center' => 'center',
                'right' => 'right',
                default => 'left',
            },
            'scrim' => self::scrim($vertical, $overlay),
        ];
    }

    /**
     * Décalage vertical fin (en % de la hauteur du slide) → transform CSS.
     * Négatif = remonte, positif = redescend.
     */
    public static function offsetTransform(mixed $offset, int $h): string
    {
        $percent = is_numeric($offset) ? (float) $offset : 0.0;
        if (abs($percent) < 0.01) {
            return '';
        }

        return 'transform:translateY('.round($h * $percent / 100, 2).'px);';
    }

    /**
     * Voile de lisibilité derrière le texte, orienté selon l'ancre verticale :
     * un dégradé qui part du bord où se trouve le texte (uniforme si centré).
     */
    private static function scrim(string $vertical, string $overlay): string
    {
        return match ($vertical) {
            'top' => 'position:absolute; left:0; right:0; top:0; height:62%;'
                ." background:linear-gradient(to bottom, {$overlay}e6 0%, {$overlay}99 45%, transparent 100%);",
            'middle' => "position:absolute; inset:0; background:{$overlay}; opacity:0.45;",
            default => 'position:absolute; left:0; right:0; bottom:0; height:62%;'
                ." background:linear-gradient(to top, {$overlay}e6 0%, {$overlay}99 45%, transparent 100%);",
        };
    }

    /**
     * @return array{0: string, 1: string} [vertical, horizontal]
     */
    private static function split(?string $position): array
    {
        $parts = explode('-', (string) $position, 2);
        $vertical = in_array($parts[0] ?? '', ['top', 'middle', 'bottom'], true) ? $parts[0] : 'bottom';
        $horizontal = in_array($parts[1] ?? '', ['left', 'center', 'right'], true) ? $parts[1] : 'left';

        return [$vertical, $horizontal];
    }
}
