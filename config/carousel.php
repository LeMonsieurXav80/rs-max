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

    /*
    |--------------------------------------------------------------------------
    | Ancres de position du bloc de texte (slots de type `position`)
    |--------------------------------------------------------------------------
    |
    | Grille 3×3. Combinée au slot `offset` (décalage fin en % de la hauteur),
    | elle permet de remonter/redescendre un titre SANS créer une brique par
    | emplacement. Le dégradé de lisibilité suit l'ancre verticale (App\Services\
    | Carousel\Anchor).
    |
    */
    'positions' => [
        'top-left' => 'Haut gauche',
        'top-center' => 'Haut centre',
        'top-right' => 'Haut droite',
        'middle-left' => 'Milieu gauche',
        'middle-center' => 'Milieu centre',
        'middle-right' => 'Milieu droite',
        'bottom-left' => 'Bas gauche',
        'bottom-center' => 'Bas centre',
        'bottom-right' => 'Bas droite',
    ],

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
    | `slots` : champs éditables. Deux écritures possibles :
    |             'title' => 'Titre'                      (raccourci = champ texte)
    |             'title' => ['label'=>…, 'type'=>…, …]   (forme complète)
    |           Types : text, textarea, image, position, range, select.
    |           BrickRegistry normalise, valide et nettoie à partir de ça — le
    |           Studio ET l'API REST s'y adossent, rien à écrire en double.
    | `ratios`: ratios compatibles ('*' = tous).
    |
    | Phase 3 ajoutera `ports` (grammaire d'adjacence) et la continuité d'image.
    |
    */
    'bricks' => [

        'photo-title-bl' => [
            // Le slug reste `photo-title-bl` (référencé par carousel.overlay_brick),
            // mais l'emplacement du titre est désormais un slot : le nom le reflète.
            'name' => 'Photo + titre positionnable',
            'description' => 'Image plein cadre, dégradé sombre, titre (et sous-titre) posés à l’emplacement de ton choix. Par défaut en bas à gauche — c’est la brique de l’incrustation overlay.',
            'view' => 'carousel.bricks.photo-title-bl',
            'ratios' => ['*'],
            'slots' => [
                'image' => ['label' => 'Photo de fond', 'type' => 'image'],
                'title' => ['label' => 'Titre', 'type' => 'text', 'max_length' => 200],
                'subtitle' => ['label' => 'Sous-titre (optionnel)', 'type' => 'text', 'max_length' => 300],
                'position' => ['label' => 'Emplacement du titre', 'type' => 'position', 'default' => 'bottom-left'],
                'offset' => [
                    'label' => 'Décalage vertical',
                    'type' => 'range',
                    'min' => -25, 'max' => 25, 'step' => 1, 'default' => 0, 'unit' => '%',
                ],
            ],
        ],

        'image-full' => [
            'name' => 'Image seule (plein cadre)',
            'description' => 'Uniquement l’image, recadrée plein cadre (cover). Aucun texte, aucun dégradé — pour insérer une image existante telle quelle.',
            'view' => 'carousel.bricks.image-full',
            'ratios' => ['*'],
            'slots' => [
                'image' => 'Image',
            ],
        ],

        'text-on-image' => [
            'name' => 'Texte sur image de fond',
            'description' => 'Image de fond assombrie, bloc de texte (titre large + paragraphe) positionnable. Idéal slide de transition ou citation.',
            'view' => 'carousel.bricks.text-on-image',
            'ratios' => ['*'],
            'slots' => [
                'image' => ['label' => 'Image de fond', 'type' => 'image'],
                'title' => ['label' => 'Titre', 'type' => 'text', 'max_length' => 200],
                'body' => ['label' => 'Paragraphe (optionnel)', 'type' => 'textarea', 'max_length' => 600],
                'position' => ['label' => 'Emplacement du texte', 'type' => 'position', 'default' => 'middle-center'],
                'offset' => [
                    'label' => 'Décalage vertical',
                    'type' => 'range',
                    'min' => -25, 'max' => 25, 'step' => 1, 'default' => 0, 'unit' => '%',
                ],
            ],
        ],

        'bold-text' => [
            'name' => 'Texte plein (sans image)',
            'description' => 'Fond uni + texte large centré. Slide d’ouverture ou de punchline, aucune image requise.',
            'view' => 'carousel.bricks.bold-text',
            'ratios' => ['*'],
            'slots' => [
                'title' => ['label' => 'Titre', 'type' => 'text', 'max_length' => 200],
                'subtitle' => ['label' => 'Sous-titre (optionnel)', 'type' => 'text', 'max_length' => 300],
                'position' => ['label' => 'Emplacement du texte', 'type' => 'position', 'default' => 'middle-left'],
                'offset' => [
                    'label' => 'Décalage vertical',
                    'type' => 'range',
                    'min' => -25, 'max' => 25, 'step' => 1, 'default' => 0, 'unit' => '%',
                ],
            ],
        ],

        'stat-grid' => [
            'name' => 'Grille de chiffres',
            'description' => 'Titre + grille de chiffres clés (chiffre à l’accent, libellé dessous). Une ligne = « chiffre | libellé ».',
            'view' => 'carousel.bricks.stat-grid',
            'ratios' => ['*'],
            'slots' => [
                'title' => ['label' => 'Titre (optionnel)', 'type' => 'text', 'max_length' => 120],
                'items' => [
                    'label' => 'Chiffres — une ligne par item : « 42 % | des lecteurs abandonnent »',
                    'type' => 'textarea',
                    'max_length' => 600,
                ],
                'columns' => [
                    'label' => 'Colonnes',
                    'type' => 'select',
                    'options' => [1 => '1 colonne', 2 => '2 colonnes'],
                    'default' => 2,
                ],
            ],
        ],

        'quote' => [
            'name' => 'Citation',
            'description' => 'Citation en grand avec guillemet d’accent et auteur. Image de fond optionnelle.',
            'view' => 'carousel.bricks.quote',
            'ratios' => ['*'],
            'slots' => [
                'quote' => ['label' => 'Citation', 'type' => 'textarea', 'max_length' => 400],
                'author' => ['label' => 'Auteur / source (optionnel)', 'type' => 'text', 'max_length' => 120],
                'image' => ['label' => 'Image de fond (optionnelle)', 'type' => 'image'],
                'position' => ['label' => 'Emplacement du texte', 'type' => 'position', 'default' => 'middle-left'],
                'offset' => [
                    'label' => 'Décalage vertical',
                    'type' => 'range',
                    'min' => -25, 'max' => 25, 'step' => 1, 'default' => 0, 'unit' => '%',
                ],
            ],
        ],

        'table-rows' => [
            'name' => 'Tableau',
            'description' => 'Lignes libellé / valeur avec filets fins. Une ligne = « libellé | valeur ». Idéal comparatif ou récapitulatif.',
            'view' => 'carousel.bricks.table-rows',
            'ratios' => ['*'],
            'slots' => [
                'title' => ['label' => 'Titre (optionnel)', 'type' => 'text', 'max_length' => 120],
                'rows' => [
                    'label' => 'Lignes — une par ligne : « Protéines | 24 g »',
                    'type' => 'textarea',
                    'max_length' => 800,
                ],
                'note' => ['label' => 'Note de bas de slide (optionnelle)', 'type' => 'text', 'max_length' => 200],
            ],
        ],

        'numbered' => [
            'name' => 'Slide numérotée',
            'description' => 'Numéro d’étape (pastille + filigrane géant) + titre et texte. Pour les carrousels en étapes.',
            'view' => 'carousel.bricks.numbered',
            'ratios' => ['*'],
            'slots' => [
                'number' => ['label' => 'Numéro (ex. 01)', 'type' => 'text', 'max_length' => 4],
                'title' => ['label' => 'Titre', 'type' => 'text', 'max_length' => 160],
                'body' => ['label' => 'Texte (optionnel)', 'type' => 'textarea', 'max_length' => 400],
                'image' => ['label' => 'Image de fond (optionnelle)', 'type' => 'image'],
                'position' => ['label' => 'Emplacement du texte', 'type' => 'position', 'default' => 'middle-left'],
                'offset' => [
                    'label' => 'Décalage vertical',
                    'type' => 'range',
                    'min' => -25, 'max' => 25, 'step' => 1, 'default' => 0, 'unit' => '%',
                ],
            ],
        ],

        'cta-end' => [
            'name' => 'Slide de fin (appel à l’action)',
            'description' => 'Dernière slide : accroche, précision et pastille de compte (@handle). Image de fond optionnelle.',
            'view' => 'carousel.bricks.cta-end',
            'ratios' => ['*'],
            'slots' => [
                'title' => ['label' => 'Accroche', 'type' => 'text', 'max_length' => 160],
                'subtitle' => ['label' => 'Précision (optionnelle)', 'type' => 'text', 'max_length' => 300],
                'handle' => ['label' => 'Compte (ex. @monsuper.compte)', 'type' => 'text', 'max_length' => 60],
                'image' => ['label' => 'Image de fond (optionnelle)', 'type' => 'image'],
                'position' => ['label' => 'Emplacement du texte', 'type' => 'position', 'default' => 'middle-center'],
                'offset' => [
                    'label' => 'Décalage vertical',
                    'type' => 'range',
                    'min' => -25, 'max' => 25, 'step' => 1, 'default' => 0, 'unit' => '%',
                ],
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Brouillons ouverts dans le Studio depuis l'API
    |--------------------------------------------------------------------------
    |
    | POST /api/carousel/studio-link dépose une composition en cache et renvoie
    | un lien vers le Studio pré-rempli. Rien n'est persisté : c'est une page
    | intermédiaire, le brouillon s'efface tout seul à l'expiration.
    |
    */
    'draft_ttl_hours' => (int) env('CAROUSEL_DRAFT_TTL_HOURS', 24),

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
