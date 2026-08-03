<?php

namespace App\Services\Carousel;

use App\Models\CarouselBrick;
use App\Models\MediaFile;
use Illuminate\Support\Facades\Storage;

/**
 * Registre des briques de carrousel : lit le manifeste `config/carousel.php`,
 * NORMALISE les slots (typés) et en dérive validation + nettoyage des données.
 *
 * C'est le contrat unique partagé par le Studio web et l'API REST : ajouter une
 * brique (ou un slot) au manifeste suffit — aucune règle de validation ni aucun
 * champ de formulaire à écrire à la main.
 *
 * Format d'un slot dans le manifeste :
 *   'title' => 'Titre'                              // raccourci = champ texte
 *   'title' => ['label' => 'Titre', 'type' => ...]  // forme complète
 *
 * Types : text, textarea, image, position, range, select.
 * Le type est inféré si absent (`image` => image, `body` => textarea, sinon text),
 * ce qui garde les briques déclarées à l'ancienne parfaitement valides.
 */
class BrickRegistry
{
    /**
     * Dégradé de repli quand la médiathèque ne fournit aucune image exploitable
     * pour illustrer un aperçu (SVG, donc quelques centaines d'octets).
     */
    private const PLACEHOLDER_IMAGE = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDgwIiBoZWlnaHQ9IjEzNTAiPjxkZWZzPjxsaW5lYXJHcmFkaWVudCBpZD0iZyIgeDE9IjAiIHkxPSIwIiB4Mj0iMSIgeTI9IjEiPjxzdG9wIG9mZnNldD0iMCIgc3RvcC1jb2xvcj0iIzMzNDE1NSIvPjxzdG9wIG9mZnNldD0iMSIgc3RvcC1jb2xvcj0iIzBmMTcyYSIvPjwvbGluZWFyR3JhZGllbnQ+PC9kZWZzPjxyZWN0IHdpZHRoPSIxMDgwIiBoZWlnaHQ9IjEzNTAiIGZpbGw9InVybCgjZykiLz48L3N2Zz4=';

    /** @var array<string, array>|null Mémo par instance (le registre est appelé plusieurs fois par rendu). */
    private ?array $cache = null;

    /** Image d'illustration retenue pour toute la requête (aperçus cohérents). */
    private ?string $sampleImage = null;

    /**
     * Manifeste complet, normalisé, indexé par slug : briques FOURNIES
     * (config/carousel.php) + briques stockées en BASE. En cas de collision de
     * slug, la base l'emporte — c'est ce qui permet de surcharger une brique
     * fournie sans toucher au code.
     *
     * @return array<string, array{slug: string, name: string, description: string, view: ?string, ratios: array, slots: array, template: ?string, is_builtin: bool}>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $bricks = [];
        foreach (config('carousel.bricks', []) as $slug => $def) {
            $bricks[$slug] = $this->normalizeBrick((string) $slug, $def);
        }

        foreach ($this->storedBricks() as $brick) {
            $bricks[$brick->slug] = $this->normalizeStored($brick);
        }

        return $this->cache = $bricks;
    }

    /**
     * @return array<int, string>
     */
    public function slugs(): array
    {
        return array_keys($this->all());
    }

    /**
     * @return array{slug: string, name: string, description: string, view: ?string, ratios: array, slots: array, template: ?string, is_builtin: bool}
     */
    public function get(string $slug): array
    {
        $brick = $this->all()[$slug] ?? null;
        if ($brick === null) {
            throw new \InvalidArgumentException("Brique de carrousel inconnue : {$slug}");
        }

        return $brick;
    }

    public function exists(string $slug): bool
    {
        return isset($this->all()[$slug]);
    }

    /**
     * Briques en base. Tolère l'absence de table (avant `migrate`) : le système
     * doit continuer à tourner sur les seules briques fournies.
     *
     * @return \Illuminate\Support\Collection<int, CarouselBrick>
     */
    private function storedBricks(): \Illuminate\Support\Collection
    {
        try {
            return CarouselBrick::query()->orderBy('sort_order')->orderBy('name')->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    /**
     * Une brique en base passe par la vue-pont `_db` et porte son gabarit.
     */
    private function normalizeStored(CarouselBrick $brick): array
    {
        $slots = [];
        foreach ($brick->slots ?? [] as $key => $slot) {
            $slots[$key] = $this->normalizeSlot((string) $key, $slot);
        }

        return [
            'slug' => $brick->slug,
            'name' => $brick->name,
            'description' => (string) $brick->description,
            'view' => 'carousel.bricks._db',
            'ratios' => $brick->ratios ?: ['*'],
            'slots' => $slots,
            'template' => $brick->html,
            'css' => $brick->css,
            'sample' => $brick->sample_data ?: [],
            'is_builtin' => false,
        ];
    }

    /**
     * Texte lisible d'une slide : la valeur de ses slots textuels, dans l'ordre
     * du manifeste. Sert à décrire l'image générée dans la médiathèque plutôt que
     * de la laisser muette — `description_fr` sert de contexte à l'IA de rédaction.
     *
     * Générique par construction : aucune liste de slots en dur, une nouvelle
     * brique est décrite sans rien ajouter ici.
     *
     * @param  array{brick?: string, data?: array<string, mixed>}  $slide
     */
    public function plainText(array $slide, int $maxLength = 500): string
    {
        $slug = (string) ($slide['brick'] ?? '');
        if ($slug === '' || ! $this->exists($slug)) {
            return '';
        }

        $data = is_array($slide['data'] ?? null) ? $slide['data'] : [];
        $parts = [];

        foreach ($this->get($slug)['slots'] as $key => $slot) {
            if (! in_array($slot['type'], ['text', 'textarea'], true)) {
                continue;
            }

            $value = $data[$key] ?? null;
            if (! is_scalar($value)) {
                continue;
            }

            // Les briques en liste (« 42 % | des lecteurs ») deviennent lisibles ;
            // les retours à la ligne s'aplatissent pour tenir sur une description.
            $value = str_replace('|', ' :', (string) $value);
            $value = trim(preg_replace('/\s+/u', ' ', $value));

            if ($value !== '') {
                $parts[] = $value;
            }
        }

        $text = implode(' — ', $parts);

        return mb_strlen($text) > $maxLength ? mb_substr($text, 0, $maxLength - 1).'…' : $text;
    }

    /**
     * @return array<int, string>
     */
    public function ratioKeys(): array
    {
        return array_keys(config('carousel.ratios', []));
    }

    /**
     * Ancres de position disponibles (valeur => libellé).
     *
     * @return array<string, string>
     */
    public function positions(): array
    {
        return config('carousel.positions', []);
    }

    /**
     * Jeu de données d'exemple pour prévisualiser une brique dans la galerie de
     * templates. Priorité au `sample` déclaré (config ou colonne sample_data) ;
     * à défaut on en déduit un à partir des slots, pour qu'une brique tout juste
     * créée ait quand même un aperçu parlant.
     *
     * @return array<string, mixed>
     */
    public function sampleData(string $slug): array
    {
        $brick = $this->get($slug);

        return $this->sampleFor($brick['slots'], $brick['sample'] ?? []);
    }

    /**
     * Même chose pour un jeu de slots quelconque — utilisé par l'éditeur, où le
     * template n'est pas encore enregistré.
     *
     * @param  array<string, array>  $slots
     * @param  array<string, mixed>  $declared
     * @return array<string, mixed>
     */
    public function sampleFor(array $slots, array $declared = []): array
    {
        $sample = [];

        foreach ($slots as $key => $slot) {
            $slot = $this->normalizeSlot((string) $key, $slot);

            if (array_key_exists($key, $declared)) {
                $sample[$key] = $declared[$key];

                continue;
            }

            $value = match ($slot['type']) {
                'image' => $this->sampleImage(),
                'position', 'select', 'range' => $slot['default'],
                'textarea' => $this->sampleList($key),
                default => $this->sampleText($key),
            };

            if ($value !== null) {
                $sample[$key] = $value;
            }
        }

        return $sample;
    }

    /**
     * Gabarit HTML équivalent d'une brique fournie, s'il existe.
     */
    private function builtinTemplate(string $slug): ?string
    {
        $path = resource_path("carousel-templates/{$slug}.html");

        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    /**
     * Image d'illustration pour les aperçus : une vignette de la médiathèque,
     * choisie une fois par requête (sinon chaque aperçu tirerait une image
     * différente). Retourne une data-URI, ou un dégradé de repli s'il n'y a
     * aucun média exploitable.
     */
    private function sampleImage(): string
    {
        if ($this->sampleImage !== null) {
            return $this->sampleImage;
        }

        try {
            $candidates = MediaFile::query()
                ->with('folder')
                ->where('mime_type', 'like', 'image/%')
                ->whereNotNull('thumbnail_path')
                ->inRandomOrder()
                ->limit(10)
                ->get();

            foreach ($candidates as $media) {
                // Jamais d'image issue d'un dossier privé dans une vignette de
                // galerie : ces aperçus s'affichent sans qu'on les demande.
                if ($media->folder?->isEffectivelyPrivate()) {
                    continue;
                }

                $path = Storage::disk('local')->path($media->thumbnail_path);
                if (! is_file($path)) {
                    continue;
                }

                $mime = str_ends_with(strtolower($path), '.png') ? 'image/png' : 'image/jpeg';

                return $this->sampleImage = 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
            }
        } catch (\Throwable $e) {
            // Médiathèque indisponible : on retombe sur le dégradé.
        }

        return $this->sampleImage = self::PLACEHOLDER_IMAGE;
    }

    private function sampleText(string $key): string
    {
        return match ($key) {
            'title' => 'Un titre d’exemple, assez long pour juger',
            'subtitle' => 'Le sous-titre qui précise l’idée.',
            'author' => 'Source de la citation, 2026',
            'handle' => '@moncompte',
            'number' => '02',
            'note' => 'Une note discrète en bas de slide.',
            default => 'Texte d’exemple',
        };
    }

    private function sampleList(string $key): string
    {
        return match ($key) {
            'items' => "26|essais contrôlés\n1 036|participants",
            'rows' => "Première ligne|valeur\nDeuxième ligne|autre valeur",
            'quote' => 'Une citation d’exemple, pour juger la mise en page à sa vraie longueur.',
            default => 'Un paragraphe d’exemple, assez fourni pour voir comment le texte se comporte sur plusieurs lignes.',
        };
    }

    /**
     * Règles de validation de l'enveloppe d'une composition (ratio + slides).
     *
     * @return array<string, mixed>
     */
    public function compositionRules(): array
    {
        return [
            'ratio' => ['required', 'string', 'in:'.implode(',', $this->ratioKeys())],
            'slides' => ['required', 'array', 'min:1', 'max:20'],
            'slides.*.brick' => ['required', 'string', 'in:'.implode(',', $this->slugs())],
            'slides.*.data' => ['nullable', 'array'],
        ] + $this->themeRules();
    }

    /**
     * Règles d'apparence, partagées par le carrousel (où le thème s'applique à
     * toutes les slides) et par l'image unique.
     *
     * Hex à 6 chiffres OBLIGATOIRE : le dégradé de lisibilité concatène un canal
     * alpha à la couleur (voir Anchor::scrim), donc #000 ou rgb(...) casseraient
     * le rendu sans erreur visible.
     *
     * @return array<string, mixed>
     */
    public function themeRules(): array
    {
        // Toute police du catalogue Google est acceptable : la copie locale est
        // téléchargée à l'usage (FontLibrary::ensureTheme), pas avant.
        $fonts = app(FontLibrary::class)->catalogueFamilies();

        return [
            'theme' => ['nullable', 'array'],
            'theme.background' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme.text' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme.accent' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme.overlay' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme.title_font' => ['nullable', 'string', \Illuminate\Validation\Rule::in($fonts)],
            'theme.body_font' => ['nullable', 'string', \Illuminate\Validation\Rule::in($fonts)],
            // Échelle typographique : 1 = tailles natives des briques. Bornée des
            // deux côtés — en dessous c'est illisible, au-dessus ça déborde du cadre.
            'theme.title_scale' => ['nullable', 'numeric', 'between:'.Typography::MIN.','.Typography::MAX],
            'theme.body_scale' => ['nullable', 'numeric', 'between:'.Typography::MIN.','.Typography::MAX],
        ];
    }

    /**
     * Ne garde que les clés de thème connues et renseignées.
     *
     * Couleurs et polices sont des chaînes ; les échelles typographiques sont des
     * nombres, ramenés dans leurs bornes ici pour qu'un brouillon mis en cache ne
     * puisse pas ressortir hors limites si les bornes bougent plus tard.
     *
     * @return array<string, string|float>
     */
    public function normalizeTheme(?array $theme): array
    {
        $strings = ['background', 'text', 'accent', 'overlay', 'title_font', 'body_font'];
        $scales = ['title_scale', 'body_scale'];

        $normalized = array_filter(
            array_intersect_key($theme ?? [], array_flip($strings)),
            fn ($value) => is_string($value) && $value !== '',
        );

        foreach ($scales as $key) {
            if (isset($theme[$key]) && is_numeric($theme[$key])) {
                $normalized[$key] = Typography::clamp($theme[$key]);
            }
        }

        return $normalized;
    }

    /**
     * Règles dérivées des slots, slide par slide (la brique de chaque slide dicte
     * ses propres champs). Les slides dont la brique est inconnue sont ignorées :
     * `slides.*.brick` les rejette déjà.
     *
     * @param  array<int, mixed>  $slides  payload brut
     * @return array<string, mixed>
     */
    public function slotRules(array $slides): array
    {
        $rules = [];

        foreach ($slides as $i => $slide) {
            $slug = is_array($slide) ? ($slide['brick'] ?? null) : null;
            if (! is_string($slug) || ! $this->exists($slug)) {
                continue;
            }

            $rules += $this->slotRulesFor($slug, "slides.{$i}.data");
        }

        return $rules;
    }

    /**
     * Règles des slots d'UNE brique, sous un préfixe de champ donné. Sert le
     * carrousel (`slides.0.data`) comme l'image unique (`data`).
     *
     * @return array<string, mixed>
     */
    public function slotRulesFor(string $slug, string $prefix = 'data'): array
    {
        if (! $this->exists($slug)) {
            return [];
        }

        $rules = [];
        foreach ($this->get($slug)['slots'] as $key => $slot) {
            $rules["{$prefix}.{$key}"] = $this->slotRule($slot);
        }

        return $rules;
    }

    /**
     * Nettoie les slides validées : ne garde que les slots déclarés par la brique,
     * applique les valeurs par défaut, borne les plages et RÉSOUT les images en
     * référence locale `/media/…` (les URL externes sont écartées — anti-SSRF au
     * rendu Chromium).
     *
     * @param  array<int, array{brick: string, data?: array}>  $slides
     * @return array<int, array{brick: string, data: array}>
     */
    public function normalizeSlides(array $slides): array
    {
        return array_values(array_map(
            fn (array $slide) => $this->normalizeSlide($slide['brick'], $slide['data'] ?? []),
            $slides,
        ));
    }

    /**
     * Même nettoyage pour une slide isolée (image unique).
     *
     * @param  array<string, mixed>  $raw
     * @return array{brick: string, data: array<string, mixed>}
     */
    public function normalizeSlide(string $slug, array $raw = []): array
    {
        $data = [];
        foreach ($this->get($slug)['slots'] as $key => $slot) {
            $value = $this->normalizeValue($slot, $raw[$key] ?? null);
            if ($value !== null) {
                $data[$key] = $value;
            }
        }

        return ['brick' => $slug, 'data' => $data];
    }

    /**
     * @param  array<string, mixed>  $def
     */
    private function normalizeBrick(string $slug, array $def): array
    {
        $slots = [];
        foreach ($def['slots'] ?? [] as $key => $slot) {
            $slots[$key] = $this->normalizeSlot((string) $key, $slot);
        }

        return [
            'slug' => $slug,
            'name' => $def['name'] ?? $slug,
            'description' => $def['description'] ?? '',
            'view' => $def['view'] ?? null,
            'ratios' => $def['ratios'] ?? ['*'],
            'slots' => $slots,
            // Gabarit HTML équivalent (resources/carousel-templates/{slug}.html) :
            // sert de point de départ réel quand on duplique une brique fournie.
            // Le rendu des briques fournies reste assuré par leur vue Blade.
            'template' => $def['template'] ?? $this->builtinTemplate($slug),
            'css' => $def['css'] ?? null,
            'sample' => $def['sample'] ?? [],
            'is_builtin' => true,
        ];
    }

    /**
     * @param  array<string, mixed>|string  $slot
     */
    private function normalizeSlot(string $key, array|string $slot): array
    {
        if (is_string($slot)) {
            $slot = ['label' => $slot];
        }

        $type = $slot['type'] ?? match ($key) {
            'image' => 'image',
            'body' => 'textarea',
            default => 'text',
        };

        $normalized = [
            'key' => $key,
            'label' => $slot['label'] ?? $key,
            'type' => $type,
            'default' => $slot['default'] ?? null,
        ];

        return match ($type) {
            'text' => $normalized + ['max_length' => (int) ($slot['max_length'] ?? 300)],
            'textarea' => $normalized + ['max_length' => (int) ($slot['max_length'] ?? 600)],
            'position' => $normalized + [
                'options' => $slot['options'] ?? $this->positions(),
                'default' => $slot['default'] ?? array_key_first($this->positions()),
            ],
            'select' => $normalized + ['options' => $slot['options'] ?? []],
            'range' => $normalized + [
                'min' => (float) ($slot['min'] ?? 0),
                'max' => (float) ($slot['max'] ?? 100),
                'step' => (float) ($slot['step'] ?? 1),
                'default' => $slot['default'] ?? 0,
                'unit' => $slot['unit'] ?? '',
            ],
            default => $normalized,
        };
    }

    /**
     * @return array<int, mixed>
     */
    private function slotRule(array $slot): array
    {
        return match ($slot['type']) {
            'text', 'textarea' => ['nullable', 'string', 'max:'.$slot['max_length']],
            // Chaîne `/media/…` ou identifiant de MediaFile : la résolution (et le
            // rejet des références externes) se fait dans normalizeValue().
            'image' => ['nullable'],
            // Pas de règle `string` : une option peut avoir une clé numérique
            // (ex. nombre de colonnes) et arriver en entier dans du JSON.
            'position', 'select' => ['nullable', 'in:'.implode(',', array_keys($slot['options']))],
            'range' => ['nullable', 'numeric', 'between:'.$slot['min'].','.$slot['max']],
            default => ['nullable'],
        };
    }

    private function normalizeValue(array $slot, mixed $value): mixed
    {
        return match ($slot['type']) {
            'image' => $this->resolveLocalMedia($value),

            'position', 'select' => is_scalar($value) && isset($slot['options'][(string) $value])
                ? $value
                : $slot['default'],

            'range' => $this->clamp(
                is_numeric($value) ? (float) $value : (float) $slot['default'],
                (float) $slot['min'],
                (float) $slot['max'],
            ),

            default => $this->normalizeText($value, $slot),
        };
    }

    private function normalizeText(mixed $value, array $slot): ?string
    {
        if (! is_scalar($value)) {
            return $slot['default'] !== null ? (string) $slot['default'] : null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    /**
     * Résout une référence d'image en `/media/{fichier}` local, ou null.
     * Accepte un identifiant de MediaFile (pratique en API) ou un chemin `/media/…`.
     * Toute autre valeur (URL externe, chemin arbitraire) est écartée.
     */
    private function resolveLocalMedia(mixed $value): ?string
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $media = MediaFile::find((int) $value);

            return $media && $this->mediaFileExists($media->filename)
                ? '/media/'.$media->filename
                : null;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        $path = parse_url($value, PHP_URL_PATH) ?: $value;
        if (! str_contains($path, '/media/')) {
            return null;
        }

        $filename = basename($path);

        return $this->mediaFileExists($filename) ? '/media/'.$filename : null;
    }

    private function mediaFileExists(?string $filename): bool
    {
        return is_string($filename)
            && $filename !== ''
            && is_file(Storage::disk('local')->path('media/'.basename($filename)));
    }
}
