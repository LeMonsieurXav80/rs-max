<?php

namespace App\Http\Controllers;

use App\Models\MediaFile;
use App\Services\Carousel\BrickRegistry;
use App\Services\Carousel\CarouselRenderService;
use App\Services\Media\ThumbnailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Studio de composition de carrousels à partir des briques HTML/CSS.
 *
 * - index()   : la page compositeur (ratio + slides + aperçu live).
 * - preview() : renvoie le HTML de la bande pour l'iframe d'aperçu — AUCUN Chromium
 *               (c'est le navigateur de l'utilisateur qui rend). Instantané.
 * - render()  : rasterise via Browsershot et persiste chaque slide en MediaFile
 *               (source=studio) pour qu'elle apparaisse dans la médiathèque.
 *
 * Sécurité : les slots image sont restreints aux fichiers locaux /media/ (pas d'URL
 * externe) pour éviter tout SSRF au moment du rendu Chromium.
 */
class CarouselStudioController extends Controller
{
    public function index(BrickRegistry $registry): View
    {
        return view('carousel.studio', [
            'ratios' => config('carousel.ratios', []),
            // Slots normalisés (typés) : le compositeur construit ses champs à partir
            // de ce contrat, sans rien coder en dur par brique.
            'bricks' => collect($registry->all())
                ->map(fn (array $b) => [
                    'slug' => $b['slug'],
                    'name' => $b['name'],
                    'description' => $b['description'],
                    'slots' => array_values($b['slots']),
                ])->values(),
            'defaultRatio' => config('carousel.default_ratio', '4:5'),
        ]);
    }

    public function preview(Request $request, CarouselRenderService $carousel): Response
    {
        $data = $this->validated($request);

        $html = $carousel->buildHtml($data['ratio'], $data['slides']);

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function render(Request $request, CarouselRenderService $carousel, ThumbnailService $thumbnails): JsonResponse
    {
        $data = $this->validated($request);

        $filenames = $carousel->render($data['ratio'], $data['slides'], 'jpg');

        $items = [];
        foreach ($filenames as $i => $filename) {
            $path = Storage::disk('local')->path("media/{$filename}");
            $dim = @getimagesize($path) ?: [null, null];

            $media = MediaFile::create([
                'filename' => $filename,
                'original_name' => 'carrousel-'.now()->format('Ymd-His')."-{$i}.jpg",
                'mime_type' => 'image/jpeg',
                'size' => is_file($path) ? filesize($path) : 0,
                'width' => $dim[0],
                'height' => $dim[1],
                'source' => 'studio',
            ]);

            try {
                if ($tp = $thumbnails->generate($media)) {
                    $media->update(['thumbnail_path' => $tp]);
                }
            } catch (\Throwable $e) {
                // Thumbnail non bloquant : le fallback à la volée s'en chargera.
            }

            $items[] = [
                'id' => $media->id,
                'filename' => $media->filename,
                'url' => $media->url,
                'thumbnail_url' => $media->thumbnail_url,
            ];
        }

        return response()->json(['items' => $items]);
    }

    /**
     * Valide et normalise la requête en {ratio, slides:[{brick, data}]}.
     * Règles et nettoyage sont DÉRIVÉS du manifeste (BrickRegistry) : les slots
     * inconnus tombent, les images non locales sont écartées (anti-SSRF).
     *
     * @return array{ratio: string, slides: array<int, array{brick: string, data: array}>}
     */
    private function validated(Request $request): array
    {
        $registry = app(BrickRegistry::class);

        $validated = $request->validate(
            $registry->compositionRules() + $registry->slotRules($request->input('slides', []))
        );

        return [
            'ratio' => $validated['ratio'],
            'slides' => $registry->normalizeSlides($validated['slides']),
        ];
    }
}
