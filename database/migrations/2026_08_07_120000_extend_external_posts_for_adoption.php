<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prepare `external_posts` a servir de file d'attente d'adoption.
 *
 * Jusqu'ici la table n'etait qu'un miroir de stats : un media unique, aucun lien
 * vers `posts`. Pour pouvoir cocher des publications natives dans un flux et les
 * fusionner en une publication RS-Max, il faut :
 *   - `media`     : la liste complete des medias (carrousels, video + miniature)
 *   - `adopted_*` : le lien vers le Post cree, qui sort la carte du flux
 *   - `ignored_at`: « pas interessant » sans supprimer la ligne (les stats en vivent)
 *   - `group_key` : regroupement inter-reseaux d'une meme publication (rempli plus tard)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_posts', function (Blueprint $table) {
            $table->json('media')->nullable()->after('media_url');
            $table->unsignedBigInteger('adopted_post_id')->nullable()->after('metrics_synced_at');
            $table->timestamp('adopted_at')->nullable()->after('adopted_post_id');
            $table->timestamp('ignored_at')->nullable()->after('adopted_at');
            $table->string('group_key', 64)->nullable()->after('ignored_at');

            $table->index('adopted_post_id');
            $table->index('group_key');
            $table->index(['ignored_at', 'published_at']);
        });

        // SQLite ne sait pas ajouter une contrainte a une table existante.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('external_posts', function (Blueprint $table) {
                $table->foreign('adopted_post_id')->references('id')->on('posts')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('external_posts', function (Blueprint $table) {
                $table->dropForeign(['adopted_post_id']);
            });
        }

        Schema::table('external_posts', function (Blueprint $table) {
            $table->dropIndex(['adopted_post_id']);
            $table->dropIndex(['group_key']);
            $table->dropIndex(['ignored_at', 'published_at']);

            $table->dropColumn(['media', 'adopted_post_id', 'adopted_at', 'ignored_at', 'group_key']);
        });
    }
};
