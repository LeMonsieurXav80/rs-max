<?php

namespace App\Services\Adapters;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InstagramAdapter implements PlatformAdapterInterface
{
    private const API_BASE = 'https://graph.facebook.com/v21.0';

    /**
     * Maximum number of polling attempts when waiting for a video container
     * to finish processing (status_code === 'FINISHED').
     */
    private const VIDEO_POLL_MAX_ATTEMPTS = 60;

    /**
     * Seconds to wait between polling attempts for video processing.
     */
    private const VIDEO_POLL_INTERVAL = 10;

    /**
     * Token used for GET requests on container objects (poll, error fetch).
     * Meta accepte le page access_token pour POST /media mais le rejette pour
     * GET /{container_id} (Authorization Error code=100 subcode=33). Seul le
     * user_access_token a les droits de lecture sur ces objets temporaires.
     */
    private ?string $readToken = null;

    /**
     * Publish content to Instagram via the Container-based Graph API.
     *
     * @param  SocialAccount  $account  Credentials: account_id, access_token.
     * @param  string  $content  The caption text.
     * @param  array|null  $media  Optional media items (each with url, mimetype, size, title).
     * @return array{success: bool, external_id: string|null, error: string|null}
     */
    public function publish(SocialAccount $account, string $content, ?array $media = null, ?array $options = null): array
    {
        try {
            $credentials = $account->credentials;
            $accountId = $credentials['account_id'];
            $accessToken = $credentials['access_token'];
            $this->readToken = $credentials['user_access_token'] ?? $accessToken;
            $locationId = $options['location_id'] ?? null;

            // Instagram requires at least one media item.
            if (empty($media)) {
                return [
                    'success' => false,
                    'external_id' => null,
                    'error' => 'Instagram requires at least one image or video to publish.',
                ];
            }

            // Single image.
            if (count($media) === 1 && $this->isImage($media[0]['mimetype'])) {
                return $this->publishSingleImage($accountId, $accessToken, $content, $media[0]['url'], $locationId);
            }

            // Single video (published as a Reel).
            if (count($media) === 1 && $this->isVideo($media[0]['mimetype'])) {
                return $this->publishSingleVideo($accountId, $accessToken, $content, $media[0]['url'], $locationId);
            }

            // Multiple media items -- carousel.
            return $this->publishCarousel($accountId, $accessToken, $content, $media, $locationId);

        } catch (\Throwable $e) {
            Log::error('InstagramAdapter: publish failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'external_id' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    // -------------------------------------------------------------------------
    //  Single Image
    // -------------------------------------------------------------------------

    /**
     * Publish a single image post.
     *
     * 1. Create an image container.
     * 2. Publish the container.
     */
    private function publishSingleImage(string $accountId, string $accessToken, string $caption, string $imageUrl, ?string $locationId = null): array
    {
        // Step 1 -- create the media container.
        $params = [
            'image_url' => $imageUrl,
            'caption' => $caption,
            'access_token' => $accessToken,
        ];

        if ($locationId) {
            $params['location_id'] = $locationId;
        }

        $container = Http::post(self::API_BASE."/{$accountId}/media", $params);

        $containerId = $this->extractId($container, 'image container creation');

        if ($containerId === null) {
            return $this->errorFromResponse($container, 'Failed to create image container');
        }

        // Step 2 -- wait for processing.
        $processingError = $this->waitForProcessing($containerId, $accessToken);

        if ($processingError !== null) {
            return [
                'success' => false,
                'external_id' => null,
                'error' => $processingError,
            ];
        }

        // Step 3 -- publish.
        return $this->publishContainer($accountId, $accessToken, $containerId);
    }

    // -------------------------------------------------------------------------
    //  Single Video (Reel)
    // -------------------------------------------------------------------------

    /**
     * Publish a single video as a Reel.
     *
     * Chemin principal : `video_url`, où Meta vient lire le fichier lui-même —
     * exactement comme `image_url`, qui fonctionne depuis toujours.
     *
     * Ce chemin avait été abandonné sur un diagnostic faux : l'erreur 2207076
     * (« Media download failed ») avait été lue comme une panne de l'API, alors
     * qu'elle disait l'inverse — la route /media/{fichier} n'est pas publique et
     * exige une URL signée, donc Meta ne pouvait jamais récupérer le fichier.
     * `ResolvesPublishableMedia` en fournit une, valable 4 h.
     *
     * Le flux resumable qui l'avait remplacé refuse `ProcessingFailedError` sur
     * nos verticales 1080x1920 d'environ 80 s — soit le format Reels standard.
     * Mesuré : 1080x1920 passe à 40 s mais pas à 82 s, et 82 s passe en
     * 720x1280 ; c'est la durée combinée à la résolution qui bloque, pas le
     * poids du fichier (13,5 Mo passent, 16,5 Mo non). Aucune vidéo n'avait
     * jamais été publiée par ce chemin. Il reste ici en secours, pour le cas où
     * Meta n'arriverait pas à lire l'URL (fichier non servable, DNS, pare-feu).
     */
    private function publishSingleVideo(string $accountId, string $accessToken, string $caption, string $videoUrl, ?string $locationId = null): array
    {
        $containerId = $this->createVideoContainerFromUrl(
            $accountId, $accessToken, $caption, $videoUrl, $locationId
        );

        if ($containerId !== null) {
            $processingError = $this->waitForProcessing($containerId, $accessToken);

            if ($processingError === null) {
                return $this->publishContainer($accountId, $accessToken, $containerId);
            }

            Log::warning('InstagramAdapter: video_url a échoué, bascule sur le flux resumable', [
                'container_id' => $containerId,
                'error' => $processingError,
            ]);
        }

        return $this->publishSingleVideoResumable(
            $accountId, $accessToken, $caption, $videoUrl, $locationId
        );
    }

    /**
     * Create a REELS container from a URL Meta fetches itself.
     *
     * @return string|null Container id, or null if the URL is unusable or Meta refused it.
     */
    private function createVideoContainerFromUrl(string $accountId, string $accessToken, string $caption, string $videoUrl, ?string $locationId = null, bool $isCarouselItem = false): ?string
    {
        // Meta doit pouvoir atteindre l'URL : un chemin relatif ne lui sert à
        // rien, et c'est précisément ce qui avait fait condamner ce flux.
        if (! str_starts_with($videoUrl, 'http://') && ! str_starts_with($videoUrl, 'https://')) {
            Log::warning('InstagramAdapter: URL vidéo non absolue, video_url inutilisable', [
                'url' => $videoUrl,
            ]);

            return null;
        }

        $params = [
            'media_type' => 'REELS',
            'video_url' => $videoUrl,
            'access_token' => $accessToken,
        ];

        if ($isCarouselItem) {
            $params['is_carousel_item'] = 'true';
        } else {
            $params['caption'] = $caption;
        }

        if ($locationId && ! $isCarouselItem) {
            $params['location_id'] = $locationId;
        }

        $container = Http::post(self::API_BASE."/{$accountId}/media", $params);

        $containerId = $container->json('id');

        if (! $containerId) {
            Log::warning('InstagramAdapter: création du conteneur via video_url refusée', [
                'http_status' => $container->status(),
                'body' => $container->json() ?? $container->body(),
            ]);

            return null;
        }

        return (string) $containerId;
    }

    /**
     * Secours : upload des octets vers rupload.facebook.com.
     *
     * 1. Create an upload session (media_type=REELS, upload_type=resumable) to
     *    get a container id + rupload URI.
     * 2. POST raw video bytes to the rupload URI.
     * 3. Poll until the container is FINISHED (usually instant with resumable).
     * 4. Publish the container.
     */
    private function publishSingleVideoResumable(string $accountId, string $accessToken, string $caption, string $videoUrl, ?string $locationId = null): array
    {
        // Step 1 -- create the upload session.
        $params = [
            'media_type' => 'REELS',
            'upload_type' => 'resumable',
            'caption' => $caption,
            'access_token' => $accessToken,
        ];

        if ($locationId) {
            $params['location_id'] = $locationId;
        }

        $session = Http::post(self::API_BASE."/{$accountId}/media", $params);

        $containerId = $this->extractId($session, 'video upload session creation');
        $uploadUri = $session->json('uri');

        if ($containerId === null || empty($uploadUri)) {
            return $this->errorFromResponse($session, 'Failed to create video upload session');
        }

        // Step 2 -- upload video bytes directly to rupload.
        $uploadError = $this->uploadVideoBytes($uploadUri, $accessToken, $videoUrl);

        if ($uploadError !== null) {
            return [
                'success' => false,
                'external_id' => null,
                'error' => $uploadError,
            ];
        }

        // Step 3 -- poll until processing is complete.
        $processingError = $this->waitForProcessing($containerId, $accessToken);

        if ($processingError !== null) {
            return [
                'success' => false,
                'external_id' => null,
                'error' => $processingError,
            ];
        }

        // Step 4 -- publish.
        return $this->publishContainer($accountId, $accessToken, $containerId);
    }

    /**
     * Resolve a video URL to bytes and POST them to Meta's rupload endpoint.
     *
     * If the URL points at our own /media/ route we read the file from disk
     * directly (avoids a round-trip through nginx for a localhost fetch).
     *
     * @return string|null Null on success, error message on failure.
     */
    private function uploadVideoBytes(string $uploadUri, string $accessToken, string $videoUrl): ?string
    {
        $localPath = $this->resolveLocalMediaPath($videoUrl);

        if ($localPath !== null) {
            $bytes = @file_get_contents($localPath);
        } else {
            $fetch = Http::timeout(120)->get($videoUrl);

            if (! $fetch->successful()) {
                return "Failed to fetch video from URL (HTTP {$fetch->status()})";
            }

            $bytes = $fetch->body();
        }

        if (empty($bytes)) {
            return 'Video content is empty or unreadable';
        }

        $fileSize = strlen($bytes);

        $upload = Http::withHeaders([
            'Authorization' => 'OAuth '.$accessToken,
            'offset' => '0',
            'file_size' => (string) $fileSize,
        ])
            ->withBody($bytes, 'application/octet-stream')
            ->timeout(300)
            ->post($uploadUri);

        if (! $upload->successful() || $upload->json('success') !== true) {
            $error = $upload->json('error.message') ?? $upload->body();

            Log::error('InstagramAdapter: rupload failed', [
                'http_status' => $upload->status(),
                'body' => $upload->json() ?? $upload->body(),
                'file_size' => $fileSize,
            ]);

            return "Instagram video upload failed (HTTP {$upload->status()}): {$error}";
        }

        return null;
    }

    /**
     * Attente entre deux relevés d'état, isolée pour que les tests n'aient pas
     * à patienter les 10 s réelles (même procédé que `ThreadsAdapter`).
     */
    protected function pause(int $seconds): void
    {
        sleep($seconds);
    }

    /**
     * If $url points at our own /media/{filename} route, return the absolute
     * path to the local file; otherwise null.
     */
    private function resolveLocalMediaPath(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! $path || ! preg_match('#^/media/([^/]+)$#', $path, $m)) {
            return null;
        }

        $filename = $m[1];
        $local = storage_path("app/private/media/{$filename}");

        return file_exists($local) ? $local : null;
    }

    // -------------------------------------------------------------------------
    //  Carousel
    // -------------------------------------------------------------------------

    /**
     * Publish a carousel post with multiple images and/or videos.
     *
     * 1. Create a container for each child item (is_carousel_item=true).
     * 2. Wait for any video containers to finish processing.
     * 3. Create the carousel container referencing all children.
     * 4. Publish the carousel container.
     */
    private function publishCarousel(string $accountId, string $accessToken, string $caption, array $media, ?string $locationId = null): array
    {
        $childIds = [];

        // Step 1 -- create child containers.
        foreach ($media as $item) {
            $isVideo = $this->isVideo($item['mimetype']);

            // Même ordre que publishSingleVideo : Meta lit l'URL lui-même, et
            // l'upload d'octets ne sert plus que de secours.
            if ($isVideo) {
                $childId = $this->createVideoContainerFromUrl(
                    $accountId, $accessToken, '', $item['url'], null, true
                );

                if ($childId === null) {
                    $session = Http::post(self::API_BASE."/{$accountId}/media", [
                        'is_carousel_item' => 'true',
                        'media_type' => 'REELS',
                        'upload_type' => 'resumable',
                        'access_token' => $accessToken,
                    ]);

                    $childId = $this->extractId($session, 'carousel video child session creation');
                    $uploadUri = $session->json('uri');

                    if ($childId === null || empty($uploadUri)) {
                        return $this->errorFromResponse($session, 'Failed to create carousel video child session');
                    }

                    $uploadError = $this->uploadVideoBytes($uploadUri, $accessToken, $item['url']);

                    if ($uploadError !== null) {
                        return [
                            'success' => false,
                            'external_id' => null,
                            'error' => "Carousel child {$childId}: {$uploadError}",
                        ];
                    }
                }
            } else {
                $response = Http::post(self::API_BASE."/{$accountId}/media", [
                    'is_carousel_item' => 'true',
                    'image_url' => $item['url'],
                    'access_token' => $accessToken,
                ]);

                $childId = $this->extractId($response, 'carousel image child creation');

                if ($childId === null) {
                    return $this->errorFromResponse($response, 'Failed to create carousel image child container');
                }
            }

            // Wait for child container to be FINISHED (required for all types, not just videos)
            $processingError = $this->waitForProcessing($childId, $accessToken);

            if ($processingError !== null) {
                return [
                    'success' => false,
                    'external_id' => null,
                    'error' => "Carousel child {$childId}: {$processingError}",
                ];
            }

            $childIds[] = $childId;
        }

        // Step 2 -- create the carousel container.
        $carouselParams = [
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $childIds),
            'caption' => $caption,
            'access_token' => $accessToken,
        ];

        if ($locationId) {
            $carouselParams['location_id'] = $locationId;
        }

        $carouselResponse = Http::post(self::API_BASE."/{$accountId}/media", $carouselParams);

        $carouselId = $this->extractId($carouselResponse, 'carousel container creation');

        if ($carouselId === null) {
            return $this->errorFromResponse($carouselResponse, 'Failed to create carousel container');
        }

        // Step 3 -- wait for carousel container processing.
        $processingError = $this->waitForProcessing($carouselId, $accessToken);

        if ($processingError !== null) {
            return [
                'success' => false,
                'external_id' => null,
                'error' => "Carousel container: {$processingError}",
            ];
        }

        // Step 4 -- publish.
        return $this->publishContainer($accountId, $accessToken, $carouselId);
    }

    // -------------------------------------------------------------------------
    //  Helpers
    // -------------------------------------------------------------------------

    /**
     * Publish a previously-created media container.
     */
    private function publishContainer(string $accountId, string $accessToken, string $creationId): array
    {
        $response = Http::post(self::API_BASE."/{$accountId}/media_publish", [
            'creation_id' => $creationId,
            'access_token' => $accessToken,
        ]);

        $body = $response->json();

        if ($response->successful() && isset($body['id'])) {
            return [
                'success' => true,
                'external_id' => (string) $body['id'],
                'error' => null,
            ];
        }

        $error = $body['error']['message'] ?? 'Unknown error during media_publish';

        Log::error('InstagramAdapter: media_publish failed', [
            'creation_id' => $creationId,
            'status' => $response->status(),
            'error' => $error,
            'body' => $body,
        ]);

        return [
            'success' => false,
            'external_id' => null,
            'error' => "Instagram publish failed: {$error}",
        ];
    }

    /**
     * Poll the status of a media container until it reaches FINISHED or fails.
     *
     * @return string|null Null on success, error message string on failure.
     */
    private function waitForProcessing(string $containerId, string $accessToken): ?string
    {
        $lastStatus = null;

        for ($attempt = 0; $attempt < self::VIDEO_POLL_MAX_ATTEMPTS; $attempt++) {
            $this->pause(self::VIDEO_POLL_INTERVAL);

            $response = Http::get(self::API_BASE."/{$containerId}", [
                'fields' => 'status_code,status',
                'access_token' => $this->readToken ?? $accessToken,
            ]);

            if (! $response->successful()) {
                $body = $response->json();
                $code = $body['error']['code'] ?? null;
                $subcode = $body['error']['error_subcode'] ?? null;

                Log::warning('InstagramAdapter: status check HTTP error', [
                    'container_id' => $containerId,
                    'http_status' => $response->status(),
                    'code' => $code,
                    'subcode' => $subcode,
                    'attempt' => $attempt + 1,
                ]);

                // Fail fast sur erreurs d'auth/permission fatales (code 100, 190, 200)
                // — re-essayer 60x serait inutile et bloquerait 10 minutes.
                if (in_array($code, [100, 190, 200], true)) {
                    return "Instagram container unreadable (code={$code}".($subcode ? ", subcode={$subcode}" : '').')';
                }

                continue;
            }

            $status = $response->json('status_code');
            $lastStatus = $status;

            if ($status === 'FINISHED') {
                return null;
            }

            if ($status === 'ERROR') {
                // Fetch detailed error info from the container
                $errorDetail = $this->fetchContainerError($containerId, $accessToken);

                Log::error('InstagramAdapter: container processing returned ERROR', [
                    'container_id' => $containerId,
                    'error_detail' => $errorDetail,
                    'attempt' => $attempt + 1,
                ]);

                return "Instagram container error: {$errorDetail}";
            }
        }

        $totalSeconds = self::VIDEO_POLL_MAX_ATTEMPTS * self::VIDEO_POLL_INTERVAL;

        Log::warning('InstagramAdapter: video processing timed out', [
            'container_id' => $containerId,
            'last_status' => $lastStatus,
            'attempts' => self::VIDEO_POLL_MAX_ATTEMPTS,
            'total_seconds' => $totalSeconds,
        ]);

        return "Instagram video processing timed out after {$totalSeconds}s (last status: {$lastStatus})";
    }

    /**
     * Fetch detailed error information from a failed container.
     */
    private function fetchContainerError(string $containerId, string $accessToken): string
    {
        $response = Http::get(self::API_BASE."/{$containerId}", [
            'fields' => 'status_code,status,id',
            'access_token' => $this->readToken ?? $accessToken,
        ]);

        if (! $response->successful()) {
            return "Could not fetch error details (HTTP {$response->status()})";
        }

        $data = $response->json();

        // status field often contains the human-readable error message
        $statusMessage = $data['status'] ?? null;
        $statusCode = $data['status_code'] ?? 'UNKNOWN';

        if ($statusMessage) {
            return "{$statusCode}: {$statusMessage}";
        }

        return "Status: {$statusCode} (no detail available)";
    }

    /**
     * Extract the 'id' field from a Graph API response.
     */
    private function extractId(\Illuminate\Http\Client\Response $response, string $context): ?string
    {
        $body = $response->json();

        if ($response->successful() && isset($body['id'])) {
            return (string) $body['id'];
        }

        Log::error("InstagramAdapter: {$context} failed", [
            'status' => $response->status(),
            'body' => $body,
        ]);

        return null;
    }

    /**
     * Build a standard error return from a failed HTTP response.
     */
    private function errorFromResponse(\Illuminate\Http\Client\Response $response, string $fallback): array
    {
        $body = $response->json();
        $error = $body['error']['message'] ?? $fallback;
        $code = $body['error']['code'] ?? null;
        $subcode = $body['error']['error_subcode'] ?? null;

        $detail = "Instagram: {$error}";
        if ($code) {
            $detail .= " (code={$code}";
            if ($subcode) {
                $detail .= ", subcode={$subcode}";
            }
            $detail .= ')';
        }

        return [
            'success' => false,
            'external_id' => null,
            'error' => $detail,
        ];
    }

    /**
     * Determine whether a MIME type represents an image.
     */
    private function isImage(string $mimetype): bool
    {
        return str_starts_with($mimetype, 'image/');
    }

    /**
     * Determine whether a MIME type represents a video.
     */
    private function isVideo(string $mimetype): bool
    {
        return str_starts_with($mimetype, 'video/');
    }
}
