<?php

use App\Models\Platform;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Platform::updateOrCreate(
            ['slug' => 'bluesky'],
            [
                'name' => 'Bluesky',
                'description' => 'Publications sur Bluesky via AT Protocol',
                'color' => '#0085FF',
                'auth_type' => 'api_key',
                'config' => json_encode([
                    'api_base' => 'https://bsky.social',
                    'credential_fields' => [
                        ['key' => 'handle', 'label' => 'Handle', 'type' => 'text'],
                        ['key' => 'app_password', 'label' => 'App Password', 'type' => 'password'],
                    ],
                ]),
            ]
        );
    }

    public function down(): void
    {
        Platform::where('slug', 'bluesky')->delete();
    }
};
