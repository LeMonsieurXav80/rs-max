<?php

namespace App\Services\Adapters;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BlueskyAdapter implements PlatformAdapterInterface, ThreadableAdapterInterface
{
    private const PDS_BASE = 'https://bsky.social';

    private const PUBLIC_API = 'https://public.api.bsky.app';

    /** Root post reference kept for thread chaining. */
    private ?string $rootUri = null;

    private ?string $rootCid = null;

    // ─── PlatformAdapterInterface ────────────────────────────────────

    public function publish(SocialAccount $account, string $content, ?array $media = null, ?array $options = null): array
    {
        try {
            $auth = $this->ensureAuthenticated($account);
            if (! $auth) {
                return $this->error('Impossible de s\'authentifier sur Bluesky.');
            }

            $did = $auth['did'];
            $jwt = $auth['accessJwt'];

            $record = $this->buildRecord($content);

            // Attach media embed, or external link card if no media.
            if (! empty($media)) {
                $embed = $this->buildMediaEmbed($jwt, $media);
                if ($embed) {
                    $record['embed'] = $embed;
                }
            } else {
                $externalEmbed = $this->buildExternalEmbed($content, $jwt);
                if ($externalEmbed) {
                    $record['embed'] = $externalEmbed;
                }
            }

            return $this->createRecord($did, $jwt, $record);

        } catch (\Throwable $e) {
            Log::error('BlueskyAdapter: publish failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error($e->getMessage());
        }
    }

    // ─── ThreadableAdapterInterface ──────────────────────────────────

    public function publishReply(SocialAccount $account, string $content, string $replyToId, ?array $media = null, ?array $options = null): array
    {
        try {
            $auth = $this->ensureAuthenticated($account);
            if (! $auth) {
                return $this->error('Impossible de s\'authentifier sur Bluesky.');
            }

            $did = $auth['did'];
            $jwt = $auth['accessJwt'];

            [$parentUri, $parentCid] = $this->parseExternalId($replyToId);

            // First reply in a thread — the parent IS the root.
            if (! $this->rootUri) {
                $this->rootUri = $parentUri;
                $this->rootCid = $parentCid;
            }

            $record = $this->buildRecord($content);
            $record['reply'] = [
                'root' => [
                    'uri' => $this->rootUri,
                    'cid' => $this->rootCid,
                ],
                'parent' => [
                    'uri' => $parentUri,
                    'cid' => $parentCid,
                ],
            ];

            if (! empty($media)) {
                $embed = $this->buildMediaEmbed($jwt, $media);
                if ($embed) {
                    $record['embed'] = $embed;
                }
            } else {
                $externalEmbed = $this->buildExternalEmbed($content, $jwt);
                if ($externalEmbed) {
                    $record['embed'] = $externalEmbed;
                }
            }

            return $this->createRecord($did, $jwt, $record);

        } catch (\Throwable $e) {
            Log::error('BlueskyAdapter: publishReply failed', [
                'account_id' => $account->id,
                'reply_to' => $replyToId,
                'error' => $e->getMessage(),
            ]);

            return $this->error($e->getMessage());
        }
    }

    // ─── Authentication ──────────────────────────────────────────────

    /**
     * Ensure we have a valid accessJwt. Refresh or re-login as needed.
     * Returns ['did' => ..., 'accessJwt' => ...] or null on failure.
     */
    private function ensureAuthenticated(SocialAccount $account): ?array
    {
        $credentials = $account->credentials;
        $accessJwt = $credentials['access_jwt'] ?? null;
        $refreshJwt = $credentials['refresh_jwt'] ?? null;

        // Try existing access token.
        if ($accessJwt && ! $this->isJwtExpired($accessJwt)) {
            return [
                'did' => $credentials['did'],
                'accessJwt' => $accessJwt,
            ];
        }

        // Try refreshing.
        if ($refreshJwt) {
            $refreshed = $this->refreshSession($refreshJwt);
            if ($refreshed) {
                $account->update([
                    'credentials' => array_merge($credentials, [
                        'access_jwt' => $refreshed['accessJwt'],
                        'refresh_jwt' => $refreshed['refreshJwt'],
                        'did' => $refreshed['did'],
                    ]),
                ]);

                return [
                    'did' => $refreshed['did'],
                    'accessJwt' => $refreshed['accessJwt'],
                ];
            }
        }

        // Full re-login with app password.
        $session = $this->createSession(
            $credentials['handle'],
            $credentials['app_password']
        );

        if (! $session) {
            return null;
        }

        $account->update([
            'credentials' => array_merge($credentials, [
                'did' => $session['did'],
                'access_jwt' => $session['accessJwt'],
                'refresh_jwt' => $session['refreshJwt'],
            ]),
        ]);

        return [
            'did' => $session['did'],
            'accessJwt' => $session['accessJwt'],
        ];
    }

    private function createSession(string $identifier, string $password): ?array
    {
        $response = Http::post(self::PDS_BASE . '/xrpc/com.atproto.server.createSession', [
            'identifier' => $identifier,
            'password' => $password,
        ]);

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('BlueskyAdapter: createSession failed', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        return null;
    }

    private function refreshSession(string $refreshJwt): ?array
    {
        $response = Http::withToken($refreshJwt)
            ->post(self::PDS_BASE . '/xrpc/com.atproto.server.refreshSession');

        if ($response->successful()) {
            return $response->json();
        }

        Log::warning('BlueskyAdapter: refreshSession failed, will re-login', [
            'status' => $response->status(),
        ]);

        return null;
    }

    private function isJwtExpired(string $jwt): bool
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return true;
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (! $payload || ! isset($payload['exp'])) {
            return true;
        }

        // Consider expired if within 5 minutes of expiry.
        return $payload['exp'] < (time() + 300);
    }

    // ─── Record creation ─────────────────────────────────────────────

    private function buildRecord(string $text): array
    {
        $record = [
            '$type' => 'app.bsky.feed.post',
            'text' => $text,
            'createdAt' => now()->toIso8601ZuluString(),
            'langs' => ['fr'],
        ];

        $facets = $this->detectFacets($text);
        if (! empty($facets)) {
            $record['facets'] = $facets;
        }

        return $record;
    }

    private function createRecord(string $did, string $accessJwt, array $record): array
    {
        $response = Http::withToken($accessJwt)
            ->post(self::PDS_BASE . '/xrpc/com.atproto.repo.createRecord', [
                'repo' => $did,
                'collection' => 'app.bsky.feed.post',
                'record' => $record,
            ]);

        $body = $response->json();

        if ($response->successful() && isset($body['uri'], $body['cid'])) {
            $externalId = $body['uri'] . '|' . $body['cid'];

            return [
                'success' => true,
                'external_id' => $externalId,
                'error' => null,
            ];
        }

        $error = $body['message'] ?? $body['error'] ?? 'Unknown Bluesky error';

        Log::error('BlueskyAdapter: createRecord failed', [
            'status' => $response->status(),
            'error' => $error,
            'body' => $body,
        ]);

        return $this->error($error);
    }

    // ─── Media handling ──────────────────────────────────────────────

    private function buildMediaEmbed(string $accessJwt, array $media): ?array
    {
        $images = [];
        $video = null;

        foreach ($media as $item) {
            $mimetype = $item['mimetype'] ?? 'image/jpeg';

            if (str_starts_with($mimetype, 'video/')) {
                $video = $item;
            } else {
                $images[] = $item;
            }
        }

        // Video takes priority (1 per post, cannot combine with images on Bluesky).
        if ($video) {
            return $this->buildVideoEmbed($accessJwt, $video);
        }

        if (! empty($images)) {
            return $this->buildImagesEmbed($accessJwt, array_slice($images, 0, 4));
        }

        return null;
    }

    private function buildImagesEmbed(string $accessJwt, array $images): ?array
    {
        $embedImages = [];

        foreach ($images as $item) {
            $url = $item['url'];
            $tempFile = tempnam(sys_get_temp_dir(), 'bsky_img_');

            try {
                $downloaded = Http::withOptions(['sink' => $tempFile])->get($url);
                if (! $downloaded->successful()) {
                    Log::warning('BlueskyAdapter: failed to download image', ['url' => $url]);

                    continue;
                }

                // Bluesky max image size is 1MB — compress if needed.
                if (filesize($tempFile) > 1_000_000) {
                    $tempFile = $this->compressImageForBluesky($tempFile);
                    if (! $tempFile) {
                        continue;
                    }
                }

                $mimeType = mime_content_type($tempFile) ?: 'image/jpeg';
                $blob = $this->uploadBlob($accessJwt, $tempFile, $mimeType);

                if ($blob) {
                    $embedImages[] = [
                        'alt' => $item['title'] ?? '',
                        'image' => $blob,
                    ];
                }
            } finally {
                if (file_exists($tempFile)) {
                    @unlink($tempFile);
                }
            }
        }

        if (empty($embedImages)) {
            return null;
        }

        return [
            '$type' => 'app.bsky.embed.images',
            'images' => $embedImages,
        ];
    }

    private function buildVideoEmbed(string $accessJwt, array $video): ?array
    {
        $url = $video['url'];
        $tempFile = tempnam(sys_get_temp_dir(), 'bsky_vid_');

        try {
            $downloaded = Http::timeout(120)->withOptions(['sink' => $tempFile])->get($url);
            if (! $downloaded->successful()) {
                Log::warning('BlueskyAdapter: failed to download video', ['url' => $url]);

                return null;
            }

            $mimeType = mime_content_type($tempFile) ?: 'video/mp4';
            $blob = $this->uploadBlob($accessJwt, $tempFile, $mimeType);

            if (! $blob) {
                return null;
            }

            return [
                '$type' => 'app.bsky.embed.video',
                'video' => $blob,
            ];
        } finally {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    private function uploadBlob(string $accessJwt, string $filePath, string $mimeType): ?array
    {
        $fileContents = file_get_contents($filePath);

        $response = Http::withToken($accessJwt)
            ->withHeaders(['Content-Type' => $mimeType])
            ->withBody($fileContents, $mimeType)
            ->post(self::PDS_BASE . '/xrpc/com.atproto.repo.uploadBlob');

        if ($response->successful() && $response->json('blob')) {
            return $response->json('blob');
        }

        Log::error('BlueskyAdapter: uploadBlob failed', [
            'status' => $response->status(),
            'body' => $response->body(),
            'size' => strlen($fileContents),
        ]);

        return null;
    }

    /**
     * Compress an image to fit under Bluesky's 1MB limit.
     */
    private function compressImageForBluesky(string $filePath): ?string
    {
        $mimeType = mime_content_type($filePath);
        $image = match ($mimeType) {
            'image/png' => imagecreatefrompng($filePath),
            'image/gif' => imagecreatefromgif($filePath),
            'image/webp' => imagecreatefromwebp($filePath),
            default => imagecreatefromjpeg($filePath),
        };

        if (! $image) {
            return null;
        }

        // Resize if very large.
        $w = imagesx($image);
        $h = imagesy($image);
        $maxDim = 2048;

        if ($w > $maxDim || $h > $maxDim) {
            if ($w >= $h) {
                $newW = $maxDim;
                $newH = (int) round($h * ($maxDim / $w));
            } else {
                $newH = $maxDim;
                $newW = (int) round($w * ($maxDim / $h));
            }
            $resized = imagecreatetruecolor($newW, $newH);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newW, $newH, $w, $h);
            imagedestroy($image);
            $image = $resized;
        }

        // Iteratively reduce quality to get under 1MB.
        $outputPath = tempnam(sys_get_temp_dir(), 'bsky_cmp_') . '.jpg';
        for ($quality = 80; $quality >= 30; $quality -= 10) {
            imagejpeg($image, $outputPath, $quality);
            if (filesize($outputPath) <= 1_000_000) {
                imagedestroy($image);
                @unlink($filePath);

                return $outputPath;
            }
        }

        imagedestroy($image);
        @unlink($outputPath);

        return null;
    }

    // ─── External link card embed ────────────────────────────────────

    /**
     * Build an app.bsky.embed.external card by fetching OG meta from the first URL in text.
     * Returns null if no URL found or fetch fails — publishing continues without card.
     */
    private function buildExternalEmbed(string $text, string $accessJwt): ?array
    {
        // Find first URL in text (with or without protocol)
        if (! preg_match('/(https?:\/\/[^\s\]\)]+|www\.[^\s\]\)]+)/u', $text, $match)) {
            return null;
        }

        $url = $match[0];
        if (! str_starts_with($url, 'http')) {
            $url = 'https://' . $url;
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; RSMax/1.0)'])
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            $html = $response->body();

            // Parse OG meta tags
            $title = $this->extractMeta($html, 'og:title')
                  ?? $this->extractHtmlTitle($html)
                  ?? $url;
            $description = $this->extractMeta($html, 'og:description')
                        ?? $this->extractMeta($html, 'description')
                        ?? '';
            $imageUrl = $this->extractMeta($html, 'og:image');

            $external = [
                '$type' => 'app.bsky.embed.external',
                'external' => [
                    'uri' => $url,
                    'title' => mb_substr($title, 0, 300),
                    'description' => mb_substr($description, 0, 1000),
                ],
            ];

            // Upload OG image as thumbnail blob
            if ($imageUrl) {
                if (! str_starts_with($imageUrl, 'http')) {
                    $parsed = parse_url($url);
                    $imageUrl = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '') . $imageUrl;
                }

                $thumbBlob = $this->uploadBlobFromUrl($accessJwt, $imageUrl);
                if ($thumbBlob) {
                    $external['external']['thumb'] = $thumbBlob;
                }
            }

            return $external;

        } catch (\Throwable $e) {
            Log::debug('BlueskyAdapter: buildExternalEmbed failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function extractMeta(string $html, string $property): ?string
    {
        // Try og: property
        if (preg_match('/<meta[^>]+property=["\']' . preg_quote($property, '/') . '["\'][^>]+content=["\']([^"\']*)["\']/', $html, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }
        // Try name= (for description)
        if (preg_match('/<meta[^>]+name=["\']' . preg_quote($property, '/') . '["\'][^>]+content=["\']([^"\']*)["\']/', $html, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }
        // Try reversed attribute order (content before property/name)
        if (preg_match('/<meta[^>]+content=["\']([^"\']*)["\'][^>]+(?:property|name)=["\']' . preg_quote($property, '/') . '["\']/', $html, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }

        return null;
    }

    private function extractHtmlTitle(string $html): ?string
    {
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        }

        return null;
    }

    private function uploadBlobFromUrl(string $accessJwt, string $imageUrl): ?array
    {
        try {
            $response = Http::timeout(15)->get($imageUrl);
            if (! $response->successful()) {
                return null;
            }

            $imageData = $response->body();
            $mimetype = $response->header('Content-Type', 'image/jpeg');

            // Bluesky blob limit: 1MB
            if (strlen($imageData) > 1_000_000) {
                return null;
            }

            $blobResponse = Http::withToken($accessJwt)
                ->withHeaders(['Content-Type' => $mimetype])
                ->withBody($imageData, $mimetype)
                ->post(self::PDS_BASE . '/xrpc/com.atproto.repo.uploadBlob');

            if ($blobResponse->successful()) {
                return $blobResponse->json('blob');
            }

            return null;
        } catch (\Throwable $e) {
            Log::debug('BlueskyAdapter: uploadBlobFromUrl failed', ['url' => $imageUrl]);

            return null;
        }
    }

    // ─── Rich text facets ────────────────────────────────────────────

    /**
     * Detect URLs in text and generate byte-offset facets for Bluesky.
     */
    private function detectFacets(string $text): array
    {
        $facets = [];

        // Match URLs (with or without protocol — www. prefix is also detected).
        $pattern = '/(https?:\/\/[^\s\]\)]+|www\.[^\s\]\)]+)/u';
        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $url = $match[0];
                $byteStart = $match[1]; // preg with PREG_OFFSET_CAPTURE gives byte offset
                $byteEnd = $byteStart + strlen($url);

                // Ensure URL has protocol for the facet URI
                $uri = str_starts_with($url, 'http') ? $url : 'https://' . $url;

                $facets[] = [
                    'index' => [
                        'byteStart' => $byteStart,
                        'byteEnd' => $byteEnd,
                    ],
                    'features' => [
                        [
                            '$type' => 'app.bsky.richtext.facet#link',
                            'uri' => $uri,
                        ],
                    ],
                ];
            }
        }

        // Match hashtags.
        $hashtagPattern = '/(?<=\s|^)#([a-zA-Z\p{L}\d_]+)/u';
        if (preg_match_all($hashtagPattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $match) {
                $fullMatch = $match[0]; // includes #
                $tag = $matches[1][$i][0]; // without #
                $byteStart = $match[1];
                $byteEnd = $byteStart + strlen($fullMatch);

                $facets[] = [
                    'index' => [
                        'byteStart' => $byteStart,
                        'byteEnd' => $byteEnd,
                    ],
                    'features' => [
                        [
                            '$type' => 'app.bsky.richtext.facet#tag',
                            'tag' => $tag,
                        ],
                    ],
                ];
            }
        }

        return $facets;
    }

    // ─── External ID handling ────────────────────────────────────────

    /**
     * Parse a Bluesky external_id (format: "uri|cid") into [uri, cid].
     */
    private function parseExternalId(string $externalId): array
    {
        $parts = explode('|', $externalId, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    /**
     * Build the web URL for a Bluesky post.
     */
    public static function buildPostUrl(string $handle, string $externalId): string
    {
        [$uri] = explode('|', $externalId, 2);

        // AT-URI format: at://did:plc:xxx/app.bsky.feed.post/rkey
        $rkey = basename($uri);

        return "https://bsky.app/profile/{$handle}/post/{$rkey}";
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    private function error(string $message): array
    {
        return [
            'success' => false,
            'external_id' => null,
            'error' => $message,
        ];
    }

    // ─── Public API helpers (for import service) ─────────────────────

    /**
     * Fetch a Bluesky profile (public, no auth needed).
     */
    public static function fetchProfile(string $actor): ?array
    {
        $response = Http::get(self::PUBLIC_API . '/xrpc/app.bsky.actor.getProfile', [
            'actor' => $actor,
        ]);

        return $response->successful() ? $response->json() : null;
    }

    /**
     * Fetch engagement metrics for a post.
     */
    public static function fetchPostMetrics(string $uri): ?array
    {
        $response = Http::get(self::PUBLIC_API . '/xrpc/app.bsky.feed.getPostThread', [
            'uri' => $uri,
            'depth' => 0,
            'parentHeight' => 0,
        ]);

        if (! $response->successful()) {
            return null;
        }

        $post = $response->json('thread.post');

        return $post ? [
            'like_count' => $post['likeCount'] ?? 0,
            'repost_count' => $post['repostCount'] ?? 0,
            'reply_count' => $post['replyCount'] ?? 0,
            'quote_count' => $post['quoteCount'] ?? 0,
            'bookmark_count' => $post['bookmarkCount'] ?? 0,
        ] : null;
    }
}
