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
     * Publish a single video as a Reel using Meta's resumable upload flow.
     *
     * The legacy `video_url` fetch flow is broken for Instagram since ~April 2026
     * (Meta returns error 2207076 without ever fetching the URL, regardless of
     * whether the video specs are valid). The resumable flow uploads bytes
     * directly to rupload.facebook.com and works reliably.
     *
     * 1. Create an upload session (media_type=REELS, upload_type=resumable) to
     *    get a container id + rupload URI.
     * 2. POST raw video bytes to the rupload URI.
     * 3. Poll until the container is FINISHED (usually instant with resumable).
     * 4. Publish the container.
     */
    private function publishSingleVideo(string $accountId, string $accessToken, string $caption, string $videoUrl, ?string $locationId = null): array
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

            // Videos go through the resumable upload flow (the URL-fetch flow
            // is broken since ~April 2026 — same reason as publishSingleVideo).
            if ($isVideo) {
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
            sleep(self::VIDEO_POLL_INTERVAL);

            $response = Http::get(self::API_BASE."/{$containerId}", [
                'fields' => 'status_code,status',
                'access_token' => $accessToken,
            ]);

            if (! $response->successful()) {
                Log::warning('InstagramAdapter: status check HTTP error', [
                    'container_id' => $containerId,
                    'http_status' => $response->status(),
                    'attempt' => $attempt + 1,
                ]);

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
            'access_token' => $accessToken,
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
