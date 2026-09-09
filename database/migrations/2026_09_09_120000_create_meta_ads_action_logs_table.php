<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Journal des gestes sur les campagnes Meta.
     *
     * Ces actions engagent de l'argent et sont declenchees par une IA : il faut
     * pouvoir répondre après coup à « qui a mis ce budget à 80 €, et quand ».
     * L'API Meta ne conserve pas l'état précédent, donc on l'enregistre ici —
     * sans ça, un retour arrière se fait à l'aveugle.
     *
     * Les dry-run sont journalises aussi : ce qu'une IA a *envisage* de faire
     * est un signal, notamment quand la meme proposition revient en boucle.
     */
    public function up(): void
    {
        Schema::create('meta_ads_action_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('object_type');   // campaign | adset
            $table->string('object_id');     // id Meta
            $table->string('object_name')->nullable();
            $table->string('action');        // status | budget

            // Etat avant / apres, pour pouvoir revenir en arriere.
            $table->json('previous')->nullable();
            $table->json('requested');

            $table->boolean('dry_run')->default(true);
            $table->boolean('success')->default(false);
            $table->text('error')->nullable();

            $table->timestamps();

            $table->index(['object_type', 'object_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ads_action_logs');
    }
};
