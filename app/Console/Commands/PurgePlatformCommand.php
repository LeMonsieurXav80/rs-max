<?php

namespace App\Console\Commands;

use App\Models\Platform;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Efface un reseau et tout ce qui s'y rattache, definitivement.
 *
 * Le balayage est generique — toute table portant `social_account_id` ou
 * `platform_id` est traitee — plutot qu'une liste ecrite a la main : elles sont
 * une bonne vingtaine aujourd'hui, et celle qu'on oublierait laisserait des
 * lignes orphelines pointant vers un compte disparu.
 *
 * Deux choses ne sont JAMAIS supprimees, a dessein :
 *   - les publications nees d'une source Reddit mais parties ailleurs
 *     (`posts.source_type = 'reddit'`) : leur contenu est legitime
 *   - les publications qui se retrouveraient sans aucun reseau, sauf si on le
 *     demande explicitement avec --drop-orphan-posts
 */
class PurgePlatformCommand extends Command
{
    protected $signature = 'platform:purge
                            {slug : Le reseau a effacer (ex: reddit)}
                            {--drop-orphan-posts : Supprimer aussi les publications qui n\'avaient que ce reseau}
                            {--commit : Executer ; sans ce drapeau, affiche le plan}';

    protected $description = 'Efface un reseau, ses comptes et toutes leurs donnees';

    /** Tables a ne pas balayer meme si elles portent la colonne. */
    private const KEEP = ['migrations'];

    public function handle(): int
    {
        $slug = $this->argument('slug');
        $commit = (bool) $this->option('commit');

        $platform = Platform::where('slug', $slug)->first();

        if (! $platform) {
            $this->error("Aucun reseau nomme « {$slug} ».");

            return self::FAILURE;
        }

        $accountIds = DB::table('social_accounts')
            ->where('platform_id', $platform->id)
            ->pluck('id');

        $plan = $this->plan($platform, $accountIds, $slug);
        $total = array_sum($plan);

        $this->table(
            ['Table', 'Lignes'],
            collect($plan)->map(fn ($count, $table) => [$table, $count])->values()->all()
        );

        $orphans = $this->orphanPostIds($accountIds);

        if ($orphans->isNotEmpty()) {
            $this->warn($orphans->count().' publication(s) n\'auront plus aucun reseau.');

            if (! $this->option('drop-orphan-posts')) {
                $this->line('  Elles seront conservees. Ajouter --drop-orphan-posts pour les supprimer aussi.');
            }
        }

        if ($total === 0) {
            $this->info('Rien a supprimer.');

            return self::SUCCESS;
        }

        if (! $commit) {
            $this->comment("Aucune ecriture : {$total} ligne(s) seraient supprimees. Relancer avec --commit.");

            return self::SUCCESS;
        }

        if (! $this->confirm("Supprimer definitivement {$total} ligne(s) ? Aucun retour arriere.", false)) {
            $this->info('Annule.');

            return self::SUCCESS;
        }

        $this->purge($platform, $accountIds, $slug, $orphans);

        $this->info("Reseau « {$slug} » supprime.");

        return self::SUCCESS;
    }

    /**
     * @return array<string, int>
     */
    private function plan(Platform $platform, $accountIds, string $slug): array
    {
        $plan = [];

        foreach ($this->sweeps($platform, $accountIds, $slug) as $table => $query) {
            $count = $query()->count();

            if ($count > 0) {
                $plan[$table] = $count;
            }
        }

        return $plan;
    }

    private function purge(Platform $platform, $accountIds, string $slug, $orphans): void
    {
        DB::transaction(function () use ($platform, $accountIds, $slug, $orphans) {
            foreach ($this->sweeps($platform, $accountIds, $slug) as $query) {
                $query()->delete();
            }

            if ($this->option('drop-orphan-posts') && $orphans->isNotEmpty()) {
                DB::table('posts')->whereIn('id', $orphans)->delete();
            }

            DB::table('social_accounts')->where('platform_id', $platform->id)->delete();
            DB::table('platforms')->where('id', $platform->id)->delete();
        });
    }

    /**
     * Toutes les suppressions a faire, dans l'ordre : les enfants d'abord.
     *
     * @return array<string, callable(): \Illuminate\Database\Query\Builder>
     */
    private function sweeps(Platform $platform, $accountIds, string $slug): array
    {
        $sweeps = [];

        // Tables propres au reseau (reddit_sources, reddit_items, ...).
        foreach (Schema::getTableListing() as $table) {
            $table = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

            if (in_array($table, self::KEEP, true)) {
                continue;
            }

            if (str_starts_with($table, $slug.'_')) {
                $sweeps[$table] = fn () => DB::table($table);

                continue;
            }

            if (Schema::hasColumn($table, 'social_account_id')) {
                $sweeps[$table] = fn () => DB::table($table)->whereIn('social_account_id', $accountIds);

                continue;
            }

            if (Schema::hasColumn($table, 'platform_id') && $table !== 'social_accounts') {
                $sweeps[$table] = fn () => DB::table($table)->where('platform_id', $platform->id);
            }
        }

        return $sweeps;
    }

    /**
     * Publications dont TOUTES les diffusions partent avec le reseau.
     */
    private function orphanPostIds($accountIds)
    {
        $touched = DB::table('post_platform')
            ->whereIn('social_account_id', $accountIds)
            ->distinct()
            ->pluck('post_id');

        $survivors = DB::table('post_platform')
            ->whereIn('post_id', $touched)
            ->whereNotIn('social_account_id', $accountIds)
            ->distinct()
            ->pluck('post_id')
            ->flip();

        return $touched->reject(fn ($id) => $survivors->has($id))->values();
    }
}
