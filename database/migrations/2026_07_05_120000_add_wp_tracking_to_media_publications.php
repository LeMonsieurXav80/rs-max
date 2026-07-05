<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute le tracking d'usage WordPress sur media_publications.
 *
 * Une même image publiée sur N sites = N lignes (une par wp_source) : l'usage
 * est par-site, pas global. Les lignes sociales existantes gardent les 5 colonnes
 * WP à NULL ; comme MySQL/SQLite traitent les NULL comme distincts dans un index
 * unique, elles n'entrent jamais en collision sur la contrainte unique ci-dessous.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_publications', function (Blueprint $table) {
            // Le site WP concerné (la ligne wp_sources), pas une string libre.
            $table->foreignId('wp_source_id')->nullable()->after('post_platform_id')
                ->constrained('wp_sources')->nullOnDelete();
            $table->unsignedBigInteger('wp_post_id')->nullable()->after('wp_source_id');       // ID de l'article WP
            $table->unsignedBigInteger('wp_attachment_id')->nullable()->after('wp_post_id');   // ID du média WP
            $table->string('match_method', 16)->default('manual')->after('wp_attachment_id');  // manual | phash
            $table->unsignedTinyInteger('match_confidence')->nullable()->after('match_method'); // 0-100

            $table->index(['wp_source_id', 'wp_attachment_id']);
            $table->unique(['media_file_id', 'wp_source_id', 'wp_attachment_id'], 'media_pub_wp_unique');
        });
    }

    public function down(): void
    {
        Schema::table('media_publications', function (Blueprint $table) {
            $table->dropUnique('media_pub_wp_unique');
            $table->dropIndex(['wp_source_id', 'wp_attachment_id']);
            $table->dropConstrainedForeignId('wp_source_id');
            $table->dropColumn(['wp_post_id', 'wp_attachment_id', 'match_method', 'match_confidence']);
        });
    }
};
