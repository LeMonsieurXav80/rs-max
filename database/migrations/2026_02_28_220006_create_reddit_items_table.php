<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reddit_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reddit_source_id')->constrained()->cascadeOnDelete();
            $table->string('reddit_post_id', 20);
            $table->text('title');
            $table->text('url');
            $table->longText('selftext')->nullable();
            $table->string('author')->nullable();
            $table->integer('score')->default(0);
            $table->integer('num_comments')->default(0);
            $table->text('permalink');
            $table->text('thumbnail_url')->nullable();
            $table->boolean('is_self')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['reddit_source_id', 'reddit_post_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reddit_items');
    }
};
