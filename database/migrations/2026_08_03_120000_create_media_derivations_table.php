<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Filiation entre une image GÉNÉRÉE (slide de carrousel, visuel de brique) et les
 * photos de la médiathèque qui l'ont composée.
 *
 * Sans ce lien, la slide produite par le Studio naissait orpheline : au moment de
 * la publication seul le fichier composé était tracé, et la photo d'origine
 * ressortait comme « jamais publiée » dans les suggestions.
 *
 * Une slide peut avoir plusieurs sources (plusieurs slots image) et une photo peut
 * alimenter plusieurs slides : c'est un many-to-many auto-référent sur media_files.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_derivations', function (Blueprint $table) {
            $table->id();
            // L'image produite (source=studio|api, is_generated=true).
            $table->foreignId('derived_media_file_id')->constrained('media_files')->cascadeOnDelete();
            // La photo du catalogue employée comme matière première.
            $table->foreignId('source_media_file_id')->constrained('media_files')->cascadeOnDelete();
            $table->string('slot', 64)->nullable();          // nom du slot image dans la brique
            $table->string('brick', 64)->nullable();         // brique utilisée, pour le compte rendu
            $table->string('match_method', 16)->default('render'); // render | phash (rattrapage rétroactif)
            $table->unsignedTinyInteger('match_confidence')->nullable(); // 0-100, null pour `render` (certain)
            $table->timestamps();

            $table->unique(['derived_media_file_id', 'source_media_file_id'], 'media_derivation_unique');
            $table->index('source_media_file_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_derivations');
    }
};
