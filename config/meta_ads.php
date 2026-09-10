<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pilotage des campagnes Meta
    |--------------------------------------------------------------------------
    |
    | Ces endpoints engagent de l'argent reel, et sont concus pour etre appeles
    | par une IA. Les garde-fous ci-dessous ne sont donc pas du confort : ils
    | sont la derniere barriere entre une hallucination et une facture.
    |
    | Principes :
    |   - la lecture est toujours ouverte, l'ecriture est opt-in ;
    |   - toute ecriture est en dry-run par defaut (cf. la convention du depot
    |     sur /api/partners/{partner}/media/detach) ;
    |   - creation et suppression de campagnes sont HORS PERIMETRE, a dessein :
    |     une campagne qui se cree toute seule depense sans qu'un humain ait vu
    |     le ciblage ni le creatif.
    |
    */

    // Interrupteur general. A false, toute ecriture est refusee en 403, meme
    // avec dry_run=false. Le seul moyen d'ouvrir l'ecriture est un acte
    // deliberatement explicite (variable d'env ou edition de ce fichier).
    'write_enabled' => env('META_ADS_WRITE_ENABLED', false),

    // Hausse de budget maximale toleree en une fois, en pourcentage du budget
    // courant. Au-dela, la requete est refusee sauf `force: true`.
    // Empeche le classique « x10 » sur une erreur d'unite ou de raisonnement.
    'max_budget_increase_pct' => env('META_ADS_MAX_BUDGET_INCREASE_PCT', 50),

    // Plafond absolu du budget quotidien, dans la devise du compte. Aucune
    // requete ne peut le depasser, `force` compris : c'est un mur, pas un seuil.
    'max_daily_budget' => env('META_ADS_MAX_DAILY_BUDGET', 100),

    // Fenetre par defaut des insights, en jours.
    'default_insights_days' => 30,

    /*
    | Statuts acceptes en ecriture. `DELETED` et `ARCHIVED` sont volontairement
    | absents : ce sont des gestes destructifs, ils se font dans le Gestionnaire
    | de publicites, sous les yeux d'un humain.
    */
    'allowed_statuses' => ['ACTIVE', 'PAUSED'],

    // Duree maximale d'une sponsorisation, en jours. Au-dela, on ne parle plus
    // d'un coup de pouce sur une publication mais d'une campagne a piloter dans
    // le Gestionnaire.
    'max_days' => 90,

    /*
    |--------------------------------------------------------------------------
    | Catalogue des objectifs (ODAX)
    |--------------------------------------------------------------------------
    |
    | Meta impose des couples valides objectif → objectif d'optimisation →
    | evenement facture. Une combinaison hors clous part chez Graph et revient
    | en « Invalid parameter » sans dire lequel : le catalogue sert a n'envoyer
    | que des couples qui existent, et a proposer un choix lisible en francais.
    |
    | `boostable` : l'objectif a un sens pour SPONSORISER UNE PUBLICATION
    | EXISTANTE. OUTCOME_APP_PROMOTION en est absent — il exige une app.
    | `needs_pixel` : sans pixel ni evenement configure, la campagne tourne mais
    | n'optimise sur rien. On previent, on ne bloque pas.
    |
    */
    'objectives' => [
        'OUTCOME_ENGAGEMENT' => [
            'label' => 'Interactions',
            'description' => 'Likes, commentaires, partages sur la publication. Le choix par défaut pour un boost.',
            'boostable' => true,
            'needs_link' => false,
            'needs_pixel' => false,
            'default_goal' => 'POST_ENGAGEMENT',
            'goals' => [
                'POST_ENGAGEMENT' => 'Interactions avec la publication',
                'REACH' => 'Couverture (personnes atteintes)',
                'IMPRESSIONS' => 'Impressions',
                'THRUPLAY' => 'Vues de vidéo (ThruPlay)',
                'PAGE_LIKES' => 'Mentions J\'aime de la Page',
                'LINK_CLICKS' => 'Clics sur le lien',
            ],
        ],
        'OUTCOME_AWARENESS' => [
            'label' => 'Notoriété',
            'description' => 'Montrer la publication au plus grand nombre, au coût le plus bas.',
            'boostable' => true,
            'needs_link' => false,
            'needs_pixel' => false,
            'default_goal' => 'REACH',
            'goals' => [
                'REACH' => 'Couverture (personnes atteintes)',
                'IMPRESSIONS' => 'Impressions',
                'AD_RECALL_LIFT' => 'Mémorisation publicitaire',
                'THRUPLAY' => 'Vues de vidéo (ThruPlay)',
            ],
        ],
        'OUTCOME_TRAFFIC' => [
            'label' => 'Trafic',
            'description' => 'Envoyer du monde vers un site. La publication doit contenir un lien.',
            'boostable' => true,
            'needs_link' => true,
            'needs_pixel' => false,
            'default_goal' => 'LINK_CLICKS',
            'goals' => [
                'LINK_CLICKS' => 'Clics sur le lien',
                'LANDING_PAGE_VIEWS' => 'Vues de page de destination',
                'REACH' => 'Couverture (personnes atteintes)',
                'IMPRESSIONS' => 'Impressions',
            ],
        ],
        'OUTCOME_LEADS' => [
            'label' => 'Prospects',
            'description' => 'Formulaires ou messages. Demande un pixel ou un formulaire déjà configuré.',
            'boostable' => true,
            'needs_link' => true,
            'needs_pixel' => true,
            'default_goal' => 'LINK_CLICKS',
            'goals' => [
                'LINK_CLICKS' => 'Clics sur le lien',
                'LANDING_PAGE_VIEWS' => 'Vues de page de destination',
                'OFFSITE_CONVERSIONS' => 'Conversions sur le site (pixel requis)',
                'LEAD_GENERATION' => 'Formulaire instantané',
            ],
        ],
        'OUTCOME_SALES' => [
            'label' => 'Ventes',
            'description' => 'Achats sur le site. Sans pixel, l\'optimisation n\'a rien à apprendre.',
            'boostable' => true,
            'needs_link' => true,
            'needs_pixel' => true,
            'default_goal' => 'LINK_CLICKS',
            'goals' => [
                'LINK_CLICKS' => 'Clics sur le lien',
                'LANDING_PAGE_VIEWS' => 'Vues de page de destination',
                'OFFSITE_CONVERSIONS' => 'Conversions sur le site (pixel requis)',
                'VALUE' => 'Valeur des conversions (pixel requis)',
            ],
        ],
    ],

    /*
    | Evenement facture accepte, par objectif d'optimisation.
    | Le premier de la liste est le defaut. IMPRESSIONS marche partout et reste
    | le choix sur : facturer au clic sur un petit budget assechhe la diffusion.
    */
    'billing_events' => [
        'POST_ENGAGEMENT' => ['IMPRESSIONS', 'POST_ENGAGEMENT'],
        'PAGE_LIKES' => ['IMPRESSIONS', 'PAGE_LIKES'],
        'LINK_CLICKS' => ['IMPRESSIONS', 'LINK_CLICKS'],
        'LANDING_PAGE_VIEWS' => ['IMPRESSIONS'],
        'REACH' => ['IMPRESSIONS'],
        'IMPRESSIONS' => ['IMPRESSIONS'],
        'AD_RECALL_LIFT' => ['IMPRESSIONS'],
        'THRUPLAY' => ['IMPRESSIONS'],
        'OFFSITE_CONVERSIONS' => ['IMPRESSIONS'],
        'LEAD_GENERATION' => ['IMPRESSIONS'],
        'VALUE' => ['IMPRESSIONS'],
    ],

    /*
    | Strategies d'enchere. `COST_CAP` et `LOWEST_COST_WITH_BID_CAP` exigent un
    | montant (`bid_amount`) ; un plafond trop bas ne depense simplement rien,
    | ce qui ressemble a une panne — d'ou le defaut sans plafond.
    */
    'bid_strategies' => [
        'LOWEST_COST_WITHOUT_CAP' => 'Volume maximal (recommandé)',
        'COST_CAP' => 'Coût par résultat plafonné',
        'LOWEST_COST_WITH_BID_CAP' => 'Enchère plafonnée',
    ],

    /*
    | Categories publicitaires speciales. Declarer faux est une infraction aux
    | regles Meta ; ne PAS declarer quand c'est le cas fait rejeter l'annonce.
    | Les choisir restreint le ciblage (age, genre, centres d'interet, geo fine)
    | — RS-Max nettoie la spec en consequence plutot que de laisser Graph
    | renvoyer une erreur illisible.
    */
    'special_ad_categories' => [
        'HOUSING' => 'Logement',
        'CREDIT' => 'Crédit',
        'EMPLOYMENT' => 'Emploi',
        'ISSUES_ELECTIONS_POLITICS' => 'Politique / enjeux sociaux',
    ],

    /*
    | Placements exposes. La liste courte est volontaire : ce sont ceux qui ont
    | un sens pour une publication organique promue. Laisser vide = placements
    | automatiques, ce que Meta recommande dans la quasi-totalite des cas.
    */
    'placements' => [
        'publisher_platforms' => [
            'facebook' => 'Facebook',
            'instagram' => 'Instagram',
            'threads' => 'Threads',
            'messenger' => 'Messenger',
            'audience_network' => 'Audience Network',
        ],
        'facebook_positions' => [
            'feed' => 'Fil',
            'video_feeds' => 'Fils vidéo',
            'story' => 'Stories',
            'facebook_reels' => 'Reels',
            'marketplace' => 'Marketplace',
            'search' => 'Recherche',
        ],
        'instagram_positions' => [
            'stream' => 'Fil',
            'story' => 'Stories',
            'reels' => 'Reels',
            'explore' => 'Explorer',
            'profile_feed' => 'Fil du profil',
        ],
        'device_platforms' => [
            'mobile' => 'Mobile',
            'desktop' => 'Ordinateur',
        ],
    ],

    /*
    | Ciblage de depart d'une nouvelle audience. Pas de valeur « magique » :
    | c'est simplement ce qu'on veut voir pre-rempli dans le formulaire.
    */
    'default_targeting' => [
        'countries' => ['PT'],
        'age_min' => 18,
        'age_max' => 65,
        'publisher_platforms' => ['facebook', 'instagram'],
    ],
];
