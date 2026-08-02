<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retrait de l'« IA Gratuite » (Groq / OpenRouter / Google AI / Mistral / Together).
 *
 * La fonctionnalité n'a jamais servi : la génération passe par OpenAI. On enlève
 * la table du catalogue de modèles, les défauts, et les clés API des providers —
 * des secrets qu'aucun code ne lit n'ont rien à faire en base.
 *
 * Sens unique assumé : `down()` recrée la structure, pas le contenu. Le catalogue
 * était de toute façon un cache reconstruit par un service désormais supprimé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('free_llm_models');

        DB::table('settings')->whereIn('key', [
            'free_llms_default_text_model',
            'free_llms_default_vision_model',
            'free_llms_last_refresh_at',
            'groq_api_key',
            'openrouter_api_key',
            'google_ai_api_key',
            'mistral_api_key',
            'together_api_key',
        ])->delete();
    }

    public function down(): void
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
            // Colonnes ajoutées par la migration de mai 2026 (bouton « tester les modèles »).
            $table->string('last_test_status', 32)->nullable();
            $table->string('last_test_error', 500)->nullable();
            $table->unsignedInteger('last_test_latency_ms')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'model_id']);
            $table->index(['provider', 'is_available']);
            $table->index('supports_vision');
            $table->index(['supports_text', 'last_test_status']);
            $table->index(['supports_vision', 'last_test_status']);
        });
    }
};
