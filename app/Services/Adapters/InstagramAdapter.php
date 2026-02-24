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
    private const VIDEO_POLL_MAX_ATTEMPTS = 30;

    /**
     * Seconds to wait between polling attempts for video processing.
     */
    private const VIDEO_POLL_INTERVAL = 5;

    /**
     * Publish content to Instagram via the Container-based Graph API.
     *
     * @param  SocialAccount  $account  Credentials: account_id, access_token.
     * @param  string  $content  The caption text.
     * @param  array|null  $media  Optional media items (each with url, mimetype, size, title).
     * @return array{success: bool, external_id: string|null, error: string|null}
     */
    public function publish(SocialAccount $account, string $content, ?array $media = null): array
    {
        try {
            $credentials = $account->credentials;
            $accountId = $credentials['account_id'];
            $accessToken = $credentials['access_token'];

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
                return $this->publishSingleImage($accountId, $accessToken, $content, $media[0]['url']);
            }

            // Single video (published as a Reel).
            if (count($media) === 1 && $this->isVideo($media[0]['mimetype'])) {
                return $this->publishSingleVideo($accountId, $accessToken, $content, $media[0]['url']);
            }

            // Multiple media items -- carousel.
            return $this->publishCarousel($accountId, $accessToken, $content, $media);

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
    private function publishSingleImage(string $accountId, string $accessToken, string $caption, string $imageUrl): array
    {
        // Step 1 -- create the media container.
        $container = Http::post(self::API_BASE . "/{$accountId}/media", [
            'image_url' => $imageUrl,
            'caption' => $caption,
            'access_token' => $accessToken,
        ]);

        $containerId = $this->extractId($container, 'image container creation');

        if ($containerId === null) {
            return $this->errorFromResponse($container, 'Failed to create image container');
        }

        // Step 2 -- publish.
        return $this->publishContainer($accountId, $accessToken, $containerId);
    }

    // -------------------------------------------------------------------------
    //  Single Video (Reel)
    // -------------------------------------------------------------------------

    /**
     * Publish a single video as a Reel.
     *
     * 1. Create a video container (media_type=REELS).
     * 2. Poll until the container is FINISHED.
     * 3. Publish the container.
     */
    private function publishSingleVideo(string $accountId, string $accessToken, string $caption, string $videoUrl): array
    {
        // Step 1 -- create the video container.
        $container = Http::post(self::API_BASE . "/{$accountId}/media", [
            'video_url' => $videoUrl,
            'caption' => $caption,
            'media_type' => 'REELS',
            'access_token' => $accessToken,
        ]);

        $containerId = $this->extractId($container, 'video container creation');

        if ($containerId === null) {
            return $this->errorFromResponse($container, 'Failed to create video container');
        }

        // Step 2 -- poll until processing is complete.
        $ready = $this->waitForProcessing($containerId, $accessToken);

        if (! $ready) {
            return [
                'success' => false,
                'external_id' => null,
                'error' => 'Video processing timed out. The container did not reach FINISHED status.',
            ];
        }

        // Step 3 -- publish.
        return $this->publishContainer($accountId, $accessToken, $containerId);
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
    private function publishCarousel(string $accountId, string $accessToken, string $caption, array $media): array
    {
        $childIds = [];

        // Step 1 -- create child containers.
        foreach ($media as $item) {
            $params = [
                'is_carousel_item' => 'true',
                'access_token' => $accessToken,
            ];

            $isVideo = $this->isVideo($item['mimetype']);

            if ($isVideo) {
                $params['video_url'] = $item['url'];
                $params['media_type'] = 'REELS';
            } else {
                $params['image_url'] = $item['url'];
            }

            $response = Http::post(self::API_BASE . "/{$accountId}/media", $params);
            $childId = $this->extractId($response, 'carousel child creation');

            if ($childId === null) {
                return $this->errorFromResponse($response, 'Failed to create carousel child container');
            }

            // If this child is a video, wait for it to finish processing.
            if ($isVideo) {
                $ready = $this->waitForProcessing($childId, $accessToken);

                if (! $ready) {
                    return [
                        'success' => false,
                        'external_id' => null,
                        'error' => "Video processing timed out for carousel child {$childId}.",
                    ];
                }
            }

            $childIds[] = $childId;
        }

        // Step 2 -- create the carousel container.
        $carouselResponse = Http::post(self::API_BASE . "/{$accountId}/media", [
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $childIds),
            'caption' => $caption,
            'access_token' => $accessToken,
        ]);

        $carouselId = $this->extractId($carouselResponse, 'carousel container creation');

        if ($carouselId === null) {
            return $this->errorFromResponse($carouselResponse, 'Failed to create carousel container');
        }

        // Step 3 -- publish.
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
        $response = Http::post(self::API_BASE . "/{$accountId}/media_publish", [
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
            'error' => $error,
        ]);

        return [
            'success' => false,
            'external_id' => null,
            'error' => $error,
        ];
    }

    /**
     * Poll the status of a media container until it reaches FINISHED or we time out.
     */
    private function waitForProcessing(string $containerId, string $accessToken): bool
    {
        for ($attempt = 0; $attempt < self::VIDEO_POLL_MAX_ATTEMPTS; $attempt++) {
            sleep(self::VIDEO_POLL_INTERVAL);

            $response = Http::get(self::API_BASE . "/{$containerId}", [
                'fields' => 'status_code',
                'access_token' => $accessToken,
            ]);

            $status = $response->json('status_code');

            if ($status === 'FINISHED') {
                return true;
            }

            if ($status === 'ERROR') {
                Log::error('InstagramAdapter: container processing returned ERROR', [
                    'container_id' => $containerId,
                ]);

                return false;
            }
        }

        return false;
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

        return [
            'success' => false,
            'external_id' => null,
            'error' => $error,
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
