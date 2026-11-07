<?php

/*
 * Catalogue des actions de l'agent, par réseau.
 *
 * `status` dit ce que RS-Max sait réellement faire :
 *   ready   exécutable, réglable compte par compte
 *   engine  assuré par un moteur existant, réglé sur sa propre page
 *   planned pas encore construit : rien à activer
 *
 * Chaque exécution est journalisée dans `bot_action_logs` en `agent.<action>` : le quota se compte là.
 */
return [
    'text_limit' => 280,
    'read_limit' => 30,

    // Une génération couvre plusieurs conversations : au-delà, le prompt coûte plus qu'il n'économise.
    'batch_limit' => 20,
    'thread_messages' => 8,
    'thread_text_limit' => 400,
    'reply_limits' => ['twitter' => 280, 'tiktok' => 150, 'bluesky' => 300, 'default' => 500],

    'platforms' => [
        'twitter' => ['name' => 'X', 'actions' => [
            'mentions.read' => ['label' => 'Lire les mentions', 'kind' => 'read', 'channel' => 'browser', 'status' => 'ready', 'limit' => 24],
            'reply' => ['label' => 'Répondre, avec validation', 'kind' => 'write', 'channel' => 'browser', 'status' => 'ready', 'limit' => 10],
            'like' => ['label' => 'Aimer', 'kind' => 'write', 'channel' => 'browser', 'status' => 'ready', 'limit' => 30],
            'feed.read' => ['label' => 'Lire le flux', 'kind' => 'read', 'channel' => 'browser', 'status' => 'ready', 'limit' => 24],
            'search.read' => ['label' => 'Rechercher des posts', 'kind' => 'read', 'channel' => 'browser', 'status' => 'ready', 'limit' => 24],
            'thread.read' => ['label' => 'Lire une conversation', 'kind' => 'read', 'channel' => 'browser', 'status' => 'ready', 'limit' => 24],
            'profile.read' => ['label' => 'Lire un profil', 'kind' => 'read', 'channel' => 'browser', 'status' => 'ready', 'limit' => 24],
            'unlike' => ['label' => 'Retirer un like', 'kind' => 'write', 'channel' => 'browser', 'status' => 'ready', 'limit' => 5],
            'bookmark' => ['label' => 'Ajouter un signet', 'kind' => 'write', 'channel' => 'browser', 'status' => 'ready', 'limit' => 10],
            'unbookmark' => ['label' => 'Retirer un signet', 'kind' => 'write', 'channel' => 'browser', 'status' => 'ready', 'limit' => 5],
            'repost' => ['label' => 'Reposter', 'kind' => 'write', 'channel' => 'browser', 'status' => 'ready', 'limit' => 5],
            'quote' => ['label' => 'Citer avec un texte fourni', 'kind' => 'write', 'channel' => 'browser', 'status' => 'ready', 'limit' => 3],
            'unrepost' => ['label' => 'Annuler un repost', 'kind' => 'write', 'channel' => 'browser', 'status' => 'ready', 'limit' => 5],
            'follow' => ['label' => 'Suivre', 'kind' => 'write', 'channel' => 'browser', 'status' => 'ready', 'limit' => 5],
            'unfollow' => ['label' => 'Désuivre après vérification du retour', 'kind' => 'write', 'channel' => 'browser', 'status' => 'ready', 'limit' => 5],
        ]],
        'tiktok' => ['name' => 'TikTok', 'actions' => [
            'comments.read' => ['label' => 'Lire les commentaires', 'kind' => 'read', 'channel' => 'browser', 'status' => 'ready', 'limit' => 24],
            'comment.like' => ['label' => 'Aimer un commentaire de vidéo', 'kind' => 'write', 'channel' => 'browser', 'status' => 'ready', 'limit' => 30],
            'video.like' => ['label' => 'Aimer une vidéo', 'kind' => 'write', 'channel' => 'browser', 'status' => 'ready', 'limit' => 30],
            'video.comments' => ['label' => 'Lire la discussion d’une vidéo', 'kind' => 'read', 'channel' => 'browser', 'status' => 'ready', 'limit' => 24],
            'video.comment' => ['label' => 'Commenter une vidéo, avec validation', 'kind' => 'write', 'channel' => 'browser', 'status' => 'ready', 'limit' => 10],
            'reply' => ['label' => 'Répondre, avec validation', 'kind' => 'write', 'channel' => 'browser', 'status' => 'ready', 'limit' => 10],
            'feed.read' => ['label' => 'Parcourir le fil', 'kind' => 'read', 'channel' => 'browser', 'status' => 'ready', 'limit' => 24],
        ]],
        'bluesky' => ['name' => 'Bluesky', 'route' => 'bot.bluesky.index', 'actions' => [
            'reply' => ['label' => 'Répondre, avec validation', 'kind' => 'write', 'channel' => 'api', 'status' => 'ready', 'limit' => 10],
            'likes' => ['label' => 'Likes', 'kind' => 'write', 'channel' => 'api', 'status' => 'engine'],
            'comments' => ['label' => 'Commentaires', 'kind' => 'write', 'channel' => 'api', 'status' => 'engine'],
            'follows' => ['label' => 'Abonnements', 'kind' => 'write', 'channel' => 'api', 'status' => 'engine'],
            'prospect' => ['label' => 'Prospection', 'kind' => 'write', 'channel' => 'api', 'status' => 'engine'],
        ]],
        'facebook' => ['name' => 'Facebook', 'route' => 'bot.facebook.index', 'actions' => [
            'reply' => ['label' => 'Répondre, avec validation', 'kind' => 'write', 'channel' => 'api', 'status' => 'ready', 'limit' => 10],
            'comment.like' => ['label' => 'Aimer les commentaires reçus', 'kind' => 'write', 'channel' => 'api', 'status' => 'engine'],
        ]],
        'instagram' => ['name' => 'Instagram', 'actions' => [
            'reply' => ['label' => 'Répondre, avec validation', 'kind' => 'write', 'channel' => 'api', 'status' => 'ready', 'limit' => 10],
        ]],
        'threads' => ['name' => 'Threads', 'actions' => [
            'reply' => ['label' => 'Répondre, avec validation', 'kind' => 'write', 'channel' => 'api', 'status' => 'ready', 'limit' => 10],
        ]],
        'youtube' => ['name' => 'YouTube', 'actions' => [
            'reply' => ['label' => 'Répondre, avec validation', 'kind' => 'write', 'channel' => 'api', 'status' => 'ready', 'limit' => 10],
        ]],
        'linkedin' => ['name' => 'LinkedIn', 'actions' => [
            'comment.reply' => ['label' => 'Répondre aux commentaires', 'kind' => 'write', 'channel' => 'api', 'status' => 'planned', 'limit' => 10],
        ]],
    ],
];
