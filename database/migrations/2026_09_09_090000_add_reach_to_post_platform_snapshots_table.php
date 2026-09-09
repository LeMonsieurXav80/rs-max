<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Portée (couverture unique) d'une publication.
     *
     * `views` compte les affichages, pas les personnes : sur Meta les deux
     * divergent d'un facteur 1,5 à 3. La valorisation EMV par CPM se défend mal
     * sans la portée, et les deux API la donnent déjà dans l'appel existant —
     * on ne la lisait simplement pas.
     *
     * Nullable partout : seuls Facebook et Instagram l'exposent.
     */
    public function up(): void
    {
        Schema::table('post_platform_snapshots', function (Blueprint $table) {
            $table->unsignedBigInteger('reach')->nullable()->after('views');
        });
    }

    public function down(): void
    {
        Schema::table('post_platform_snapshots', function (Blueprint $table) {
            $table->dropColumn('reach');
        });
    }
};
