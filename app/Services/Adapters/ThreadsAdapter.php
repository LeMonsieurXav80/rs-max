<?php

namespace App\Services\Adapters;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ThreadsAdapter implements PlatformAdapterInterface
{
    private const API_BASE = 'https://graph.threads.net/v1.0';

    private const VIDEO_POLL_MAX_ATTEMPTS = 30;

    private const VIDEO_POLL_INTERVAL = 5;

    public function publish(SocialAccount $account, string $content, ?array $media = null, ?array $options = null): array
    {
        try {
            $credentials = $account->credentials;
            $userId = $credentials['user_id'];
            $accessToken = $credentials['access_token'];

            // No media — text-only post.
            if (empty($media)) {
                return $this->publishTextPost($userId, $accessToken, $content, $options);
            }

            // Single image.
            if (count($media) === 1 && $this->isImage($media[0]['mimetype'])) {
                return $this->publishSingleImage($userId, $accessToken, $content, $media[0]['url'], $options);
            }

            // Single video.
            if (count($media) === 1 && $this->isVideo($media[0]['mimetype'])) {
                return $this->publishSingleVideo($userId, $accessToken, $content, $media[0]['url'], $options);
            }

            // Multiple media — carousel.
            return $this->publishCarousel($userId, $accessToken, $content, $media, $options);

        } catch (\Throwable $e) {
            Log::error('ThreadsAdapter: publish failed', [
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
    //  Text-only
    // -------------------------------------------------------------------------

    private function publishTextPost(string $userId, string $accessToken, string $text, ?array $options): array
    {
        $params = [
            'text' => $text,
            'media_type' => 'TEXT',
            'access_token' => $accessToken,
        ];

        $this->addLocationParams($params, $options);

        $container = Http::post(self::API_BASE . "/{$userId}/threads", $params);
        $containerId = $this->extractId($container, 'text container creation');

        if ($containerId === null) {
            return $this->errorFromResponse($container, 'Failed to create text container');
        }

        return $this->publishContainer($userId, $accessToken, $containerId);
    }

    // -------------------------------------------------------------------------
    //  Single Image
    // -------------------------------------------------------------------------

    private function publishSingleImage(string $userId, string $accessToken, string $text, string $imageUrl, ?array $options): array
    {
        $params = [
            'text' => $text,
            'image_url' => $imageUrl,
            'media_type' => 'IMAGE',
            'access_token' => $accessToken,
        ];

        $this->addLocationParams($params, $options);

        $container = Http::post(self::API_BASE . "/{$userId}/threads", $params);
        $containerId = $this->extractId($container, 'image container creation');

        if ($containerId === null) {
            return $this->errorFromResponse($container, 'Failed to create image container');
        }

        return $this->publishContainer($userId, $accessToken, $containerId);
    }

    // -------------------------------------------------------------------------
    //  Single Video
    // -------------------------------------------------------------------------

    private function publishSingleVideo(string $userId, string $accessToken, string $text, string $videoUrl, ?array $options): array
    {
        $params = [
            'text' => $text,
            'video_url' => $videoUrl,
            'media_type' => 'VIDEO',
            'access_token' => $accessToken,
        ];

        $this->addLocationParams($params, $options);

        $container = Http::post(self::API_BASE . "/{$userId}/threads", $params);
        $containerId = $this->extractId($container, 'video container creation');

        if ($containerId === null) {
            return $this->errorFromResponse($container, 'Failed to create video container');
        }

        // Poll until processing is complete.
        $ready = $this->waitForProcessing($containerId, $accessToken);

        if (! $ready) {
            return [
                'success' => false,
                'external_id' => null,
                'error' => 'Video processing timed out.',
            ];
        }

        return $this->publishContainer($userId, $accessToken, $containerId);
    }

    // -------------------------------------------------------------------------
    //  Carousel
    // -------------------------------------------------------------------------

    private function publishCarousel(string $userId, string $accessToken, string $text, array $media, ?array $options): array
    {
        $childIds = [];

        // Create child containers.
        foreach ($media as $item) {
            $params = [
                'is_carousel_item' => 'true',
                'access_token' => $accessToken,
            ];

            $isVideo = $this->isVideo($item['mimetype']);

            if ($isVideo) {
                $params['video_url'] = $item['url'];
                $params['media_type'] = 'VIDEO';
            } else {
                $params['image_url'] = $item['url'];
                $params['media_type'] = 'IMAGE';
            }

            $response = Http::post(self::API_BASE . "/{$userId}/threads", $params);
            $childId = $this->extractId($response, 'carousel child creation');

            if ($childId === null) {
                return $this->errorFromResponse($response, 'Failed to create carousel child container');
            }

            // Wait for video processing.
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

        // Create the carousel container.
        $carouselParams = [
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $childIds),
            'text' => $text,
            'access_token' => $accessToken,
        ];

        $this->addLocationParams($carouselParams, $options);

        $carouselResponse = Http::post(self::API_BASE . "/{$userId}/threads", $carouselParams);
        $carouselId = $this->extractId($carouselResponse, 'carousel container creation');

        if ($carouselId === null) {
            return $this->errorFromResponse($carouselResponse, 'Failed to create carousel container');
        }

        return $this->publishContainer($userId, $accessToken, $carouselId);
    }

    // -------------------------------------------------------------------------
    //  Helpers
    // -------------------------------------------------------------------------

    private function publishContainer(string $userId, string $accessToken, string $creationId): array
    {
        $response = Http::post(self::API_BASE . "/{$userId}/threads_publish", [
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

        $error = $body['error']['message'] ?? 'Unknown error during threads_publish';

        Log::error('ThreadsAdapter: threads_publish failed', [
            'creation_id' => $creationId,
            'error' => $error,
        ]);

        return [
            'success' => false,
            'external_id' => null,
            'error' => $error,
        ];
    }

    private function waitForProcessing(string $containerId, string $accessToken): bool
    {
        for ($attempt = 0; $attempt < self::VIDEO_POLL_MAX_ATTEMPTS; $attempt++) {
            sleep(self::VIDEO_POLL_INTERVAL);

            $response = Http::get(self::API_BASE . "/{$containerId}", [
                'fields' => 'status',
                'access_token' => $accessToken,
            ]);

            $status = $response->json('status');

            if ($status === 'FINISHED') {
                return true;
            }

            if ($status === 'ERROR') {
                Log::error('ThreadsAdapter: container processing returned ERROR', [
                    'container_id' => $containerId,
                ]);

                return false;
            }
        }

        return false;
    }

    private function addLocationParams(array &$params, ?array $options): void
    {
        if (! empty($options['location_id'])) {
            $params['location_id'] = $options['location_id'];
        }
    }

    private function extractId(\Illuminate\Http\Client\Response $response, string $context): ?string
    {
        $body = $response->json();

        if ($response->successful() && isset($body['id'])) {
            return (string) $body['id'];
        }

        Log::error("ThreadsAdapter: {$context} failed", [
            'status' => $response->status(),
            'body' => $body,
        ]);

        return null;
    }

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

    private function isImage(string $mimetype): bool
    {
        return str_starts_with($mimetype, 'image/');
    }

    private function isVideo(string $mimetype): bool
    {
        return str_starts_with($mimetype, 'video/');
    }
}
