<?php

namespace App\Console\Commands;

use App\Models\Platform;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\ThreadSegmentPlatform;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Rattrape les `platform_url` Threads forgees a partir de l'id media numerique.
 *
 * Les vraies URLs Threads portent un shortcode (`/post/Dbpm73MEVjG`) qu'on ne
 * peut pas deriver de l'id : l'URL construite par PostUrlBuilder tombait sur un
 * mur de connexion. Le permalink est desormais stocke a la publication, mais
 * tout l'historique reste a corriger — d'ou cette commande, a lancer une fois.
 */
class ThreadsBackfillPermalinksCommand extends Command
{
    protected $signature = 'threads:backfill-permalinks
        {--dry-run : affiche ce qui serait fait sans rien ecrire}';

    protected $description = 'Remplace les URLs Threads forgees par le vrai permalink lu sur l\'API';

    private const API_BASE = 'https://graph.threads.net/v1.0';

    public function handle(): int
    {
        $platform = Platform::where('slug', 'threads')->first();

        if (! $platform) {
            $this->error('Plateforme threads introuvable.');

            return self::FAILURE;
        }

        $dryRun = $this->option('dry-run');
        $tokens = $this->tokensByAccount($platform);

        if (empty($tokens)) {
            $this->error('Aucun compte Threads avec un access_token.');

            return self::FAILURE;
        }

        $stats = ['ok' => 0, 'inchange' => 0, 'echec' => 0];

        foreach ([ThreadSegmentPlatform::class, PostPlatform::class] as $model) {
            $rows = $model::query()
                ->where('platform_id', $platform->id)
                ->whereNotNull('external_id')
                ->get();

            $this->info(sprintf('%s : %d ligne(s)', class_basename($model), $rows->count()));

            foreach ($rows as $row) {
                $token = $tokens[$row->social_account_id] ?? null;

                if (! $token) {
                    $stats['echec']++;

                    continue;
                }

                $permalink = Http::get(self::API_BASE.'/'.$row->external_id, [
                    'fields' => 'permalink',
                    'access_token' => $token,
                ])->json('permalink');

                if (! $permalink) {
                    // Post supprime cote Meta, ou token d'un autre compte : on laisse
                    // la valeur existante plutot que d'effacer une info.
                    $this->line("  <fg=red>✗</> {$row->external_id} — permalink introuvable");
                    $stats['echec']++;

                    continue;
                }

                if ($row->platform_url === $permalink) {
                    $stats['inchange']++;

                    continue;
                }

                $this->line("  <fg=green>→</> {$row->external_id} : {$permalink}");

                if (! $dryRun) {
                    $row->update(['platform_url' => $permalink]);
                }

                $stats['ok']++;
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s%d corrigee(s), %d deja bonne(s), %d en echec.',
            $dryRun ? '[dry-run] ' : '',
            $stats['ok'],
            $stats['inchange'],
            $stats['echec'],
        ));

        return self::SUCCESS;
    }

    /** @return array<int, string> access_token indexe par social_account_id */
    private function tokensByAccount(Platform $platform): array
    {
        return SocialAccount::where('platform_id', $platform->id)
            ->get()
            ->mapWithKeys(fn (SocialAccount $a) => [$a->id => $a->credentials['access_token'] ?? null])
            ->filter()
            ->all();
    }
}
