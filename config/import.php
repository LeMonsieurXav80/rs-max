<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fenetre d'import des publications natives
    |--------------------------------------------------------------------------
    |
    | L'import ne sert plus a rattraper l'historique (fait une fois) mais a
    | ramener les nouveautes. Chaque compte repart donc de sa publication la
    | plus recente deja connue ; a defaut d'historique, on se limite a
    | `first_run_days` — inutile de remonter a des publications d'il y a deux ans.
    |
    */

    // Profondeur maximale quand un compte n'a encore aucune publication connue.
    'first_run_days' => (int) env('IMPORT_FIRST_RUN_DAYS', 30),

    // Recouvrement applique au point de reprise : une publication peut arriver
    // en base avec quelques heures de retard (moderation, programmation).
    'overlap_hours' => (int) env('IMPORT_OVERLAP_HOURS', 12),

    // Nombre de publications ramenees par compte et par passage.
    'default_limit' => (int) env('IMPORT_DEFAULT_LIMIT', 25),

    /*
    |--------------------------------------------------------------------------
    | Rafraichissement des metriques
    |--------------------------------------------------------------------------
    |
    | Instagram et Threads facturent un appel d'insights PAR publication : c'est
    | le poste de cout principal. On ne le paie donc que quand ca peut encore
    | changer quelque chose.
    |
    */

    // Au-dela de cet age, les statistiques d'une publication ne bougent plus.
    'metrics_settle_days' => (int) env('IMPORT_METRICS_SETTLE_DAYS', 30),

    // Duree pendant laquelle des metriques fraiches sont considerees valables.
    'metrics_ttl_hours' => (int) env('IMPORT_METRICS_TTL_HOURS', 24),

];
