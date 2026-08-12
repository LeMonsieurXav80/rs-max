<?php

namespace App\Support;

/**
 * Normalisation des items media stockes en JSON (posts.media, thread_segments.media).
 *
 * Deux ecritures cohabitent historiquement : le composer web ecrit `mimetype`
 * ("image/jpeg"), l'API et les services d'import ecrivent `type` ("image").
 * Les vues et les adapters de publication ne lisent que `mimetype` : un post
 * cree par l'API se retrouvait donc sans apercu (carre vide) et sans detection
 * image/video au moment de publier.
 *
 * `mimetype` fait foi. On le derive de l'extension du fichier, puis de `type`.
 */
class MediaItems
{
    private const EXTENSION_MIMES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'heic' => 'image/heic',
        'mp4' => 'video/mp4',
        'mov' => 'video/quicktime',
        'm4v' => 'video/mp4',
        'webm' => 'video/webm',
    ];

    /**
     * @param  mixed  $items  Tableau d'items bruts (ou null / valeur inattendue).
     * @return array|null Items avec un `mimetype` garanti, ou null si vide.
     */
    public static function normalize(mixed $items): ?array
    {
        if (! is_array($items) || $items === []) {
            return null;
        }

        $normalized = array_values(array_filter(array_map(
            fn ($item) => self::normalizeItem($item),
            $items
        )));

        return $normalized ?: null;
    }

    /**
     * @return array<string, mixed>|null Item normalise, ou null s'il est inexploitable.
     */
    public static function normalizeItem(mixed $item): ?array
    {
        // Ancien format : une simple chaine d'URL.
        if (is_string($item)) {
            $item = ['url' => $item];
        }

        if (! is_array($item) || empty($item['url']) || ! is_string($item['url'])) {
            return null;
        }

        if (empty($item['mimetype']) || ! is_string($item['mimetype'])) {
            $item['mimetype'] = self::guessMimetype($item['url'], $item['type'] ?? null);
        }

        return $item;
    }

    /**
     * Devine le type MIME depuis l'extension, puis depuis le `type` de l'API.
     */
    public static function guessMimetype(string $url, ?string $type = null): string
    {
        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: $url, PATHINFO_EXTENSION));

        if (isset(self::EXTENSION_MIMES[$extension])) {
            return self::EXTENSION_MIMES[$extension];
        }

        return $type === 'video' ? 'video/mp4' : 'image/jpeg';
    }
}
