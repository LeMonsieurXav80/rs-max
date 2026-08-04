<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Titre d'Article X. Rempli => la publication vers un compte X Premium
     * passe par /2/articles au lieu de /2/tweets. Vide => comportement inchangé.
     */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('article_title')->nullable()->after('content_en');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('article_title');
        });
    }
};
