<?php

namespace App\Services\Carousel;

use App\Models\Setting;
use App\Services\GoogleFontsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Polices des carrousels : catalogue Google Fonts complet côté choix, copies
 * locales côté rendu.
 *
 * Pourquoi ce dédoublement : l'interface peut afficher les ~1900 familles de
 * Google et en donner un aperçu (c'est une page web, elle a le droit d'appeler
 * le CDN). Le RENDU, lui, ne peut pas : la rastérisation Chromium doit être
 * déterministe et sans dépendance réseau, sinon une police qui ne se charge pas
 * produit une image en police système, sans la moindre erreur. La police choisie
 * est donc téléchargée une fois puis servie en base64 — mais l'utilisateur n'a
 * rien à faire, `ensure()` s'en charge au moment où elle est utilisée.
 */
class FontLibrary
{
    private const SETTING_KEY = 'carousel_extra_fonts';

    private const CATALOGUE_CACHE_KEY = 'carousel_google_fonts_catalogue';

    /** Catalogue Google Fonts, sans clé d'API. */
    private const CATALOGUE_URL = 'https://fonts.google.com/metadata/fonts';

    /** Graisses téléchargées quand la famille les propose. */
    private const WEIGHTS = ['Regular' => 400, 'Bold' => 700, 'ExtraBold' => 800];

    public function __construct(
        private GoogleFontsService $fonts,
    ) {}

    /**
     * Polices dont on possède les fichiers : livrées + déjà téléchargées.
     * C'est la liste qui alimente les @font-face du rendu.
     *
     * @return array<string, array<int, string>>
     */
    public function all(): array
    {
        return array_merge(config('carousel.fonts', []), $this->extra());
    }

    /**
     * @return array<int, string>
     */
    public function families(): array
    {
        return array_keys($this->all());
    }

    /**
     * Catalogue Google Fonts complet : [['family' => …, 'category' => …], …].
     * Mis en cache une semaine ; retombe sur la sélection livrée avec
     * GoogleFontsService si Google est injoignable.
     *
     * @return array<int, array{family: string, category: string}>
     */
    public function catalogue(): array
    {
        // En test, on ne sort pas sur le réseau : la suite doit rester rapide et
        // exécutable hors ligne. La sélection livrée suffit à couvrir les cas.
        if (app()->runningUnitTests()) {
            return $this->fallbackCatalogue();
        }

        return Cache::remember(self::CATALOGUE_CACHE_KEY, now()->addWeek(), function () {
            try {
                $response = Http::timeout(10)->get(self::CATALOGUE_URL);
                $list = $response->successful() ? ($response->json('familyMetadataList') ?? []) : [];

                $catalogue = [];
                foreach ($list as $item) {
                    if (! empty($item['family'])) {
                        $catalogue[] = [
                            'family' => $item['family'],
                            'category' => $item['category'] ?? '',
                        ];
                    }
                }

                if ($catalogue !== []) {
                    return $catalogue;
                }
            } catch (\Throwable $e) {
                Log::warning('Catalogue Google Fonts injoignable', ['error' => $e->getMessage()]);
            }

            return $this->fallbackCatalogue();
        });
    }

    /**
     * @return array<int, string>
     */
    public function catalogueFamilies(): array
    {
        return array_column($this->catalogue(), 'family');
    }

    /**
     * Garantit qu'on possède les fichiers d'une police, en la téléchargeant au
     * besoin. Appelé au moment où la police est utilisée : choisir une police
     * dans l'interface suffit, il n'y a pas d'étape « ajouter ».
     */
    public function ensure(?string $family): bool
    {
        $family = trim((string) $family);
        if ($family === '' || isset($this->all()[$family])) {
            return $family !== '';
        }

        $downloaded = [];
        foreach (self::WEIGHTS as $name => $weight) {
            if ($this->fonts->ensureFont($family, $name)) {
                // ensureFont écrit storage/app/fonts/{FamilleSansEspaces}-{Poids}.ttf
                $downloaded[$weight] = str_replace(' ', '', $family).'-'.$name.'.ttf';
            }
        }

        // Sans graisse normale, la police est inexploitable.
        if (! isset($downloaded[400])) {
            return false;
        }

        $extra = $this->extra();
        $extra[$family] = $downloaded;
        Setting::set(self::SETTING_KEY, json_encode($extra));

        return true;
    }

    /**
     * Télécharge les polices d'un thème (titres + textes) avant le rendu.
     *
     * @param  array<string, mixed>  $theme
     */
    public function ensureTheme(array $theme): void
    {
        foreach (['title_font', 'body_font'] as $key) {
            if (! empty($theme[$key])) {
                $this->ensure((string) $theme[$key]);
            }
        }
    }

    /**
     * Retire une police téléchargée (les polices livrées ne partent pas).
     */
    public function remove(string $family): bool
    {
        $extra = $this->extra();
        if (! isset($extra[$family])) {
            return false;
        }

        unset($extra[$family]);
        Setting::set(self::SETTING_KEY, json_encode($extra));

        return true;
    }

    /**
     * @return array<int, array{family: string, category: string}>
     */
    private function fallbackCatalogue(): array
    {
        $catalogue = [];
        foreach (GoogleFontsService::CURATED_FONTS as $category => $families) {
            foreach ($families as $family) {
                $catalogue[] = ['family' => $family, 'category' => $category];
            }
        }

        return $catalogue;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function extra(): array
    {
        try {
            $raw = Setting::get(self::SETTING_KEY);
        } catch (\Throwable $e) {
            return []; // base indisponible : on se contente des polices livrées
        }

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
