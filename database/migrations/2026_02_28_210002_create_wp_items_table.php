<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wp_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wp_source_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('wp_post_id');
            $table->text('title');
            $table->text('url');
            $table->longText('content')->nullable();
            $table->longText('summary')->nullable();
            $table->string('author')->nullable();
            $table->text('image_url')->nullable();
            $table->string('post_type', 50);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['wp_source_id', 'wp_post_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wp_items');
    }
};
