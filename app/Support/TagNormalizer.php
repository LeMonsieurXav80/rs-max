<?php

namespace App\Support;

/**
 * Normalisation canonique des thematic_tags.
 *
 * Convention RS-Max :
 *  - minuscules
 *  - SANS accents (NFD + strip diacritiques)
 *  - séparateur entre mots = ESPACE (pas tiret, pas underscore)
 *  - max 60 caractères
 *  - dédup case-insensitive en préservant l'ordre d'apparition
 *
 * Utilisé par MediaApiController (ingest/enrich/validate/analyze-vision)
 * ET par la commande `media:normalize-tags` qui réécrit l'existant.
 */
class TagNormalizer
{
    /**
     * Normalise un tableau brut de tags vers la forme canonique.
     */
    public static function normalize(?array $tags): ?array
    {
        if ($tags === null) {
            return null;
        }

        $seen = [];
        $out = [];

        foreach ($tags as $raw) {
            if (! is_string($raw)) {
                continue;
            }

            // Si présence de ':', on jette la clé et on garde la valeur.
            // Ex : "couleurs dominantes: bleu, blanc" -> "bleu, blanc"
            if (str_contains($raw, ':')) {
                $raw = explode(':', $raw, 2)[1];
            }

            // Si virgule, on splitte chaque morceau.
            $parts = str_contains($raw, ',') ? explode(',', $raw) : [$raw];

            foreach ($parts as $part) {
                $clean = self::canonicalize($part);
                if ($clean === '' || mb_strlen($clean) > 60 || isset($seen[$clean])) {
                    continue;
                }
                $seen[$clean] = true;
                $out[] = $clean;
            }
        }

        return $out;
    }

    /**
     * Transformations sur un seul tag : lowercase, strip accents,
     * séparateurs unifiés en espace, collapse espaces, trim.
     */
    public static function canonicalize(string $tag): string
    {
        $s = mb_strtolower($tag, 'UTF-8');

        // _ et - → espace (avant le strip-accents pour éviter de coller des mots)
        $s = preg_replace('/[_\-]+/u', ' ', $s);

        // Strip accents : décompose en NFD puis supprime les marques combinantes.
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($s, \Normalizer::FORM_D);
            if ($decomposed !== false) {
                $s = preg_replace('/\p{M}+/u', '', $decomposed);
            }
        } else {
            // Fallback si l'extension intl n'est pas dispo : table de mapping minimale.
            $s = strtr($s, [
                'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a',
                'ç' => 'c',
                'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
                'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
                'ñ' => 'n',
                'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
                'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
                'ý' => 'y', 'ÿ' => 'y',
            ]);
        }

        // Collapse les espaces multiples (création possible par les substitutions ci-dessus).
        $s = preg_replace('/\s+/u', ' ', $s);

        // Trim espaces + ponctuation parasite en bordure.
        return trim($s, " \"'.-");
    }
}
