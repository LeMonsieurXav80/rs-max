<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ratios supportés
    |--------------------------------------------------------------------------
    |
    | Un carrousel est verrouillé sur UN seul ratio pour tous ses slides
    | (contrainte Instagram). Le ratio se choisit au niveau du carrousel ; on ne
    | propose ensuite que les briques compatibles avec ce ratio.
    |
    | Registre ouvert/extensible : ajouter un ratio = ajouter une ligne (donnée,
    | pas une enum figée). `w`/`h` = résolution d'export canonique en pixels.
    |
    */
    'ratios' => [
        '1:1' => ['w' => 1080, 'h' => 1080, 'label' => 'Carré'],
        '4:5' => ['w' => 1080, 'h' => 1350, 'label' => 'Portrait'],
        '3:4' => ['w' => 1080, 'h' => 1440, 'label' => 'Portrait haut'],
        '9:16' => ['w' => 1080, 'h' => 1920, 'label' => 'Story / Reel'],
        '4:3' => ['w' => 1080, 'h' => 810, 'label' => 'Paysage'],
        '1.91:1' => ['w' => 1080, 'h' => 566, 'label' => 'Paysage lien'],
    ],

    'default_ratio' => '4:5',

    // Brique utilisée pour l'incrustation titre sur la 1re image d'un fil (feature overlay).
    'overlay_brick' => 'photo-title-bl',

    /*
    |--------------------------------------------------------------------------
    | Rendu
    |--------------------------------------------------------------------------
    |
    | `scale` = deviceScaleFactor Chromium (netteté). `chrome_path` force le
    | binaire (utile en local Mac / conteneur Alpine) ; null => auto-détection
    | Puppeteer. En prod Alpine on pointe PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium.
    |
    */
    'scale' => 2,
    'chrome_path' => env('CAROUSEL_CHROME_PATH'),
    'node_binary' => env('CAROUSEL_NODE_BINARY'),
    'npm_binary' => env('CAROUSEL_NPM_BINARY'),
    // --no-sandbox + --disable-dev-shm-usage : requis quand Chromium tourne en conteneur (Docker/Alpine).
    'no_sandbox' => (bool) env('CAROUSEL_NO_SANDBOX', false),

    /*
    |--------------------------------------------------------------------------
    | Polices disponibles pour les briques (self-hosted, embarquées en base64)
    |--------------------------------------------------------------------------
    |
    | Chaque entrée => [famille CSS, poids => nom de fichier dans storage/app/fonts].
    | Les fichiers sont peuplés/téléchargés via GoogleFontsService::ensureFont().
    |
    */
    'fonts' => [
        'Montserrat' => [
            400 => 'Montserrat-Regular.ttf',
            700 => 'Montserrat-Bold.ttf',
            800 => 'Montserrat-ExtraBold.ttf',
        ],
        'Poppins' => [
            400 => 'Poppins-Regular.ttf',
            700 => 'Poppins-Bold.ttf',
        ],
        'Playfair Display' => [
            700 => 'PlayfairDisplay-Bold.ttf',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Manifeste des briques de slide (Phase 1 : fichiers Blade, pas de table DB)
    |--------------------------------------------------------------------------
    |
    | Chaque brique = un template de slide nommé/décrit, scopé à des ratios.
    | `view`  : partial Blade dans resources/views/carousel/bricks/.
    | `slots` : champs éditables (clé => libellé humain).
    | `ratios`: ratios compatibles ('*' = tous).
    |
    | Phase 3 ajoutera `ports` (grammaire d'adjacence) et la continuité d'image.
    |
    */
    'bricks' => [

        'photo-title-bl' => [
            'name' => 'Photo + titre bas-gauche',
            'description' => 'Image plein cadre, dégradé sombre en bas, titre (et sous-titre) alignés en bas à gauche. Remplace l’ancienne incrustation overlay.',
            'view' => 'carousel.bricks.photo-title-bl',
            'ratios' => ['*'],
            'slots' => [
                'image' => 'Photo de fond',
                'title' => 'Titre',
                'subtitle' => 'Sous-titre (optionnel)',
            ],
        ],

        'text-on-image' => [
            'name' => 'Texte sur image de fond',
            'description' => 'Image de fond assombrie, bloc de texte centré (titre large + paragraphe). Idéal slide de transition ou citation.',
            'view' => 'carousel.bricks.text-on-image',
            'ratios' => ['*'],
            'slots' => [
                'image' => 'Image de fond',
                'title' => 'Titre',
                'body' => 'Paragraphe (optionnel)',
            ],
        ],

        'bold-text' => [
            'name' => 'Texte plein (sans image)',
            'description' => 'Fond uni + texte large centré. Slide d’ouverture ou de punchline, aucune image requise.',
            'view' => 'carousel.bricks.bold-text',
            'ratios' => ['*'],
            'slots' => [
                'title' => 'Titre',
                'subtitle' => 'Sous-titre (optionnel)',
            ],
        ],

    ],

    /*
    | Thème par défaut appliqué aux briques (surchargeable slide par slide via
    | les données `theme`). Couleurs en hex.
    */
    'theme' => [
        'background' => '#0f0f1a',
        'text' => '#ffffff',
        'accent' => '#0083ff',
        'overlay' => '#000000',
        'title_font' => 'Montserrat',
        'body_font' => 'Poppins',
    ],

];
