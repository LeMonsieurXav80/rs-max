<?php

namespace App\Services\Import;

use App\Models\ExternalPost;
use App\Models\Platform;
use App\Models\SocialAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InstagramImportService implements PlatformImportInterface
{
    private const API_VERSION = 'v21.0';

    private const API_BASE = 'https://graph.facebook.com/'.self::API_VERSION;

    /**
     * Import historical posts from an Instagram account.
     */
    public function importHistory(SocialAccount $account, int $limit = 50): Collection
    {
        $credentials = $account->credentials;
        $accessToken = $credentials['access_token'] ?? null;
        $instagramAccountId = $account->platform_account_id;

        if (! $accessToken) {
            throw new \Exception('No access token found for Instagram account');
        }

        $mediaList = $this->fetchMedia($instagramAccountId, $accessToken, $limit);

        return $this->storeExternalPosts($account, $mediaList, $accessToken);
    }

    /**
     * Fetch media from Instagram account.
     */
    private function fetchMedia(string $accountId, string $accessToken, int $limit): Collection
    {
        $media = collect();

        try {
            $url = self::API_BASE."/{$accountId}/media";
            $params = [
                'fields' => 'id,caption,media_type,media_product_type,media_url,permalink,timestamp,like_count,comments_count',
                'limit' => min(100, $limit),
                'access_token' => $accessToken,
            ];

            do {
                $response = Http::get($url, $params);

                if (! $response->successful()) {
                    Log::error('Instagram API error fetching media', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    break;
                }

                $data = $response->json();

                foreach ($data['data'] ?? [] as $item) {
                    $media->push($item);

                    if ($media->count() >= $limit) {
                        break 2;
                    }
                }

                $url = $data['paging']['next'] ?? null;
                $params = [];

            } while ($url && $media->count() < $limit);

            return $media;
        } catch (\Exception $e) {
            Log::error('Exception fetching Instagram media', ['error' => $e->getMessage()]);

            return $media;
        }
    }

    /**
     * Store media as ExternalPost records with deduplication.
     */
    private function storeExternalPosts(SocialAccount $account, Collection $mediaList, string $accessToken): Collection
    {
        $platform = Platform::where('slug', 'instagram')->first();
        $imported = collect();

        foreach ($mediaList as $media) {
            $mediaId = $media['id'];

            $exists = ExternalPost::where('platform_id', $platform->id)
                ->where('external_id', $mediaId)
                ->exists();

            if ($exists) {
                continue;
            }

            // Basic metrics (always available)
            $basicLikes = (int) ($media['like_count'] ?? 0);
            $basicComments = (int) ($media['comments_count'] ?? 0);
            $mediaType = $media['media_product_type'] ?? $media['media_type'] ?? 'FEED';

            // Try to fetch insights (views, shares) - requires instagram_manage_insights
            $insights = $this->fetchMediaInsights($mediaId, $accessToken, $mediaType);

            $metrics = [
                'views' => $insights['views'],
                'likes' => $insights['likes'] > 0 ? $insights['likes'] : $basicLikes,
                'comments' => $insights['comments'] > 0 ? $insights['comments'] : $basicComments,
                'shares' => $insights['shares'],
            ];

            $externalPost = ExternalPost::create([
                'social_account_id' => $account->id,
                'platform_id' => $platform->id,
                'external_id' => $mediaId,
                'content' => $media['caption'] ?? null,
                'media_url' => $media['media_url'] ?? null,
                'post_url' => $media['permalink'] ?? null,
                'published_at' => $media['timestamp'] ?? null,
                'metrics' => $metrics,
                'metrics_synced_at' => now(),
            ]);

            $imported->push($externalPost);
        }

        if ($imported->isNotEmpty()) {
            $account->update(['last_history_import_at' => now()]);
        }

        return $imported;
    }

    /**
     * Fetch insights for a specific media.
     * Reels use 'plays' instead of 'impressions'.
     */
    private function fetchMediaInsights(string $mediaId, string $accessToken, string $mediaType): array
    {
        $defaults = ['views' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0];

        try {
            // 'views' replaces deprecated 'impressions' (FEED) and 'plays' (REELS) from v22.0+
            $metric = 'views,reach,likes,comments,shares,saved';

            $response = Http::get(self::API_BASE."/{$mediaId}/insights", [
                'metric' => $metric,
                'access_token' => $accessToken,
            ]);

            if ($response->successful()) {
                $data = $response->json();

                return $this->parseInsights($data['data'] ?? []);
            }

            Log::debug('Instagram insights API error', [
                'media_id' => $mediaId,
                'status' => $response->status(),
            ]);
        } catch (\Exception $e) {
            Log::debug('Instagram insights exception for '.$mediaId);
        }

        return $defaults;
    }

    /**
     * Parse insights data into metrics array.
     */
    private function parseInsights(array $insights): array
    {
        $metrics = ['views' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0];

        foreach ($insights as $insight) {
            $name = $insight['name'] ?? '';
            $value = $insight['values'][0]['value'] ?? 0;

            switch ($name) {
                case 'views':
                case 'reach':
                    $metrics['views'] = max($metrics['views'], (int) $value);
                    break;
                case 'likes':
                    $metrics['likes'] = (int) $value;
                    break;
                case 'comments':
                    $metrics['comments'] = (int) $value;
                    break;
                case 'shares':
                    $metrics['shares'] += (int) $value;
                    break;
                case 'saved':
                case 'saves':
                    $metrics['shares'] += (int) $value;
                    break;
            }
        }

        return $metrics;
    }

    /**
     * Instagram has reasonable rate limits.
     */
    public function getQuotaCost(int $postCount): array
    {
        return [
            'cost' => 0,
            'description' => "Import de {$postCount} posts Instagram (pas de quota strict)",
        ];
    }
}
