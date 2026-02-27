<?php

namespace App\Services\Adapters;

use App\Models\SocialAccount;
use App\Services\YouTubeTokenHelper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YouTubeAdapter implements PlatformAdapterInterface
{
    private const API_BASE = 'https://www.googleapis.com/youtube/v3';
    private const UPLOAD_BASE = 'https://www.googleapis.com/upload/youtube/v3';

    /**
     * Publish a video to YouTube.
     *
     * @param  SocialAccount  $account  Credentials: channel_id, access_token, refresh_token.
     * @param  string  $content  The video title and description.
     * @param  array|null  $media  Video file to upload (must have exactly one video).
     * @param  array|null  $options  Additional options (privacy, tags, etc.).
     * @return array{success: bool, external_id: string|null, error: string|null}
     */
    public function publish(SocialAccount $account, string $content, ?array $media = null, ?array $options = null): array
    {
        try {
            // YouTube requires a video
            if (empty($media)) {
                return [
                    'success' => false,
                    'external_id' => null,
                    'error' => 'YouTube nécessite au moins une vidéo.',
                ];
            }

            // Find the first video in media
            $video = null;
            foreach ($media as $item) {
                if (isset($item['mimetype']) && str_starts_with($item['mimetype'], 'video/')) {
                    $video = $item;
                    break;
                }
            }

            if (! $video) {
                return [
                    'success' => false,
                    'external_id' => null,
                    'error' => 'Aucune vidéo trouvée dans les médias.',
                ];
            }

            // Refresh token (YouTube tokens expire after 1 hour)
            $accessToken = YouTubeTokenHelper::getFreshAccessToken($account);

            if (! $accessToken) {
                return [
                    'success' => false,
                    'external_id' => null,
                    'error' => 'Impossible de rafraîchir le token YouTube.',
                ];
            }

            // Extract title from content (first line, max 100 chars)
            $lines = explode("\n", $content);
            $title = mb_substr($lines[0], 0, 100);
            if (empty(trim($title))) {
                $title = 'Video uploaded via RS-Max';
            }

            // Extract tags from hashtags
            $tags = $this->extractTags($content);

            // Prepare video metadata
            $metadata = [
                'snippet' => [
                    'title' => $title,
                    'description' => $content,
                    'tags' => $tags,
                    'categoryId' => '22', // People & Blogs
                ],
                'status' => [
                    'privacyStatus' => $options['privacy'] ?? 'public', // public, unlisted, private
                    'selfDeclaredMadeForKids' => false,
                ],
            ];

            // Upload video
            $videoId = $this->uploadVideo($accessToken, $video['url'], $metadata);

            if (! $videoId) {
                return [
                    'success' => false,
                    'external_id' => null,
                    'error' => 'Échec de l\'upload de la vidéo sur YouTube.',
                ];
            }

            return [
                'success' => true,
                'external_id' => $videoId,
                'error' => null,
            ];

        } catch (\Throwable $e) {
            Log::error('YouTubeAdapter: publish failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'external_id' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Upload video to YouTube using resumable upload.
     */
    private function uploadVideo(string $accessToken, string $videoUrl, array $metadata): ?string
    {
        // Download video file
        $videoContent = @file_get_contents($videoUrl);
        if ($videoContent === false) {
            Log::error('YouTubeAdapter: Failed to download video', ['url' => $videoUrl]);
            return null;
        }

        // Step 1: Initiate resumable upload
        $response = Http::withToken($accessToken)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'X-Upload-Content-Type' => 'video/*',
                'X-Upload-Content-Length' => strlen($videoContent),
            ])
            ->post(self::UPLOAD_BASE . '/videos?uploadType=resumable&part=snippet,status', $metadata);

        if (! $response->successful()) {
            Log::error('YouTubeAdapter: Upload initiation failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        }

        // Get upload URL from Location header
        $uploadUrl = $response->header('Location');
        if (! $uploadUrl) {
            Log::error('YouTubeAdapter: No upload URL in response');
            return null;
        }

        // Step 2: Upload video content
        $uploadResponse = Http::withHeaders([
            'Content-Type' => 'video/*',
        ])->withBody($videoContent, 'video/*')->put($uploadUrl);

        if (! $uploadResponse->successful()) {
            Log::error('YouTubeAdapter: Video upload failed', [
                'status' => $uploadResponse->status(),
                'body' => $uploadResponse->body(),
            ]);
            return null;
        }

        $data = $uploadResponse->json();

        return $data['id'] ?? null;
    }

    /**
     * Extract tags from content (hashtags).
     */
    private function extractTags(string $content): array
    {
        preg_match_all('/#(\w+)/', $content, $matches);

        $tags = [];
        if (! empty($matches[1])) {
            // YouTube allows max 500 characters total for tags
            $totalLength = 0;
            foreach ($matches[1] as $tag) {
                $tagLength = mb_strlen($tag);
                if ($totalLength + $tagLength + 1 <= 500) {
                    $tags[] = $tag;
                    $totalLength += $tagLength + 1; // +1 for comma separator
                }
            }
        }

        return $tags;
    }
}
