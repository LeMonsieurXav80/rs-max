<?php

namespace App\Services;

use App\Models\MediaTemplate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Geometry\Factories\RectangleFactory;
use Intervention\Image\Image;
use Intervention\Image\ImageManager;
use Intervention\Image\Typography\FontFactory;

/**
 * Rend une image composee (photo source + titre + sous-titre incrustes) a partir
 * d'un MediaTemplate. Utilise pour incruster un titre sur la PREMIERE image d'un
 * fil / carrousel au moment de la publication.
 *
 * L'image produite est ecrite dans le disque local sous media/ (servable via la
 * route signee media.show) mais est EPHEMERE : l'appelant est responsable de la
 * supprimer apres l'upload vers la plateforme. On ne cree jamais de MediaFile.
 */
class TemplateImageService
{
    private ImageManager $manager;

    public function __construct(
        private GoogleFontsService $fonts,
    ) {
        $this->manager = new ImageManager(new Driver);
    }

    /**
     * Compose l'image et l'ecrit dans media/ (disque local). Retourne le nom de
     * fichier temporaire (ex: tmp_overlay_xxx.jpg) ou null si le rendu echoue —
     * dans ce cas l'appelant publie l'image d'origine (fallback silencieux).
     */
    public function renderOverlayToTemp(
        ?MediaTemplate $template,
        string $sourceAbsolutePath,
        string $title,
        ?string $subtitle = null,
    ): ?string {
        $image = $this->renderOverlay($template, $sourceAbsolutePath, $title, $subtitle);

        if (! $image) {
            return null;
        }

        try {
            $filename = 'tmp_overlay_'.Str::random(24).'.jpg';
            Storage::disk('local')->put("media/{$filename}", $image->toJpeg(88)->toString());

            return $filename;
        } catch (\Throwable $e) {
            Log::error('TemplateImageService: echec ecriture overlay temporaire', [
                'template_id' => $template->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Compose l'image (photo source + titre + sous-titre) et retourne l'objet Image
     * en memoire, ou null si le rendu est impossible (template absent, textes vides,
     * police indisponible, source introuvable). Coeur partage par le rendu final
     * (renderOverlayToTemp) et l'apercu (encode en data-URI cote controleur).
     */
    public function renderOverlay(
        ?MediaTemplate $template,
        string $sourceAbsolutePath,
        string $title,
        ?string $subtitle = null,
    ): ?Image {
        if (! $template) {
            return null;
        }

        $title = trim($title);
        $subtitle = $subtitle !== null ? trim($subtitle) : '';

        if ($title === '' && $subtitle === '') {
            return null;
        }

        try {
            if (! is_file($sourceAbsolutePath)) {
                return null;
            }

            $titleFont = $this->resolveFont($template->title_font, $template->title_font_weight ?? 'Bold');
            if (! $titleFont) {
                Log::warning('TemplateImageService: police titre indisponible', [
                    'template_id' => $template->id,
                    'font' => $template->title_font,
                ]);

                return null;
            }

            $subtitleFont = null;
            if ($subtitle !== '' && $template->body_font) {
                $subtitleFont = $this->resolveFont($template->body_font, $template->body_font_weight ?? 'Regular');
            }
            // Repli : reutilise la police titre pour le sous-titre si pas de body_font.
            if ($subtitle !== '' && ! $subtitleFont) {
                $subtitleFont = $titleFont;
            }

            return $this->compose($template, $sourceAbsolutePath, $title, $subtitle, $titleFont, $subtitleFont);
        } catch (\Throwable $e) {
            Log::error('TemplateImageService: echec du rendu overlay', [
                'template_id' => $template->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function compose(
        MediaTemplate $template,
        string $sourcePath,
        string $title,
        string $subtitle,
        string $titleFont,
        ?string $subtitleFont,
    ): Image {
        $width = (int) ($template->width ?: 1080);
        $height = (int) ($template->height ?: 1080);
        $colors = $template->colors ?? [];

        $bgColor = $colors['background'] ?? '#0f0f1a';
        $textColor = $colors['text'] ?? '#ffffff';
        $bandColor = $colors['title_band_color'] ?? '#000000';
        $bandOpacity = (float) ($colors['title_band_opacity'] ?? 1.0);
        $overlayOpacity = (float) ($colors['overlay_opacity'] ?? 0.85);

        $titleSize = (int) ($template->title_font_size ?: 52);
        $subtitleSize = (int) ($template->body_font_size ?: max(24, (int) round($titleSize * 0.55)));

        $canvas = $this->manager->create($width, $height)->fill($bgColor);

        $source = $this->manager->read(file_get_contents($sourcePath));

        $layout = $template->layout ?: 'overlay';
        $padding = (int) round($width * 0.06);
        $maxTextWidth = $width - 2 * $padding;

        // Mesure des blocs de texte.
        $titleLines = $this->wrapText($title, $titleFont, $titleSize, $maxTextWidth);
        $titleLineHeight = (int) round($titleSize * 1.28);
        $subtitleLines = ($subtitle !== '' && $subtitleFont)
            ? $this->wrapText($subtitle, $subtitleFont, $subtitleSize, $maxTextWidth)
            : [];
        $subtitleLineHeight = (int) round($subtitleSize * 1.35);
        $gap = $subtitleLines ? (int) round($titleSize * 0.5) : 0;

        $titleBlock = count($titleLines) * $titleLineHeight;
        $subtitleBlock = count($subtitleLines) * $subtitleLineHeight;
        $totalTextHeight = $titleBlock + $gap + $subtitleBlock;

        if ($layout === 'split') {
            // Photo en haut, bandeau plein en bas avec le texte centre verticalement.
            $imageHeight = (int) round($height * 0.55);
            $source = $source->coverDown($width, $imageHeight);
            $canvas->place($source, 'top-left');

            $bandHeight = $height - $imageHeight;
            $canvas->drawRectangle(0, $imageHeight, function (RectangleFactory $r) use ($width, $bandHeight, $bandColor, $bandOpacity) {
                $r->size($width, $bandHeight);
                $r->background($this->rgba($bandColor, $bandOpacity));
            });

            $textY = $imageHeight + (int) round(($bandHeight - $totalTextHeight) / 2);
        } else {
            // overlay (defaut) : photo plein cadre + degrade sombre en bas.
            $source = $source->coverDown($width, $height);
            $canvas->place($source);

            $gradientStart = (int) round($height * 0.45);
            for ($y = $gradientStart; $y < $height; $y++) {
                $ratio = ($y - $gradientStart) / max(1, $height - $gradientStart);
                $alpha = min($overlayOpacity, $ratio * $overlayOpacity);
                $canvas->drawRectangle(0, $y, function (RectangleFactory $r) use ($width, $bandColor, $alpha) {
                    $r->size($width, 1);
                    $r->background($this->rgba($bandColor, $alpha));
                });
            }

            $textY = $height - $padding - $totalTextHeight;
        }

        // Titre.
        foreach ($titleLines as $i => $line) {
            $canvas->text($line, $padding, $textY + $i * $titleLineHeight, function (FontFactory $font) use ($titleFont, $titleSize, $textColor) {
                $font->filename($titleFont);
                $font->size($titleSize);
                $font->color($textColor);
                $font->valign('top');
            });
        }

        // Sous-titre.
        if ($subtitleLines) {
            $subY = $textY + $titleBlock + $gap;
            foreach ($subtitleLines as $i => $line) {
                $canvas->text($line, $padding, $subY + $i * $subtitleLineHeight, function (FontFactory $font) use ($subtitleFont, $subtitleSize, $textColor) {
                    $font->filename($subtitleFont);
                    $font->size($subtitleSize);
                    $font->color($textColor);
                    $font->valign('top');
                });
            }
        }

        return $canvas;
    }

    /**
     * Telecharge la police si besoin et retourne son chemin TTF, ou null.
     */
    private function resolveFont(?string $family, string $weight): ?string
    {
        if (! $family) {
            return null;
        }

        $path = $this->fonts->ensureFont($family, $weight);

        return ($path && is_file($path)) ? $path : null;
    }

    /**
     * Convertit un hex (#rrggbb) en chaine rgba() pour Intervention.
     */
    private function rgba(string $hex, float $alpha): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $a = max(0.0, min(1.0, $alpha));

        return "rgba({$r}, {$g}, {$b}, {$a})";
    }

    /**
     * Word-wrap via imagettfbbox (mesure GD).
     */
    private function wrapText(string $text, string $fontFile, int $fontSize, int $maxWidth): array
    {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $paragraph) {
            $words = preg_split('/\s+/', trim($paragraph)) ?: [];
            $current = '';
            foreach ($words as $word) {
                if ($word === '') {
                    continue;
                }
                $test = $current === '' ? $word : "{$current} {$word}";
                $bbox = imagettfbbox($fontSize, 0, $fontFile, $test);
                if ($bbox && ($bbox[2] - $bbox[0]) > $maxWidth && $current !== '') {
                    $lines[] = $current;
                    $current = $word;
                } else {
                    $current = $test;
                }
            }
            if ($current !== '') {
                $lines[] = $current;
            }
        }

        return $lines;
    }
}
