<?php

namespace App\Console\Commands;

use App\Models\ExternalPost;
use App\Services\Import\ExternalMediaHasher;
use Illuminate\Console\Command;

/**
 * Calcule l'empreinte perceptuelle de la premiere image de chaque publication
 * native, pour pouvoir reconnaitre la meme publication d'un reseau a l'autre.
 *
 * Separe de l'import a dessein : il faut telecharger une image par publication,
 * ce qui n'a rien a faire dans un passage quotidien qui, lui, ne coute qu'une
 * requete d'API par compte.
 *
 * On ne hache que la PREMIERE image. Un carrousel republie ailleurs garde son
 * ouverture ; comparer toutes les images multiplierait le cout sans rien
 * apporter.
 */
class HashExternalMediaCommand extends Command
{
    protected $signature = 'external:hash-media
                            {--days=180 : Profondeur a traiter}
                            {--platform=* : Restreindre a un ou plusieurs reseaux}
                            {--force : Recalculer meme les empreintes deja connues}
                            {--limit=2000 : Plafond de publications traitees}';

    protected $description = 'Calcule l\'empreinte des images des publications natives';

    public function handle(ExternalMediaHasher $hasher): int
    {
        $query = ExternalPost::query()
            ->whereNotNull('published_at')
            ->where('published_at', '>=', now()->subDays((int) $this->option('days')))
            ->when($this->option('platform'), fn ($q, $slugs) => $q->whereHas('platform', fn ($p) => $p->whereIn('slug', $slugs)))
            ->when(! $this->option('force'), fn ($q) => $q->whereNull('media_hashed_at'))
            ->limit((int) $this->option('limit'));

        $posts = $query->get();

        if ($posts->isEmpty()) {
            $this->info('Rien a hacher.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($posts->count());
        $bar->start();

        $result = ['hashed' => 0, 'without_image' => 0, 'failed' => 0];

        foreach ($posts as $post) {
            $result[$hasher->hash($post)]++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("{$result['hashed']} empreinte(s) calculee(s), {$result['without_image']} sans image, {$result['failed']} echec(s).");

        if ($result['failed'] > 0) {
            $this->warn('Les echecs viennent surtout d\'URL expirees : Instagram signe ses liens de CDN.');
            $this->line('  Relancer `external:import` rafraichit les URL, et hache dans la foulee.');
        }

        return self::SUCCESS;
    }
}
