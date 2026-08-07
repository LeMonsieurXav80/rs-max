<?php

namespace App\Services\Carousel;

use App\Services\GoogleFontsService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Image;
use Intervention\Image\ImageManager;
use Spatie\Browsershot\Browsershot;

/**
 * Rend un carrousel HTML/CSS (une suite de briques de slide) en N images.
 *
 * Pipeline : briques Blade → BANDE HTML continue HORIZONTALE (band.blade.php).
 * - Aperçu : la bande est affichée telle quelle dans une iframe (aucun Chromium).
 * - Export : chaque slide est rasterisée individuellement via Chromium/Browsershot
 *   (robuste quel que soit le nombre de slides — pas de limite de taille de canvas).
 *
 * La bande horizontale posera les fondations de la continuité d'image inter-slides
 * (Phase 3). Les polices et les images de slots sont embarquées en data-URI base64 :
 * le rendu marche à l'identique en aperçu navigateur et dans Browsershot, sans
 * dépendance réseau ni souci de chemin/CSP.
 */
class CarouselRenderService
{
    private ImageManager $manager;

    public function __construct(
        private GoogleFontsService $fonts,
        private BrickRegistry $bricks,
        private FontLibrary $fontLibrary,
    ) {
        $this->manager = new ImageManager(new Driver);
    }

    /**
     * Construit le HTML complet de la bande (sans rasteriser). Utilisé par
     * l'aperçu local (commande carousel:preview) et par render().
     *
     * @param  array<int, array{brick: string, data?: array, theme?: array}>  $slides
     */
    public function buildHtml(string $ratio, array $slides, bool $embedFonts = true): string
    {
        [$w, $h] = $this->dimensions($ratio);

        return $this->buildHtmlWithDims($w, $h, $slides, $embedFonts);
    }

    /**
     * Feuille de style des @font-face (TTF en base64). Servie par une route mise
     * en cache : une page qui affiche PLUSIEURS aperçus (galerie de templates)
     * ne duplique pas ~1,3 Mo de polices par iframe.
     */
    public function fontFaceCss(): string
    {
        $css = '';
        // Polices livrées + polices ajoutées depuis l'interface.
        foreach ($this->fontLibrary->all() as $family => $weights) {
            foreach ($weights as $weight => $filename) {
                $path = storage_path('app/fonts/'.$filename);
                if (! is_file($path)) {
                    // Tente un téléchargement via GoogleFontsService (nom de poids inféré).
                    $this->fonts->ensureFont($family, $this->weightName((int) $weight));
                }
                if (! is_file($path)) {
                    continue;
                }
                $b64 = base64_encode((string) file_get_contents($path));
                $css .= "@font-face{font-family:'{$family}';font-weight:{$weight};font-style:normal;"
                    ."src:url(data:font/ttf;base64,{$b64}) format('truetype');}\n";
            }
        }

        return $css;
    }

    /**
     * HTML d'un slide unique à partir d'un gabarit BRUT (non enregistré) : sert
     * l'aperçu live de l'éditeur de templates, où la brique n'existe pas encore
     * dans le registre.
     */
    public function buildTemplateHtml(string $ratio, string $template, array $data = [], array $theme = [], bool $embedFonts = true, ?string $css = null): string
    {
        [$w, $h] = $this->dimensions($ratio);

        return View::make('carousel.band', [
            'w' => $w,
            'h' => $h,
            'fontFaces' => $this->fontFaceBlock($embedFonts),
            'slides' => [[
                'view' => 'carousel.bricks._db',
                'data' => $this->resolveImageSlots($data),
                'theme' => $this->resolveTheme($theme),
                'template' => $template,
                'css' => $css,
            ]],
        ])->render();
    }

    /**
     * Rend le carrousel en N fichiers image dans storage/app/media/.
     * Retourne la liste des noms de fichiers (servables via la route media.show).
     *
     * @param  array<int, array{brick: string, data?: array, theme?: array}>  $slides
     * @return array<int, string>
     */
    public function render(string $ratio, array $slides, string $format = 'jpg', int $quality = 88): array
    {
        [$w, $h] = $this->dimensions($ratio);

        // Continuité d'image : chaque slide d'un groupe reçoit l'image du groupe
        // et sa position dedans, AVANT d'être rasterisée isolément.
        $slides = $this->linkSpans($slides);

        $filenames = [];
        foreach ($slides as $slide) {
            // Une rasterisation par slide : toujours net (supersampling), aucune
            // limite de taille de canvas quel que soit le nombre de slides.
            $filenames[] = $this->store($this->rasterizeSlide($w, $h, $slide), $format, $quality);
        }

        return $filenames;
    }

    /**
     * Rend UNE brique en UNE image — un template employé seul, sans carrousel
     * (visuel de tweet, vignette d'article…). Retourne le nom de fichier écrit
     * dans storage/app/media/.
     *
     * `$ratio` = clé de ratio du manifeste, ou null pour épouser le ratio NATIF
     * de l'image de fond (plafonné à 1080 sur le grand côté, comme l'incrustation
     * de publication). Sans image de fond, null retombe sur le ratio par défaut.
     *
     * @param  array{brick: string, data?: array, theme?: array}  $slide
     */
    public function renderOne(array $slide, ?string $ratio = null, string $format = 'jpg', int $quality = 88): string
    {
        [$w, $h] = $ratio !== null
            ? $this->dimensions($ratio)
            : $this->sourceDimensions((string) $this->localPath($slide['data']['image'] ?? null));

        return $this->store($this->rasterizeSlide($w, $h, $slide), $format, $quality);
    }

    /**
     * Dimensions d'export d'une image unique, sans rien rasteriser : permet à
     * l'appelant d'annoncer la taille produite.
     *
     * @return array{0: int, 1: int} [w, h]
     */
    public function outputDimensions(?string $ratio, mixed $imageRef = null): array
    {
        return $ratio !== null
            ? $this->dimensions($ratio)
            : $this->sourceDimensions((string) $this->localPath($imageRef));
    }

    /**
     * Encode et écrit une image rendue sous media/. Retourne le nom de fichier.
     */
    private function store(Image $image, string $format, int $quality): string
    {
        $encoded = $format === 'png'
            ? $image->toPng()->toString()
            : $image->toJpeg($quality)->toString();

        $filename = 'carousel_'.Str::random(24).'.'.$format;
        Storage::disk('local')->put("media/{$filename}", $encoded);

        return $filename;
    }

    /**
     * Chemin disque d'une référence d'image locale (`/media/…`), ou null.
     * Les data-URI et URL externes n'ont pas de chemin : le ratio natif ne peut
     * pas s'en déduire.
     */
    private function localPath(mixed $value): ?string
    {
        if (! is_string($value) || ! str_contains($value, '/media/')) {
            return null;
        }

        $path = Storage::disk('local')->path('media/'.basename(parse_url($value, PHP_URL_PATH) ?: $value));

        return is_file($path) ? $path : null;
    }

    /**
     * Incruste titre + sous-titre sur une image et écrit un fichier ÉPHÉMÈRE sous
     * media/ (tmp_overlay_*.jpg). Drop-in de l'ancien TemplateImageService : la
     * sortie épouse le ratio NATIF de l'image source (pas de recadrage carrousel).
     * Retourne le nom de fichier, ou null (=> l'appelant publie l'image d'origine).
     */
    public function renderOverlayToTemp(string $sourcePath, string $title, ?string $subtitle = null): ?string
    {
        try {
            $title = trim($title);
            $subtitle = trim((string) $subtitle);
            if (($title === '' && $subtitle === '') || ! is_file($sourcePath)) {
                return null;
            }

            [$w, $h] = $this->sourceDimensions($sourcePath);
            $image = $this->rasterizeSlide($w, $h, $this->overlaySlide($sourcePath, $title, $subtitle));

            $filename = 'tmp_overlay_'.Str::random(24).'.jpg';
            Storage::disk('local')->put("media/{$filename}", $image->toJpeg(88)->toString());

            return $filename;
        } catch (\Throwable $e) {
            Log::error('CarouselRenderService: échec renderOverlayToTemp', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Aperçu de l'incrustation (sans rien persister) : renvoie une data-URI JPEG,
     * ou null si le rendu échoue. Utilisé par ThreadController::overlayPreview.
     */
    public function renderOverlayDataUri(string $sourcePath, string $title, ?string $subtitle = null): ?string
    {
        try {
            $title = trim($title);
            $subtitle = trim((string) $subtitle);
            if (($title === '' && $subtitle === '') || ! is_file($sourcePath)) {
                return null;
            }

            [$w, $h] = $this->sourceDimensions($sourcePath);

            return $this->rasterizeSlide($w, $h, $this->overlaySlide($sourcePath, $title, $subtitle))
                ->toJpeg(85)
                ->toDataUri();
        } catch (\Throwable $e) {
            Log::error('CarouselRenderService: échec renderOverlayDataUri', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Slide overlay standard (brique configurée, défaut photo-title-bl).
     */
    private function overlaySlide(string $sourcePath, string $title, string $subtitle): array
    {
        return [
            'brick' => config('carousel.overlay_brick', 'photo-title-bl'),
            'data' => ['image' => $sourcePath, 'title' => $title, 'subtitle' => $subtitle],
        ];
    }

    /**
     * Rasterise UNE brique en une image w×h (supersampling puis downscale).
     */
    private function rasterizeSlide(int $w, int $h, array $slide): Image
    {
        $scale = (int) config('carousel.scale', 2);
        $html = $this->buildHtmlWithDims($w, $h, [$slide]);
        $binary = $this->screenshot($html, $w, $h, $scale);

        return $this->manager->read($binary)->resize($w, $h);
    }

    /**
     * Rend band.blade.php pour des dimensions explicites.
     *
     * @param  array<int, array{brick: string, data?: array, theme?: array}>  $slides
     */
    private function buildHtmlWithDims(int $w, int $h, array $slides, bool $embedFonts = true): string
    {
        // Même préparation qu'à l'export : l'aperçu montre la continuité telle
        // qu'elle sortira, découpe comprise.
        $slides = $this->linkSpans($slides);

        $resolved = array_map(function (array $slide) {
            $brick = $this->brick($slide['brick']);

            return [
                'view' => $brick['view'],
                'data' => $this->resolveImageSlots($slide['data'] ?? []),
                'theme' => $this->resolveTheme($slide['theme'] ?? []),
                // Non nul pour les briques stockées en base (rendues par TemplateRenderer).
                'template' => $brick['template'] ?? null,
                'css' => $brick['css'] ?? null,
            ];
        }, $slides);

        return View::make('carousel.band', [
            'w' => $w,
            'h' => $h,
            'fontFaces' => $this->fontFaceBlock($embedFonts),
            'slides' => $resolved,
        ])->render();
    }

    /**
     * Continuité d'image : regroupe les slides voisines qui prolongent leur photo.
     *
     * Une slide dont `extend_image` est coché forme un groupe avec la suivante ;
     * si celle-ci l'a coché aussi, le groupe continue (panorama sur 3 slides et
     * plus) — SANS qu'elle ait à porter une photo, puisqu'elle reçoit celle du
     * groupe. Seule la tête du groupe doit en avoir une. Chaque membre reçoit
     * l'image du PREMIER de son groupe, la taille du groupe (`_span`) et son rang
     * dedans (`_span_index`) — Backdrop::frame() en déduit le cadrage.
     *
     * Le groupe s'arrête net si la slide suivante ne peut pas peindre d'image
     * (brique sans slot image) : mieux vaut une continuité qui s'interrompt qu'une
     * photo qui disparaît sans explication. Sa propre image, si elle en avait une,
     * est remplacée par celle du groupe — c'est le sens même de « prolonger ».
     *
     * @param  array<int, array{brick: string, data?: array, theme?: array}>  $slides
     * @return array<int, array{brick: string, data?: array, theme?: array}>
     */
    private function linkSpans(array $slides): array
    {
        $slides = array_values($slides);
        $count = count($slides);
        $registry = app(BrickRegistry::class);

        $extends = function (int $i) use ($slides, $count, $registry): bool {
            if ($i >= $count - 1) {
                return false; // rien après la dernière slide
            }
            if (empty($slides[$i]['data']['extend_image'])) {
                return false;
            }

            // La photo n'est exigée que sur la TÊTE du groupe (vérifiée plus bas) :
            // une slide du milieu reçoit celle du groupe, exiger la sienne
            // interromprait le panorama là où l'utilisateur l'a justement prolongé.
            return $registry->hasImageSlot((string) ($slides[$i + 1]['brick'] ?? ''));
        };

        for ($start = 0; $start < $count; $start++) {
            if (empty($slides[$start]['data']['image'])) {
                continue; // rien à prolonger depuis cette slide
            }

            $end = $start;
            while ($extends($end)) {
                $end++;
            }

            $span = $end - $start + 1;
            if ($span < 2) {
                continue;
            }

            $image = $slides[$start]['data']['image'];
            for ($i = $start; $i <= $end; $i++) {
                $slides[$i]['data']['image'] = $image;
                $slides[$i]['data']['_span'] = $span;
                $slides[$i]['data']['_span_index'] = $i - $start;
            }

            $start = $end; // le groupe consommé, on reprend après lui
        }

        return $slides;
    }

    /**
     * Lance Chromium sur le HTML et retourne le PNG brut (octets). windowSize en px
     * de design ; deviceScaleFactor multiplie la résolution réelle (netteté).
     */
    private function screenshot(string $html, int $w, int $totalH, int $scale): string
    {
        $shot = Browsershot::html($html)
            ->setScreenshotType('png')
            ->windowSize($w, $totalH)
            ->deviceScaleFactor($scale)
            ->waitUntilNetworkIdle()
            ->setDelay(200); // laisse Chromium poser les @font-face

        if ($path = config('carousel.chrome_path')) {
            $shot->setChromePath($path);
        }
        if ($node = config('carousel.node_binary')) {
            $shot->setNodeBinary($node);
        }
        if ($npm = config('carousel.npm_binary')) {
            $shot->setNpmBinary($npm);
        }
        if (config('carousel.no_sandbox')) {
            // Conteneur : pas de sandbox utilisateur, et /dev/shm trop petit => crash sans ce flag.
            $shot->noSandbox()->addChromiumArguments(['disable-dev-shm-usage']);
        }

        return $shot->screenshot();
    }

    /**
     * Dimensions d'export à partir d'un ratio nommé.
     *
     * @return array{0: int, 1: int} [w, h]
     */
    private function dimensions(string $ratio): array
    {
        $ratios = config('carousel.ratios', []);
        $r = $ratios[$ratio] ?? $ratios[config('carousel.default_ratio', '4:5')] ?? ['w' => 1080, 'h' => 1350];

        return [(int) $r['w'], (int) $r['h']];
    }

    /**
     * Dimensions de sortie épousant le ratio natif de l'image source, plafonnées à
     * 1080 sur le grand côté (netteté suffisante, rendu raisonnable).
     *
     * @return array{0: int, 1: int} [w, h]
     */
    private function sourceDimensions(string $sourcePath): array
    {
        $info = @getimagesize($sourcePath);
        $sw = $info[0] ?? 1080;
        $sh = $info[1] ?? 1350;

        $cap = 1080;
        $long = max($sw, $sh);
        $factor = $long > $cap ? $cap / $long : 1.0;

        return [max(1, (int) round($sw * $factor)), max(1, (int) round($sh * $factor))];
    }

    /**
     * Brique normalisée (fournie en code OU stockée en base) via le registre.
     *
     * @return array{name: string, view: string, slots: array, ratios: array, template: ?string}
     */
    private function brick(string $slug): array
    {
        $brick = $this->bricks->get($slug);
        if (empty($brick['view'])) {
            throw new \InvalidArgumentException("Brique de carrousel sans vue : {$slug}");
        }

        return $brick;
    }

    /**
     * Fusionne le thème par défaut avec les surcharges de la slide.
     */
    private function resolveTheme(array $overrides): array
    {
        return array_merge(config('carousel.theme', []), array_filter($overrides));
    }

    /**
     * Remplace la valeur du slot `image` par une data-URI base64 (lecture du
     * fichier local). Passe-plat pour les data-URI/URL http déjà prêtes.
     */
    private function resolveImageSlots(array $data): array
    {
        if (empty($data['image'])) {
            return $data;
        }

        $data['image'] = $this->toDataUri((string) $data['image']) ?? $data['image'];

        return $data;
    }

    /**
     * Résout une image (chemin absolu, URL /media/…, ou data-URI/http déjà prêt)
     * en data-URI base64. Retourne null si introuvable.
     */
    private function toDataUri(string $value): ?string
    {
        if (str_starts_with($value, 'data:') || str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        $path = $value;
        if (str_contains($value, '/media/')) {
            $path = Storage::disk('local')->path('media/'.basename(parse_url($value, PHP_URL_PATH) ?: $value));
        }

        if (! is_file($path)) {
            return null;
        }

        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }

    /**
     * En-tête des polices. Embarquées en base64 pour le rendu Chromium (aucune
     * dépendance réseau) ; simple <link> vers la route cachée quand l'aperçu est
     * affiché dans le navigateur de l'utilisateur.
     */
    private function fontFaceBlock(bool $embed = true): string
    {
        if (! $embed) {
            // `v` = empreinte des familles disponibles. La feuille est mise en
            // cache 24 h par le navigateur : sans ce paramètre, une police tout
            // juste téléchargée (thème d'un brouillon) s'afficherait en police
            // système jusqu'à expiration, sans erreur.
            $version = substr(md5(implode(',', array_keys($this->fontLibrary->all()))), 0, 8);

            return '<link rel="stylesheet" href="'.e(route('carousel.fonts', ['v' => $version])).'">';
        }

        return "<style>\n".$this->fontFaceCss().'</style>';
    }

    private function weightName(int $weight): string
    {
        return match ($weight) {
            100 => 'Thin', 200 => 'ExtraLight', 300 => 'Light', 400 => 'Regular',
            500 => 'Medium', 600 => 'SemiBold', 700 => 'Bold', 800 => 'ExtraBold', 900 => 'Black',
            default => 'Regular',
        };
    }
}
