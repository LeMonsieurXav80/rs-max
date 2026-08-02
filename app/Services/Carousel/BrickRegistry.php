<?php

namespace App\Services\Carousel;

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
     * Manifeste complet, normalisé, indexé par slug.
     *
     * @return array<string, array{slug: string, name: string, description: string, view: ?string, ratios: array, slots: array}>
     */
    public function all(): array
    {
        $bricks = [];
        foreach (config('carousel.bricks', []) as $slug => $def) {
            $bricks[$slug] = $this->normalizeBrick($slug, $def);
        }

        return $bricks;
    }

    /**
     * @return array<int, string>
     */
    public function slugs(): array
    {
        return array_keys(config('carousel.bricks', []));
    }

    /**
     * @return array{slug: string, name: string, description: string, view: ?string, ratios: array, slots: array}
     */
    public function get(string $slug): array
    {
        $def = config("carousel.bricks.{$slug}");
        if (! is_array($def)) {
            throw new \InvalidArgumentException("Brique de carrousel inconnue : {$slug}");
        }

        return $this->normalizeBrick($slug, $def);
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
        ];
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
            if (! is_string($slug) || ! is_array(config("carousel.bricks.{$slug}"))) {
                continue;
            }

            foreach ($this->get($slug)['slots'] as $key => $slot) {
                $rules["slides.{$i}.data.{$key}"] = $this->slotRule($slot);
            }
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
        return array_values(array_map(function (array $slide) {
            $brick = $this->get($slide['brick']);
            $raw = $slide['data'] ?? [];

            $data = [];
            foreach ($brick['slots'] as $key => $slot) {
                $value = $this->normalizeValue($slot, $raw[$key] ?? null);
                if ($value !== null) {
                    $data[$key] = $value;
                }
            }

            return ['brick' => $slide['brick'], 'data' => $data];
        }, $slides));
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
