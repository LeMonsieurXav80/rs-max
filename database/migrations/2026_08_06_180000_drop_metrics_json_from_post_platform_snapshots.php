<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retire le payload JSON des snapshots.
     *
     * Il avait été ajouté en prévision d'une métrique spécifique à une plateforme,
     * mais les six colonnes typées (views, likes, comments, shares, bookmarks,
     * followers) couvrent l'intégralité de ce que renvoient les services de stats
     * actuels. Le JSON ne faisait donc que dupliquer ces valeurs, pour ~40 % du
     * poids de chaque ligne. À rétablir par une nouvelle migration le jour où une
     * plateforme fournit réellement autre chose.
     */
    public function up(): void
    {
        Schema::table('post_platform_snapshots', function (Blueprint $table) {
            $table->dropColumn('metrics');
        });
    }

    public function down(): void
    {
        Schema::table('post_platform_snapshots', function (Blueprint $table) {
            $table->json('metrics')->nullable();
        });
    }
};
