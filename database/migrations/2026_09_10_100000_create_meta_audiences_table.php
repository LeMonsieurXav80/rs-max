<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audiences reutilisables pour le ciblage des campagnes Meta.
     *
     * Meta stocke le ciblage DANS l'ad set : il meurt avec lui, et refaire la
     * meme audience a chaque sponsorisation, c'est refaire les memes erreurs.
     * On garde donc la definition ici, cote RS-Max, et on la deroule en
     * `targeting` a la creation de l'ad set.
     *
     * Le ciblage vit dans une colonne JSON plutot qu'en colonnes typees : la
     * grammaire de ciblage de Meta bouge (positions, automation, exclusions) et
     * une migration par nouveau champ ne tiendrait pas le rythme. La forme
     * attendue est documentee sur le modele `MetaAudience`.
     */
    public function up(): void
    {
        Schema::create('meta_audiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();

            // Spec de ciblage RS-Max (pas la spec Meta : la traduction se fait
            // dans MetaAudience::toTargetingSpec()).
            $table->json('spec');

            // Pre-selectionnee dans les formulaires. Non unique en base : c'est
            // le modele qui degage l'ancienne, une contrainte ici obligerait a
            // jongler avec l'ordre des ecritures.
            $table->boolean('is_default')->default(false);

            // Derniere estimation de portee connue, pour afficher un ordre de
            // grandeur sans rappeler Graph a chaque liste.
            $table->unsignedBigInteger('estimated_reach')->nullable();
            $table->timestamp('estimated_at')->nullable();

            $table->timestamps();

            $table->index('is_default');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_audiences');
    }
};
