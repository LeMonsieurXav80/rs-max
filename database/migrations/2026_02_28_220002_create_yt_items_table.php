<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('yt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('yt_source_id')->constrained()->cascadeOnDelete();
            $table->string('video_id', 50);
            $table->text('title');
            $table->text('url');
            $table->longText('description')->nullable();
            $table->text('thumbnail_url')->nullable();
            $table->string('duration', 20)->nullable();
            $table->unsignedBigInteger('view_count')->nullable();
            $table->unsignedBigInteger('like_count')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['yt_source_id', 'video_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('yt_items');
    }
};
