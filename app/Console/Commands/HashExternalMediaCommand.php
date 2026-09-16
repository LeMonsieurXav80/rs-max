<?php

namespace App\Console\Commands;

use App\Models\ExternalPost;
use App\Services\Media\PerceptualHasher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

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

    public function handle(PerceptualHasher $hasher): int
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

        $hashed = 0;
        $sansImage = 0;
        $echecs = 0;

        foreach ($posts as $post) {
            $bar->advance();

            $first = $post->mediaItems()[0] ?? null;
            // La miniature suffit et pese moins : l'empreinte travaille de
            // toute facon sur une image reduite a quelques pixels.
            $url = $first['thumbnail_url'] ?? $first['url'] ?? null;

            if (! $url) {
                $post->update(['media_hashed_at' => now()]);
                $sansImage++;

                continue;
            }

            $hash = $this->hashOf($hasher, $url);

            if ($hash === null) {
                $echecs++;

                // Pas de `media_hashed_at` : une URL momentanement injoignable
                // doit etre retentee au prochain passage.
                continue;
            }

            $post->update(['media_hash' => $hash, 'media_hashed_at' => now()]);
            $hashed++;
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("{$hashed} empreinte(s) calculee(s), {$sansImage} sans image, {$echecs} echec(s).");

        return self::SUCCESS;
    }

    private function hashOf(PerceptualHasher $hasher, string $url): ?string
    {
        $temp = null;

        try {
            $response = Http::timeout(20)->get($url);

            if (! $response->successful()) {
                return null;
            }

            $temp = tempnam(sys_get_temp_dir(), 'rshash_');
            file_put_contents($temp, $response->body());

            return $hasher->hash($temp);
        } catch (\Throwable) {
            return null;
        } finally {
            if ($temp) {
                @unlink($temp);
            }
        }
    }
}
