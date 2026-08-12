<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Support\MediaItems;
use Illuminate\Console\Command;

/**
 * Rattrape les posts dont les medias n'ont pas de `mimetype`.
 *
 * Les publications creees par l'API (ou importees) ne portaient que
 * `type: image|video`. Les apercus (edition, liste, fiche) et les filtres SQL
 * photo/video/carrousel lisent `mimetype` : sans lui, on affichait un carre
 * vide ou une icone cassee. Le modele normalise desormais a l'ecriture ; cette
 * commande traite les lignes deja en base.
 */
class NormalizePostMediaCommand extends Command
{
    protected $signature = 'posts:normalize-media {--dry-run : Affiche ce qui serait corrige sans rien ecrire}';

    protected $description = 'Ajoute le mimetype manquant aux medias des posts (API / imports)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $fixed = 0;

        Post::whereNotNull('media')
            ->orderBy('id')
            ->chunkById(200, function ($posts) use (&$fixed, $dryRun) {
                foreach ($posts as $post) {
                    $raw = json_decode($post->getRawOriginal('media') ?? '', true);
                    $normalized = MediaItems::normalize($raw);

                    if ($normalized === null || $normalized === $raw) {
                        continue;
                    }

                    $fixed++;
                    $this->line("post #{$post->id} : ".collect($normalized)->pluck('mimetype')->implode(', '));

                    if (! $dryRun) {
                        $post->media = $normalized;
                        $post->timestamps = false;
                        $post->saveQuietly();
                    }
                }
            });

        $this->info($dryRun
            ? "{$fixed} post(s) a corriger (dry-run, rien ecrit)."
            : "{$fixed} post(s) corrige(s).");

        return self::SUCCESS;
    }
}
