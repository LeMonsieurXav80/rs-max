<?php

namespace App\Console\Commands;

use App\Services\Carousel\CarouselRenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Authoring local des briques de carrousel. Écrit le HTML de la bande dans
 * storage/app/ pour l'ouvrir dans un navigateur (aucun Chromium requis). Avec
 * --render, rasterise réellement via Browsershot pour tester le pipeline complet.
 *
 *   php artisan carousel:preview                       # démo 3 briques en 4:5
 *   php artisan carousel:preview photo-title-bl --ratio=1:1 --image=/chemin/photo.jpg
 *   php artisan carousel:preview --render              # produit aussi les PNG
 */
class CarouselPreviewCommand extends Command
{
    protected $signature = 'carousel:preview
        {brick? : Slug de brique à prévisualiser seule (défaut : démo multi-briques)}
        {--ratio=4:5 : Ratio (clé de config carousel.ratios)}
        {--image= : Chemin d\'une image de test pour les slots image}
        {--render : Rasterise via Browsershot en plus du HTML (nécessite Chromium)}';

    protected $description = 'Prévisualise/rends des briques de carrousel en local';

    public function handle(CarouselRenderService $service): int
    {
        $ratio = (string) $this->option('ratio');
        if (! config("carousel.ratios.{$ratio}")) {
            $this->error("Ratio inconnu : {$ratio}. Dispo : ".implode(', ', array_keys(config('carousel.ratios', []))));

            return self::FAILURE;
        }

        $image = $this->option('image');
        $slides = $this->slides($this->argument('brick'), $image);

        if (empty($slides)) {
            $this->error('Brique inconnue. Dispo : '.implode(', ', array_keys(config('carousel.bricks', []))));

            return self::FAILURE;
        }

        $html = $service->buildHtml($ratio, $slides);
        Storage::disk('local')->put('carousel-preview.html', $html);
        $htmlPath = Storage::disk('local')->path('carousel-preview.html');

        $this->info('HTML écrit → '.$htmlPath);
        $this->line('Ouvre : file://'.$htmlPath);

        if ($this->option('render')) {
            $this->line('Rasterisation Browsershot…');
            $files = $service->render($ratio, $slides, 'png');
            foreach ($files as $f) {
                $this->line('  slide → '.Storage::disk('local')->path("media/{$f}"));
            }
            $this->info(count($files).' slide(s) rendus.');
        }

        return self::SUCCESS;
    }

    /**
     * Construit les slides de démo (ou une brique seule) avec des données factices.
     */
    private function slides(?string $brick, ?string $image): array
    {
        $demo = [
            'photo-title-bl' => [
                'brick' => 'photo-title-bl',
                'data' => [
                    'image' => $image,
                    'title' => 'La créatine abîme-t-elle vos reins ?',
                    'subtitle' => '26 essais, 1036 participants passés au crible.',
                ],
            ],
            'text-on-image' => [
                'brick' => 'text-on-image',
                'data' => [
                    'image' => $image,
                    'title' => 'Ce que révèlent vraiment les marqueurs rénaux',
                    'body' => 'Sans dramatiser ni survendre — juste les données.',
                ],
            ],
            'bold-text' => [
                'brick' => 'bold-text',
                'data' => [
                    'title' => 'Bien toléré aux doses étudiées.',
                    'subtitle' => 'Le verdict, réserve honnête comprise.',
                ],
            ],
        ];

        if ($brick !== null) {
            return isset($demo[$brick]) ? [$demo[$brick]] : [];
        }

        return array_values($demo);
    }
}
