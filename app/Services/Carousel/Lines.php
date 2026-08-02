<?php

namespace App\Services\Carousel;

/**
 * Parse les slots « liste » des briques riches (grille de chiffres, tableau…).
 *
 * Convention d'écriture, volontairement tapable dans un simple textarea :
 *   une ligne = un item, deux colonnes séparées par une barre verticale.
 *
 *     42 % | des lecteurs abandonnent
 *     3 min | temps de lecture moyen
 *
 * Évite d'avoir à refondre l'UI du Studio (et reste trivial à produire en API).
 */
class Lines
{
    /**
     * @return array<int, array{0: string, 1: string}> [gauche, droite] par ligne
     */
    public static function parse(mixed $raw, int $limit = 8): array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $items = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = explode('|', $line, 2);
            $items[] = [trim($parts[0]), trim($parts[1] ?? '')];

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }
}
