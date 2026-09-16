<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Services\Import\ExternalMediaHasher;
use App\Services\Import\ImportService;
use Illuminate\Console\Command;

/**
 * Remplit le flux des publications natives sans intervention.
 *
 * Jusqu'ici l'import ne partait que du bouton de `/external`. Il coute pourtant
 * une requete PAR COMPTE (100 publications d'un coup, metriques comprises),
 * la ou `stats:sync` en coute deux a trois PAR PUBLICATION : le planifier ne
 * pese rien a cote de ce qui tourne deja.
 */
class ImportExternalPostsCommand extends Command
{
    protected $signature = 'external:import
                            {--account= : Un seul compte social, par son id}
                            {--platform= : Un seul reseau, par son slug}
                            {--limit= : Publications ramenees par compte}
                            {--since= : Rattrapage force sur N jours, en ignorant le point de reprise}
                            {--no-hash : Ne pas calculer l\'empreinte des images}';

    protected $description = 'Importe les publications faites nativement sur les reseaux';

    /**
     * Reseaux pour lesquels un service d'import existe.
     *
     * @see \App\Services\Import\ImportService::getServiceForPlatform()
     */
    private const IMPORTABLE = ['facebook', 'instagram', 'twitter', 'youtube', 'threads', 'bluesky', 'pinterest'];

    public function handle(ImportService $importService, ExternalMediaHasher $hasher): int
    {
        $limit = (int) ($this->option('limit') ?: config('import.default_limit'));

        // Rattrapage : on redescend a la profondeur demandee au lieu de
        // reprendre a la derniere publication connue. Pense pour une reprise
        // depuis zero, pas pour le passage quotidien — chaque reseau borne de
        // toute facon ce qu'il accepte de rendre (X s'arrete vers 3 200
        // publications, les autres paginent plus loin).
        if ($since = $this->option('since')) {
            config(['import.force_since_days' => (int) $since]);
            $this->warn("Rattrapage force sur {$since} jours : le point de reprise est ignore.");
        }

        $accounts = SocialAccount::with('platform')
            ->when($this->option('account'), fn ($q, $id) => $q->where('id', (int) $id))
            ->when($this->option('platform'), fn ($q, $slug) => $q->whereHas('platform', fn ($p) => $p->where('slug', $slug)))
            ->get()
            ->filter(fn (SocialAccount $a) => in_array($a->platform?->slug, self::IMPORTABLE, true));

        if ($accounts->isEmpty()) {
            $this->warn('Aucun compte a importer.');

            return self::SUCCESS;
        }

        $total = 0;
        $failed = 0;

        foreach ($accounts as $account) {
            if (! $importService->canImport($account)['allowed']) {
                $this->line("  {$account->name} ({$account->platform->slug}) : en cooldown, ignore");

                continue;
            }

            $result = $importService->import($account, $limit);

            if ($result['success']) {
                $total += $result['imported'];
                $suffixe = '';

                // Tant que les URL sont fraiches : Instagram signe ses liens de
                // CDN et un hachage differe se prend des 403.
                if (! $this->option('no-hash')) {
                    $aHacher = \App\Models\ExternalPost::where('social_account_id', $account->id)
                        ->whereNull('media_hashed_at')
                        ->whereNotNull('published_at')
                        ->orderByDesc('published_at')
                        ->limit(200)
                        ->get();

                    if ($aHacher->isNotEmpty()) {
                        $h = $hasher->hashAll($aHacher);
                        $suffixe = ", {$h['hashed']} empreinte(s)";
                    }
                }

                $this->line("  {$account->name} ({$account->platform->slug}) : {$result['imported']} publication(s){$suffixe}");

                continue;
            }

            $failed++;
            $this->error("  {$account->name} ({$account->platform->slug}) : {$result['error']}");
        }

        $this->newLine();
        $this->info("{$total} publication(s) importee(s) sur {$accounts->count()} compte(s).");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
