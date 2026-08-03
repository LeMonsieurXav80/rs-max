<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deux ajouts liés à la filiation des images générées (voir media_derivations) :
 *
 * 1. `media_files.is_generated` : marqueur explicite « ce fichier a été fabriqué
 *    par le moteur de rendu ». On ne le déduit pas de `source`, qui décrit la
 *    PROVENANCE (upload, mac_pipeline, og_image, studio, api) et non la nature.
 *
 * 2. `media_publications.via_media_file_id` : quand une slide générée est publiée,
 *    ses photos sources reçoivent elles aussi une ligne de publication ; cette
 *    colonne dit PAR QUELLE image composée l'usage a eu lieu (null = usage direct).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->boolean('is_generated')->default(false)->after('source');
            $table->index('is_generated');
        });

        // Rattrapage : tout ce que le Studio et l'API carrousel ont déjà produit.
        DB::table('media_files')->whereIn('source', ['studio', 'api'])->update(['is_generated' => true]);

        Schema::table('media_publications', function (Blueprint $table) {
            // nullOnDelete et NON cascade : si la slide est supprimée de la
            // médiathèque, la publication de la photo source a quand même eu lieu
            // sur le réseau. On perd le « par quel visuel », pas l'historique —
            // et publication_count (qui n'est jamais décrémenté) reste cohérent
            // avec le nombre de lignes.
            $table->foreignId('via_media_file_id')->nullable()->after('media_file_id')
                ->constrained('media_files')->nullOnDelete();
            $table->index('via_media_file_id');
        });
    }

    public function down(): void
    {
        Schema::table('media_publications', function (Blueprint $table) {
            // dropConstrainedForeignId supprime la contrainte, la colonne — et donc
            // l'index. Le dropIndex explicite échouerait avant (MySQL refuse de
            // supprimer un index encore utilisé par une clé étrangère).
            $table->dropConstrainedForeignId('via_media_file_id');
        });

        Schema::table('media_files', function (Blueprint $table) {
            $table->dropIndex(['is_generated']);
            $table->dropColumn('is_generated');
        });
    }
};
