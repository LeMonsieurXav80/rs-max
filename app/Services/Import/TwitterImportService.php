<?php

namespace App\Services\Import;

use App\Models\ExternalPost;
use App\Models\Platform;
use App\Models\SocialAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TwitterImportService implements PlatformImportInterface
{
    private const API_BASE = 'https://api.twitter.com/2';

    /**
     * Import historical tweets from a Twitter account.
     *
     * Strategy:
     * 1. Fetch tweets from /users/{id}/tweets with metrics
     * 2. Store as ExternalPost records
     *
     * Rate limit: 1500 requests per 15 minutes (Free tier)
     */
    public function importHistory(SocialAccount $account, int $limit = 50): Collection
    {
        $credentials = $account->credentials;
        $accessToken = $credentials['access_token'] ?? null;
        $userId = $account->platform_account_id;

        if (! $accessToken) {
            throw new \Exception('No access token found for Twitter account');
        }

        // Fetch tweets with metrics
        $tweets = $this->fetchTweets($userId, $accessToken, $limit);

        // Store as ExternalPost records (with deduplication)
        return $this->storeExternalPosts($account, $tweets);
    }

    /**
     * Fetch tweets from Twitter user timeline.
     */
    private function fetchTweets(string $userId, string $accessToken, int $limit): Collection
    {
        $tweets = collect();

        try {
            $url = self::API_BASE."/users/{$userId}/tweets";
            $params = [
                'max_results' => min(100, $limit),
                'tweet.fields' => 'created_at,public_metrics,attachments',
                'media.fields' => 'url,preview_image_url',
                'expansions' => 'attachments.media_keys',
            ];

            $paginationToken = null;

            do {
                if ($paginationToken) {
                    $params['pagination_token'] = $paginationToken;
                }

                $response = Http::withToken($accessToken)->get($url, $params);

                if (! $response->successful()) {
                    Log::error('Twitter API error fetching tweets', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    break;
                }

                $data = $response->json();

                // Extract media info for reference
                $mediaMap = [];
                foreach ($data['includes']['media'] ?? [] as $media) {
                    $mediaMap[$media['media_key']] = $media['url'] ?? $media['preview_image_url'] ?? null;
                }

                foreach ($data['data'] ?? [] as $tweet) {
                    // Attach media URL if available
                    $mediaKeys = $tweet['attachments']['media_keys'] ?? [];
                    $tweet['media_url'] = ! empty($mediaKeys) ? $mediaMap[$mediaKeys[0]] ?? null : null;

                    $tweets->push($tweet);

                    if ($tweets->count() >= $limit) {
                        break 2;
                    }
                }

                $paginationToken = $data['meta']['next_token'] ?? null;

            } while ($paginationToken && $tweets->count() < $limit);

            return $tweets;
        } catch (\Exception $e) {
            Log::error('Exception fetching Twitter tweets', ['error' => $e->getMessage()]);

            return $tweets;
        }
    }

    /**
     * Store tweets as ExternalPost records with deduplication.
     */
    private function storeExternalPosts(SocialAccount $account, Collection $tweets): Collection
    {
        $platform = Platform::where('slug', 'twitter')->first();
        $imported = collect();

        foreach ($tweets as $tweet) {
            $tweetId = $tweet['id'];
            $metrics = $tweet['public_metrics'] ?? [];

            $metricsData = [
                'views' => (int) ($metrics['impression_count'] ?? 0),
                'likes' => (int) ($metrics['like_count'] ?? 0),
                'comments' => (int) ($metrics['reply_count'] ?? 0),
                'shares' => (int) ($metrics['retweet_count'] ?? 0),
            ];

            // Update existing or create new
            $existing = ExternalPost::where('platform_id', $platform->id)
                ->where('external_id', $tweetId)
                ->first();

            if ($existing) {
                $existing->update([
                    'metrics' => $metricsData,
                    'metrics_synced_at' => now(),
                ]);

                continue;
            }

            $externalPost = ExternalPost::create([
                'social_account_id' => $account->id,
                'platform_id' => $platform->id,
                'external_id' => $tweetId,
                'content' => $tweet['text'] ?? null,
                'media_url' => $tweet['media_url'] ?? null,
                'post_url' => "https://twitter.com/i/web/status/{$tweetId}",
                'published_at' => $tweet['created_at'] ?? null,
                'metrics' => $metricsData,
                'metrics_synced_at' => now(),
            ]);

            $imported->push($externalPost);
        }

        $account->update(['last_history_import_at' => now()]);

        return $imported;
    }

    /**
     * Twitter Free tier has 1500 requests per 15 minutes.
     */
    public function getQuotaCost(int $postCount): array
    {
        $requests = (int) ceil($postCount / 100);

        return [
            'cost' => $requests,
            'description' => "Import de {$postCount} tweets : {$requests} requête(s) (limite: 1500/15min)",
        ];
    }
}
