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
        {--position= : Ancre du bloc de texte (top-left … bottom-right)}
        {--offset= : Décalage vertical fin du bloc de texte, en % de la hauteur (-25 à 25)}
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

        // Surcharges d'authoring : tester une ancre / un décalage sans éditer le code.
        if ($position = $this->option('position')) {
            if (! isset(config('carousel.positions', [])[$position])) {
                $this->error("Position inconnue : {$position}. Dispo : ".implode(', ', array_keys(config('carousel.positions', []))));

                return self::FAILURE;
            }
            foreach ($slides as $i => $slide) {
                $slides[$i]['data']['position'] = $position;
            }
        }
        if (($offset = $this->option('offset')) !== null && $offset !== '') {
            foreach ($slides as $i => $slide) {
                $slides[$i]['data']['offset'] = (float) $offset;
            }
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
            'image-full' => [
                'brick' => 'image-full',
                'data' => [
                    'image' => $image,
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
            'stat-grid' => [
                'brick' => 'stat-grid',
                'data' => [
                    'title' => 'Ce que dit la littérature',
                    'items' => "26|essais contrôlés\n1 036|participants\n0|effet rénal significatif\n12 sem.|durée médiane",
                    'columns' => 2,
                ],
            ],
            'quote' => [
                'brick' => 'quote',
                'data' => [
                    'image' => $image,
                    'quote' => 'Aucune altération de la fonction rénale n’a été observée chez des sujets sains.',
                    'author' => 'Journal of the ISSN, 2021',
                ],
            ],
            'table-rows' => [
                'brick' => 'table-rows',
                'data' => [
                    'title' => 'Ce qu’on mesure vraiment',
                    'rows' => "Créatinine sérique|↑ artefact\nDFG estimé|inchangé\nCystatine C|inchangée\nProtéinurie|absente",
                    'note' => 'La créatinine monte sans que le rein souffre : c’est le piège.',
                ],
            ],
            'numbered' => [
                'brick' => 'numbered',
                'data' => [
                    'image' => $image,
                    'number' => '02',
                    'title' => 'Choisir le bon marqueur',
                    'body' => 'La cystatine C ne dépend pas de la masse musculaire.',
                ],
            ],
            'cta-end' => [
                'brick' => 'cta-end',
                'data' => [
                    'title' => 'Enregistre ce post pour ton prochain bilan.',
                    'subtitle' => 'On décortique une étude par semaine, sans hype.',
                    'handle' => '@lemonsieurxav',
                ],
            ],
        ];

        if ($brick !== null) {
            return isset($demo[$brick]) ? [$demo[$brick]] : [];
        }

        return array_values($demo);
    }
}
