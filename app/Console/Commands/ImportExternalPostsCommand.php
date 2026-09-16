<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
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
                            {--limit= : Publications ramenees par compte}';

    protected $description = 'Importe les publications faites nativement sur les reseaux';

    /**
     * Reseaux pour lesquels un service d'import existe.
     *
     * @see \App\Services\Import\ImportService::getServiceForPlatform()
     */
    private const IMPORTABLE = ['facebook', 'instagram', 'twitter', 'youtube', 'threads', 'bluesky', 'pinterest'];

    public function handle(ImportService $importService): int
    {
        $limit = (int) ($this->option('limit') ?: config('import.default_limit'));

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
                $this->line("  {$account->name} ({$account->platform->slug}) : {$result['imported']} publication(s)");

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
