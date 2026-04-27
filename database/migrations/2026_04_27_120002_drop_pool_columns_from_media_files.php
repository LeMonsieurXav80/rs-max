<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supprime les colonnes "pool" hardcodées : la visibilité API est désormais
 * portée par le dossier (media_folders.is_private). Voir migration précédente
 * qui a déplacé les photos taggées dans les bons dossiers.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('media_files', 'allow_wildycaro')) {
            return;
        }

        Schema::table('media_files', function (Blueprint $table) {
            $table->dropIndex('media_files_pool_filter_idx');
        });

        Schema::table('media_files', function (Blueprint $table) {
            $table->dropColumn([
                'allow_wildycaro',
                'allow_pdc_vantour',
                'allow_mamawette',
                'pool_suggested',
            ]);
        });

        Schema::table('media_files', function (Blueprint $table) {
            $table->index(['folder_id', 'intimacy_level'], 'media_files_folder_intimacy_idx');
        });
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropIndex('media_files_folder_intimacy_idx');
        });

        Schema::table('media_files', function (Blueprint $table) {
            $table->string('pool_suggested', 32)->nullable()->after('embedding_model');
            $table->boolean('allow_wildycaro')->default(false)->after('pool_suggested');
            $table->boolean('allow_pdc_vantour')->default(false)->after('allow_wildycaro');
            $table->boolean('allow_mamawette')->default(false)->after('allow_pdc_vantour');
            $table->index(
                ['allow_wildycaro', 'allow_pdc_vantour', 'allow_mamawette', 'intimacy_level'],
                'media_files_pool_filter_idx'
            );
        });

        // Re-remplit les flags depuis les dossiers (pour rollback propre).
        $folders = DB::table('media_folders')
            ->whereIn('slug', ['wildycaro', 'pdc-vantour', 'mamawette'])
            ->get()->keyBy('slug');

        if ($wildy = $folders->get('wildycaro')) {
            DB::table('media_files')->where('folder_id', $wildy->id)->update(['allow_wildycaro' => true]);
        }
        if ($pdc = $folders->get('pdc-vantour')) {
            DB::table('media_files')->where('folder_id', $pdc->id)->update(['allow_pdc_vantour' => true]);
        }
        if ($mama = $folders->get('mamawette')) {
            DB::table('media_files')->where('folder_id', $mama->id)->update(['allow_mamawette' => true]);
        }
    }
};
