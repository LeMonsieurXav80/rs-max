<?php

namespace App\Services\Media;

use App\Models\MediaFile;
use App\Services\Browser\BrowserException;

/** Vérifie la sélection du compositeur et les fichiers réellement transmis au NAS. */
class TikTokPostMedia
{
    public const MAX_BATCH_BYTES = 250 * 1024 * 1024;

    private const MAX_PHOTO_BYTES = 50 * 1024 * 1024;

    private const IMAGES = ['image/jpeg', 'image/png', 'image/webp'];

    public function conflictForAccounts(?array $media, iterable $accounts): ?string
    {
        foreach ($accounts as $account) {
            if ($account->platform->slug !== 'tiktok') {
                continue;
            }

            try {
                $files = [];
                foreach ($media ?? [] as $item) {
                    $item = is_string($item) ? json_decode($item, true) : $item;
                    $url = $item['url'] ?? '';
                    $file = is_string($url) && preg_match('~^/media/([^/?#]+)$~', $url, $matches)
                        ? $this->catalogFile($matches[1])
                        : null;
                    if (! $file) {
                        throw new BrowserException('TikTok demande des fichiers de la médiathèque RS-Max.');
                    }
                    $files[] = ['mimetype' => $file->mime_type, 'size' => $file->size];
                }
                $this->type($files);
            } catch (BrowserException $e) {
                return $e->getMessage();
            }
            break;
        }

        return null;
    }

    public function catalogFile(string $name): ?MediaFile
    {
        $file = MediaFile::with('folder')->where('filename', $name)->first();
        if ($file && ($file->intimacy_level !== 'public' || $file->folder?->isEffectivelyPrivate())) {
            throw new BrowserException('Média TikTok privé ou interdit de publication.');
        }

        return $file;
    }

    public function type(array $media): string
    {
        if (count($media) === 1 && ($media[0]['mimetype'] ?? '') === 'video/mp4'
            && ($media[0]['size'] ?? 0) > 0 && $media[0]['size'] <= self::MAX_BATCH_BYTES) {
            return 'video';
        }
        if ($media && count($media) <= 35 && array_sum(array_column($media, 'size')) <= self::MAX_BATCH_BYTES) {
            foreach ($media as $item) {
                if (! in_array($item['mimetype'] ?? null, self::IMAGES, true)
                    || ($item['size'] ?? 0) < 1 || $item['size'] > self::MAX_PHOTO_BYTES) {
                    throw new BrowserException($this->mediaError());
                }
            }

            return 'photo';
        }

        throw new BrowserException($this->mediaError());
    }

    private function mediaError(): string
    {
        return 'TikTok accepte une vidéo MP4 (250 Mo max) ou 1 à 35 photos JPEG, PNG ou WebP (50 Mo chacune, 250 Mo au total).';
    }
}
