<?php

namespace App\Services\Import;

use App\Models\ExternalPost;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Services\Import\Concerns\ImportsIncrementally;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ThreadsImportService implements PlatformImportInterface
{
    use ImportsIncrementally;

    private const API_BASE = 'https://graph.threads.net/v1.0';

    /**
     * Import historical posts from a Threads account.
     */
    public function importHistory(SocialAccount $account, int $limit = 50): Collection
    {
        $credentials = $account->credentials;
        $accessToken = $credentials['access_token'] ?? null;
        $userId = $account->platform_account_id;

        if (! $accessToken) {
            throw new \Exception('No access token found for Threads account');
        }

        $since = $this->importSince($account);
        $threads = $this->fetchThreads($userId, $accessToken, $limit, $since);

        return $this->storeExternalPosts($account, $threads, $accessToken);
    }

    /**
     * Fetch threads from Threads account.
     */
    private function fetchThreads(string $userId, string $accessToken, int $limit, CarbonImmutable $since): Collection
    {
        $threads = collect();

        try {
            $url = self::API_BASE."/{$userId}/threads";
            $params = [
                'fields' => 'id,text,media_type,media_url,thumbnail_url,permalink,timestamp,children{id,media_type,media_url,thumbnail_url}',
                'limit' => min(100, $limit),
                'since' => $since->toDateString(),
                'access_token' => $accessToken,
            ];

            do {
                $response = Http::get($url, $params);

                if (! $response->successful()) {
                    Log::error('Threads API error fetching threads', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    break;
                }

                $data = $response->json();

                foreach ($data['data'] ?? [] as $item) {
                    // Flux du plus recent au plus ancien : le premier hors
                    // fenetre signale qu'il n'y a plus rien a prendre.
                    if ($this->isBeforeWindow($item['timestamp'] ?? null, $since)) {
                        break 2;
                    }

                    $threads->push($item);

                    if ($threads->count() >= $limit) {
                        break 2;
                    }
                }

                $url = $data['paging']['next'] ?? null;
                $params = [];

            } while ($url && $threads->count() < $limit);

            return $threads;
        } catch (\Exception $e) {
            Log::error('Exception fetching Threads', ['error' => $e->getMessage()]);

            return $threads;
        }
    }

    /**
     * Store threads as ExternalPost records with deduplication.
     */
    private function storeExternalPosts(SocialAccount $account, Collection $threadsList, string $accessToken): Collection
    {
        $platform = Platform::where('slug', 'threads')->first();
        $imported = collect();

        foreach ($threadsList as $thread) {
            $threadId = $thread['id'];

            $existing = $this->existingPost($platform->id, $threadId);
            $mediaItems = $this->extractMedia($thread);

            // Un appel d'insights PAR publication : on ne le paie que si la
            // reponse peut encore changer.
            if (! $this->needsMetricsRefresh($existing)) {
                if ($existing && $mediaItems && $mediaItems !== $existing->media) {
                    $existing->update(['media' => $mediaItems]);
                }

                continue;
            }

            $insights = $this->fetchThreadInsights($threadId, $accessToken);

            $metricsData = [
                'views' => $insights['views'],
                'likes' => $insights['likes'],
                'comments' => $insights['comments'],
                'shares' => $insights['shares'],
            ];

            if ($existing) {
                $existing->update([
                    'metrics' => $metricsData,
                    'metrics_synced_at' => now(),
                    // Rattrape les lignes importees avant l'ajout de la colonne.
                    'media' => $mediaItems ?: $existing->media,
                ]);

                continue;
            }

            $externalPost = ExternalPost::create([
                'social_account_id' => $account->id,
                'platform_id' => $platform->id,
                'external_id' => $threadId,
                'content' => $thread['text'] ?? null,
                'media_url' => $thread['media_url'] ?? null,
                'media' => $mediaItems,
                'post_url' => $thread['permalink'] ?? null,
                'published_at' => $thread['timestamp'] ?? null,
                'metrics' => $metricsData,
                'metrics_synced_at' => now(),
            ]);

            $imported->push($externalPost);
        }

        $account->update(['last_history_import_at' => now()]);

        return $imported;
    }

    /**
     * Liste des medias d'un post Threads : les enfants pour un carrousel,
     * le post lui-meme sinon. media_type vaut TEXT_POST, IMAGE, VIDEO,
     * CAROUSEL_ALBUM, AUDIO ou REPOST_FACADE.
     *
     * @return array<int, array{url: string, type: string, thumbnail_url: ?string, external_media_id: ?string}>
     */
    private function extractMedia(array $thread): array
    {
        $children = $thread['children']['data'] ?? [];
        $nodes = ! empty($children) ? $children : [$thread];

        $items = [];

        foreach ($nodes as $node) {
            $url = $node['media_url'] ?? $node['thumbnail_url'] ?? null;

            if (! $url) {
                continue;
            }

            $items[] = [
                'url' => $url,
                'type' => strtoupper($node['media_type'] ?? '') === 'VIDEO' ? 'video' : 'image',
                'thumbnail_url' => $node['thumbnail_url'] ?? null,
                'external_media_id' => $node['id'] ?? null,
            ];
        }

        return ExternalPost::normalizeMediaItems($items);
    }

    /**
     * Fetch insights for a specific thread.
     */
    private function fetchThreadInsights(string $threadId, string $accessToken): array
    {
        $defaults = ['views' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0];

        try {
            $response = Http::get(self::API_BASE."/{$threadId}/insights", [
                'metric' => 'views,likes,replies,reposts,quotes',
                'access_token' => $accessToken,
            ]);

            if ($response->successful()) {
                $data = $response->json();

                return $this->parseInsights($data['data'] ?? []);
            }

            Log::debug('Threads insights API error', [
                'thread_id' => $threadId,
                'status' => $response->status(),
            ]);
        } catch (\Exception $e) {
            Log::debug('Threads insights exception for '.$threadId);
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
            $value = (int) ($insight['values'][0]['value'] ?? 0);

            switch ($name) {
                case 'views':
                    $metrics['views'] = $value;
                    break;
                case 'likes':
                    $metrics['likes'] = $value;
                    break;
                case 'replies':
                    $metrics['comments'] = $value;
                    break;
                case 'reposts':
                case 'quotes':
                    $metrics['shares'] += $value;
                    break;
            }
        }

        return $metrics;
    }

    /**
     * Threads has reasonable rate limits.
     */
    public function getQuotaCost(int $postCount): array
    {
        return [
            'cost' => 0,
            'description' => "Import de {$postCount} posts Threads (pas de quota strict)",
        ];
    }
}
