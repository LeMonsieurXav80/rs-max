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
];
