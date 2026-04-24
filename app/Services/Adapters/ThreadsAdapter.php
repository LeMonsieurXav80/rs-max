<?php

namespace App\Services\Adapters;

use App\Models\Platform;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ThreadsAdapter implements PlatformAdapterInterface, ThreadableAdapterInterface
{
    private const API_BASE = 'https://graph.threads.net/v1.0';

    private const VIDEO_POLL_MAX_ATTEMPTS = 60;

    private const VIDEO_POLL_INTERVAL = 10;

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

    /**
     * Publish a reply to an existing Threads post (for thread chaining).
     */
    public function publishReply(SocialAccount $account, string $content, string $replyToId, ?array $media = null, ?array $options = null): array
    {
        try {
            $credentials = $account->credentials;
            $userId = $credentials['user_id'];
            $accessToken = $credentials['access_token'];

            $params = [
                'text' => $content,
                'media_type' => 'TEXT',
                'reply_to_id' => $replyToId,
                'access_token' => $accessToken,
            ];

            // Single image reply.
            if (! empty($media) && count($media) === 1 && $this->isImage($media[0]['mimetype'])) {
                $params['media_type'] = 'IMAGE';
                $params['image_url'] = $this->resolveImageUrl($media[0]['url']);
            }

            // Single video reply.
            if (! empty($media) && count($media) === 1 && $this->isVideo($media[0]['mimetype'])) {
                $params['media_type'] = 'VIDEO';
                $params['video_url'] = $media[0]['url'];
            }

            $this->addLocationParams($params, $options);

            $container = Http::post(self::API_BASE."/{$userId}/threads", $params);
            $containerId = $this->extractId($container, 'reply container creation');

            if ($containerId === null) {
                return $this->errorFromResponse($container, 'Failed to create reply container');
            }

            // Wait for video processing if needed.
            if (($params['media_type'] ?? '') === 'VIDEO') {
                $processingError = $this->waitForProcessing($containerId, $accessToken);

                if ($processingError !== null) {
                    return [
                        'success' => false,
                        'external_id' => null,
                        'error' => $processingError,
                    ];
                }
            }

            return $this->publishContainer($userId, $accessToken, $containerId);

        } catch (\Throwable $e) {
            Log::error('ThreadsAdapter: publishReply failed', [
                'account_id' => $account->id,
                'reply_to' => $replyToId,
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

        Log::info('ThreadsAdapter: creating text container', [
            'user_id' => $userId,
            'text_length' => mb_strlen($text),
            'text_preview' => mb_substr($text, 0, 100),
            'media_type' => $params['media_type'],
        ]);

        $container = Http::post(self::API_BASE."/{$userId}/threads", $params);
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
            'image_url' => $this->resolveImageUrl($imageUrl),
            'media_type' => 'IMAGE',
            'access_token' => $accessToken,
        ];

        $this->addLocationParams($params, $options);

        $container = Http::post(self::API_BASE."/{$userId}/threads", $params);
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

        $container = Http::post(self::API_BASE."/{$userId}/threads", $params);
        $containerId = $this->extractId($container, 'video container creation');

        if ($containerId === null) {
            return $this->errorFromResponse($container, 'Failed to create video container');
        }

        // Poll until processing is complete.
        $processingError = $this->waitForProcessing($containerId, $accessToken);

        if ($processingError !== null) {
            return [
                'success' => false,
                'external_id' => null,
                'error' => $processingError,
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
                $params['image_url'] = $this->resolveImageUrl($item['url']);
                $params['media_type'] = 'IMAGE';
            }

            $response = Http::post(self::API_BASE."/{$userId}/threads", $params);
            $childId = $this->extractId($response, 'carousel child creation');

            if ($childId === null) {
                return $this->errorFromResponse($response, 'Failed to create carousel child container');
            }

            // Wait for child container to be FINISHED (required for all types, not just videos)
            if ($isVideo) {
                $processingError = $this->waitForProcessing($childId, $accessToken);
            } else {
                $processingError = $this->waitUntilReady($childId, $accessToken);
            }

            if ($processingError !== null) {
                return [
                    'success' => false,
                    'external_id' => null,
                    'error' => "Carousel child {$childId}: {$processingError}",
                ];
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

        $carouselResponse = Http::post(self::API_BASE."/{$userId}/threads", $carouselParams);
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
        // Wait for the container to be ready before publishing.
        // Even text posts can have a brief delay before FINISHED status.
        $readyError = $this->waitUntilReady($creationId, $accessToken);

        if ($readyError !== null) {
            return [
                'success' => false,
                'external_id' => null,
                'error' => $readyError,
            ];
        }

        $response = Http::post(self::API_BASE."/{$userId}/threads_publish", [
            'creation_id' => $creationId,
            'access_token' => $accessToken,
        ]);

        $body = $response->json();

        if ($response->successful() && isset($body['id'])) {
            $mediaId = (string) $body['id'];

            // Fetch permalink for proper URL format.
            $permalink = null;
            try {
                $plResp = Http::get(self::API_BASE."/{$mediaId}", [
                    'fields' => 'permalink',
                    'access_token' => $accessToken,
                ]);
                $permalink = $plResp->json('permalink');
            } catch (\Throwable $e) {
                // Non-critical — backlink just won't work.
            }

            return [
                'success' => true,
                'external_id' => $mediaId,
                'permalink' => $permalink,
                'error' => null,
            ];
        }

        $error = $body['error']['message'] ?? 'Unknown error during threads_publish';
        $code = $body['error']['code'] ?? null;

        Log::error('ThreadsAdapter: threads_publish failed', [
            'creation_id' => $creationId,
            'status' => $response->status(),
            'error' => $error,
            'body' => $body,
        ]);

        $detail = "Threads publish failed: {$error}";
        if ($code) {
            $detail .= " (code={$code})";
        }

        return [
            'success' => false,
            'external_id' => null,
            'error' => $detail,
        ];
    }

    /**
     * Wait for a container to reach FINISHED status (used for videos before publishContainer).
     *
     * @return string|null Null on success, error message on failure.
     */
    private function waitForProcessing(string $containerId, string $accessToken): ?string
    {
        $lastStatus = null;

        for ($attempt = 0; $attempt < self::VIDEO_POLL_MAX_ATTEMPTS; $attempt++) {
            sleep(self::VIDEO_POLL_INTERVAL);

            $response = Http::get(self::API_BASE."/{$containerId}", [
                'fields' => 'status,error_message',
                'access_token' => $accessToken,
            ]);

            if (! $response->successful()) {
                Log::warning('ThreadsAdapter: status check HTTP error', [
                    'container_id' => $containerId,
                    'http_status' => $response->status(),
                    'attempt' => $attempt + 1,
                ]);

                continue;
            }

            $status = $response->json('status');
            $lastStatus = $status;

            if ($status === 'FINISHED') {
                return null;
            }

            if ($status === 'ERROR' || $status === 'EXPIRED') {
                $errorDetail = $this->fetchContainerError($containerId, $accessToken, $response);

                Log::error('ThreadsAdapter: container processing failed', [
                    'container_id' => $containerId,
                    'status' => $status,
                    'error_detail' => $errorDetail,
                    'attempt' => $attempt + 1,
                ]);

                return "Threads {$status}: {$errorDetail}";
            }
        }

        $totalSeconds = self::VIDEO_POLL_MAX_ATTEMPTS * self::VIDEO_POLL_INTERVAL;

        Log::warning('ThreadsAdapter: video processing timed out', [
            'container_id' => $containerId,
            'last_status' => $lastStatus,
            'attempts' => self::VIDEO_POLL_MAX_ATTEMPTS,
            'total_seconds' => $totalSeconds,
        ]);

        return "Threads video processing timed out after {$totalSeconds}s (last status: {$lastStatus})";
    }

    /**
     * Lightweight wait before publishing — handles the brief delay even text/image containers can have.
     *
     * @return string|null Null on success, error message on failure.
     */
    private function waitUntilReady(string $containerId, string $accessToken, int $maxAttempts = 10, int $intervalSeconds = 1): ?string
    {
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $response = Http::get(self::API_BASE."/{$containerId}", [
                'fields' => 'status,error_message',
                'access_token' => $accessToken,
            ]);

            $status = $response->json('status');

            if ($status === 'FINISHED') {
                return null;
            }

            if ($status === 'ERROR') {
                $errorDetail = $this->fetchContainerError($containerId, $accessToken, $response);

                Log::error('ThreadsAdapter: container returned ERROR during ready check', [
                    'container_id' => $containerId,
                    'error_detail' => $errorDetail,
                ]);

                return "Threads container error: {$errorDetail}";
            }

            sleep($intervalSeconds);
        }

        return "Threads container not ready after {$maxAttempts}s";
    }

    /**
     * Fetch detailed error info on a failed container. Threads only exposes
     * `error_message` on the container status endpoint, and often leaves it null —
     * we re-query with all useful fields and log the raw body so the cause is never
     * lost as "UNKNOWN".
     */
    private function fetchContainerError(string $containerId, string $accessToken, \Illuminate\Http\Client\Response $initialResponse): string
    {
        $errorMessage = $initialResponse->json('error_message');

        if (! empty($errorMessage)) {
            return $errorMessage;
        }

        $detail = Http::get(self::API_BASE."/{$containerId}", [
            'fields' => 'id,status,error_message,media_type,permalink',
            'access_token' => $accessToken,
        ]);

        $errorMessage = $detail->json('error_message');
        $status = $detail->json('status') ?? 'UNKNOWN';

        Log::warning('ThreadsAdapter: raw container detail after error', [
            'container_id' => $containerId,
            'http_status' => $detail->status(),
            'body' => $detail->json(),
            'raw_body' => $detail->body(),
        ]);

        if (! empty($errorMessage)) {
            return $errorMessage;
        }

        return "status={$status} (API returned no error_message — see logs for raw body)";
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
            'http_status' => $response->status(),
            'body' => $body,
            'raw_body' => $response->body(),
            'error_type' => $body['error']['type'] ?? null,
            'error_code' => $body['error']['code'] ?? null,
            'error_subcode' => $body['error']['error_subcode'] ?? null,
            'error_message' => $body['error']['message'] ?? null,
            'fb_trace_id' => $body['error']['fbtrace_id'] ?? null,
        ]);

        return null;
    }

    private function errorFromResponse(\Illuminate\Http\Client\Response $response, string $fallback): array
    {
        $body = $response->json();
        $error = $body['error']['message'] ?? $fallback;
        $code = $body['error']['code'] ?? null;

        $detail = "Threads: {$error}";
        if ($code) {
            $detail .= " (code={$code})";
        }

        return [
            'success' => false,
            'external_id' => null,
            'error' => $detail,
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

    /**
     * Upload an image to a Facebook Page as unpublished photo to get an fbcdn.net URL.
     * Threads API (graph.threads.net) often can't fetch images from external servers
     * (Cloudflare bot protection, etc.), but can always access fbcdn.net URLs.
     *
     * @return string|null The fbcdn.net URL, or null if no Facebook page is available.
     */
    private function proxyImageViaFacebook(string $imageUrl): ?string
    {
        $fbPlatform = Platform::where('slug', 'facebook')->first();
        if (! $fbPlatform) {
            return null;
        }

        $fbAccount = SocialAccount::where('platform_id', $fbPlatform->id)->first();
        if (! $fbAccount) {
            return null;
        }

        $credentials = $fbAccount->credentials;
        $pageId = $credentials['page_id'] ?? null;
        $accessToken = $credentials['access_token'] ?? null;

        if (! $pageId || ! $accessToken) {
            return null;
        }

        try {
            // Upload as unpublished photo.
            $response = Http::post("https://graph.facebook.com/v21.0/{$pageId}/photos", [
                'url' => $imageUrl,
                'published' => 'false',
                'access_token' => $accessToken,
            ]);

            $body = $response->json();

            if (! $response->successful() || empty($body['id'])) {
                Log::warning('ThreadsAdapter: Facebook image proxy upload failed', [
                    'status' => $response->status(),
                    'body' => $body,
                ]);

                return null;
            }

            $photoId = $body['id'];

            // Fetch the source URL (fbcdn.net).
            $sourceResponse = Http::get("https://graph.facebook.com/v21.0/{$photoId}", [
                'fields' => 'images',
                'access_token' => $accessToken,
            ]);

            $sourceBody = $sourceResponse->json();
            $fbcdnUrl = $sourceBody['images'][0]['source'] ?? null;

            if ($fbcdnUrl) {
                Log::info('ThreadsAdapter: proxied image via Facebook', [
                    'photo_id' => $photoId,
                    'original_url' => $imageUrl,
                    'fbcdn_url' => $fbcdnUrl,
                ]);
            }

            return $fbcdnUrl;
        } catch (\Throwable $e) {
            Log::warning('ThreadsAdapter: Facebook image proxy error', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Resolve an image URL for Threads: proxy via Facebook if possible, otherwise use original.
     */
    private function resolveImageUrl(string $imageUrl): string
    {
        $proxied = $this->proxyImageViaFacebook($imageUrl);

        return $proxied ?? $imageUrl;
    }
}
