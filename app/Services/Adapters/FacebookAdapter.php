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
     * @param  array|null  $media  Optional media items (each with url, mimetype, size, title).
     * @return array{success: bool, external_id: string|null, error: string|null}
     */
    public function publish(SocialAccount $account, string $content, ?array $media = null): array
    {
        try {
            $credentials = $account->credentials;
            $pageId = $credentials['page_id'];
            $accessToken = $credentials['access_token'];

            // No media -- text-only post (optionally with a link).
            if (empty($media)) {
                return $this->publishTextPost($pageId, $accessToken, $content);
            }

            // Single image.
            if (count($media) === 1 && $this->isImage($media[0]['mimetype'])) {
                return $this->publishSinglePhoto($pageId, $accessToken, $content, $media[0]['url']);
            }

            // Single video.
            if (count($media) === 1 && $this->isVideo($media[0]['mimetype'])) {
                return $this->publishSingleVideo($pageId, $accessToken, $content, $media[0]['url']);
            }

            // Multiple images -- unpublished photo upload then multi-photo post.
            $images = array_filter($media, fn ($item) => $this->isImage($item['mimetype']));

            if (count($images) === count($media)) {
                return $this->publishMultiPhoto($pageId, $accessToken, $content, $images);
            }

            // Fallback: mixed media -- publish each individually isn't well-supported,
            // so post the first video/photo with text.
            $first = $media[0];

            if ($this->isVideo($first['mimetype'])) {
                return $this->publishSingleVideo($pageId, $accessToken, $content, $first['url']);
            }

            return $this->publishSinglePhoto($pageId, $accessToken, $content, $first['url']);

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
    private function publishTextPost(string $pageId, string $accessToken, string $content): array
    {
        $params = [
            'message' => $content,
            'access_token' => $accessToken,
        ];

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
    private function publishSinglePhoto(string $pageId, string $accessToken, string $content, string $imageUrl): array
    {
        $response = Http::post(self::API_BASE . "/{$pageId}/photos", [
            'url' => $imageUrl,
            'message' => $content,
            'access_token' => $accessToken,
        ]);

        return $this->parseResponse($response);
    }

    /**
     * Publish a single video with a description.
     */
    private function publishSingleVideo(string $pageId, string $accessToken, string $content, string $videoUrl): array
    {
        $response = Http::post(self::API_BASE . "/{$pageId}/videos", [
            'file_url' => $videoUrl,
            'description' => $content,
            'access_token' => $accessToken,
        ]);

        return $this->parseResponse($response);
    }

    /**
     * Publish multiple photos as a single multi-photo post.
     *
     * 1. Upload each photo as unpublished.
     * 2. Create a feed post referencing all photo IDs.
     */
    private function publishMultiPhoto(string $pageId, string $accessToken, string $content, array $images): array
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
                $error = $body['error']['message'] ?? 'Failed to upload unpublished photo';

                Log::error('FacebookAdapter: unpublished photo upload failed', [
                    'url' => $image['url'],
                    'error' => $error,
                ]);

                return [
                    'success' => false,
                    'external_id' => null,
                    'error' => $error,
                ];
            }

            $photoIds[] = $body['id'];
        }

        // Build the attached_media parameters.
        $params = [
            'message' => $content,
            'access_token' => $accessToken,
        ];

        foreach ($photoIds as $index => $photoId) {
            $params["attached_media[{$index}]"] = json_encode(['media_fbid' => $photoId]);
        }

        $response = Http::post(self::API_BASE . "/{$pageId}/feed", $params);

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

        $error = $body['error']['message'] ?? 'Unknown Facebook API error';

        Log::error('FacebookAdapter: API error', [
            'status' => $response->status(),
            'error' => $error,
        ]);

        return [
            'success' => false,
            'external_id' => null,
            'error' => $error,
        ];
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
