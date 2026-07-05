<?php

namespace App\Console\Commands;

use App\Models\MediaFile;
use App\Services\Media\PhashComputer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Calcule le phash perceptuel des media_files images qui n'en ont pas encore,
 * à partir du fichier stocké localement. Prérequis pour que media:reconcile-wp
 * soit utile : sans phash au catalogue, aucune image WP ne peut être rapprochée.
 *
 * Utilise le même helper Python (venv du pipeline d'ingest) que reconcile-wp, donc
 * un algo identique. Le fichier local est la version compressée : les phash obtenus
 * sont cohérents avec ceux calculés sur les tailles "medium" WP par reconcile-wp
 * (deux dérivés compressés), ce qui est idéal pour le rapprochement.
 */
class BackfillPhashCommand extends Command
{
    protected $signature = 'media:backfill-phash
        {--limit= : Limite le nombre d\'images traitées}
        {--force : Recalcule aussi les phash déjà présents}
        {--python= : Binaire Python à utiliser (défaut: config services.media_reconcile.python)}
        {--commit : Écrit réellement les phash (par défaut: dry-run)}';

    protected $description = 'Calcule le phash des media_files images sans phash depuis les fichiers locaux (prérequis de media:reconcile-wp). Dry-run par défaut.';

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');
        $force = (bool) $this->option('force');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $phash = PhashComputer::fromConfig($this->option('python'));
        if ($reason = $phash->unavailableReason()) {
            $this->error($reason);

            return self::FAILURE;
        }

        $query = MediaFile::where('mime_type', 'like', 'image/%');
        if (! $force) {
            $query->whereNull('phash');
        }
        $query->orderBy('id');
        if ($limit !== null) {
            $query->limit($limit);
        }

        $disk = Storage::disk('local');
        $candidates = $query->get(['id', 'filename']);

        $this->info(sprintf(
            '%d image(s) à traiter · %s%s · %s',
            $candidates->count(),
            $force ? 'recalcul forcé' : 'sans phash uniquement',
            $limit !== null ? " (limit {$limit})" : '',
            $commit ? 'COMMIT' : 'DRY-RUN',
        ));

        $hashed = 0;
        $missing = 0;
        $unreadable = 0;
        $tmpDir = sys_get_temp_dir().'/rsmax-backfill-'.getmypid();
        @mkdir($tmpDir, 0700, true);

        foreach ($candidates->chunk(200) as $batch) {
            // id => chemin absolu, pour les fichiers réellement présents sur disque.
            $byId = [];
            foreach ($batch as $mf) {
                $rel = 'media/'.$mf->filename;
                if ($disk->exists($rel)) {
                    $byId[$mf->id] = $disk->path($rel);
                } else {
                    $missing++;
                }
            }

            $phashes = $phash->compute($tmpDir.'/manifest.json', array_values($byId));

            foreach ($byId as $id => $path) {
                $hex = $phashes[$path] ?? null;
                if (! $hex) {
                    $unreadable++;

                    continue;
                }
                if ($commit) {
                    MediaFile::where('id', $id)->update(['phash' => $hex]);
                }
                $hashed++;
            }
        }

        foreach (glob($tmpDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($tmpDir);

        if (! $commit && $hashed > 0) {
            $this->warn("DRY-RUN : aucune écriture. Relance avec --commit pour écrire {$hashed} phash.");
        }

        $this->newLine();
        $this->info(sprintf(
            'Résumé : %d %s · %d fichier(s) manquant(s) · %d illisible(s)',
            $hashed, $commit ? 'phash écrits' : 'phash calculables', $missing, $unreadable,
        ));

        return self::SUCCESS;
    }
}
