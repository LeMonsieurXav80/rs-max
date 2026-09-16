<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Services\Pinterest\PinterestApiService;
use Illuminate\Console\Command;

/**
 * Sonde `GET /pins` avant de s'y fier.
 *
 * L'import Pinterest a ete ecrit sur la documentation v5 : epingles du
 * proprietaire, statistiques dans le meme appel via `pin_metrics`. Deux choses
 * restent a verifier en vrai, et elles decident du reste :
 *   - les epingles ENREGISTREES chez d'autres remontent-elles aussi ? (si oui,
 *     il faut les ecarter, sinon l'adoption automatique les avalerait)
 *   - `pin_metrics` repond-il avec le scope actuel, ou reclame-t-il un droit
 *     supplementaire ?
 */
class PinterestTestPinsCommand extends Command
{
    protected $signature = 'pinterest:test-pins
                            {--account= : Compte Pinterest, par son id}
                            {--limit=5 : Nombre d\'epingles a examiner}
                            {--raw : Afficher la reponse brute de la premiere epingle}';

    protected $description = 'Verifie ce que l\'API Pinterest renvoie reellement pour les epingles';

    public function handle(PinterestApiService $api): int
    {
        $account = SocialAccount::with('platform')
            ->when($this->option('account'), fn ($q, $id) => $q->where('id', (int) $id))
            ->whereHas('platform', fn ($q) => $q->where('slug', 'pinterest'))
            ->first();

        if (! $account) {
            $this->error('Aucun compte Pinterest connecte.');

            return self::FAILURE;
        }

        $this->info("Compte : {$account->name}");

        $pins = $api->getOwnedPins($account, (int) $this->option('limit'));

        if ($pins === []) {
            $this->error('Aucune epingle rendue. Voir storage/logs pour la reponse de l\'API.');

            return self::FAILURE;
        }

        if ($this->option('raw')) {
            $this->line(json_encode($pins[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->newLine();
        }

        $rows = [];

        foreach ($pins as $pin) {
            $rows[] = [
                $pin['id'] ?? '?',
                substr((string) ($pin['title'] ?? $pin['description'] ?? ''), 0, 30),
                $pin['created_at'] ?? '?',
                $pin['media']['media_type'] ?? '?',
                // Renseigne quand l'epingle a ete enregistree depuis un autre
                // compte : c'est le signal a exclure de l'adoption.
                isset($pin['board_owner']['username']) ? $pin['board_owner']['username'] : '-',
                isset($pin['pin_metrics']) ? 'oui' : 'NON',
            ];
        }

        $this->table(['Id', 'Titre', 'Creee le', 'Type', 'Proprietaire du tableau', 'Stats'], $rows);

        $sansStats = collect($pins)->whereNull('pin_metrics')->count();

        if ($sansStats > 0) {
            $this->warn("{$sansStats} epingle(s) sans `pin_metrics` : le scope ne suffit peut-etre pas.");
        }

        return self::SUCCESS;
    }
}
