<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->boolean('allow_mamawette')->default(false)->after('allow_pdc_vantour');
        });

        // Drop ancien index pool puis remettre avec mamawette inclus
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropIndex('media_files_pool_filter_idx');
        });
        Schema::table('media_files', function (Blueprint $table) {
            $table->index(
                ['allow_wildycaro', 'allow_pdc_vantour', 'allow_mamawette', 'intimacy_level'],
                'media_files_pool_filter_idx'
            );
        });

        // Simplifie l'intimacy : on supprime semi_prive, toutes les photos en semi_prive
        // basculent vers public (les photos restent invisibles tant que allow_*=false).
        DB::table('media_files')
            ->where('intimacy_level', 'semi_prive')
            ->update(['intimacy_level' => 'public']);

        // Change la valeur par défaut de la colonne intimacy_level. SQLite n'a pas de
        // MODIFY COLUMN, on saute cette étape pour les tests in-memory (le défaut côté
        // SQLite reste 'semi_prive' mais c'est sans importance en environnement de test).
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE media_files MODIFY COLUMN intimacy_level VARCHAR(32) NOT NULL DEFAULT 'public'");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE media_files MODIFY COLUMN intimacy_level VARCHAR(32) NOT NULL DEFAULT 'semi_prive'");
        }

        Schema::table('media_files', function (Blueprint $table) {
            $table->dropIndex('media_files_pool_filter_idx');
        });
        Schema::table('media_files', function (Blueprint $table) {
            $table->index(
                ['allow_wildycaro', 'allow_pdc_vantour', 'intimacy_level'],
                'media_files_pool_filter_idx'
            );
            $table->dropColumn('allow_mamawette');
        });
    }
};
