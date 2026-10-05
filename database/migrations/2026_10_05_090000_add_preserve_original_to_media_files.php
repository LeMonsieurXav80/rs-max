<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `preserve_original` marque un fichier dont les octets ne doivent JAMAIS etre
 * remplaces : ni recompression GD, ni transcodage ffmpeg.
 *
 * Pourquoi une colonne et pas un reglage global : les quatre chemins d'entree
 * existants (upload web, /media/ingest, telechargement d'URL, import externe)
 * compressent a dessein — une photo de flux n'a pas besoin de ses 12 Mo. Le
 * besoin de l'octet exact est propre aux fichiers produits pour etre publies
 * (slides de carrousel, exports video). Porter l'intention sur la ligne, c'est
 * pouvoir faire cohabiter les deux sans basculer tout le catalogue.
 *
 * Ce que la colonne garantit, au-dela du stockage : a la publication, la
 * normalisation imposee par un reseau est ecrite dans une VARIANTE a cote,
 * l'original restant intact. Sans ce drapeau, `MediaController::compressVideo`
 * supprime le fichier source (`@unlink`) et la qualite d'origine est perdue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->boolean('preserve_original')->default(false)->after('is_generated');
        });
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropColumn('preserve_original');
        });
    }
};
