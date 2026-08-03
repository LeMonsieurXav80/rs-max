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
        ?int $socialAccountId = null,
        ?string $externalUrl = null,
        ?string $context = null,
    ): int {
        if (empty($media)) {
            return 0;
        }

        $count = 0;
        // Une même photo source peut alimenter plusieurs slides du même carrousel :
        // on ne lui compte qu'UNE publication par appel (= par post/segment/plateforme).
        $sourcesSeen = [];

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
                    'social_account_id' => $socialAccountId,
                    'external_url' => $externalUrl,
                    'published_at' => now(),
                    'context' => $context,
                ]);
                // Incrémente le compteur dénormalisé (cache pour tri/affichage).
                $mediaFile->increment('publication_count');
                $count++;
                // Publiée en direct : ne pas la recompter si une slide s'en sert aussi.
                $sourcesSeen[$mediaFile->id] = true;

                // Publier une slide de carrousel, c'est publier les photos qui la
                // composent : elles reçoivent la même ligne, marquée `via`, pour que
                // les filtres d'usage (used, exclude_recently_published_days, compte
                // par réseau) les voient comme réellement publiées.
                $count += $this->trackSources(
                    $mediaFile, $sourcesSeen, $postId, $threadSegmentId,
                    $postPlatformId, $socialAccountId, $externalUrl, $context,
                );
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

    /**
     * Répercute la publication d'une image générée sur ses photos sources.
     *
     * @param  array<int, true>  $seen  Ids déjà tracés dans cet appel (modifié sur place)
     * @return int Nombre de lignes créées
     */
    private function trackSources(
        MediaFile $derived,
        array &$seen,
        ?int $postId,
        ?int $threadSegmentId,
        ?int $postPlatformId,
        ?int $socialAccountId,
        ?string $externalUrl,
        ?string $context,
    ): int {
        if (! $derived->is_generated) {
            return 0;
        }

        $count = 0;
        foreach ($derived->sources()->get() as $source) {
            if (isset($seen[$source->id])) {
                continue;
            }
            $seen[$source->id] = true;

            try {
                MediaPublication::create([
                    'media_file_id' => $source->id,
                    'via_media_file_id' => $derived->id,
                    'post_id' => $postId,
                    'thread_segment_id' => $threadSegmentId,
                    'post_platform_id' => $postPlatformId,
                    'social_account_id' => $socialAccountId,
                    'external_url' => $externalUrl,
                    'published_at' => now(),
                    'context' => $context,
                ]);
                $source->increment('publication_count');
                $count++;
            } catch (\Throwable $e) {
                Log::warning('MediaPublicationTracker: échec du report sur la photo source', [
                    'source_media_file_id' => $source->id,
                    'derived_media_file_id' => $derived->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }
}
