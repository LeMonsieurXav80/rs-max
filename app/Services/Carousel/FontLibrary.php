<?php

namespace App\Services\Carousel;

use App\Models\Setting;
use App\Services\GoogleFontsService;

/**
 * Polices disponibles pour les carrousels : celles livrées avec l'application
 * (config/carousel.php) plus celles ajoutées depuis l'interface.
 *
 * Les ajouts sont mémorisés dans un réglage (`carousel_extra_fonts`) plutôt que
 * dans la config, qui est du code : ajouter une police ne doit pas demander de
 * déploiement. Les fichiers TTF, eux, vivent dans storage/app/fonts.
 */
class FontLibrary
{
    private const SETTING_KEY = 'carousel_extra_fonts';

    /** Poids téléchargés pour chaque nouvelle police (titres + textes courants). */
    private const WEIGHTS = ['Regular' => 400, 'Bold' => 700, 'ExtraBold' => 800];

    public function __construct(
        private GoogleFontsService $fonts,
    ) {}

    /**
     * Toutes les polices utilisables : famille => [poids => fichier].
     *
     * @return array<string, array<int, string>>
     */
    public function all(): array
    {
        return array_merge(config('carousel.fonts', []), $this->extra());
    }

    /**
     * @return array<int, string> noms de familles, pour un menu déroulant
     */
    public function families(): array
    {
        return array_keys($this->all());
    }

    /**
     * Catalogue Google Fonts proposé au choix (celui de GoogleFontsService),
     * privé des polices déjà installées. Groupé par catégorie.
     *
     * @return array<string, array<int, string>>
     */
    public function catalogue(): array
    {
        $installed = $this->families();

        $catalogue = [];
        foreach (GoogleFontsService::CURATED_FONTS as $category => $families) {
            $remaining = array_values(array_diff($families, $installed));
            if ($remaining !== []) {
                $catalogue[$category] = $remaining;
            }
        }

        return $catalogue;
    }

    /**
     * Télécharge une police Google et l'ajoute à la bibliothèque.
     * Retourne les poids réellement obtenus, ou un tableau vide en cas d'échec
     * (police inconnue, réseau indisponible…).
     *
     * @return array<int, string>
     */
    public function add(string $family): array
    {
        $family = trim($family);
        if ($family === '' || isset($this->all()[$family])) {
            return [];
        }

        $downloaded = [];
        foreach (self::WEIGHTS as $name => $weight) {
            if ($this->fonts->ensureFont($family, $name)) {
                // ensureFont écrit storage/app/fonts/{Famille sans espaces}-{Poids}.ttf
                $downloaded[$weight] = str_replace(' ', '', $family).'-'.$name.'.ttf';
            }
        }

        // Une police sans graisse normale est inexploitable : on n'enregistre rien.
        if (! isset($downloaded[400])) {
            return [];
        }

        $extra = $this->extra();
        $extra[$family] = $downloaded;
        Setting::set(self::SETTING_KEY, json_encode($extra));

        return $downloaded;
    }

    /**
     * Retire une police ajoutée (les polices livrées ne sont pas supprimables).
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
