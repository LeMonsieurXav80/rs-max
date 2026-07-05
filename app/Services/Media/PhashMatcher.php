<?php

namespace App\Services\Media;

/**
 * Logique pure de comparaison de phash perceptuels (imagehash 8×8 → 64 bits,
 * représentés en 16 caractères hexadécimaux). Séparée de la commande pour être
 * testable sans HTTP ni Python.
 */
class PhashMatcher
{
    /**
     * Distance de Hamming entre deux phash hex (16 chars = 64 bits).
     * Retourne null si l'un des deux n'est pas un phash 16-hex valide.
     */
    public static function hamming(string $a, string $b): ?int
    {
        $a = self::normalize($a);
        $b = self::normalize($b);
        if ($a === null || $b === null) {
            return null;
        }

        // Deux moitiés de 32 bits : hexdec(8 chars) tient dans un int PHP 64 bits.
        $dHi = self::popcount(hexdec(substr($a, 0, 8)) ^ hexdec(substr($b, 0, 8)));
        $dLo = self::popcount(hexdec(substr($a, 8, 8)) ^ hexdec(substr($b, 8, 8)));

        return $dHi + $dLo;
    }

    /**
     * Confiance 0-100 dérivée de la distance : 100 - distance*8, bornée.
     * distance 0 → 100, distance 10 → 20, distance ≥ 13 → 0.
     */
    public static function confidence(int $distance): int
    {
        return max(0, min(100, 100 - $distance * 8));
    }

    /**
     * Normalise un phash en 16 chars hex minuscules, ou null si invalide.
     */
    private static function normalize(string $hex): ?string
    {
        $hex = strtolower(trim($hex));

        return preg_match('/^[0-9a-f]{16}$/', $hex) === 1 ? $hex : null;
    }

    /**
     * popcount d'un entier 32 bits (nombre de bits à 1).
     */
    private static function popcount(int $x): int
    {
        $x &= 0xFFFFFFFF;
        $count = 0;
        while ($x !== 0) {
            $count += $x & 1;
            $x >>= 1;
        }

        return $count;
    }
}
