<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Empreinte perceptuelle de la premiere image d'une publication native.
 *
 * Sert a reconnaitre la meme publication d'un reseau a l'autre quand ni
 * l'heure ni le texte ne le permettent : Pinterest republie le lendemain la
 * photo d'Instagram, avec sa propre description. L'image, elle, est la meme —
 * re-encodee, donc reconnaissable seulement par empreinte perceptuelle, pas
 * par comparaison d'octets.
 *
 * Rempli a la demande par `external:hash-media` : telecharger l'image de
 * chaque publication coute trop cher pour le faire au fil de l'import.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_posts', function (Blueprint $table) {
            $table->string('media_hash', 32)->nullable()->after('media');
            $table->timestamp('media_hashed_at')->nullable()->after('media_hash');

            // Le rapprochement balaye toutes les empreintes connues : une
            // distance de Hamming ne s'indexe pas, mais le filtre « qui en a
            // une » si.
            $table->index('media_hashed_at');
        });
    }

    public function down(): void
    {
        Schema::table('external_posts', function (Blueprint $table) {
            $table->dropIndex(['media_hashed_at']);
            $table->dropColumn(['media_hash', 'media_hashed_at']);
        });
    }
};
