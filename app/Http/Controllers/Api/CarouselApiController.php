<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CarouselBrick;
use App\Models\MediaFile;
use App\Services\Carousel\BrickRegistry;
use App\Services\Carousel\CarouselRenderService;
use App\Services\Carousel\TemplateRenderer;
use App\Services\Media\ThumbnailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * API REST du studio carrousel : mêmes briques, même pipeline de rendu que le
 * Studio web (CarouselStudioController) — validation et nettoyage dérivés du
 * manifeste via BrickRegistry, donc une nouvelle brique est exploitable en API
 * sans écrire une seule règle.
 *
 * - GET  /api/carousel/bricks   : le contrat (briques, slots typés, ratios, positions).
 * - POST /api/carousel/preview  : HTML de la bande, SANS Chromium (aperçu instantané).
 * - POST /api/carousel/render   : rasterise (Chromium) et crée les MediaFile (source=api).
 *
 * Le rendu est SYNCHRONE : compter ~2 s par slide (un Chromium par slide), d'où le
 * plafond de 20 slides hérité du manifeste et le throttle sur la route.
 *
 * Sécurité : les slots image n'acceptent qu'un identifiant de MediaFile ou une
 * référence locale /media/… — aucune URL externe n'atteint Chromium (anti-SSRF).
 */
class CarouselApiController extends Controller
{
    public function bricks(BrickRegistry $registry): JsonResponse
    {
        return response()->json([
            'ratios' => config('carousel.ratios', []),
            'default_ratio' => config('carousel.default_ratio', '4:5'),
            'positions' => $registry->positions(),
            'bricks' => array_values(array_map(fn (array $brick) => [
                'slug' => $brick['slug'],
                'name' => $brick['name'],
                'description' => $brick['description'],
                'ratios' => $brick['ratios'],
                'slots' => array_values($brick['slots']),
            ], $registry->all())),
        ]);
    }

    /**
     * Crée une brique en base (un « template » de slide). Le gabarit n'est jamais
     * compilé : il est validé ici puis rendu par substitution échappée.
     */
    public function storeBrick(Request $request, TemplateRenderer $renderer): JsonResponse
    {
        $data = $this->validatedBrick($request, $renderer, null);
        $data['user_id'] = $request->user()->id;

        return response()->json(['brick' => CarouselBrick::create($data)], 201);
    }

    public function updateBrick(Request $request, CarouselBrick $brick, TemplateRenderer $renderer): JsonResponse
    {
        $brick->update($this->validatedBrick($request, $renderer, $brick));

        return response()->json(['brick' => $brick->fresh()]);
    }

    public function destroyBrick(CarouselBrick $brick): JsonResponse
    {
        $brick->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedBrick(Request $request, TemplateRenderer $renderer, ?CarouselBrick $existing): array
    {
        $unique = 'unique:carousel_bricks,slug'.($existing ? ','.$existing->id : '');

        $validated = $request->validate([
            'slug' => [$existing ? 'nullable' : 'required', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/', $unique],
            'name' => [$existing ? 'nullable' : 'required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'ratios' => ['nullable', 'array'],
            'ratios.*' => ['string'],
            'html' => [$existing ? 'nullable' : 'required', 'string', 'max:60000'],
            'slots' => ['nullable', 'array', 'max:12'],
            'sample_data' => ['nullable', 'array'],
        ]);

        if (isset($validated['html']) && ($problems = $renderer->violations($validated['html']))) {
            throw ValidationException::withMessages(['html' => $problems]);
        }

        return array_filter(
            $validated,
            fn ($value) => $value !== null,
        );
    }

    public function preview(Request $request, CarouselRenderService $carousel): Response
    {
        $data = $this->composition($request);

        return response(
            $carousel->buildHtml($data['ratio'], $data['slides']),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    public function render(Request $request, CarouselRenderService $carousel, ThumbnailService $thumbnails): JsonResponse
    {
        $data = $this->composition($request);

        $options = $request->validate([
            'format' => ['nullable', 'string', 'in:jpg,png'],
            'quality' => ['nullable', 'integer', 'between:40,100'],
        ]);

        $format = $options['format'] ?? 'jpg';
        $quality = (int) ($options['quality'] ?? 88);

        try {
            $filenames = $carousel->render($data['ratio'], $data['slides'], $format, $quality);
        } catch (\Throwable $e) {
            Log::error('CarouselApi: échec du rendu', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Échec du rendu du carrousel (navigateur headless indisponible ?).',
            ], 500);
        }

        return response()->json([
            'ratio' => $data['ratio'],
            'items' => array_map(
                fn (string $filename, int $i) => $this->persist($filename, $format, $i, $thumbnails),
                $filenames,
                array_keys($filenames),
            ),
        ], 201);
    }

    /**
     * Enregistre une slide rendue dans la médiathèque (source=api).
     *
     * @return array<string, mixed>
     */
    private function persist(string $filename, string $format, int $index, ThumbnailService $thumbnails): array
    {
        $path = Storage::disk('local')->path("media/{$filename}");
        $dim = @getimagesize($path) ?: [null, null];

        $media = MediaFile::create([
            'filename' => $filename,
            'original_name' => 'carrousel-'.now()->format('Ymd-His').'-'.$index.'.'.$format,
            'mime_type' => $format === 'png' ? 'image/png' : 'image/jpeg',
            'size' => is_file($path) ? filesize($path) : 0,
            'width' => $dim[0],
            'height' => $dim[1],
            'source' => 'api',
        ]);

        try {
            if ($tp = $thumbnails->generate($media)) {
                $media->update(['thumbnail_path' => $tp]);
            }
        } catch (\Throwable $e) {
            // Vignette non bloquante : le fallback à la volée s'en chargera.
        }

        return [
            'id' => $media->id,
            'filename' => $media->filename,
            'url' => $media->url,
            'thumbnail_url' => $media->thumbnail_url,
            'width' => $media->width,
            'height' => $media->height,
        ];
    }

    /**
     * Valide + normalise la composition à partir du manifeste.
     *
     * @return array{ratio: string, slides: array<int, array{brick: string, data: array}>}
     */
    private function composition(Request $request): array
    {
        $registry = app(BrickRegistry::class);

        $validated = $request->validate(
            $registry->compositionRules() + $registry->slotRules($request->input('slides', []))
        );

        // `theme` s'applique à tout le carrousel : couleurs (hex 6 chiffres) et
        // polices choisies parmi celles de la bibliothèque.
        $theme = $registry->normalizeTheme($validated['theme'] ?? null);

        $slides = array_map(
            fn (array $slide) => $slide + ['theme' => $theme],
            $registry->normalizeSlides($validated['slides'])
        );

        return ['ratio' => $validated['ratio'], 'slides' => $slides];
    }
}
