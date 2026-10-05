<?php

/**
 * Plafonds par réseau, utilisés UNIQUEMENT pour les fichiers marqués
 * `preserve_original` (cf `MediaVariantService`).
 *
 * À quoi ça sert : jusqu'ici la normalisation avait lieu à l'upload, une fois
 * pour tous les réseaux, donc calée sur la contrainte la plus sévère — Bluesky
 * et ses 50 Mo. Instagram, qui accepte bien davantage, recevait quand même le
 * fichier dégradé. Ces plafonds permettent de ne réduire que pour le réseau qui
 * l'exige, et de laisser l'original partir partout ailleurs.
 *
 * Valeurs volontairement CONSERVATRICES : un plafond trop bas ne coûte qu'une
 * variante inutile, un plafond trop haut coûte un échec de publication. Quand
 * un réseau n'impose rien d'exploitable (YouTube), tout est à `null` et aucune
 * variante n'est fabriquée.
 *
 * `video_requires_h264` traduit le refus par Meta des pixel formats 10-bit et
 * des profils 4:2:2 / 4:4:4 (cf `VideoNormalizer::needsNormalization`).
 */
return [
    'platforms' => [
        'instagram' => [
            'image_max_mb' => 8,
            'video_max_mb' => 1024,
            'video_requires_h264' => true,
            'video_max_dimension' => 1920,
        ],
        'facebook' => [
            'image_max_mb' => 25,
            'video_max_mb' => 1024,
            'video_requires_h264' => true,
            'video_max_dimension' => 1920,
        ],
        'threads' => [
            'image_max_mb' => 8,
            'video_max_mb' => 1024,
            'video_requires_h264' => true,
            'video_max_dimension' => 1920,
        ],
        // Bluesky est le réseau le plus contraint, et c'est lui qui tirait toute
        // la médiathèque vers le bas. `BlueskyAdapter` compresse déjà les images
        // sous 1 Mo à la publication ; le plafond est répété ici pour que la
        // variante soit calculée une fois et réutilisée.
        'bluesky' => [
            'image_max_mb' => 1,
            'video_max_mb' => 50,
            'video_requires_h264' => true,
            'video_max_dimension' => 1920,
        ],
        // Bot API Telegram : 50 Mo, codec libre.
        'telegram' => [
            'image_max_mb' => 10,
            'video_max_mb' => 50,
            'video_requires_h264' => false,
            'video_max_dimension' => null,
        ],
        // `TwitterAdapter::compressVideo` applique en plus son propre plafond
        // agressif (800 kbps) pour le palier gratuit.
        'twitter' => [
            'image_max_mb' => 5,
            'video_max_mb' => 512,
            'video_requires_h264' => true,
            'video_max_dimension' => 1920,
        ],
        'linkedin' => [
            'image_max_mb' => 10,
            'video_max_mb' => 200,
            'video_requires_h264' => true,
            'video_max_dimension' => 1920,
        ],
        'pinterest' => [
            'image_max_mb' => 20,
            'video_max_mb' => 2048,
            'video_requires_h264' => true,
            'video_max_dimension' => 1920,
        ],
        // Aucune contrainte atteignable par nos fichiers : l'original part tel quel.
        'youtube' => [
            'image_max_mb' => null,
            'video_max_mb' => null,
            'video_requires_h264' => false,
            'video_max_dimension' => null,
        ],
    ],

    // Qualité JPEG de départ d'une variante image, puis plancher. On vise la
    // qualité visuelle, pas une taille de fichier : rien à voir avec la cible
    // 200-500 ko de `ProcessesImages`, qui est le comportement qu'on évite ici.
    'image_quality_max' => 92,
    'image_quality_min' => 80,
];
