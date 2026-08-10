<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_APP_ID'),
        'client_secret' => env('FACEBOOK_APP_SECRET'),
        'config_id' => env('FACEBOOK_CONFIG_ID'),
        'api_version' => 'v21.0',
    ],

    'threads' => [
        'client_id' => env('THREADS_APP_ID'),
        'client_secret' => env('THREADS_APP_SECRET'),
        'api_version' => 'v1.0',
    ],

    'youtube' => [
        'client_id' => env('YOUTUBE_CLIENT_ID'),
        'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
        'redirect' => env('APP_URL').'/oauth/youtube/callback',
    ],

    'linkedin' => [
        'client_id' => env('LINKEDIN_CLIENT_ID'),
        'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
        'redirect' => env('APP_URL').'/auth/linkedin/callback',
        // Versions supportées 1 an seulement (format YYYYMM) : à remonter
        // avant sunset, sinon 426 NONEXISTENT_VERSION sur tous les appels.
        'version' => env('LINKEDIN_API_VERSION', '202607'),
    ],

    'pinterest' => [
        'client_id' => env('PINTEREST_APP_ID'),
        'client_secret' => env('PINTEREST_APP_SECRET'),
        'redirect' => env('APP_URL').'/auth/pinterest/callback',
    ],

    'deploy' => [
        'api_url' => env('DEPLOY_API_URL'),
        'api_token' => env('DEPLOY_API_TOKEN'),
        'app_uuid' => env('DEPLOY_APP_UUID'),
        'git_repo' => env('DEPLOY_GIT_REPO'),
        'git_branch' => env('DEPLOY_GIT_BRANCH', 'main'),
    ],

    // Réconciliation rétroactive média WordPress (commande media:reconcile-wp).
    'media_reconcile' => [
        // Binaire Python du MÊME venv que le pipeline d'ingest Mac : garantit un
        // imagehash.phash identique (mêmes versions imagehash/PIL) → distances de
        // Hamming exploitables. Sans ce venv exact, les hashes divergent.
        'python' => env('MEDIA_PHASH_PYTHON', '/Volumes/Samsung_T5/DEV/Scripts/.venv/bin/python'),
        // User-Agent obligatoire sur tous les appels WP (sinon Cloudflare 1010/302).
        'user_agent' => env('WP_RECONCILE_USER_AGENT', 'RS-Max-Reconcile/1.0 (+https://lemonsieurxav.com)'),
    ],

];
