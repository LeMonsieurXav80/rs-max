<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thread_social_account', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained()->cascadeOnDelete();
            $table->enum('publish_mode', ['thread', 'compiled'])->default('thread');
            $table->enum('status', ['pending', 'publishing', 'published', 'failed', 'partial'])->default('pending');
            $table->timestamps();

            $table->unique(['thread_id', 'social_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thread_social_account');
    }
};
