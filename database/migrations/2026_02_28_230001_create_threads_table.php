<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->string('source_url_hash', 64)->nullable();
            $table->string('source_type', 20)->default('manual');
            $table->enum('status', ['draft', 'scheduled', 'publishing', 'published', 'failed', 'partial'])->default('draft');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('scheduled_at');
            $table->index('source_url_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('threads');
    }
};
