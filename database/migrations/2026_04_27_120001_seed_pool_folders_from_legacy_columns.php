<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convertit les anciens "pools" (allow_wildycaro, allow_pdc_vantour, allow_mamawette)
 * en vrais dossiers. Chaque photo encore taggée d'un pool est déplacée dans le dossier
 * correspondant. Les dossiers Wildycaro et PDC Vantour sont publics (searchable API),
 * Mamawette est privé.
 *
 * Idempotent : ne fait rien si les colonnes legacy n'existent plus.
 */
return new class extends Migration
{
    private array $poolFolders = [
        'wildycaro' => ['name' => 'Wildycaro', 'slug' => 'wildycaro', 'is_private' => false, 'color' => '#10b981'],
        'pdc_vantour' => ['name' => 'PDC Vantour', 'slug' => 'pdc-vantour', 'is_private' => false, 'color' => '#3b82f6'],
        'mamawette' => ['name' => 'Mamawette', 'slug' => 'mamawette', 'is_private' => true, 'color' => '#ec4899'],
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('media_files', 'allow_wildycaro')) {
            return;
        }

        $folderIds = [];
        foreach ($this->poolFolders as $key => $data) {
            $existing = DB::table('media_folders')->where('slug', $data['slug'])->first();
            if ($existing) {
                $folderIds[$key] = $existing->id;
                continue;
            }
            $folderIds[$key] = DB::table('media_folders')->insertGetId([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'parent_id' => null,
                'color' => $data['color'],
                'is_system' => false,
                'is_private' => $data['is_private'],
                'sort_order' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Ordre de priorité si une photo a plusieurs flags : mamawette > pdc_vantour > wildycaro.
        // (rare mais le schéma legacy l'autorisait — on prend le plus restrictif)
        DB::table('media_files')
            ->where('allow_mamawette', true)
            ->whereNull('folder_id')
            ->update(['folder_id' => $folderIds['mamawette']]);

        DB::table('media_files')
            ->where('allow_pdc_vantour', true)
            ->whereNull('folder_id')
            ->update(['folder_id' => $folderIds['pdc_vantour']]);

        DB::table('media_files')
            ->where('allow_wildycaro', true)
            ->whereNull('folder_id')
            ->update(['folder_id' => $folderIds['wildycaro']]);
    }

    public function down(): void
    {
        // On ne supprime pas les dossiers : si l'utilisateur les a renommés ou y a
        // ajouté des photos après-coup, ce serait destructif. Le rollback de la
        // migration suivante (drop colonnes) suffit pour revenir au schéma précédent.
    }
};
