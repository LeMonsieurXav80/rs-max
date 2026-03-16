<?php

namespace Database\Seeders;

use App\Models\Platform;
use Illuminate\Database\Seeder;

class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        $platforms = [
            [
                'slug' => 'facebook',
                'name' => 'Facebook',
                'description' => 'Pages et profils Facebook',
                'color' => '#1877F2',
                'auth_type' => 'oauth',
                'config' => json_encode([
                    'api_version' => 'v21.0',
                    'credential_fields' => [
                        ['key' => 'page_id', 'label' => 'Page ID', 'type' => 'text'],
                        ['key' => 'access_token', 'label' => 'Access Token', 'type' => 'password'],
                    ],
                ]),
            ],
            [
                'slug' => 'twitter',
                'name' => 'Twitter / X',
                'description' => 'Posts sur Twitter/X',
                'color' => '#000000',
                'auth_type' => 'oauth',
                'config' => json_encode([
                    'api_version' => 'v2',
                    'credential_fields' => [
                        ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password'],
                        ['key' => 'api_secret', 'label' => 'API Secret', 'type' => 'password'],
                        ['key' => 'access_token', 'label' => 'Access Token', 'type' => 'password'],
                        ['key' => 'access_token_secret', 'label' => 'Access Token Secret', 'type' => 'password'],
                    ],
                ]),
            ],
            [
                'slug' => 'instagram',
                'name' => 'Instagram',
                'description' => 'Publications Instagram via Graph API',
                'color' => '#E4405F',
                'auth_type' => 'oauth',
                'config' => json_encode([
                    'api_version' => 'v21.0',
                    'credential_fields' => [
                        ['key' => 'account_id', 'label' => 'Account ID', 'type' => 'text'],
                        ['key' => 'access_token', 'label' => 'Access Token', 'type' => 'password'],
                    ],
                ]),
            ],
            [
                'slug' => 'telegram',
                'name' => 'Telegram',
                'description' => 'Messages dans des channels/groupes Telegram',
                'color' => '#26A5E4',
                'auth_type' => 'bot_token',
                'config' => json_encode([
                    'api_base' => 'https://api.telegram.org',
                    'credential_fields' => [
                        ['key' => 'bot_token', 'label' => 'Bot Token', 'type' => 'password'],
                        ['key' => 'chat_id', 'label' => 'Chat ID', 'type' => 'text'],
                    ],
                ]),
            ],
            [
                'slug' => 'threads',
                'name' => 'Threads',
                'description' => 'Publications sur Threads (Meta)',
                'color' => '#000000',
                'auth_type' => 'oauth',
                'config' => json_encode([
                    'api_version' => 'v1.0',
                    'credential_fields' => [
                        ['key' => 'user_id', 'label' => 'User ID', 'type' => 'text'],
                        ['key' => 'access_token', 'label' => 'Access Token', 'type' => 'password'],
                    ],
                ]),
            ],
            [
                'slug' => 'bluesky',
                'name' => 'Bluesky',
                'description' => 'Publications sur Bluesky (AT Protocol)',
                'color' => '#0085FF',
                'auth_type' => 'oauth',
                'config' => json_encode([
                    'credential_fields' => [
                        ['key' => 'handle', 'label' => 'Handle', 'type' => 'text'],
                        ['key' => 'app_password', 'label' => 'App Password', 'type' => 'password'],
                        ['key' => 'did', 'label' => 'DID', 'type' => 'text'],
                    ],
                ]),
            ],
            [
                'slug' => 'youtube',
                'name' => 'YouTube',
                'description' => 'Publications sur YouTube via OAuth',
                'color' => '#FF0000',
                'auth_type' => 'oauth',
                'config' => json_encode([
                    'credential_fields' => [
                        ['key' => 'channel_id', 'label' => 'Channel ID', 'type' => 'text'],
                        ['key' => 'access_token', 'label' => 'Access Token', 'type' => 'password'],
                        ['key' => 'refresh_token', 'label' => 'Refresh Token', 'type' => 'password'],
                    ],
                ]),
            ],
            [
                'slug' => 'reddit',
                'name' => 'Reddit',
                'description' => 'Publications sur Reddit (subreddits)',
                'color' => '#FF4500',
                'auth_type' => 'oauth',
                'config' => json_encode([
                    'credential_fields' => [
                        ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'password'],
                        ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password'],
                        ['key' => 'username', 'label' => 'Username', 'type' => 'text'],
                        ['key' => 'password', 'label' => 'Password', 'type' => 'password'],
                        ['key' => 'subreddit', 'label' => 'Subreddit', 'type' => 'text'],
                    ],
                ]),
            ],
            [
                'slug' => 'linkedin',
                'name' => 'LinkedIn',
                'description' => 'Publications sur LinkedIn (profils et pages)',
                'color' => '#0A66C2',
                'auth_type' => 'oauth',
                'config' => json_encode([
                    'api_version' => '202402',
                    'credential_fields' => [
                        ['key' => 'person_urn', 'label' => 'Person/Org URN', 'type' => 'text'],
                        ['key' => 'access_token', 'label' => 'Access Token', 'type' => 'password'],
                        ['key' => 'refresh_token', 'label' => 'Refresh Token', 'type' => 'password'],
                    ],
                ]),
            ],
        ];

        foreach ($platforms as $platform) {
            Platform::updateOrCreate(
                ['slug' => $platform['slug']],
                $platform
            );
        }
    }
}
