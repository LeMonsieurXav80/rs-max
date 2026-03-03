<?php

use App\Models\Platform;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Platform::updateOrCreate(
            ['slug' => 'reddit'],
            [
                'name' => 'Reddit',
                'description' => 'Publications sur des subreddits Reddit',
                'color' => '#FF4500',
                'auth_type' => 'api_key',
                'config' => json_encode([
                    'api_base' => 'https://oauth.reddit.com',
                    'credential_fields' => [
                        ['key' => 'username', 'label' => 'Nom d\'utilisateur Reddit', 'type' => 'text'],
                        ['key' => 'password', 'label' => 'Mot de passe', 'type' => 'password'],
                        ['key' => 'client_id', 'label' => 'Client ID (Script App)', 'type' => 'text'],
                        ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password'],
                        ['key' => 'subreddit', 'label' => 'Subreddit (sans r/)', 'type' => 'text'],
                    ],
                ]),
            ]
        );
    }

    public function down(): void
    {
        Platform::where('slug', 'reddit')->delete();
    }
};
