<?php

namespace App\Services\Adapters;

use App\Http\Controllers\LinkedInOAuthController;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LinkedInAdapter implements PlatformAdapterInterface
{
    private const API_BASE = 'https://api.linkedin.com';
    private const API_VERSION = '202402';

    public function publish(SocialAccount $account, string $content, ?array $media = null, ?array $options = null): array
    {
        try {
            $credentials = $account->credentials;
            $accessToken = $this->getValidToken($account);
            $authorUrn = $credentials['person_urn'];

            if (empty($media)) {
                return $this->publishTextPost($authorUrn, $accessToken, $content);
            }

            // Single image
            if (count($media) === 1 && $this->isImage($media[0]['mimetype'])) {
                return $this->publishImagePost($authorUrn, $accessToken, $content, $media[0]);
            }

            // Single video
            if (count($media) === 1 && $this->isVideo($media[0]['mimetype'])) {
                return $this->publishVideoPost($authorUrn, $accessToken, $content, $media[0]);
            }

            // Multiple images
            $images = array_filter($media, fn ($item) => $this->isImage($item['mimetype']));
            if (count($images) > 1) {
                return $this->publishMultiImagePost($authorUrn, $accessToken, $content, array_values($images));
            }

            // Fallback: first media item
            if (! empty($images)) {
                return $this->publishImagePost($authorUrn, $accessToken, $content, reset($images));
            }

            return $this->publishVideoPost($authorUrn, $accessToken, $content, $media[0]);
        } catch (\Throwable $e) {
            Log::error('LinkedInAdapter: publish failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'external_id' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Text-only post (or text + article link).
     */
    private function publishTextPost(string $authorUrn, string $accessToken, string $content): array
    {
        $body = [
            'author' => $authorUrn,
            'lifecycleState' => 'PUBLISHED',
            'visibility' => 'PUBLIC',
            'commentary' => $content,
            'distribution' => [
                'feedDistribution' => 'MAIN_FEED',
            ],
        ];

        // If content contains a URL, add it as an article
        $link = $this->extractLink($content);
        if ($link) {
            $body['content'] = [
                'article' => [
                    'source' => $link,
                ],
            ];
        }

        return $this->createPost($accessToken, $body);
    }

    /**
     * Single image post using the new images API.
     */
    private function publishImagePost(string $authorUrn, string $accessToken, string $content, array $mediaItem): array
    {
        // Step 1: Initialize upload
        $initResponse = Http::withHeaders($this->headers($accessToken))
            ->post(self::API_BASE . '/rest/images', [
                'initializeUploadRequest' => [
                    'owner' => $authorUrn,
                ],
            ]);

        if ($initResponse->failed()) {
            return $this->errorResult('Image upload init failed: ' . $initResponse->body());
        }

        $initData = $initResponse->json('value') ?? $initResponse->json();
        $uploadUrl = $initData['uploadUrl'] ?? $initData['initializeUploadRequest']['uploadUrl'] ?? null;
        $imageUrn = $initData['image'] ?? $initData['initializeUploadRequest']['image'] ?? null;

        if (! $uploadUrl || ! $imageUrn) {
            return $this->errorResult('Missing uploadUrl or image URN from init response');
        }

        // Step 2: Upload the image binary
        $imageData = $this->fetchMediaContent($mediaItem);
        if (! $imageData) {
            return $this->errorResult('Failed to download image from URL');
        }

        $uploadResponse = Http::withHeaders([
            'Authorization' => "Bearer {$accessToken}",
            'Content-Type' => 'application/octet-stream',
        ])->withBody($imageData, 'application/octet-stream')
            ->put($uploadUrl);

        if ($uploadResponse->failed()) {
            return $this->errorResult('Image upload failed: ' . $uploadResponse->body());
        }

        // Step 3: Create the post with the uploaded image
        $body = [
            'author' => $authorUrn,
            'lifecycleState' => 'PUBLISHED',
            'visibility' => 'PUBLIC',
            'commentary' => $content,
            'distribution' => [
                'feedDistribution' => 'MAIN_FEED',
            ],
            'content' => [
                'media' => [
                    'id' => $imageUrn,
                ],
            ],
        ];

        return $this->createPost($accessToken, $body);
    }

    /**
     * Single video post using the new videos API.
     */
    private function publishVideoPost(string $authorUrn, string $accessToken, string $content, array $mediaItem): array
    {
        // Step 1: Initialize video upload
        $fileSize = $mediaItem['size'] ?? 0;
        if (! $fileSize) {
            // Try to get the file size from the URL
            $videoData = $this->fetchMediaContent($mediaItem);
            if (! $videoData) {
                return $this->errorResult('Failed to download video');
            }
            $fileSize = strlen($videoData);
        }

        $initResponse = Http::withHeaders($this->headers($accessToken))
            ->post(self::API_BASE . '/rest/videos', [
                'initializeUploadRequest' => [
                    'owner' => $authorUrn,
                    'fileSizeBytes' => $fileSize,
                ],
            ]);

        if ($initResponse->failed()) {
            return $this->errorResult('Video upload init failed: ' . $initResponse->body());
        }

        $initData = $initResponse->json('value') ?? $initResponse->json();
        $uploadUrl = $initData['uploadInstructions'][0]['uploadUrl'] ?? null;
        $videoUrn = $initData['video'] ?? null;

        if (! $uploadUrl || ! $videoUrn) {
            return $this->errorResult('Missing uploadUrl or video URN from init response');
        }

        // Step 2: Upload the video binary
        if (! isset($videoData)) {
            $videoData = $this->fetchMediaContent($mediaItem);
            if (! $videoData) {
                return $this->errorResult('Failed to download video from URL');
            }
        }

        $uploadResponse = Http::withHeaders([
            'Authorization' => "Bearer {$accessToken}",
            'Content-Type' => 'application/octet-stream',
        ])->timeout(300)
            ->withBody($videoData, 'application/octet-stream')
            ->put($uploadUrl);

        if ($uploadResponse->failed()) {
            return $this->errorResult('Video upload failed: ' . $uploadResponse->body());
        }

        // Step 3: Finalize video upload
        Http::withHeaders($this->headers($accessToken))
            ->post(self::API_BASE . '/rest/videos', [
                'finalizeUploadRequest' => [
                    'video' => $videoUrn,
                ],
            ]);

        // Step 4: Wait for video processing (poll status)
        $ready = $this->waitForVideoProcessing($accessToken, $videoUrn);
        if (! $ready) {
            Log::warning('LinkedInAdapter: video processing timeout, attempting post anyway', ['video' => $videoUrn]);
        }

        // Step 5: Create the post
        $body = [
            'author' => $authorUrn,
            'lifecycleState' => 'PUBLISHED',
            'visibility' => 'PUBLIC',
            'commentary' => $content,
            'distribution' => [
                'feedDistribution' => 'MAIN_FEED',
            ],
            'content' => [
                'media' => [
                    'id' => $videoUrn,
                ],
            ],
        ];

        return $this->createPost($accessToken, $body);
    }

    /**
     * Multi-image post.
     */
    private function publishMultiImagePost(string $authorUrn, string $accessToken, string $content, array $images): array
    {
        $imageUrns = [];

        foreach ($images as $mediaItem) {
            // Initialize upload
            $initResponse = Http::withHeaders($this->headers($accessToken))
                ->post(self::API_BASE . '/rest/images', [
                    'initializeUploadRequest' => [
                        'owner' => $authorUrn,
                    ],
                ]);

            if ($initResponse->failed()) {
                return $this->errorResult('Multi-image init failed: ' . $initResponse->body());
            }

            $initData = $initResponse->json('value') ?? $initResponse->json();
            $uploadUrl = $initData['uploadUrl'] ?? $initData['initializeUploadRequest']['uploadUrl'] ?? null;
            $imageUrn = $initData['image'] ?? $initData['initializeUploadRequest']['image'] ?? null;

            if (! $uploadUrl || ! $imageUrn) {
                return $this->errorResult('Missing uploadUrl or image URN');
            }

            // Upload binary
            $imageData = $this->fetchMediaContent($mediaItem);
            if (! $imageData) {
                return $this->errorResult('Failed to download image: ' . ($mediaItem['url'] ?? 'unknown'));
            }

            $uploadResponse = Http::withHeaders([
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/octet-stream',
            ])->withBody($imageData, 'application/octet-stream')
                ->put($uploadUrl);

            if ($uploadResponse->failed()) {
                return $this->errorResult('Image upload failed: ' . $uploadResponse->body());
            }

            $imageUrns[] = $imageUrn;
        }

        // Create multi-image post
        $body = [
            'author' => $authorUrn,
            'lifecycleState' => 'PUBLISHED',
            'visibility' => 'PUBLIC',
            'commentary' => $content,
            'distribution' => [
                'feedDistribution' => 'MAIN_FEED',
            ],
            'content' => [
                'multiImage' => [
                    'images' => array_map(fn ($urn) => ['id' => $urn], $imageUrns),
                ],
            ],
        ];

        return $this->createPost($accessToken, $body);
    }

    // ─── Shared helpers ──────────────────────────────────────────

    /**
     * Create a post via the LinkedIn Posts API.
     */
    private function createPost(string $accessToken, array $body): array
    {
        $response = Http::withHeaders($this->headers($accessToken))
            ->post(self::API_BASE . '/rest/posts', $body);

        if ($response->successful()) {
            // LinkedIn returns the post URN in the x-restli-id header
            $postUrn = $response->header('x-restli-id') ?? $response->header('X-RestLi-Id');

            return [
                'success' => true,
                'external_id' => $postUrn,
                'error' => null,
            ];
        }

        $error = $response->json('message') ?? $response->body();
        Log::error('LinkedInAdapter: post creation failed', [
            'status' => $response->status(),
            'error' => $error,
            'body' => $body,
        ]);

        return ['success' => false, 'external_id' => null, 'error' => $error];
    }

    /**
     * Get a valid access token, refreshing if expired.
     */
    private function getValidToken(SocialAccount $account): string
    {
        $credentials = $account->credentials;
        $expiresAt = $credentials['token_expires_at'] ?? null;

        // If token expires within 24 hours, try to refresh
        if ($expiresAt && now()->gt(now()->parse($expiresAt)->subDay())) {
            $newToken = LinkedInOAuthController::refreshAccessToken($account);
            if ($newToken) {
                return $newToken;
            }
        }

        return $credentials['access_token'];
    }

    private function headers(string $accessToken): array
    {
        return [
            'Authorization' => "Bearer {$accessToken}",
            'LinkedIn-Version' => self::API_VERSION,
            'X-Restli-Protocol-Version' => '2.0.0',
            'Content-Type' => 'application/json',
        ];
    }

    private function fetchMediaContent(array $mediaItem): ?string
    {
        // Use local_path if available (faster, no network call)
        if (! empty($mediaItem['local_path']) && file_exists($mediaItem['local_path'])) {
            return file_get_contents($mediaItem['local_path']);
        }

        $response = Http::timeout(120)->get($mediaItem['url']);

        return $response->successful() ? $response->body() : null;
    }

    private function waitForVideoProcessing(string $accessToken, string $videoUrn, int $maxAttempts = 30): bool
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            sleep(5);

            $response = Http::withHeaders($this->headers($accessToken))
                ->get(self::API_BASE . '/rest/videos/' . urlencode($videoUrn));

            if ($response->failed()) {
                continue;
            }

            $status = $response->json('status') ?? '';
            if ($status === 'AVAILABLE') {
                return true;
            }
            if (in_array($status, ['PROCESSING_FAILED', 'WAITING_UPLOAD'])) {
                return false;
            }
        }

        return false;
    }

    private function extractLink(string $content): ?string
    {
        if (preg_match('/https?:\/\/[^\s]+/', $content, $matches)) {
            return $matches[0];
        }

        return null;
    }

    private function isImage(string $mimetype): bool
    {
        return str_starts_with($mimetype, 'image/');
    }

    private function isVideo(string $mimetype): bool
    {
        return str_starts_with($mimetype, 'video/');
    }

    private function errorResult(string $message): array
    {
        Log::error("LinkedInAdapter: {$message}");

        return ['success' => false, 'external_id' => null, 'error' => $message];
    }
}
