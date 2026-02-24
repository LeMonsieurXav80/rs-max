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
                    'permissions' => ['pages_manage_posts', 'pages_read_engagement'],
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
                    'requires_facebook' => true,
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
