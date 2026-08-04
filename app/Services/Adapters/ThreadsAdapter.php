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

    // Threads' carousel/container endpoints are intermittently flaky: they return
    // HTTP 5xx with a generic `code=1` "An unknown error occurred", reject a
    // freshly-created carousel child as expired (subcode 4279004), or lose the
    // parent container between assembly and publish (subcode 4279009,
    // "resource does not exist" / « contenu multimédia introuvable »). We retry
    // those by rebuilding the whole carousel from scratch.
    private const TRANSIENT_MAX_ATTEMPTS = 3;

    private const TRANSIENT_BACKOFF = 3;

    private const CAROUSEL_ASSEMBLY_MAX_ATTEMPTS = 3;

    private const INVALID_CHILDREN_SUBCODE = 4279004;

    private const EXPIRED_RESOURCE_SUBCODE = 4279009;

    // Renvoyé par Graph (code=100) quand l'objet interrogé n'existe pas/plus.
    private const MISSING_OBJECT_SUBCODE = 33;

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

            // Le parent a-t-il survecu ? Threads accepte parfois un post (id + permalink
            // renvoyes, quota consomme) puis le supprime dans la minute. Sans ce controle
            // la reponse echoue plus loin en code=24 / 4279009 en designant SON conteneur,
            // ce qui masque completement la vraie cause.
            if (! $this->mediaExists($replyToId, $accessToken)) {
                return $this->parentMissingError($replyToId);
            }

            // Multiple media reply — carousel (chained to the previous post).
            if (! empty($media) && count($media) > 1) {
                return $this->qualifyReplyFailure(
                    $this->publishCarousel($userId, $accessToken, $content, $media, $options, $replyToId),
                    $replyToId,
                    $accessToken,
                );
            }

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

            $container = $this->postWithRetry(self::API_BASE."/{$userId}/threads", $params);
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

            return $this->qualifyReplyFailure(
                $this->publishContainer($userId, $accessToken, $containerId),
                $replyToId,
                $accessToken,
            );

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

        $container = $this->postWithRetry(self::API_BASE."/{$userId}/threads", $params);
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

        $container = $this->postWithRetry(self::API_BASE."/{$userId}/threads", $params);
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

        $container = $this->postWithRetry(self::API_BASE."/{$userId}/threads", $params);
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

    private function publishCarousel(string $userId, string $accessToken, string $text, array $media, ?array $options, ?string $replyToId = null): array
    {
        // Threads sometimes invalidates a carousel child between its creation and the
        // parent assembly (subcode 4279004, "children invalid/expired"). We rebuild the
        // children and retry the assembly a bounded number of times before giving up.
        $lastError = null;

        for ($attempt = 1; $attempt <= self::CAROUSEL_ASSEMBLY_MAX_ATTEMPTS; $attempt++) {
            $childIds = [];
            $childError = null;

            foreach ($media as $item) {
                $child = $this->createCarouselChild($userId, $accessToken, $item);

                if (! $child['success']) {
                    $childError = $child;
                    break;
                }

                $childIds[] = $child['child_id'];
            }

            if ($childError !== null) {
                return $childError;
            }

            // Re-verify every child is still FINISHED immediately before assembling the
            // parent — this shrinks the window in which a child can silently expire.
            $staleChild = false;
            foreach ($childIds as $childId) {
                if ($this->waitUntilReady($childId, $accessToken, 5, 1) !== null) {
                    $staleChild = true;
                    break;
                }
            }

            if ($staleChild) {
                Log::warning('ThreadsAdapter: carousel child not ready at assembly time', [
                    'attempt' => $attempt,
                    'child_ids' => $childIds,
                ]);
                $lastError = [
                    'success' => false,
                    'external_id' => null,
                    'error' => 'Threads carousel: child containers not ready for assembly',
                ];

                continue;
            }

            // Create the carousel container.
            $carouselParams = [
                'media_type' => 'CAROUSEL',
                'children' => implode(',', $childIds),
                'text' => $text,
                'access_token' => $accessToken,
            ];

            // Reply carousel: chain the container to the previous post.
            if ($replyToId !== null) {
                $carouselParams['reply_to_id'] = $replyToId;
            }

            $this->addLocationParams($carouselParams, $options);

            $carouselResponse = $this->postWithRetry(self::API_BASE."/{$userId}/threads", $carouselParams);
            $carouselId = $this->extractId($carouselResponse, 'carousel container creation');

            if ($carouselId !== null) {
                $publishResult = $this->publishContainer($userId, $accessToken, $carouselId);

                // Threads can also lose the parent container between assembly and
                // finalize (subcode 4279009). Rebuild the whole carousel and retry.
                if ($publishResult['success'] || empty($publishResult['retryable_carousel']) || $attempt >= self::CAROUSEL_ASSEMBLY_MAX_ATTEMPTS) {
                    return $publishResult;
                }

                Log::warning('ThreadsAdapter: carousel container vanished at publish, rebuilding', [
                    'attempt' => $attempt,
                    'carousel_id' => $carouselId,
                ]);
                $lastError = $publishResult;

                continue;
            }

            $lastError = $this->errorFromResponse($carouselResponse, 'Failed to create carousel container');

            // If Threads rejected the children as invalid/expired, rebuild them and retry.
            if ($this->isInvalidChildrenError($carouselResponse) && $attempt < self::CAROUSEL_ASSEMBLY_MAX_ATTEMPTS) {
                Log::warning('ThreadsAdapter: carousel children invalid/expired, rebuilding', [
                    'attempt' => $attempt,
                    'child_ids' => $childIds,
                ]);

                continue;
            }

            return $lastError;
        }

        return $lastError ?? [
            'success' => false,
            'external_id' => null,
            'error' => 'Threads carousel: failed after retries',
        ];
    }

    /**
     * Create a single carousel item container and wait until it is FINISHED.
     *
     * @return array{success: true, child_id: string}|array{success: false, external_id: null, error: string}
     */
    private function createCarouselChild(string $userId, string $accessToken, array $item): array
    {
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

        $response = $this->postWithRetry(self::API_BASE."/{$userId}/threads", $params);
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

        return ['success' => true, 'child_id' => $childId];
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

        $response = $this->postWithRetry(self::API_BASE."/{$userId}/threads_publish", [
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
        $subcode = (int) ($body['error']['error_subcode'] ?? 0);

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
            'error_subcode' => $subcode,
            // Signals publishCarousel() to rebuild the carousel from scratch: the
            // parent/children vanished or expired between assembly and finalize.
            'retryable_carousel' => in_array($subcode, [self::INVALID_CHILDREN_SUBCODE, self::EXPIRED_RESOURCE_SUBCODE], true),
        ];
    }

    /**
     * Verifie qu'un media Threads existe encore.
     *
     * Fail-open volontaire : sur erreur reseau ou erreur inattendue on renvoie true,
     * pour ne jamais bloquer une publication valide a cause d'une lecture ratee. Seul
     * un « n'existe pas » explicite de Graph fait renvoyer false.
     */
    private function mediaExists(string $mediaId, string $accessToken): bool
    {
        try {
            $response = Http::get(self::API_BASE."/{$mediaId}", [
                'fields' => 'id',
                'access_token' => $accessToken,
            ]);
        } catch (\Throwable $e) {
            return true;
        }

        if ($response->successful()) {
            return true;
        }

        $code = (int) ($response->json('error.code') ?? 0);
        $subcode = (int) ($response->json('error.error_subcode') ?? 0);

        return ! (($code === 100 && $subcode === self::MISSING_OBJECT_SUBCODE) || $code === 24);
    }

    /**
     * Requalifie l'echec d'une reponse : si Threads renvoie « ressource introuvable »
     * (4279004 / 4279009) alors que c'est en fait le post parent qui a disparu, on
     * remplace l'erreur brute — qui designe le conteneur de la reponse — par la cause
     * reelle. Sans ca l'utilisateur voit « Contenu multimedia introuvable » sur un
     * conteneur pourtant FINISHED.
     */
    private function qualifyReplyFailure(array $result, string $replyToId, string $accessToken): array
    {
        if ($result['success'] ?? false) {
            return $result;
        }

        $subcode = (int) ($result['error_subcode'] ?? 0);

        if (! in_array($subcode, [self::INVALID_CHILDREN_SUBCODE, self::EXPIRED_RESOURCE_SUBCODE], true)) {
            return $result;
        }

        if ($this->mediaExists($replyToId, $accessToken)) {
            return $result;
        }

        return $this->parentMissingError($replyToId);
    }

    private function parentMissingError(string $replyToId): array
    {
        Log::error('ThreadsAdapter: parent post missing', [
            'reply_to_id' => $replyToId,
        ]);

        return [
            'success' => false,
            'external_id' => null,
            'error' => "Threads : le post precedent du fil (id {$replyToId}) n'existe plus — il a ete accepte a la publication puis supprime cote Meta. Le fil ne peut pas etre poursuivi.",
            'parent_missing' => true,
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
            'error_subcode' => (int) ($body['error']['error_subcode'] ?? 0),
        ];
    }

    /**
     * POST to the Threads API, retrying transient failures (HTTP 5xx or the generic
     * `code=1` "unknown error") with a linear backoff. Non-transient errors and
     * successes are returned as-is on the first response.
     */
    private function postWithRetry(string $url, array $params): \Illuminate\Http\Client\Response
    {
        $response = Http::post($url, $params);

        for ($attempt = 1; $attempt < self::TRANSIENT_MAX_ATTEMPTS && $this->isTransientError($response); $attempt++) {
            Log::warning('ThreadsAdapter: transient error, retrying', [
                'url' => $url,
                'attempt' => $attempt,
                'http_status' => $response->status(),
                'error_code' => $response->json('error.code'),
                'error_message' => $response->json('error.message'),
            ]);

            sleep(self::TRANSIENT_BACKOFF * $attempt);

            $response = Http::post($url, $params);
        }

        return $response;
    }

    private function isTransientError(\Illuminate\Http\Client\Response $response): bool
    {
        if ($response->successful()) {
            return false;
        }

        // HTTP 5xx from Threads is always worth retrying.
        if ($response->serverError()) {
            return true;
        }

        // Threads surfaces intermittent glitches as a generic `code=1`.
        return (int) $response->json('error.code') === 1;
    }

    private function isInvalidChildrenError(\Illuminate\Http\Client\Response $response): bool
    {
        return (int) $response->json('error.error_subcode') === self::INVALID_CHILDREN_SUBCODE;
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
