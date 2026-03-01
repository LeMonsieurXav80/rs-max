<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thread_segment_platform', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_segment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['pending', 'publishing', 'published', 'failed', 'skipped'])->default('pending');
            $table->string('external_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['thread_segment_id', 'social_account_id'], 'tsp_segment_account_idx');
            $table->index(['social_account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thread_segment_platform');
    }
};
