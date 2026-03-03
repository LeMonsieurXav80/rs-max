<?php

namespace App\Services\Adapters;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RedditAdapter implements PlatformAdapterInterface
{
    private const AUTH_URL = 'https://www.reddit.com/api/v1/access_token';

    private const API_BASE = 'https://oauth.reddit.com';

    private const USER_AGENT = 'server:rs-max:v1.0.0 (by /u/%s)';

    // ─── PlatformAdapterInterface ────────────────────────────────────

    public function publish(SocialAccount $account, string $content, ?array $media = null, ?array $options = null): array
    {
        try {
            $credentials = $account->credentials;
            $subreddit = $credentials['subreddit'] ?? null;

            if (! $subreddit) {
                return $this->error('Aucun subreddit configuré pour ce compte.');
            }

            $accessToken = $this->authenticate($credentials);

            if (! $accessToken) {
                return $this->error('Impossible de s\'authentifier sur Reddit.');
            }

            $userAgent = sprintf(self::USER_AGENT, $credentials['username'] ?? 'unknown');
            [$title, $body] = $this->extractTitleAndBody($content);

            // Determine post type based on media.
            if (! empty($media)) {
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

                // Video takes priority (Reddit allows 1 video per post).
                if ($video) {
                    return $this->publishVideo($accessToken, $userAgent, $subreddit, $title, $body, $video);
                }

                if (count($images) === 1) {
                    return $this->publishImage($accessToken, $userAgent, $subreddit, $title, $body, $images[0]);
                }

                if (count($images) > 1) {
                    return $this->publishGallery($accessToken, $userAgent, $subreddit, $title, $body, $images);
                }
            }

            // Text post (self post).
            return $this->publishText($accessToken, $userAgent, $subreddit, $title, $body);

        } catch (\Throwable $e) {
            Log::error('RedditAdapter: publish failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error($e->getMessage());
        }
    }

    // ─── Authentication ──────────────────────────────────────────────

    /**
     * Authenticate via Reddit OAuth2 password grant (script app).
     * Returns access token or null on failure.
     */
    private function authenticate(array $credentials): ?string
    {
        $clientId = $credentials['client_id'] ?? '';
        $clientSecret = $credentials['client_secret'] ?? '';
        $username = $credentials['username'] ?? '';
        $password = $credentials['password'] ?? '';
        $userAgent = sprintf(self::USER_AGENT, $username);

        $response = Http::withBasicAuth($clientId, $clientSecret)
            ->withHeaders(['User-Agent' => $userAgent])
            ->asForm()
            ->post(self::AUTH_URL, [
                'grant_type' => 'password',
                'username' => $username,
                'password' => $password,
            ]);

        if ($response->successful() && $response->json('access_token')) {
            return $response->json('access_token');
        }

        Log::error('RedditAdapter: authentication failed', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        return null;
    }

    // ─── Text post ───────────────────────────────────────────────────

    private function publishText(string $token, string $userAgent, string $subreddit, string $title, ?string $body): array
    {
        $params = [
            'api_type' => 'json',
            'kind' => 'self',
            'sr' => $subreddit,
            'title' => $title,
            'resubmit' => true,
            'sendreplies' => false,
        ];

        if ($body) {
            $params['text'] = $body;
        }

        return $this->submitPost($token, $userAgent, $params);
    }

    // ─── Image post (single) ────────────────────────────────────────

    private function publishImage(string $token, string $userAgent, string $subreddit, string $title, ?string $body, array $image): array
    {
        $assetUrl = $this->uploadMediaToReddit($token, $userAgent, $image);

        if (! $assetUrl) {
            // Fallback: publish as text with image URL in body.
            Log::warning('RedditAdapter: image upload failed, falling back to text post');

            return $this->publishText($token, $userAgent, $subreddit, $title, $body);
        }

        $params = [
            'api_type' => 'json',
            'kind' => 'image',
            'sr' => $subreddit,
            'title' => $title,
            'url' => $assetUrl,
            'resubmit' => true,
            'sendreplies' => false,
        ];

        return $this->submitPost($token, $userAgent, $params);
    }

    // ─── Gallery post (multiple images) ──────────────────────────────

    private function publishGallery(string $token, string $userAgent, string $subreddit, string $title, ?string $body, array $images): array
    {
        $galleryItems = [];

        foreach (array_slice($images, 0, 20) as $image) {
            $assetId = $this->uploadMediaAsset($token, $userAgent, $image);

            if ($assetId) {
                $galleryItems[] = [
                    'media_id' => $assetId,
                    'caption' => $image['title'] ?? '',
                ];
            }
        }

        if (empty($galleryItems)) {
            Log::warning('RedditAdapter: all gallery uploads failed, falling back to text post');

            return $this->publishText($token, $userAgent, $subreddit, $title, $body);
        }

        // Gallery posts use a different endpoint structure.
        $response = Http::withToken($token)
            ->withHeaders(['User-Agent' => $userAgent])
            ->asForm()
            ->post(self::API_BASE . '/api/submit_gallery_post.json', [
                'api_type' => 'json',
                'sr' => $subreddit,
                'title' => $title,
                'sendreplies' => false,
                'items' => json_encode($galleryItems),
            ]);

        // Gallery endpoint returns JSON with 'json.data.url' on success.
        $data = $response->json();

        if ($response->successful() && isset($data['json']['data']['id'])) {
            $postId = 't3_' . $data['json']['data']['id'];

            return [
                'success' => true,
                'external_id' => $postId,
                'error' => null,
            ];
        }

        // Try parsing gallery-specific response format.
        if ($response->successful() && isset($data['id'])) {
            return [
                'success' => true,
                'external_id' => 't3_' . $data['id'],
                'error' => null,
            ];
        }

        $error = $this->extractError($data);
        Log::error('RedditAdapter: gallery submit failed', [
            'status' => $response->status(),
            'error' => $error,
            'body' => $data,
        ]);

        return $this->error($error);
    }

    // ─── Video post ──────────────────────────────────────────────────

    private function publishVideo(string $token, string $userAgent, string $subreddit, string $title, ?string $body, array $video): array
    {
        $assetUrl = $this->uploadMediaToReddit($token, $userAgent, $video);

        if (! $assetUrl) {
            Log::warning('RedditAdapter: video upload failed, falling back to text post');

            return $this->publishText($token, $userAgent, $subreddit, $title, $body);
        }

        $mimetype = $video['mimetype'] ?? 'video/mp4';
        $kind = str_contains($mimetype, 'gif') ? 'videogif' : 'video';

        $params = [
            'api_type' => 'json',
            'kind' => $kind,
            'sr' => $subreddit,
            'title' => $title,
            'url' => $assetUrl,
            'resubmit' => true,
            'sendreplies' => false,
        ];

        return $this->submitPost($token, $userAgent, $params);
    }

    // ─── Media upload helpers ────────────────────────────────────────

    /**
     * Upload a media file to Reddit's asset system and return the CDN URL.
     * Two-step process: (1) request upload lease, (2) upload to S3.
     */
    private function uploadMediaToReddit(string $token, string $userAgent, array $mediaItem): ?string
    {
        $url = $mediaItem['url'];
        $mimetype = $mediaItem['mimetype'] ?? 'image/jpeg';
        $extension = $this->mimeToExtension($mimetype);
        $filename = 'rsmax_upload.' . $extension;

        // Download the file locally first.
        $tempFile = tempnam(sys_get_temp_dir(), 'reddit_');

        try {
            $downloaded = Http::timeout(120)->withOptions(['sink' => $tempFile])->get($url);

            if (! $downloaded->successful()) {
                Log::warning('RedditAdapter: failed to download media', ['url' => $url]);

                return null;
            }

            // Step 1: Request upload lease from Reddit.
            $leaseResponse = Http::withToken($token)
                ->withHeaders(['User-Agent' => $userAgent])
                ->asForm()
                ->post(self::API_BASE . '/api/media/asset.json', [
                    'filepath' => $filename,
                    'mimetype' => $mimetype,
                ]);

            if (! $leaseResponse->successful()) {
                Log::error('RedditAdapter: media lease request failed', [
                    'status' => $leaseResponse->status(),
                    'body' => $leaseResponse->body(),
                ]);

                return null;
            }

            $leaseData = $leaseResponse->json();
            $action = $leaseData['args']['action'] ?? null;
            $fields = $leaseData['args']['fields'] ?? [];
            $assetId = $leaseData['asset']['asset_id'] ?? null;

            if (! $action || empty($fields)) {
                Log::error('RedditAdapter: invalid lease response', ['data' => $leaseData]);

                return null;
            }

            // Step 2: Upload file to S3 using the lease fields.
            $uploadUrl = str_starts_with($action, '//') ? 'https:' . $action : $action;

            $multipart = [];
            foreach ($fields as $field) {
                $multipart[] = [
                    'name' => $field['name'],
                    'contents' => $field['value'],
                ];
            }
            $multipart[] = [
                'name' => 'file',
                'contents' => fopen($tempFile, 'r'),
                'filename' => $filename,
                'headers' => ['Content-Type' => $mimetype],
            ];

            $s3Response = Http::timeout(120)
                ->asMultipart()
                ->post($uploadUrl, $multipart);

            if (! $s3Response->successful() && $s3Response->status() !== 201) {
                Log::error('RedditAdapter: S3 upload failed', [
                    'status' => $s3Response->status(),
                    'body' => substr($s3Response->body(), 0, 500),
                ]);

                return null;
            }

            // Extract CDN URL from S3 XML response.
            $cdnUrl = $this->extractS3Location($s3Response->body());

            return $cdnUrl ?? $uploadUrl . '/' . ($fields[0]['value'] ?? '');

        } finally {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    /**
     * Upload a media file and return just the asset_id (for gallery posts).
     */
    private function uploadMediaAsset(string $token, string $userAgent, array $mediaItem): ?string
    {
        $url = $mediaItem['url'];
        $mimetype = $mediaItem['mimetype'] ?? 'image/jpeg';
        $extension = $this->mimeToExtension($mimetype);
        $filename = 'rsmax_upload.' . $extension;

        $tempFile = tempnam(sys_get_temp_dir(), 'reddit_');

        try {
            $downloaded = Http::timeout(120)->withOptions(['sink' => $tempFile])->get($url);

            if (! $downloaded->successful()) {
                return null;
            }

            // Request upload lease.
            $leaseResponse = Http::withToken($token)
                ->withHeaders(['User-Agent' => $userAgent])
                ->asForm()
                ->post(self::API_BASE . '/api/media/asset.json', [
                    'filepath' => $filename,
                    'mimetype' => $mimetype,
                ]);

            if (! $leaseResponse->successful()) {
                return null;
            }

            $leaseData = $leaseResponse->json();
            $action = $leaseData['args']['action'] ?? null;
            $fields = $leaseData['args']['fields'] ?? [];
            $assetId = $leaseData['asset']['asset_id'] ?? null;

            if (! $action || empty($fields) || ! $assetId) {
                return null;
            }

            // Upload to S3.
            $uploadUrl = str_starts_with($action, '//') ? 'https:' . $action : $action;

            $multipart = [];
            foreach ($fields as $field) {
                $multipart[] = [
                    'name' => $field['name'],
                    'contents' => $field['value'],
                ];
            }
            $multipart[] = [
                'name' => 'file',
                'contents' => fopen($tempFile, 'r'),
                'filename' => $filename,
                'headers' => ['Content-Type' => $mimetype],
            ];

            $s3Response = Http::timeout(120)
                ->asMultipart()
                ->post($uploadUrl, $multipart);

            if (! $s3Response->successful() && $s3Response->status() !== 201) {
                return null;
            }

            return $assetId;

        } finally {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    // ─── Submit post ─────────────────────────────────────────────────

    private function submitPost(string $token, string $userAgent, array $params): array
    {
        $response = Http::withToken($token)
            ->withHeaders(['User-Agent' => $userAgent])
            ->asForm()
            ->post(self::API_BASE . '/api/submit', $params);

        $data = $response->json();

        // Reddit returns: {"json": {"errors": [], "data": {"url": "...", "id": "...", "name": "t3_..."}}}
        if ($response->successful()) {
            $errors = $data['json']['errors'] ?? [];

            if (! empty($errors)) {
                $errorMsg = collect($errors)->map(fn ($e) => implode(': ', $e))->implode(', ');
                Log::error('RedditAdapter: submit returned errors', ['errors' => $errors]);

                return $this->error($errorMsg);
            }

            $postData = $data['json']['data'] ?? [];
            $postId = $postData['name'] ?? null; // e.g. "t3_abc123"

            if (! $postId && isset($postData['id'])) {
                $postId = 't3_' . $postData['id'];
            }

            if ($postId) {
                return [
                    'success' => true,
                    'external_id' => $postId,
                    'error' => null,
                ];
            }
        }

        $error = $this->extractError($data);
        Log::error('RedditAdapter: submit failed', [
            'status' => $response->status(),
            'error' => $error,
            'body' => $data,
        ]);

        return $this->error($error);
    }

    // ─── Title extraction ────────────────────────────────────────────

    /**
     * Extract title and body from content.
     * Reddit requires a title (max 300 chars). First sentence = title, rest = body.
     *
     * @return array{0: string, 1: string|null}
     */
    private function extractTitleAndBody(string $content): array
    {
        $content = trim($content);

        // If content is very short, use it entirely as title.
        if (mb_strlen($content) <= 300) {
            return [$content, null];
        }

        // Try to find the first sentence break.
        $breakPattern = '/(?<=[.!?\n])\s+/u';

        if (preg_match($breakPattern, $content, $match, PREG_OFFSET_CAPTURE)) {
            $breakPos = $match[0][1];

            // Only use the break if the title part is reasonable length.
            if ($breakPos > 10 && $breakPos <= 300) {
                $title = trim(mb_substr($content, 0, $breakPos));
                $body = trim(mb_substr($content, $breakPos));

                return [$title, $body ?: null];
            }
        }

        // No suitable break found — truncate at ~100 chars on a word boundary.
        $truncated = mb_substr($content, 0, 100);
        $lastSpace = mb_strrpos($truncated, ' ');

        if ($lastSpace && $lastSpace > 50) {
            $title = mb_substr($content, 0, $lastSpace) . '...';
        } else {
            $title = mb_substr($content, 0, 100) . '...';
        }

        return [$title, $content];
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

    private function extractError(mixed $data): string
    {
        if (is_array($data)) {
            // Standard submit format.
            $errors = $data['json']['errors'] ?? [];
            if (! empty($errors)) {
                return collect($errors)->map(fn ($e) => implode(': ', $e))->implode(', ');
            }

            return $data['json']['data']['status_msg'] ?? $data['message'] ?? 'Unknown Reddit error';
        }

        return 'Unknown Reddit error';
    }

    private function extractS3Location(string $xmlBody): ?string
    {
        if (preg_match('/<Location>(.+?)<\/Location>/i', $xmlBody, $matches)) {
            return html_entity_decode($matches[1]);
        }

        return null;
    }

    private function mimeToExtension(string $mimetype): string
    {
        return match ($mimetype) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            default => 'jpg',
        };
    }

    // ─── Public API helpers (for stats service) ──────────────────────

    /**
     * Fetch post info from Reddit API (requires auth).
     */
    public static function fetchPostInfo(array $credentials, string $fullname): ?array
    {
        $adapter = new self;
        $token = $adapter->authenticate($credentials);

        if (! $token) {
            return null;
        }

        $userAgent = sprintf(self::USER_AGENT, $credentials['username'] ?? 'unknown');

        $response = Http::withToken($token)
            ->withHeaders(['User-Agent' => $userAgent])
            ->get(self::API_BASE . '/api/info', ['id' => $fullname]);

        if (! $response->successful()) {
            return null;
        }

        $children = $response->json('data.children', []);

        return ! empty($children) ? $children[0]['data'] ?? null : null;
    }

    /**
     * Build the web URL for a Reddit post.
     */
    public static function buildPostUrl(string $subreddit, string $externalId): string
    {
        $id = str_replace('t3_', '', $externalId);

        return "https://www.reddit.com/r/{$subreddit}/comments/{$id}/";
    }
}
