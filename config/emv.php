<?php

return [

    /*
    |--------------------------------------------------------------------------
    | EMV — Earned Media Value
    |--------------------------------------------------------------------------
    |
    | Deux methodes coexistent volontairement, elles ne mesurent pas la meme
    | chose et aucune ne fait autorite :
    |
    |   - « cpm »       : (vues / 1000) x CPM de reference. Repond a « combien
    |                     aurait coute cette audience en publicite payante ».
    |   - « ayzenberg » : somme (nb d'actions x valeur unitaire). Repond a
    |                     « que vaut l'engagement de la communaute ».
    |
    | Ces valeurs sont des ORDRES DE GRANDEUR marche, pas des constantes. Elles
    | sont surchargeables sans deploiement depuis /settings (cle `emv_rates`),
    | et le calcul se fait toujours a la volee : changer un tarif recalcule tout
    | l'historique. Ne jamais persister un montant EMV en base, il serait fige
    | sur un tarif perime.
    |
    */

    'currency' => env('EMV_CURRENCY', 'EUR'),

    'defaults' => [
        // CPM de reference, dans la devise ci-dessus, pour 1000 vues.
        'cpm' => 8.0,

        // Valeur unitaire par action, methode Ayzenberg.
        'actions' => [
            // 0 a dessein : valoriser la vue ici reviendrait a additionner deux
            // fois la meme audience quand on affiche les deux methodes cote a
            // cote. A remonter seulement si on assume de ne lire que l'index.
            'view' => 0.0,
            'like' => 0.05,
            'comment' => 0.30,
            'share' => 0.50,
            'bookmark' => 0.20,
        ],
    ],

    /*
    | Surcharges par plateforme. Une cle absente retombe sur `defaults`.
    |
    | `views_available` a false = l'API ne rend aucune vue (cf. les services
    | App\Services\Stats\*StatsService). La methode CPM y est structurellement
    | impossible : on ne l'estime pas, on la declare non couverte.
    */
    'platforms' => [

        'facebook' => [
            'cpm' => 7.5,
            'actions' => ['like' => 0.05, 'comment' => 0.30, 'share' => 0.60],
        ],

        'instagram' => [
            'cpm' => 8.5,
            'actions' => ['like' => 0.06, 'comment' => 0.40, 'share' => 0.60, 'bookmark' => 0.35],
        ],

        'threads' => [
            'cpm' => 6.0,
            'actions' => ['like' => 0.04, 'comment' => 0.25, 'share' => 0.40],
        ],

        'twitter' => [
            'cpm' => 6.5,
            'actions' => ['like' => 0.04, 'comment' => 0.25, 'share' => 0.45, 'bookmark' => 0.25],
        ],

        // Le CPM B2B est structurellement plus cher que le grand public.
        'linkedin' => [
            'cpm' => 25.0,
            'actions' => ['like' => 0.15, 'comment' => 0.90, 'share' => 1.50, 'bookmark' => 0.50],
        ],

        // Une vue YouTube est un visionnage, pas un affichage : elle vaut plus
        // cher qu'une impression de fil, d'ou le CPM eleve.
        'youtube' => [
            'cpm' => 12.0,
            'actions' => ['like' => 0.08, 'comment' => 0.50, 'share' => 0.60],
        ],

        'pinterest' => [
            'cpm' => 5.0,
            'actions' => ['like' => 0.04, 'comment' => 0.25, 'share' => 0.40, 'bookmark' => 0.30],
        ],

        'telegram' => [
            'cpm' => 4.0,
            'actions' => ['like' => 0.03, 'comment' => 0.20, 'share' => 0.35],
        ],

        // score = upvotes nets, pas des likes : volontairement sous-value.
        'reddit' => [
            'views_available' => false,
            'actions' => ['like' => 0.03, 'comment' => 0.25],
        ],

        'bluesky' => [
            'views_available' => false,
            'actions' => ['like' => 0.04, 'comment' => 0.25, 'share' => 0.40, 'bookmark' => 0.20],
        ],
    ],
];
