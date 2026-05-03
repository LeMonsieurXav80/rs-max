<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('free_llm_models', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('model_id');
            $table->string('display_name');
            $table->boolean('supports_text')->default(true);
            $table->boolean('supports_vision')->default(false);
            $table->unsignedInteger('context_length')->nullable();
            $table->unsignedBigInteger('daily_token_limit')->nullable();
            $table->unsignedInteger('rpm_limit')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'model_id']);
            $table->index(['provider', 'is_available']);
            $table->index('supports_vision');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('free_llm_models');
    }
};
