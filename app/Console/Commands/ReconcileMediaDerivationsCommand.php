<?php

namespace App\Console\Commands;

use App\Models\MediaFile;
use App\Models\MediaPublication;
use App\Services\Media\PhashComputer;
use App\Services\Media\PhashMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Rattrapage rétroactif de la filiation des images générées : retrouver, pour les
 * slides déjà produites par le Studio / l'API carrousel, la photo du catalogue qui
 * leur a servi de fond. Depuis août 2026 le lien est enregistré au rendu — cette
 * commande ne sert qu'à l'historique antérieur, où rien n'a été conservé.
 *
 * BEST-EFFORT, et nettement moins fiable que media:reconcile-wp : une slide est un
 * RECADRAGE de la photo, surchargé de texte et de dégradés. Le phash perceptuel
 * encaisse la compression, pas le recadrage. Attendre un rappel faible (beaucoup
 * de « sans match ») ; ce qui sort au-dessus du seuil reste néanmoins sûr.
 *
 * Dry-run par défaut. --commit écrit les liens et, sauf --no-publications, reporte
 * les publications passées de la slide sur la photo retrouvée (mêmes lignes,
 * marquées via_media_file_id) pour que son compteur d'usage soit juste.
 */
class ReconcileMediaDerivationsCommand extends Command
{
    protected $signature = 'media:reconcile-derivations
        {--threshold=12 : Distance de Hamming max pour accepter un match (plus permissif que reconcile-wp : la slide est recadrée)}
        {--margin=2 : Écart minimal avec le 2e candidat, sinon le cas est déclaré ambigu et ignoré}
        {--limit= : Limite le nombre d\'images générées examinées}
        {--force : Réexamine aussi les images générées qui ont déjà une filiation}
        {--no-publications : N\'écrit que les liens, sans reporter les publications passées sur les photos sources}
        {--python= : Binaire Python à utiliser (défaut: config services.media_reconcile.python)}
        {--commit : Écrit réellement (par défaut: dry-run, aucune écriture)}';

    protected $description = 'Retrouve par phash les photos sources des images générées avant la mise en place du suivi de filiation (best-effort, dry-run par défaut).';

    public function handle(): int
    {
        $threshold = (int) $this->option('threshold');
        $margin = (int) $this->option('margin');
        $commit = (bool) $this->option('commit');
        $withPublications = ! $this->option('no-publications');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $phash = PhashComputer::fromConfig($this->option('python'));
        if ($reason = $phash->unavailableReason()) {
            $this->error($reason);

            return self::FAILURE;
        }

        // Catalogue = les photos d'origine hashées. Les images générées en sont
        // exclues : une slide ne peut pas être la source d'une autre slide ici.
        $catalog = MediaFile::query()
            ->where('is_generated', false)
            ->whereNotNull('phash')
            ->get(['id', 'filename', 'phash'])
            ->filter(fn (MediaFile $m) => PhashMatcher::hamming($m->phash, $m->phash) !== null)
            ->values();

        if ($catalog->isEmpty()) {
            $this->error('Aucune photo du catalogue n\'a de phash valide : lance d\'abord media:backfill-phash --commit.');

            return self::FAILURE;
        }

        $query = MediaFile::query()
            ->where('is_generated', true)
            ->where('mime_type', 'like', 'image/%')
            ->orderBy('id');

        if (! $this->option('force')) {
            $query->whereDoesntHave('sources');
        }
        if ($limit !== null) {
            $query->limit($limit);
        }

        $candidates = $query->get(['id', 'filename']);

        $this->info(sprintf(
            '%d image(s) générée(s) à examiner · catalogue=%d photos hashées · seuil=%d (marge %d) · %s',
            $candidates->count(), $catalog->count(), $threshold, $margin,
            $commit ? 'COMMIT' : 'DRY-RUN',
        ));
        $this->warn('Rappel : une slide est un recadrage surchargé de texte — beaucoup de « sans match » est NORMAL.');
        $this->newLine();

        $disk = Storage::disk('local');
        $tmpDir = sys_get_temp_dir().'/rsmax-derivations-'.getmypid();
        @mkdir($tmpDir, 0700, true);

        $linked = 0;
        $ambiguous = 0;
        $noMatch = 0;
        $missing = 0;
        $publications = 0;
        $rows = [];

        foreach ($candidates->chunk(100) as $batch) {
            $byId = [];
            foreach ($batch as $mf) {
                $rel = 'media/'.$mf->filename;
                if ($disk->exists($rel)) {
                    $byId[$mf->id] = $disk->path($rel);
                } else {
                    $missing++;
                }
            }

            $hashes = $phash->compute($tmpDir.'/manifest.json', array_values($byId));

            foreach ($byId as $id => $path) {
                $hex = $hashes[$path] ?? null;
                if (! $hex) {
                    $noMatch++;

                    continue;
                }

                // Deux meilleurs candidats : la marge sépare un vrai match d'une série
                // de photos qui se ressemblent toutes (même lieu, même séance).
                $best = null;
                $second = null;
                foreach ($catalog as $photo) {
                    $d = PhashMatcher::hamming($hex, $photo->phash);
                    if ($d === null || $d > $threshold) {
                        continue;
                    }
                    if ($best === null || $d < $best['distance']) {
                        $second = $best;
                        $best = ['photo' => $photo, 'distance' => $d];
                    } elseif ($second === null || $d < $second['distance']) {
                        $second = ['photo' => $photo, 'distance' => $d];
                    }
                }

                if ($best === null) {
                    $noMatch++;

                    continue;
                }
                if ($second !== null && ($second['distance'] - $best['distance']) < $margin) {
                    $ambiguous++;

                    continue;
                }

                $derived = MediaFile::find($id);
                if (! $derived) {
                    continue;
                }

                $confidence = PhashMatcher::confidence($best['distance']);
                $rows[] = [
                    '#'.$derived->id,
                    $derived->filename,
                    '#'.$best['photo']->id,
                    $best['photo']->filename,
                    $best['distance'],
                    $confidence.'%',
                ];
                $linked++;

                if (! $commit) {
                    continue;
                }

                $derived->sources()->syncWithoutDetaching([
                    $best['photo']->id => [
                        'slot' => null,
                        'brick' => null,
                        'match_method' => 'phash',
                        'match_confidence' => $confidence,
                    ],
                ]);

                if ($withPublications) {
                    $publications += $this->backfillPublications($derived, $best['photo']->id);
                }
            }
        }

        foreach (glob($tmpDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($tmpDir);

        if ($rows !== []) {
            $this->table(['Générée', 'Fichier', 'Source', 'Fichier source', 'Dist.', 'Conf.'], $rows);
        }

        $this->newLine();
        $this->info(sprintf(
            'Résumé : %d lien(s) %s · %d ambigu(s) ignoré(s) · %d sans match · %d fichier(s) absent(s) du disque%s',
            $linked, $commit ? 'écrits' : 'trouvés (dry-run)', $ambiguous, $noMatch, $missing,
            $commit && $withPublications ? " · {$publications} publication(s) reportée(s)" : '',
        ));

        if (! $commit && $linked > 0) {
            $this->warn('DRY-RUN : aucune écriture. Relance avec --commit.');
        }

        return self::SUCCESS;
    }

    /**
     * Reporte sur la photo source les publications déjà enregistrées pour la slide,
     * comme le fait MediaPublicationTracker pour les nouvelles. Idempotent : une
     * ligne identique (même photo, même image intermédiaire, même publication) n'est
     * jamais créée deux fois, donc relancer la commande ne gonfle pas les compteurs.
     */
    private function backfillPublications(MediaFile $derived, int $sourceId): int
    {
        $created = 0;

        foreach ($derived->publications()->get() as $pub) {
            $exists = MediaPublication::where('media_file_id', $sourceId)
                ->where('via_media_file_id', $derived->id)
                ->where('post_id', $pub->post_id)
                ->where('thread_segment_id', $pub->thread_segment_id)
                ->where('post_platform_id', $pub->post_platform_id)
                ->where('social_account_id', $pub->social_account_id)
                ->exists();

            if ($exists) {
                continue;
            }

            MediaPublication::create([
                'media_file_id' => $sourceId,
                'via_media_file_id' => $derived->id,
                'post_id' => $pub->post_id,
                'thread_segment_id' => $pub->thread_segment_id,
                'post_platform_id' => $pub->post_platform_id,
                'social_account_id' => $pub->social_account_id,
                'external_url' => $pub->external_url,
                'published_at' => $pub->published_at,
                'context' => $pub->context,
            ]);
            MediaFile::where('id', $sourceId)->increment('publication_count');
            $created++;
        }

        return $created;
    }
}
