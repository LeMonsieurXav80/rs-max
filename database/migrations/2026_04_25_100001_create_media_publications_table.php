<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_file_id')->constrained('media_files')->cascadeOnDelete();
            $table->foreignId('post_id')->nullable()->constrained('posts')->nullOnDelete();
            $table->foreignId('thread_segment_id')->nullable()->constrained('thread_segments')->nullOnDelete();
            $table->foreignId('post_platform_id')->nullable()->constrained('post_platform')->nullOnDelete();
            $table->text('external_url')->nullable();
            $table->timestamp('published_at');
            $table->string('context')->nullable();
            $table->timestamps();

            $table->index(['media_file_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_publications');
    }
};
