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
    ],

    'deploy' => [
        'api_url' => env('DEPLOY_API_URL'),
        'api_token' => env('DEPLOY_API_TOKEN'),
        'app_uuid' => env('DEPLOY_APP_UUID'),
        'git_repo' => env('DEPLOY_GIT_REPO'),
        'git_branch' => env('DEPLOY_GIT_BRANCH', 'main'),
    ],

];
