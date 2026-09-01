<?php

namespace App\Console\Commands;

use App\Models\PostPlatform;
use App\Services\Adapters\LinkedInAdapter;
use Illuminate\Console\Command;

/**
 * Sonde : l'API `memberCreatorPostAnalytics` répond-elle pour ce compte ?
 *
 * Tant que le produit « Member Post Analytics » n'est pas accordé à l'app
 * LinkedIn, chaque appel repart en 403 — cette commande le montre en clair
 * plutôt que de laisser la synchro échouer en silence.
 */
class LinkedInTestStatsCommand extends Command
{
    protected $signature = 'linkedin:test-stats {--post-platform= : ID de post_platform à sonder}';

    protected $description = 'Sonde la récupération des statistiques d\'un post LinkedIn (profil personnel)';

    public function handle(): int
    {
        $query = PostPlatform::with(['platform', 'socialAccount'])
            ->whereHas('platform', fn ($q) => $q->where('slug', 'linkedin'))
            ->where('status', 'published')
            ->whereNotNull('external_id');

        $postPlatform = $this->option('post-platform')
            ? $query->find($this->option('post-platform'))
            : $query->latest('published_at')->first();

        if (! $postPlatform) {
            $this->error('Aucune publication LinkedIn publiée avec un external_id.');

            return self::FAILURE;
        }

        $this->info("post_platform #{$postPlatform->id} — URN : {$postPlatform->external_id}");
        $this->line('Compte : '.$postPlatform->socialAccount->name
            .' ('.($postPlatform->socialAccount->credentials['account_type'] ?? 'person').')');

        $metrics = (new LinkedInAdapter)->fetchPostMetrics($postPlatform->socialAccount, $postPlatform->external_id);

        if ($metrics === null) {
            $this->error('Aucune métrique récupérée — voir laravel.log pour le corps de la réponse.');

            return self::FAILURE;
        }

        foreach ($metrics as $key => $value) {
            $this->line(str_pad($key, 12).': '.($value ?? '—'));
        }

        return self::SUCCESS;
    }
}
