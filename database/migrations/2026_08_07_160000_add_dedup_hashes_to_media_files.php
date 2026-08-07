<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deux empreintes pour ne telecharger qu'une fois une meme photo.
 *
 * `content_hash` (SHA-256 des octets) attrape l'identique parfait.
 * `dhash` (difference hash, calcule en PHP/GD) attrape la meme photo
 * re-encodee differemment par chaque reseau — cas normal quand une publication
 * part sur Facebook ET Instagram.
 *
 * A ne pas confondre avec la colonne `phash` existante : elle vient du helper
 * Python du pipeline Mac (imagehash+PIL), indisponible sur le VPS, et n'est
 * renseignee que pour les medias `source=mac_pipeline`. Les deux algorithmes
 * ne sont pas comparables entre eux.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->string('content_hash', 64)->nullable()->after('phash');
            $table->string('dhash', 16)->nullable()->after('content_hash');

            $table->index('content_hash');
            $table->index('dhash');
        });
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropIndex(['content_hash']);
            $table->dropIndex(['dhash']);
            $table->dropColumn(['content_hash', 'dhash']);
        });
    }
};
