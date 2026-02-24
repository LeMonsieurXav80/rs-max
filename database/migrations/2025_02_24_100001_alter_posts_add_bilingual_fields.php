<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->text('content_fr')->after('user_id');
            $table->text('content_en')->nullable()->after('content_fr');
            $table->string('hashtags')->nullable()->after('content_en');
            $table->boolean('auto_translate')->default(true)->after('hashtags');
            $table->json('media')->nullable()->after('auto_translate');
            $table->string('telegram_channel')->nullable()->after('link_url');

            $table->dropColumn(['title', 'content', 'media_urls']);
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('title')->nullable()->after('user_id');
            $table->text('content')->after('title');
            $table->json('media_urls')->nullable()->after('content');

            $table->dropColumn(['content_fr', 'content_en', 'hashtags', 'auto_translate', 'media', 'telegram_channel']);
        });
    }
};
