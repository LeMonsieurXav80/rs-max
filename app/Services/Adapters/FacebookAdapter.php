<?php

namespace App\Services\Adapters;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookAdapter implements PlatformAdapterInterface
{
    private const API_BASE = 'https://graph.facebook.com/v21.0';

    /**
     * Publish content to a Facebook Page via the Graph API.
     *
     * @param  SocialAccount  $account  Credentials: page_id, access_token.
     * @param  string  $content  The post text / message.
     * @param  array|null  $media  Optional media items (each with url, mimetype, size, title, local_path).
     * @return array{success: bool, external_id: string|null, error: string|null}
     */
    public function publish(SocialAccount $account, string $content, ?array $media = null, ?array $options = null): array
    {
        try {
            $credentials = $account->credentials;
            $pageId = $credentials['page_id'];
            $accessToken = $credentials['access_token'];
            $placeId = $options['location_id'] ?? null;

            // No media -- text-only post (optionally with a link).
            if (empty($media)) {
                return $this->publishTextPost($pageId, $accessToken, $content, $placeId);
            }

            // Single image.
            if (count($media) === 1 && $this->isImage($media[0]['mimetype'])) {
                return $this->publishSinglePhoto($pageId, $accessToken, $content, $media[0]['url'], $placeId);
            }

            // Single video.
            if (count($media) === 1 && $this->isVideo($media[0]['mimetype'])) {
                return $this->publishSingleVideo($pageId, $accessToken, $content, $media[0], $placeId);
            }

            // Multiple images -- unpublished photo upload then multi-photo post.
            $images = array_filter($media, fn ($item) => $this->isImage($item['mimetype']));

            if (count($images) === count($media)) {
                return $this->publishMultiPhoto($pageId, $accessToken, $content, $images, $placeId);
            }

            // Mixed media -- publish all images together, skip videos
            // (Facebook API does not support photos + videos in a single post).
            if (count($images) > 1) {
                return $this->publishMultiPhoto($pageId, $accessToken, $content, array_values($images), $placeId);
            }

            if (count($images) === 1) {
                return $this->publishSinglePhoto($pageId, $accessToken, $content, reset($images)['url'], $placeId);
            }

            // No images at all -- publish first video.
            return $this->publishSingleVideo($pageId, $accessToken, $content, $media[0], $placeId);

        } catch (\Throwable $e) {
            Log::error('FacebookAdapter: publish failed', [
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
     * Post a text-only (or text + link) update to the Page feed.
     */
    private function publishTextPost(string $pageId, string $accessToken, string $content, ?string $placeId = null): array
    {
        $params = [
            'message' => $content,
            'access_token' => $accessToken,
        ];

        if ($placeId) {
            $params['place'] = $placeId;
        }

        // If the content contains a URL, extract it and pass as `link` for a rich preview.
        $link = $this->extractLink($content);
        if ($link) {
            $params['link'] = $link;
        }

        $response = Http::post(self::API_BASE . "/{$pageId}/feed", $params);

        return $this->parseResponse($response);
    }

    /**
     * Publish a single photo with a message.
     */
    private function publishSinglePhoto(string $pageId, string $accessToken, string $content, string $imageUrl, ?string $placeId = null): array
    {
        $params = [
            'url' => $imageUrl,
            'message' => $content,
            'access_token' => $accessToken,
        ];

        if ($placeId) {
            $params['place'] = $placeId;
        }

        $response = Http::post(self::API_BASE . "/{$pageId}/photos", $params);

        return $this->parseResponse($response);
    }

    /**
     * Publish a single video as a Facebook Reel.
     *
     * 1. Initialize the upload session.
     * 2. Upload the video via hosted URL.
     * 3. Publish the reel.
     */
    private function publishSingleVideo(string $pageId, string $accessToken, string $content, array $mediaItem, ?string $placeId = null): array
    {
        $videoUrl = $mediaItem['url'];
        $localPath = $mediaItem['local_path'] ?? null;
        $fileSize = $mediaItem['size'] ?? null;

        // Step 1 -- initialize the upload session.
        $initResponse = Http::post(self::API_BASE . "/{$pageId}/video_reels", [
            'upload_phase' => 'start',
            'access_token' => $accessToken,
        ]);

        $initBody = $initResponse->json();

        if (! $initResponse->successful() || empty($initBody['video_id'])) {
            $error = $this->buildErrorMessage('Reel init failed', $initResponse->status(), $initBody);
            Log::error('FacebookAdapter: reel init failed', ['status' => $initResponse->status(), 'body' => $initBody]);

            return ['success' => false, 'external_id' => null, 'error' => $error];
        }

        $videoId = $initBody['video_id'];

        // Step 2 -- upload the video.
        // Try file_url first (Facebook fetches from our server), fall back to binary upload.
        $uploadResponse = Http::withHeaders([
            'Authorization' => "OAuth {$accessToken}",
            'file_url' => $videoUrl,
        ])->post("https://rupload.facebook.com/video-upload/v21.0/{$videoId}");

        $uploadBody = $uploadResponse->json();

        // If file_url fails (Facebook can't reach our server), try binary upload from local file.
        if ((! $uploadResponse->successful() || empty($uploadBody['success'])) && $localPath && file_exists($localPath)) {
            Log::info('FacebookAdapter: file_url upload failed, trying binary upload', [
                'video_id' => $videoId,
                'file_url_status' => $uploadResponse->status(),
                'file_url_error' => $uploadBody,
            ]);

            $uploadResponse = $this->uploadVideoBinary($videoId, $accessToken, $localPath, $fileSize);
            $uploadBody = $uploadResponse->json();
        }

        if (! $uploadResponse->successful() || empty($uploadBody['success'])) {
            $error = $this->buildErrorMessage('Reel upload failed', $uploadResponse->status(), $uploadBody, [
                'video_id' => $videoId,
                'video_url' => $videoUrl,
            ]);
            Log::error('FacebookAdapter: reel upload failed', [
                'video_id' => $videoId,
                'status' => $uploadResponse->status(),
                'body' => $uploadBody,
                'video_url' => $videoUrl,
            ]);

            return ['success' => false, 'external_id' => null, 'error' => $error];
        }

        // Step 3 -- publish the reel.
        $publishParams = [
            'upload_phase' => 'finish',
            'video_id' => $videoId,
            'video_state' => 'PUBLISHED',
            'description' => $content,
            'access_token' => $accessToken,
        ];

        if ($placeId) {
            $publishParams['place'] = $placeId;
        }

        $publishResponse = Http::post(self::API_BASE . "/{$pageId}/video_reels", $publishParams);

        $publishBody = $publishResponse->json();

        if ($publishResponse->successful() && isset($publishBody['success']) && $publishBody['success']) {
            return [
                'success' => true,
                'external_id' => (string) $videoId,
                'error' => null,
            ];
        }

        $error = $this->buildErrorMessage('Reel publish failed', $publishResponse->status(), $publishBody, ['video_id' => $videoId]);
        Log::error('FacebookAdapter: reel publish failed', ['video_id' => $videoId, 'status' => $publishResponse->status(), 'body' => $publishBody]);

        return ['success' => false, 'external_id' => null, 'error' => $error];
    }

    /**
     * Publish multiple photos as a single multi-photo post.
     *
     * 1. Upload each photo as unpublished.
     * 2. Create a feed post referencing all photo IDs.
     */
    private function publishMultiPhoto(string $pageId, string $accessToken, string $content, array $images, ?string $placeId = null): array
    {
        $photoIds = [];

        foreach ($images as $image) {
            $response = Http::post(self::API_BASE . "/{$pageId}/photos", [
                'url' => $image['url'],
                'published' => 'false',
                'access_token' => $accessToken,
            ]);

            $body = $response->json();

            if (! $response->successful() || empty($body['id'])) {
                $error = $this->buildErrorMessage('Photo upload failed', $response->status(), $body, ['url' => $image['url']]);

                Log::error('FacebookAdapter: unpublished photo upload failed', [
                    'url' => $image['url'],
                    'status' => $response->status(),
                    'body' => $body,
                ]);

                return [
                    'success' => false,
                    'external_id' => null,
                    'error' => $error,
                ];
            }

            $photoIds[] = $body['id'];
        }

        // Build the attached_media parameters (must use asForm so that
        // attached_media[N] keys are parsed as array elements by Facebook).
        $params = [
            'message' => $content,
            'access_token' => $accessToken,
        ];

        if ($placeId) {
            $params['place'] = $placeId;
        }

        foreach ($photoIds as $index => $photoId) {
            $params["attached_media[{$index}]"] = json_encode(['media_fbid' => $photoId]);
        }

        $response = Http::asForm()->post(self::API_BASE . "/{$pageId}/feed", $params);

        return $this->parseResponse($response);
    }

    /**
     * Parse a Facebook Graph API response into the standard return format.
     */
    private function parseResponse(\Illuminate\Http\Client\Response $response): array
    {
        $body = $response->json();

        if ($response->successful() && (isset($body['id']) || isset($body['post_id']))) {
            return [
                'success' => true,
                'external_id' => (string) ($body['post_id'] ?? $body['id']),
                'error' => null,
            ];
        }

        $error = $this->buildErrorMessage('API error', $response->status(), $body);

        Log::error('FacebookAdapter: API error', [
            'status' => $response->status(),
            'body' => $body,
        ]);

        return [
            'success' => false,
            'external_id' => null,
            'error' => $error,
        ];
    }

    /**
     * Upload video binary directly to rupload.facebook.com.
     * Fallback when Facebook can't fetch the video via file_url (503, firewall, etc.).
     */
    private function uploadVideoBinary(string $videoId, string $accessToken, string $localPath, ?int $fileSize = null): \Illuminate\Http\Client\Response
    {
        $fileSize = $fileSize ?: filesize($localPath);

        return Http::withHeaders([
            'Authorization' => "OAuth {$accessToken}",
            'offset' => '0',
            'file_size' => (string) $fileSize,
        ])->withBody(
            file_get_contents($localPath),
            'application/octet-stream'
        )->post("https://rupload.facebook.com/video-upload/v21.0/{$videoId}");
    }

    /**
     * Build a detailed, human-readable error message from an API response.
     */
    private function buildErrorMessage(string $context, int $httpStatus, ?array $body, array $extra = []): string
    {
        $parts = ["Facebook: {$context} (HTTP {$httpStatus})"];

        if (isset($body['error']['message'])) {
            $parts[] = $body['error']['message'];
            if (isset($body['error']['code'])) {
                $parts[] = "code={$body['error']['code']}";
            }
            if (isset($body['error']['error_subcode'])) {
                $parts[] = "subcode={$body['error']['error_subcode']}";
            }
        } elseif ($body) {
            // Non-standard error format -- include raw body summary
            $raw = json_encode($body, JSON_UNESCAPED_UNICODE);
            if (strlen($raw) > 300) {
                $raw = substr($raw, 0, 300) . '…';
            }
            $parts[] = $raw;
        }

        foreach ($extra as $key => $value) {
            $parts[] = "{$key}={$value}";
        }

        return implode(' | ', $parts);
    }

    /**
     * Extract the first URL found in text content.
     */
    private function extractLink(string $content): ?string
    {
        if (preg_match('/https?:\/\/[^\s]+/', $content, $matches)) {
            return $matches[0];
        }

        return null;
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
