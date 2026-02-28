<?php

namespace App\Services\YouTube;

use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\YtItem;
use App\Models\YtSource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YouTubeFetchService
{
    private const API_BASE = 'https://www.googleapis.com/youtube/v3';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const MIN_DURATION_SECONDS = 61;

    /**
     * Get a fresh access token from any connected YouTube social account.
     */
    private function getAccessToken(): ?string
    {
        $platform = Platform::where('slug', 'youtube')->first();
        if (! $platform) {
            return null;
        }

        $account = SocialAccount::where('platform_id', $platform->id)
            ->whereNotNull('credentials')
            ->first();

        if (! $account) {
            return null;
        }

        $credentials = $account->credentials;
        $refreshToken = $credentials['refresh_token'] ?? null;

        if (! $refreshToken) {
            return $credentials['access_token'] ?? null;
        }

        // Always refresh — tokens expire after 1 hour
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => config('services.youtube.client_id'),
            'client_secret' => config('services.youtube.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            Log::warning('YouTubeFetchService: Token refresh failed, using existing token', [
                'status' => $response->status(),
            ]);

            return $credentials['access_token'] ?? null;
        }

        $newAccessToken = $response->json('access_token');

        // Update stored token
        $account->update([
            'credentials' => array_merge($credentials, [
                'access_token' => $newAccessToken,
                'expires_in' => $response->json('expires_in', 3600),
            ]),
        ]);

        return $newAccessToken;
    }

    /**
     * Test the connection to a YouTube channel and return its info.
     */
    public function testConnection(string $channelUrl): array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            return [
                'success' => false,
                'error' => "Aucun compte YouTube connecté. Connectez une chaîne YouTube dans Plateformes > YouTube.",
            ];
        }

        try {
            $channelInfo = $this->resolveChannel($channelUrl, $accessToken);

            if (! $channelInfo) {
                return [
                    'success' => false,
                    'error' => "Impossible de trouver la chaîne YouTube. Vérifiez l'URL.",
                ];
            }

            return [
                'success' => true,
                'channel_id' => $channelInfo['id'],
                'channel_name' => $channelInfo['name'],
                'thumbnail_url' => $channelInfo['thumbnail_url'],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => "Erreur : {$e->getMessage()}",
            ];
        }
    }

    /**
     * Fetch videos from a YouTube source.
     */
    public function fetchSource(YtSource $source): int
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::warning('YouTubeFetchService: No YouTube account connected');

            return 0;
        }

        try {
            // Get uploads playlist ID
            $uploadsPlaylistId = $this->getUploadsPlaylistId($source->channel_id, $accessToken);

            if (! $uploadsPlaylistId) {
                Log::error('YouTubeFetchService: Could not get uploads playlist', [
                    'source' => $source->name,
                    'channel_id' => $source->channel_id,
                ]);

                return 0;
            }

            // Fetch all video IDs from playlist
            $videoIds = $this->fetchPlaylistVideoIds($uploadsPlaylistId, $accessToken);

            if (empty($videoIds)) {
                return 0;
            }

            // Fetch video details in batches of 50
            $newCount = 0;
            $batches = array_chunk($videoIds, 50);

            foreach ($batches as $batch) {
                $newCount += $this->fetchAndStoreVideos($source, $batch, $accessToken);
            }

            $source->update(['last_fetched_at' => now()]);

            Log::info('YouTubeFetchService: Fetch complete', [
                'source' => $source->name,
                'new_items' => $newCount,
            ]);

            return $newCount;

        } catch (\Exception $e) {
            Log::error('YouTubeFetchService: Error fetching source', [
                'source' => $source->name,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Resolve a channel URL to channel info.
     */
    private function resolveChannel(string $channelUrl, string $accessToken): ?array
    {
        $channelUrl = trim($channelUrl, '/ ');

        // Extract handle or channel ID from URL
        if (preg_match('/@([\w.-]+)/', $channelUrl, $matches)) {
            $handle = $matches[1];

            return $this->fetchChannelByHandle($handle, $accessToken);
        }

        if (preg_match('/channel\/(UC[\w-]+)/', $channelUrl, $matches)) {
            return $this->fetchChannelById($matches[1], $accessToken);
        }

        // Try as handle directly (without @)
        if (preg_match('/^[\w.-]+$/', $channelUrl)) {
            return $this->fetchChannelByHandle($channelUrl, $accessToken);
        }

        // Try the whole URL as a custom URL path
        if (preg_match('/youtube\.com\/(c\/)?(\w+)/i', $channelUrl, $matches)) {
            return $this->fetchChannelByHandle($matches[2], $accessToken);
        }

        return null;
    }

    private function fetchChannelByHandle(string $handle, string $accessToken): ?array
    {
        $response = Http::timeout(15)->withToken($accessToken)->get(self::API_BASE.'/channels', [
            'part' => 'snippet,contentDetails',
            'forHandle' => $handle,
        ]);

        return $this->parseChannelResponse($response);
    }

    private function fetchChannelById(string $channelId, string $accessToken): ?array
    {
        $response = Http::timeout(15)->withToken($accessToken)->get(self::API_BASE.'/channels', [
            'part' => 'snippet,contentDetails',
            'id' => $channelId,
        ]);

        return $this->parseChannelResponse($response);
    }

    private function parseChannelResponse($response): ?array
    {
        if (! $response->successful()) {
            return null;
        }

        $items = $response->json('items', []);

        if (empty($items)) {
            return null;
        }

        $channel = $items[0];

        return [
            'id' => $channel['id'],
            'name' => $channel['snippet']['title'] ?? '',
            'thumbnail_url' => $channel['snippet']['thumbnails']['default']['url'] ?? null,
        ];
    }

    private function getUploadsPlaylistId(string $channelId, string $accessToken): ?string
    {
        $response = Http::timeout(15)->withToken($accessToken)->get(self::API_BASE.'/channels', [
            'part' => 'contentDetails',
            'id' => $channelId,
        ]);

        if (! $response->successful()) {
            return null;
        }

        return $response->json('items.0.contentDetails.relatedPlaylists.uploads');
    }

    private function fetchPlaylistVideoIds(string $playlistId, string $accessToken): array
    {
        $videoIds = [];
        $pageToken = null;

        do {
            $params = [
                'part' => 'contentDetails',
                'playlistId' => $playlistId,
                'maxResults' => 50,
            ];

            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }

            $response = Http::timeout(30)->withToken($accessToken)->get(self::API_BASE.'/playlistItems', $params);

            if (! $response->successful()) {
                break;
            }

            $data = $response->json();

            foreach ($data['items'] ?? [] as $item) {
                $videoIds[] = $item['contentDetails']['videoId'];
            }

            $pageToken = $data['nextPageToken'] ?? null;

        } while ($pageToken);

        return $videoIds;
    }

    private function fetchAndStoreVideos(YtSource $source, array $videoIds, string $accessToken): int
    {
        $response = Http::timeout(30)->withToken($accessToken)->get(self::API_BASE.'/videos', [
            'part' => 'snippet,contentDetails,statistics',
            'id' => implode(',', $videoIds),
        ]);

        if (! $response->successful()) {
            return 0;
        }

        $newCount = 0;

        foreach ($response->json('items', []) as $video) {
            $duration = $video['contentDetails']['duration'] ?? 'PT0S';
            $durationSeconds = $this->parseDurationToSeconds($duration);

            // Skip Shorts (≤ 60 seconds)
            if ($durationSeconds <= self::MIN_DURATION_SECONDS) {
                continue;
            }

            $snippet = $video['snippet'] ?? [];
            $stats = $video['statistics'] ?? [];
            $videoId = $video['id'];

            $item = YtItem::updateOrCreate(
                [
                    'yt_source_id' => $source->id,
                    'video_id' => $videoId,
                ],
                [
                    'title' => $snippet['title'] ?? '',
                    'url' => "https://www.youtube.com/watch?v={$videoId}",
                    'description' => $snippet['description'] ?? null,
                    'thumbnail_url' => $snippet['thumbnails']['high']['url']
                        ?? $snippet['thumbnails']['medium']['url']
                        ?? $snippet['thumbnails']['default']['url']
                        ?? null,
                    'duration' => $duration,
                    'view_count' => $stats['viewCount'] ?? null,
                    'like_count' => $stats['likeCount'] ?? null,
                    'published_at' => $snippet['publishedAt'] ?? null,
                    'fetched_at' => now(),
                ]
            );

            if ($item->wasRecentlyCreated) {
                $newCount++;
            }
        }

        return $newCount;
    }

    /**
     * Parse ISO 8601 duration to seconds (e.g., PT1H30M15S → 5415).
     */
    public function parseDurationToSeconds(string $iso8601): int
    {
        $seconds = 0;

        if (preg_match('/(\d+)H/', $iso8601, $m)) {
            $seconds += (int) $m[1] * 3600;
        }
        if (preg_match('/(\d+)M/', $iso8601, $m)) {
            $seconds += (int) $m[1] * 60;
        }
        if (preg_match('/(\d+)S/', $iso8601, $m)) {
            $seconds += (int) $m[1];
        }

        return $seconds;
    }

    /**
     * Format ISO 8601 duration to human readable (e.g., PT1H30M15S → 1:30:15).
     */
    public static function formatDuration(string $iso8601): string
    {
        $seconds = (new self)->parseDurationToSeconds($iso8601);

        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        if ($h > 0) {
            return sprintf('%d:%02d:%02d', $h, $m, $s);
        }

        return sprintf('%d:%02d', $m, $s);
    }
}
