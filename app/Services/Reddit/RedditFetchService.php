<?php

namespace App\Services\Reddit;

use App\Models\RedditItem;
use App\Models\RedditSource;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RedditFetchService
{
    private const BASE_URL = 'https://www.reddit.com';

    private const USER_AGENT = 'RS-Max/1.0 (Content Aggregator)';

    /**
     * Test the connection to a subreddit and return its info.
     */
    public function testConnection(string $subreddit): array
    {
        $subreddit = $this->cleanSubreddit($subreddit);

        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->get(self::BASE_URL."/r/{$subreddit}/about.json");

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'error' => "Subreddit introuvable ou inaccessible (HTTP {$response->status()}).",
                ];
            }

            $data = $response->json('data', []);

            if (empty($data['display_name'])) {
                return [
                    'success' => false,
                    'error' => 'Subreddit introuvable.',
                ];
            }

            return [
                'success' => true,
                'name' => $data['display_name'] ?? $subreddit,
                'title' => $data['title'] ?? '',
                'subscribers' => $data['subscribers'] ?? 0,
                'description' => $data['public_description'] ?? '',
                'icon_url' => $data['icon_img'] ?? $data['community_icon'] ?? null,
            ];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return [
                'success' => false,
                'error' => 'Impossible de se connecter à Reddit.',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => "Erreur : {$e->getMessage()}",
            ];
        }
    }

    /**
     * Fetch posts from a Reddit source.
     */
    public function fetchSource(RedditSource $source): int
    {
        $subreddit = $this->cleanSubreddit($source->subreddit);
        $sortBy = $source->sort_by ?? 'hot';
        $timeFilter = $source->time_filter ?? 'week';
        $minScore = $source->min_score ?? 0;

        $newCount = 0;
        $after = null;

        try {
            do {
                $params = [
                    'limit' => 100,
                    'raw_json' => 1,
                ];

                if ($sortBy === 'top') {
                    $params['t'] = $timeFilter;
                }

                if ($after) {
                    $params['after'] = $after;
                }

                $response = Http::timeout(30)
                    ->withHeaders(['User-Agent' => self::USER_AGENT])
                    ->get(self::BASE_URL."/r/{$subreddit}/{$sortBy}.json", $params);

                if (! $response->successful()) {
                    Log::error('RedditFetchService: API error', [
                        'source' => $source->name,
                        'status' => $response->status(),
                    ]);
                    break;
                }

                $data = $response->json('data', []);
                $children = $data['children'] ?? [];

                if (empty($children)) {
                    break;
                }

                foreach ($children as $child) {
                    $post = $child['data'] ?? [];

                    if (empty($post['id'])) {
                        continue;
                    }

                    // Skip stickied posts
                    if (! empty($post['stickied'])) {
                        continue;
                    }

                    // Filter by minimum score
                    $score = (int) ($post['score'] ?? 0);
                    if ($score < $minScore) {
                        continue;
                    }

                    // Determine the URL - for self posts use permalink, for links use the URL
                    $isSelf = (bool) ($post['is_self'] ?? false);
                    $url = $isSelf
                        ? 'https://www.reddit.com'.($post['permalink'] ?? '')
                        : ($post['url'] ?? '');

                    // Extract thumbnail
                    $thumbnailUrl = null;
                    $thumbnail = $post['thumbnail'] ?? '';
                    if ($thumbnail && ! in_array($thumbnail, ['self', 'default', 'nsfw', 'spoiler', 'image'])) {
                        $thumbnailUrl = $thumbnail;
                    }
                    // Use preview image if available
                    if (! $thumbnailUrl && ! empty($post['preview']['images'][0]['source']['url'])) {
                        $thumbnailUrl = $post['preview']['images'][0]['source']['url'];
                    }

                    $publishedAt = ! empty($post['created_utc'])
                        ? Carbon::createFromTimestamp($post['created_utc'])
                        : null;

                    $item = RedditItem::updateOrCreate(
                        [
                            'reddit_source_id' => $source->id,
                            'reddit_post_id' => $post['id'],
                        ],
                        [
                            'title' => $post['title'] ?? '',
                            'url' => $url,
                            'selftext' => $post['selftext'] ?? null,
                            'author' => $post['author'] ?? null,
                            'score' => $score,
                            'num_comments' => (int) ($post['num_comments'] ?? 0),
                            'permalink' => 'https://www.reddit.com'.($post['permalink'] ?? ''),
                            'thumbnail_url' => $thumbnailUrl,
                            'is_self' => $isSelf,
                            'published_at' => $publishedAt,
                            'fetched_at' => now(),
                        ]
                    );

                    if ($item->wasRecentlyCreated) {
                        $newCount++;
                    }
                }

                $after = $data['after'] ?? null;

                // Rate limiting: wait between pages
                if ($after) {
                    usleep(500000); // 500ms
                }

            } while ($after);

            $source->update(['last_fetched_at' => now()]);

            Log::info('RedditFetchService: Fetch complete', [
                'source' => $source->name,
                'new_items' => $newCount,
            ]);

            return $newCount;

        } catch (\Exception $e) {
            Log::error('RedditFetchService: Error fetching source', [
                'source' => $source->name,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Clean subreddit input (remove r/ prefix, URL, etc.)
     */
    private function cleanSubreddit(string $input): string
    {
        $input = trim($input, '/ ');

        // Extract from URL
        if (preg_match('/reddit\.com\/r\/([\w]+)/i', $input, $matches)) {
            return $matches[1];
        }

        // Remove r/ prefix
        if (str_starts_with($input, 'r/')) {
            return substr($input, 2);
        }

        return $input;
    }
}
