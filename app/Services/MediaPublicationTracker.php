<?php

namespace App\Services;

use App\Models\MediaFile;
use App\Models\MediaPublication;
use Illuminate\Support\Facades\Log;

/**
 * Trace l'utilisation effective des MediaFile dans les publications réussies.
 * Alimente la table media_publications utilisée par /api/media/search pour
 * exclure les images publiées trop récemment.
 */
class MediaPublicationTracker
{
    /**
     * @param  array|null  $media  Tableau d'items {url, mimetype?} comme stocké dans posts.media / thread_segments.media.
     */
    public function track(
        ?array $media,
        ?int $postId = null,
        ?int $threadSegmentId = null,
        ?int $postPlatformId = null,
        ?string $externalUrl = null,
        ?string $context = null,
    ): int {
        if (empty($media)) {
            return 0;
        }

        $count = 0;
        foreach ($media as $item) {
            $url = $item['url'] ?? null;
            if (! is_string($url) || $url === '') {
                continue;
            }

            // Les URLs locales sont sous la forme /media/xxx.ext (potentiellement signées avec query string).
            $filename = basename(parse_url($url, PHP_URL_PATH) ?: $url);
            if ($filename === '') {
                continue;
            }

            $mediaFile = MediaFile::where('filename', $filename)->first();
            if (! $mediaFile) {
                continue;
            }

            try {
                MediaPublication::create([
                    'media_file_id' => $mediaFile->id,
                    'post_id' => $postId,
                    'thread_segment_id' => $threadSegmentId,
                    'post_platform_id' => $postPlatformId,
                    'external_url' => $externalUrl,
                    'published_at' => now(),
                    'context' => $context,
                ]);
                $count++;
            } catch (\Throwable $e) {
                Log::warning('MediaPublicationTracker: failed to record publication', [
                    'media_file_id' => $mediaFile->id,
                    'post_id' => $postId,
                    'thread_segment_id' => $threadSegmentId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }
}
