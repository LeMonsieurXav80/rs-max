<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GoogleFontsService
{
    /**
     * Popular Google Fonts curated for social media design.
     * Organized by style category for easy selection.
     */
    public const CURATED_FONTS = [
        'Sans-Serif' => [
            'Montserrat',
            'Poppins',
            'Raleway',
            'Open Sans',
            'Lato',
            'Roboto',
            'Oswald',
            'Nunito',
            'Inter',
            'Bebas Neue',
        ],
        'Serif' => [
            'Playfair Display',
            'Merriweather',
            'Lora',
            'Cormorant Garamond',
            'EB Garamond',
            'Libre Baskerville',
            'Crimson Text',
            'DM Serif Display',
        ],
        'Manuscrite / Brush' => [
            'Pacifico',
            'Dancing Script',
            'Caveat',
            'Satisfy',
            'Kalam',
            'Permanent Marker',
            'Indie Flower',
            'Sacramento',
            'Great Vibes',
            'Amatic SC',
        ],
        'Display / Impact' => [
            'Anton',
            'Righteous',
            'Archivo Black',
            'Bungee',
            'Abril Fatface',
            'Alfa Slab One',
            'Passion One',
            'Teko',
            'Staatliches',
            'Black Ops One',
        ],
    ];

    public const WEIGHTS = [
        'Thin' => 100,
        'ExtraLight' => 200,
        'Light' => 300,
        'Regular' => 400,
        'Medium' => 500,
        'SemiBold' => 600,
        'Bold' => 700,
        'ExtraBold' => 800,
        'Black' => 900,
    ];

    /**
     * Check if a font file exists locally.
     */
    public function fontExists(string $family, string $weight = 'Regular'): bool
    {
        $path = $this->getFontPath($family, $weight);

        return file_exists($path);
    }

    /**
     * Get the local path for a font file.
     */
    public function getFontPath(string $family, string $weight = 'Regular'): string
    {
        $filename = str_replace(' ', '', $family) . '-' . $weight . '.ttf';

        return storage_path('app/fonts/' . $filename);
    }

    /**
     * Download a Google Font TTF file if not already present.
     */
    public function ensureFont(string $family, string $weight = 'Regular'): ?string
    {
        $path = $this->getFontPath($family, $weight);

        if (file_exists($path)) {
            return $path;
        }

        return $this->downloadFont($family, $weight);
    }

    /**
     * Download a font from Google Fonts API.
     */
    public function downloadFont(string $family, string $weight = 'Regular'): ?string
    {
        $weightValue = self::WEIGHTS[$weight] ?? 400;
        $familyEncoded = urlencode($family);

        // Use Google Fonts CSS2 API to get the TTF URL
        $cssUrl = "https://fonts.googleapis.com/css2?family={$familyEncoded}:wght@{$weightValue}&display=swap";

        try {
            // Google Fonts returns different formats based on User-Agent
            // Using a simple UA to get TTF format
            $cssResponse = Http::withHeaders([
                'User-Agent' => 'Mozilla/4.0', // Trick to get TTF instead of WOFF2
            ])->timeout(10)->get($cssUrl);

            if (! $cssResponse->successful()) {
                Log::warning('Google Fonts CSS fetch failed', [
                    'family' => $family,
                    'weight' => $weight,
                    'status' => $cssResponse->status(),
                ]);

                return null;
            }

            $css = $cssResponse->body();

            // Extract font URL from CSS
            if (! preg_match('/url\(([^)]+\.ttf)\)/', $css, $matches)) {
                // Try WOFF2 as fallback — won't work with GD but log it
                Log::warning('Google Fonts: no TTF URL found in CSS', [
                    'family' => $family,
                    'weight' => $weight,
                ]);

                return null;
            }

            $fontUrl = $matches[1];

            // Download the actual font file
            $fontResponse = Http::timeout(15)->get($fontUrl);
            if (! $fontResponse->successful()) {
                return null;
            }

            // Ensure fonts directory exists
            $fontsDir = storage_path('app/fonts');
            if (! is_dir($fontsDir)) {
                mkdir($fontsDir, 0755, true);
            }

            $path = $this->getFontPath($family, $weight);
            file_put_contents($path, $fontResponse->body());

            Log::info('Google Font downloaded', [
                'family' => $family,
                'weight' => $weight,
                'path' => $path,
                'size' => strlen($fontResponse->body()),
            ]);

            return $path;
        } catch (\Exception $e) {
            Log::error('Google Font download failed', [
                'family' => $family,
                'weight' => $weight,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get list of locally available fonts (already downloaded).
     */
    public function getLocalFonts(): array
    {
        $fontsDir = storage_path('app/fonts');
        if (! is_dir($fontsDir)) {
            return [];
        }

        $fonts = [];
        foreach (glob($fontsDir . '/*.ttf') as $file) {
            $basename = basename($file, '.ttf');
            // Parse "FontFamily-Weight" format
            if (preg_match('/^(.+)-(\w+)$/', $basename, $matches)) {
                $family = preg_replace('/([a-z])([A-Z])/', '$1 $2', $matches[1]);
                $weight = $matches[2];
                $fonts[$family][] = $weight;
            }
        }

        return $fonts;
    }
}
