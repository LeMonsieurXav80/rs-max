<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_items', function (Blueprint $table) {
            $table->string('media_url', 1024)->nullable()->after('content');
            $table->string('media_type', 20)->nullable()->after('media_url'); // image, gif, video, sticker
        });
    }

    public function down(): void
    {
        Schema::table('inbox_items', function (Blueprint $table) {
            $table->dropColumn(['media_url', 'media_type']);
        });
    }
};
