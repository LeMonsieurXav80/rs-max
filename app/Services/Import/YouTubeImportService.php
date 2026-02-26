<?php

namespace App\Services\Import;

use App\Models\ExternalPost;
use App\Models\Platform;
use App\Models\SocialAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YouTubeImportService implements PlatformImportInterface
{
    private const API_BASE = 'https://www.googleapis.com/youtube/v3';

    /**
     * Import historical posts (videos) from a YouTube channel.
     *
     * Strategy:
     * 1. Get channel's "uploads" playlist ID (1 unit)
     * 2. Fetch videos from uploads playlist (1 unit per 50 videos)
     * 3. Batch fetch video details with stats (1 unit per 50 videos)
     * 4. Store as ExternalPost records
     *
     * @throws \Exception
     */
    public function importHistory(SocialAccount $account, int $limit = 50): Collection
    {
        $credentials = $account->credentials;
        $accessToken = $credentials['access_token'] ?? null;
        $channelId = $account->platform_account_id;

        if (! $accessToken) {
            throw new \Exception('No access token found for YouTube account');
        }

        // Step 1: Get the "uploads" playlist ID (1 quota unit)
        $uploadsPlaylistId = $this->getUploadsPlaylistId($channelId, $accessToken);

        if (! $uploadsPlaylistId) {
            throw new \Exception('Could not retrieve uploads playlist for channel');
        }

        // Step 2: Fetch video IDs from playlist (1 unit per 50 items)
        $videoIds = $this->fetchPlaylistVideoIds($uploadsPlaylistId, $accessToken, $limit);

        if ($videoIds->isEmpty()) {
            return collect();
        }

        // Step 3: Batch fetch video details with stats (1 unit per 50 videos)
        $videos = $this->fetchVideosDetails($videoIds, $accessToken);

        // Step 4: Store as ExternalPost records (with deduplication)
        return $this->storeExternalPosts($account, $videos);
    }

    /**
     * Get the channel's "uploads" playlist ID.
     * Cost: 1 quota unit.
     */
    private function getUploadsPlaylistId(string $channelId, string $accessToken): ?string
    {
        try {
            $response = Http::withToken($accessToken)->get(self::API_BASE.'/channels', [
                'part' => 'contentDetails',
                'id' => $channelId,
            ]);

            if (! $response->successful()) {
                Log::error('YouTube API error fetching channel', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $data = $response->json();

            return $data['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? null;
        } catch (\Exception $e) {
            Log::error('Exception fetching uploads playlist', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Fetch video IDs from the uploads playlist.
     * Cost: 1 quota unit per 50 items (maxResults=50).
     */
    private function fetchPlaylistVideoIds(string $playlistId, string $accessToken, int $limit): Collection
    {
        $videoIds = collect();
        $pageToken = null;

        try {
            do {
                $params = [
                    'part' => 'contentDetails',
                    'playlistId' => $playlistId,
                    'maxResults' => min(50, $limit - $videoIds->count()),
                ];

                if ($pageToken) {
                    $params['pageToken'] = $pageToken;
                }

                $response = Http::withToken($accessToken)->get(self::API_BASE.'/playlistItems', $params);

                if (! $response->successful()) {
                    Log::error('YouTube API error fetching playlist items', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    break;
                }

                $data = $response->json();

                foreach ($data['items'] ?? [] as $item) {
                    $videoIds->push($item['contentDetails']['videoId']);
                }

                $pageToken = $data['nextPageToken'] ?? null;

            } while ($pageToken && $videoIds->count() < $limit);

            return $videoIds;
        } catch (\Exception $e) {
            Log::error('Exception fetching playlist video IDs', ['error' => $e->getMessage()]);

            return $videoIds;
        }
    }

    /**
     * Fetch video details with stats in batches of 50.
     * Cost: 1 quota unit per 50 videos.
     */
    private function fetchVideosDetails(Collection $videoIds, string $accessToken): Collection
    {
        $videos = collect();

        try {
            // Process in chunks of 50 (API limit)
            foreach ($videoIds->chunk(50) as $chunk) {
                $response = Http::withToken($accessToken)->get(self::API_BASE.'/videos', [
                    'part' => 'snippet,statistics,contentDetails',
                    'id' => $chunk->implode(','),
                ]);

                if (! $response->successful()) {
                    Log::error('YouTube API error fetching video details', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    continue;
                }

                $data = $response->json();

                foreach ($data['items'] ?? [] as $video) {
                    $videos->push($video);
                }

                // Small delay to avoid rate limiting
                usleep(100000); // 100ms
            }

            return $videos;
        } catch (\Exception $e) {
            Log::error('Exception fetching video details', ['error' => $e->getMessage()]);

            return $videos;
        }
    }

    /**
     * Store videos as ExternalPost records with deduplication.
     */
    private function storeExternalPosts(SocialAccount $account, Collection $videos): Collection
    {
        $platform = Platform::where('slug', 'youtube')->first();
        $imported = collect();

        foreach ($videos as $video) {
            $videoId = $video['id'];
            $snippet = $video['snippet'] ?? [];
            $stats = $video['statistics'] ?? [];

            $metricsData = [
                'views' => (int) ($stats['viewCount'] ?? 0),
                'likes' => (int) ($stats['likeCount'] ?? 0),
                'comments' => (int) ($stats['commentCount'] ?? 0),
                'shares' => null,
            ];

            // Update existing or create new
            $existing = ExternalPost::where('platform_id', $platform->id)
                ->where('external_id', $videoId)
                ->first();

            if ($existing) {
                $existing->update([
                    'metrics' => $metricsData,
                    'metrics_synced_at' => now(),
                ]);

                continue;
            }

            // Get thumbnail URL (best quality available)
            $thumbnails = $snippet['thumbnails'] ?? [];
            $mediaUrl = $thumbnails['maxres']['url']
                ?? $thumbnails['high']['url']
                ?? $thumbnails['medium']['url']
                ?? null;

            $externalPost = ExternalPost::create([
                'social_account_id' => $account->id,
                'platform_id' => $platform->id,
                'external_id' => $videoId,
                'content' => $snippet['title'] ?? null,
                'media_url' => $mediaUrl,
                'post_url' => "https://www.youtube.com/watch?v={$videoId}",
                'published_at' => $snippet['publishedAt'] ?? null,
                'metrics' => $metricsData,
                'metrics_synced_at' => now(),
            ]);

            $imported->push($externalPost);
        }

        $account->update(['last_history_import_at' => now()]);

        return $imported;
    }

    /**
     * Calculate quota cost for importing N posts.
     *
     * Cost breakdown:
     * - 1 unit: Get uploads playlist ID
     * - ceil(N/50) units: Fetch video IDs
     * - ceil(N/50) units: Fetch video details with stats
     */
    public function getQuotaCost(int $postCount): array
    {
        $playlistFetchCost = (int) ceil($postCount / 50);
        $detailsFetchCost = (int) ceil($postCount / 50);
        $totalCost = 1 + $playlistFetchCost + $detailsFetchCost;

        return [
            'cost' => $totalCost,
            'description' => "Import de {$postCount} vidéos : {$totalCost} unités de quota (1 pour la playlist + {$playlistFetchCost} + {$detailsFetchCost})",
        ];
    }
}
