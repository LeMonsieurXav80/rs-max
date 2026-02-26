<?php

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
        Schema::create('external_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->index(); // ID on the social network
            $table->text('content')->nullable();
            $table->string('media_url')->nullable(); // Primary media (image/video)
            $table->string('post_url')->nullable(); // URL to view the post
            $table->timestamp('published_at')->nullable();
            $table->json('metrics')->nullable(); // Stats (views, likes, comments, shares)
            $table->timestamp('metrics_synced_at')->nullable();
            $table->timestamps();

            // Unique constraint: one external post per platform/external_id
            $table->unique(['platform_id', 'external_id']);
        });

        // Add last_history_import_at to social_accounts table
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->timestamp('last_history_import_at')->nullable()->after('profile_picture_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn('last_history_import_at');
        });

        Schema::dropIfExists('external_posts');
    }
};
