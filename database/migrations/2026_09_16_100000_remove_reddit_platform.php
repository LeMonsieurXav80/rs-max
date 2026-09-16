<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retire Reddit : le reseau social ET la source de contenu.
 *
 * Reddit n'etait plus utilise, et il trainait dans une quinzaine d'aiguillages
 * (`match` d'adapters, listes de plateformes de l'inbox, onglets du composer)
 * ou il fallait penser a lui a chaque ajout de fonctionnalite.
 *
 * IRREVERSIBLE : `down()` ne sait pas rendre les donnees. Le balayage est
 * generique — toute table portant `social_account_id` ou `platform_id` — parce
 * qu'une liste ecrite a la main oublierait celle ajoutee le mois dernier et
 * laisserait des lignes pointant vers un compte disparu.
 *
 * Ce qui SURVIT, a dessein : les publications nees d'une source Reddit mais
 * parties sur d'autres reseaux. Leur contenu est legitime ; seul leur
 * `source_type` devient un vestige sans table derriere, ce qui est sans effet
 * (`getSourceImageUrlAttribute` ne le consulte plus).
 */
return new class extends Migration
{
    private const TABLES = [
        'reddit_posts',
        'reddit_source_social_account',
        'reddit_items',
        'reddit_sources',
    ];

    public function up(): void
    {
        $platformId = DB::table('platforms')->where('slug', 'reddit')->value('id');

        if ($platformId) {
            $accountIds = DB::table('social_accounts')
                ->where('platform_id', $platformId)
                ->pluck('id');

            foreach (Schema::getTableListing() as $table) {
                $table = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

                if ($table === 'migrations' || $table === 'social_accounts' || in_array($table, self::TABLES, true)) {
                    continue;
                }

                if (Schema::hasColumn($table, 'social_account_id')) {
                    DB::table($table)->whereIn('social_account_id', $accountIds)->delete();

                    continue;
                }

                if (Schema::hasColumn($table, 'platform_id')) {
                    DB::table($table)->where('platform_id', $platformId)->delete();
                }
            }

            DB::table('social_accounts')->where('platform_id', $platformId)->delete();
            DB::table('platforms')->where('id', $platformId)->delete();
        }

        // Les reglages d'inbox n'ont plus d'objet.
        DB::table('settings')->whereIn('key', [
            'inbox_platform_reddit_enabled',
            'inbox_sync_freq_reddit',
        ])->delete();

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        // Rien a defaire : les tables se recreeraient vides et la plateforme
        // sans ses comptes. Restaurer Reddit passe par une sauvegarde.
    }
};
