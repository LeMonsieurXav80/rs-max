<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reporte sur les publications adoptees les metriques restees sur leur ligne
 * d'origine.
 *
 * L'adoption creait le `post_platform` sans recopier les metriques que l'import
 * avait pourtant deja payees : la publication s'affichait vide. Sur un reseau
 * sans service de stats, elle le serait restee pour toujours.
 *
 * Ne touche qu'aux lignes encore vides : une publication deja resynchronisee
 * depuis a des chiffres plus frais que ceux de son import.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('external_posts')
            ->whereNotNull('adopted_post_id')
            ->whereNotNull('metrics')
            ->orderBy('id')
            ->chunk(200, function ($externalPosts) {
                foreach ($externalPosts as $externalPost) {
                    DB::table('post_platform')
                        ->where('post_id', $externalPost->adopted_post_id)
                        ->where('platform_id', $externalPost->platform_id)
                        ->where('external_id', $externalPost->external_id)
                        ->whereNull('metrics')
                        ->update([
                            'metrics' => $externalPost->metrics,
                            'metrics_synced_at' => $externalPost->metrics_synced_at,
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Rien a defaire : remettre ces metriques a null reviendrait a vider
        // des publications qui affichent des chiffres justes.
    }
};
