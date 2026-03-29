<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pinterest_feeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('board_id')->nullable();
            $table->string('board_name')->nullable();
            $table->string('template', 30)->default('overlay');
            $table->json('colors')->nullable();
            $table->string('language', 10)->default('fr');
            $table->unsignedInteger('max_items')->default(50);
            $table->unsignedInteger('items_per_day')->default(3);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pinterest_feed_wp_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pinterest_feed_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wp_source_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('wp_category_id');
            $table->string('wp_category_name')->nullable();
            $table->timestamps();

            $table->unique(['pinterest_feed_id', 'wp_source_id', 'wp_category_id'], 'pin_feed_wp_cat_unique');
        });

        Schema::create('pinterest_pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pinterest_feed_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 20);
            $table->unsignedBigInteger('source_id');
            $table->string('guid')->unique();
            $table->unsignedTinyInteger('version')->default(1);
            $table->string('title_original')->nullable();
            $table->string('title_generated')->nullable();
            $table->text('description')->nullable();
            $table->string('link_url');
            $table->string('source_image_url')->nullable();
            $table->string('generated_image_path')->nullable();
            $table->string('template', 30)->nullable();
            $table->enum('status', ['pending', 'generated', 'in_feed', 'published', 'failed'])->default('pending');
            $table->string('error_message')->nullable();
            $table->timestamp('added_to_feed_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['pinterest_feed_id', 'status']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pinterest_pins');
        Schema::dropIfExists('pinterest_feed_wp_category');
        Schema::dropIfExists('pinterest_feeds');
    }
};
