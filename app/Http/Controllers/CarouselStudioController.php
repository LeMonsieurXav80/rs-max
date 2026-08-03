<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\CarouselApiController;
use App\Models\MediaFile;
use App\Services\Carousel\BrickRegistry;
use App\Services\Carousel\CarouselRenderService;
use App\Services\Carousel\FontLibrary;
use App\Services\Carousel\StudioDefaults;
use App\Services\Carousel\Typography;
use App\Services\Media\MediaDerivationService;
use App\Services\Media\ThumbnailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
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
    public function index(Request $request, BrickRegistry $registry, FontLibrary $fonts): View
    {
        return view('carousel.studio', [
            'ratios' => config('carousel.ratios', []),
            'theme' => config('carousel.theme', []),
            // Palette réglable : même source que la validation API. Une couleur
            // ajoutée au manifeste apparaît dans Apparence sans toucher au Blade.
            'themeColors' => array_values($registry->themeColors()),
            // Composition déposée par l'API (?draft=…) : le compositeur s'ouvre
            // pré-rempli au lieu de sa slide vierge. Null si absent ou expiré.
            'draft' => $this->draft($request->query('draft'), $registry),
            // Catalogue Google Fonts complet : le choix se fait directement
            // dedans, la copie locale est téléchargée à la première utilisation.
            'fontCatalogue' => $fonts->catalogue(),
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
            // Bornes des curseurs d'échelle typographique : la même source que la
            // validation API, pour que le Studio ne propose rien qui sera refusé.
            'scaleRange' => ['min' => Typography::MIN, 'max' => Typography::MAX],
        ]);
    }

    /**
     * Brouillon déposé par `POST /api/carousel/studio-link`, prêt à être injecté
     * dans le compositeur. Retourne null si le jeton est absent, inconnu ou
     * expiré : la page s'ouvre alors normalement, vierge.
     *
     * Le brouillon n'est PAS consommé à la lecture — recharger la page doit
     * continuer à fonctionner tant qu'il n'a pas expiré.
     *
     * @return array{ratio: string, theme: array, slides: array}|null
     */
    private function draft(mixed $token, BrickRegistry $registry): ?array
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        $draft = Cache::get(CarouselApiController::DRAFT_PREFIX.$token);
        if (! is_array($draft) || ! isset($draft['ratio'], $draft['slides'])) {
            return null;
        }

        $slides = [];
        foreach ($draft['slides'] as $slide) {
            // Une brique supprimée entre le dépôt et l'ouverture donnerait une
            // slide sans champs : on l'écarte plutôt que de l'afficher vide.
            if (! is_array($slide) || ! $registry->exists($slide['brick'] ?? '')) {
                continue;
            }

            $data = $slide['data'] ?? [];
            // Le compositeur affiche la vignette du média choisi : sans elle, la
            // slide serait correcte à l'aperçu mais sans miniature dans le formulaire.
            if (! empty($data['image'])) {
                $media = MediaFile::where('filename', basename((string) $data['image']))->first();
                $data['_thumb'] = $media?->thumbnail_url ?? $data['image'];
            }

            $slides[] = ['brick' => $slide['brick'], 'data' => $data];
        }

        if ($slides === []) {
            return null;
        }

        return [
            'ratio' => $draft['ratio'],
            'theme' => is_array($draft['theme'] ?? null) ? $draft['theme'] : [],
            'slides' => $slides,
        ];
    }

    public function preview(Request $request, CarouselRenderService $carousel): Response
    {
        $data = $this->validated($request);

        // embedFonts:false => les polices viennent de la route cachée plutôt que
        // d'être renvoyées en base64 à chaque frappe.
        $html = $carousel->buildHtml($data['ratio'], $data['slides'], embedFonts: false);

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function render(
        Request $request,
        CarouselRenderService $carousel,
        ThumbnailService $thumbnails,
        MediaDerivationService $derivations,
    ): JsonResponse {
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
                'is_generated' => true,
                // Le texte composé sur la slide décrit l'image : sans lui elle
                // arriverait muette dans la médiathèque (ni recherche, ni contexte IA).
                'description_fr' => app(BrickRegistry::class)->plainText($data['slides'][$i] ?? []) ?: null,
                // Dossier de dépôt choisi dans Paramètres → Studio (null = racine).
                'folder_id' => StudioDefaults::folderId(),
            ]);

            // Filiation : la slide garde le lien vers les photos qui l'ont composée,
            // pour que leur usage remonte à la publication (voir MediaPublicationTracker).
            $sources = $derivations->linkSlide($media, $data['slides'][$i] ?? []);

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
                'is_generated' => true,
                'sources' => array_map(fn (MediaFile $s) => ['id' => $s->id, 'filename' => $s->filename], $sources),
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

        // L'apparence est choisie au niveau du carrousel puis portée par chaque
        // slide (le moteur de rendu résout le thème slide par slide).
        $theme = $registry->normalizeTheme($validated['theme'] ?? null);

        // La copie locale de la police est téléchargée ICI, à la première utilisation :
        // choisir une police dans l'interface suffit, il n'y a pas d'étape d'ajout.
        app(FontLibrary::class)->ensureTheme($theme);

        $slides = array_map(
            fn (array $slide) => $slide + ['theme' => $theme],
            $registry->normalizeSlides($validated['slides'])
        );

        return ['ratio' => $validated['ratio'], 'slides' => $slides];
    }
}
