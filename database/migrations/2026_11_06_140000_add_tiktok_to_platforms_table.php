<?php

use App\Models\Platform;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Platform::updateOrCreate(
            ['slug' => 'tiktok'],
            [
                'name' => 'TikTok',
                'description' => 'Comptes TikTok pilotés par le navigateur du VPS',
                'color' => '#000000',
                'auth_type' => 'browser',
                'config' => [
                    'browser_only' => true,
                    'browser_profile' => 'wildycaro',
                    'credential_fields' => [],
                ],
            ]
        );
    }

    public function down(): void
    {
        Platform::where('slug', 'tiktok')->delete();
    }
};
