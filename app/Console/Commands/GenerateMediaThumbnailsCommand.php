<?php

namespace App\Console\Commands;

use App\Models\MediaFile;
use App\Services\Media\ThumbnailService;
use Illuminate\Console\Command;

/**
 * Pré-génère les vignettes légères (JPEG ~480px) des médias existants.
 *
 * Indispensable après le déploiement de cette fonctionnalité : sans backfill, la
 * première ouverture de chaque dossier génèrerait toutes les vignettes à la volée
 * (ffmpeg/GD) dans la requête HTTP → lenteur au premier affichage. Cette commande
 * fait tout le travail une bonne fois, en amont.
 *
 * Par défaut ne (re)génère que les médias sans thumbnail_path exploitable.
 */
class GenerateMediaThumbnailsCommand extends Command
{
    protected $signature = 'media:generate-thumbnails
        {--force : Régénère aussi les vignettes déjà présentes}
        {--images-only : Ne traite que les images}
        {--videos-only : Ne traite que les vidéos}
        {--limit= : Limite le nombre de médias traités}';

    protected $description = 'Pré-génère les vignettes légères des médias (images GD + vidéos ffmpeg).';

    public function handle(ThumbnailService $thumbnails): int
    {
        $force = (bool) $this->option('force');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $query = MediaFile::query()->orderBy('id');

        if ($this->option('images-only')) {
            $query->where('mime_type', 'like', 'image/%');
        } elseif ($this->option('videos-only')) {
            $query->where('mime_type', 'like', 'video/%');
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $total = $query->count();
        if ($total === 0) {
            $this->info('Aucun média à traiter.');

            return self::SUCCESS;
        }

        $this->info("Traitement de {$total} média(s)".($force ? ' (force)' : '').' …');
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $done = 0;
        $failed = 0;
        $skipped = 0;

        $query->chunkById(100, function ($chunk) use ($thumbnails, $force, &$done, &$failed, &$skipped, $bar) {
            foreach ($chunk as $media) {
                // Sans --force, on saute ce qui a déjà une vignette valide sur disque.
                if (! $force && $media->thumbnail_path && $thumbnails->exists($media->filename)) {
                    $skipped++;
                    $bar->advance();

                    continue;
                }

                $rel = $thumbnails->generate($media, $force);
                if ($rel) {
                    if ($media->thumbnail_path !== $rel) {
                        $media->update(['thumbnail_path' => $rel]);
                    }
                    $done++;
                } else {
                    $failed++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Vignettes générées : {$done} — ignorées : {$skipped} — échecs : {$failed}");

        return self::SUCCESS;
    }
}
