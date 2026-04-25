<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Recherche d'images de stock via Pexels, Pixabay et Unsplash.
 * Les images ne sont JAMAIS stockées localement — on retourne juste les URLs
 * et l'attribution. Le caller décide quoi faire (afficher, attacher au post, etc.).
 */
class StockPhotoService
{
    private const PEXELS_BASE = 'https://api.pexels.com/v1';

    private const PIXABAY_BASE = 'https://pixabay.com/api/';

    private const UNSPLASH_BASE = 'https://api.unsplash.com';

    /**
     * Recherche agrégée sur tous les providers configurés.
     *
     * @return array<int, array{
     *     id: string,
     *     source: string,
     *     url_full: string,
     *     url_thumb: string,
     *     width: int,
     *     height: int,
     *     photographer: string,
     *     attribution: string,
     *     source_url: string
     * }>
     */
    public function searchAll(string $query, int $perProvider = 8): array
    {
        $results = [];
        foreach (['pexels', 'pixabay', 'unsplash'] as $provider) {
            $method = "search{$provider}";
            $providerResults = $this->{$method}($query, $perProvider);
            $results = array_merge($results, $providerResults);
        }

        // Mélange pour ne pas favoriser un provider
        shuffle($results);

        return $results;
    }

    public function searchPexels(string $query, int $perPage = 10): array
    {
        $key = Setting::getEncrypted('pexels_api_key');
        if (! $key) {
            return [];
        }

        try {
            $resp = Http::withHeaders(['Authorization' => $key])
                ->timeout(10)
                ->get(self::PEXELS_BASE.'/search', [
                    'query' => $query,
                    'per_page' => max(1, min($perPage, 80)),
                    'locale' => 'fr-FR',
                ]);

            if (! $resp->successful()) {
                Log::warning('Pexels API error', ['status' => $resp->status(), 'body' => substr($resp->body(), 0, 300)]);

                return [];
            }

            $items = [];
            foreach ($resp->json('photos', []) as $p) {
                $items[] = [
                    'id' => 'pexels:'.($p['id'] ?? ''),
                    'source' => 'pexels',
                    'url_full' => $p['src']['large2x'] ?? $p['src']['large'] ?? $p['src']['original'] ?? '',
                    'url_thumb' => $p['src']['medium'] ?? $p['src']['small'] ?? '',
                    'width' => $p['width'] ?? 0,
                    'height' => $p['height'] ?? 0,
                    'photographer' => $p['photographer'] ?? 'unknown',
                    'attribution' => 'Photo by '.($p['photographer'] ?? 'unknown').' on Pexels',
                    'source_url' => $p['url'] ?? '',
                ];
            }

            return $items;
        } catch (\Throwable $e) {
            Log::warning('Pexels exception', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function searchPixabay(string $query, int $perPage = 10): array
    {
        $key = Setting::getEncrypted('pixabay_api_key');
        if (! $key) {
            return [];
        }

        try {
            $resp = Http::timeout(10)->get(self::PIXABAY_BASE, [
                'key' => $key,
                'q' => $query,
                'per_page' => max(3, min($perPage, 200)),
                'lang' => 'fr',
                'safesearch' => 'true',
                'image_type' => 'photo',
            ]);

            if (! $resp->successful()) {
                Log::warning('Pixabay API error', ['status' => $resp->status(), 'body' => substr($resp->body(), 0, 300)]);

                return [];
            }

            $items = [];
            foreach ($resp->json('hits', []) as $p) {
                $items[] = [
                    'id' => 'pixabay:'.($p['id'] ?? ''),
                    'source' => 'pixabay',
                    'url_full' => $p['largeImageURL'] ?? $p['webformatURL'] ?? '',
                    'url_thumb' => $p['previewURL'] ?? $p['webformatURL'] ?? '',
                    'width' => $p['imageWidth'] ?? 0,
                    'height' => $p['imageHeight'] ?? 0,
                    'photographer' => $p['user'] ?? 'unknown',
                    'attribution' => 'Image by '.($p['user'] ?? 'unknown').' on Pixabay',
                    'source_url' => $p['pageURL'] ?? '',
                ];
            }

            return $items;
        } catch (\Throwable $e) {
            Log::warning('Pixabay exception', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function searchUnsplash(string $query, int $perPage = 10): array
    {
        $key = Setting::getEncrypted('unsplash_access_key');
        if (! $key) {
            return [];
        }

        try {
            $resp = Http::withHeaders([
                'Authorization' => 'Client-ID '.$key,
                'Accept-Version' => 'v1',
            ])
                ->timeout(10)
                ->get(self::UNSPLASH_BASE.'/search/photos', [
                    'query' => $query,
                    'per_page' => max(1, min($perPage, 30)),
                    'lang' => 'fr',
                    'content_filter' => 'high',
                ]);

            if (! $resp->successful()) {
                Log::warning('Unsplash API error', ['status' => $resp->status(), 'body' => substr($resp->body(), 0, 300)]);

                return [];
            }

            $items = [];
            foreach ($resp->json('results', []) as $p) {
                $name = $p['user']['name'] ?? 'unknown';
                $items[] = [
                    'id' => 'unsplash:'.($p['id'] ?? ''),
                    'source' => 'unsplash',
                    'url_full' => $p['urls']['regular'] ?? $p['urls']['full'] ?? '',
                    'url_thumb' => $p['urls']['small'] ?? $p['urls']['thumb'] ?? '',
                    'width' => $p['width'] ?? 0,
                    'height' => $p['height'] ?? 0,
                    'photographer' => $name,
                    'attribution' => 'Photo by '.$name.' on Unsplash',
                    'source_url' => $p['links']['html'] ?? '',
                ];
            }

            return $items;
        } catch (\Throwable $e) {
            Log::warning('Unsplash exception', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Indique quels providers ont une clé configurée.
     *
     * @return array<string, bool>
     */
    public function availableProviders(): array
    {
        return [
            'pexels' => (bool) Setting::getEncrypted('pexels_api_key'),
            'pixabay' => (bool) Setting::getEncrypted('pixabay_api_key'),
            'unsplash' => (bool) Setting::getEncrypted('unsplash_access_key'),
        ];
    }
}
