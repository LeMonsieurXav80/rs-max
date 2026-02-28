<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rss_posts', function (Blueprint $table) {
            // Drop FK that depends on the unique index, then drop the unique index,
            // then re-add the FK as a plain index.
            $table->dropForeign(['rss_item_id']);
            $table->dropUnique(['rss_item_id', 'social_account_id']);
            $table->index('rss_item_id');
            $table->foreign('rss_item_id')->references('id')->on('rss_items')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rss_posts', function (Blueprint $table) {
            $table->dropForeign(['rss_item_id']);
            $table->dropIndex(['rss_item_id']);
            $table->unique(['rss_item_id', 'social_account_id']);
            $table->foreign('rss_item_id')->references('id')->on('rss_items')->cascadeOnDelete();
        });
    }
};
