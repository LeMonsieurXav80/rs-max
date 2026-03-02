<?php

namespace App\Services\Import;

use App\Models\ExternalPost;
use App\Models\Platform;
use App\Models\SocialAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BlueskyImportService implements PlatformImportInterface
{
    private const API_BASE = 'https://public.api.bsky.app';

    /**
     * Import historical posts from a Bluesky account.
     */
    public function importHistory(SocialAccount $account, int $limit = 50): Collection
    {
        $did = $account->platform_account_id;
        $handle = $account->credentials['handle'] ?? null;

        if (! $did) {
            throw new \Exception('No DID found for Bluesky account');
        }

        $posts = $this->fetchPosts($did, $limit);

        return $this->storeExternalPosts($account, $posts, $handle);
    }

    /**
     * Fetch posts from Bluesky account via AT Protocol public API.
     */
    private function fetchPosts(string $did, int $limit): Collection
    {
        $posts = collect();

        try {
            $cursor = null;

            do {
                $params = [
                    'actor' => $did,
                    'limit' => min(50, $limit - $posts->count()),
                ];

                if ($cursor) {
                    $params['cursor'] = $cursor;
                }

                $response = Http::get(self::API_BASE . '/xrpc/app.bsky.feed.getAuthorFeed', $params);

                if (! $response->successful()) {
                    Log::error('Bluesky API error fetching posts', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    break;
                }

                $data = $response->json();

                foreach ($data['feed'] ?? [] as $item) {
                    $post = $item['post'] ?? null;

                    if (! $post) {
                        continue;
                    }

                    // Skip reposts (only import original posts)
                    if (isset($item['reason']) && ($item['reason']['$type'] ?? '') === 'app.bsky.feed.defs#reasonRepost') {
                        continue;
                    }

                    $posts->push($post);

                    if ($posts->count() >= $limit) {
                        break 2;
                    }
                }

                $cursor = $data['cursor'] ?? null;

            } while ($cursor && $posts->count() < $limit);

            return $posts;
        } catch (\Exception $e) {
            Log::error('Exception fetching Bluesky posts', ['error' => $e->getMessage()]);

            return $posts;
        }
    }

    /**
     * Store Bluesky posts as ExternalPost records with deduplication.
     */
    private function storeExternalPosts(SocialAccount $account, Collection $postsList, ?string $handle): Collection
    {
        $platform = Platform::where('slug', 'bluesky')->first();
        $imported = collect();

        foreach ($postsList as $post) {
            $uri = $post['uri'] ?? null;
            $cid = $post['cid'] ?? null;

            if (! $uri || ! $cid) {
                continue;
            }

            $externalId = $uri . '|' . $cid;

            $metricsData = [
                'likes' => (int) ($post['likeCount'] ?? 0),
                'comments' => (int) ($post['replyCount'] ?? 0),
                'shares' => (int) ($post['repostCount'] ?? 0) + (int) ($post['quoteCount'] ?? 0),
            ];

            $postUrl = $this->buildPostUrl($uri, $handle);

            // Update existing or create new
            $existing = ExternalPost::where('platform_id', $platform->id)
                ->where('external_id', $externalId)
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
                'external_id' => $externalId,
                'content' => $post['record']['text'] ?? null,
                'post_url' => $postUrl,
                'published_at' => $post['record']['createdAt'] ?? null,
                'metrics' => $metricsData,
                'metrics_synced_at' => now(),
            ]);

            $imported->push($externalPost);
        }

        $account->update(['last_history_import_at' => now()]);

        return $imported;
    }

    /**
     * Build a Bluesky post URL from the AT URI and handle.
     *
     * URI format: at://did/app.bsky.feed.post/rkey
     * URL format: https://bsky.app/profile/{handle}/post/{rkey}
     */
    private function buildPostUrl(string $uri, ?string $handle): ?string
    {
        if (! $handle) {
            return null;
        }

        // Extract rkey from AT URI: at://did/app.bsky.feed.post/rkey
        $parts = explode('/', $uri);
        $rkey = end($parts);

        if (! $rkey) {
            return null;
        }

        return "https://bsky.app/profile/{$handle}/post/{$rkey}";
    }

    /**
     * Bluesky public API has no rate limit concerns.
     */
    public function getQuotaCost(int $postCount): array
    {
        return [
            'cost' => 0,
            'description' => "Import de {$postCount} posts Bluesky (API publique, pas de quota)",
        ];
    }
}
