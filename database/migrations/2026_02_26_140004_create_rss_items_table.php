<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rss_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rss_feed_id')->constrained()->cascadeOnDelete();
            $table->string('guid');
            $table->text('title');
            $table->text('url');
            $table->text('content')->nullable();
            $table->text('summary')->nullable();
            $table->string('author')->nullable();
            $table->text('image_url')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['rss_feed_id', 'guid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rss_items');
    }
};
