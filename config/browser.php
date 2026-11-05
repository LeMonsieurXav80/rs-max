<?php

return [
    // Camofox dédié à RS-Max : jamais celui de Hermes, dont l'agent lit du contenu public.
    'url' => env('RSMAX_BROWSER_URL'),
    'access_key' => env('RSMAX_BROWSER_ACCESS_KEY'),
    'api_key' => env('RSMAX_BROWSER_API_KEY'),

    // Le dossier des médias RS-Max, monté en lecture seule dans le conteneur Camofox.
    'uploads_dir' => env('RSMAX_BROWSER_UPLOADS_DIR', '/uploads'),

    'timeout' => (int) env('RSMAX_BROWSER_TIMEOUT', 45),
    // Une vidéo reste « en traitement » côté X avant que le bouton Poster s'active.
    'media_timeout' => (int) env('RSMAX_BROWSER_MEDIA_TIMEOUT', 600),
];
