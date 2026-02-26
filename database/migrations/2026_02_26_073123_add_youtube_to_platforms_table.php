<?php

use App\Models\Platform;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Platform::updateOrCreate(
            ['slug' => 'youtube'],
            [
                'slug' => 'youtube',
                'name' => 'YouTube',
                'description' => 'Upload de vidéos sur YouTube',
                'color' => '#FF0000',
                'auth_type' => 'oauth',
                'config' => json_encode([
                    'api_version' => 'v3',
                    'credential_fields' => [
                        ['key' => 'channel_id', 'label' => 'Channel ID', 'type' => 'text'],
                        ['key' => 'access_token', 'label' => 'Access Token', 'type' => 'password'],
                        ['key' => 'refresh_token', 'label' => 'Refresh Token', 'type' => 'password'],
                    ],
                ]),
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Platform::where('slug', 'youtube')->delete();
    }
};
