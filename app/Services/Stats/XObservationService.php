<?php

namespace App\Services\Stats;

use App\Models\BotActionLog;
use App\Models\ExternalPost;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\SocialAccountSnapshot;

/**
 * Enregistre ce que le navigateur du NAS a lu sur X.
 *
 * Mêmes tables que l'API X : stats, snapshots et abonnés restent lus par les
 * écrans existants, quelle que soit la provenance du relevé.
 */
class XObservationService
{
    public function __construct(private StatsSyncService $stats) {}

    /**
     * @param  array<int, array<string, mixed>>  $tweets
     * @return array{followers: bool, published: int, external_updated: int, external_created: int}
     */
    public function record(SocialAccount $account, ?int $followers, array $tweets): array
    {
        $result = ['followers' => false, 'published' => 0, 'external_updated' => 0, 'external_created' => 0];

        if ($followers !== null) {
            $account->update(['followers_count' => $followers, 'followers_synced_at' => now()]);
            $this->snapshotFollowers($account, $followers);
            $result['followers'] = true;
        }

        foreach ($tweets as $tweet) {
            $metrics = [
                'views' => $tweet['views'] ?? null,
                'likes' => isset($tweet['likes']) ? (int) $tweet['likes'] : null,
                'comments' => isset($tweet['replies']) ? (int) $tweet['replies'] : null,
                'shares' => isset($tweet['reposts']) ? (int) $tweet['reposts'] : null,
                'bookmarks' => isset($tweet['bookmarks']) ? (int) $tweet['bookmarks'] : null,
            ];

            $postPlatform = PostPlatform::where('social_account_id', $account->id)
                ->where('external_id', $tweet['id'])
                ->where('status', 'published')
                ->first();

            if ($postPlatform) {
                $this->stats->applyMetrics($postPlatform, $metrics + ['followers' => $followers]);
                $result['published']++;
            }

            $existing = ExternalPost::where('platform_id', $account->platform_id)
                ->where('external_id', $tweet['id'])
                ->first();

            if ($existing) {
                $existing->update([
                    'metrics' => array_replace($existing->metrics ?? [], array_filter($metrics, fn ($value) => $value !== null)),
                    'metrics_synced_at' => now(),
                ]);
                $result['external_updated']++;
            } elseif (! $postPlatform) {
                ExternalPost::create($this->newExternalPost($account, $tweet, $metrics));
                $result['external_created']++;
            }
        }

        if ($result['followers'] || array_sum(array_slice($result, 1)) > 0) {
            $this->log($account, $result);
        }

        return $result;
    }

    /** Plusieurs relevés par jour : `whereDate`, le format stocké du jour diffère entre SQLite et MySQL. */
    private function snapshotFollowers(SocialAccount $account, int $followers): void
    {
        $today = SocialAccountSnapshot::where('social_account_id', $account->id)
            ->where('granularity', 'daily')
            ->whereDate('date', today())
            ->first();

        if ($today) {
            $today->update(['followers_count' => $followers]);

            return;
        }

        SocialAccountSnapshot::create([
            'social_account_id' => $account->id,
            'date' => today(),
            'granularity' => 'daily',
            'followers_count' => $followers,
            'created_at' => now(),
        ]);
    }

    /** Réponses et reposts restent en base pour leurs stats, hors du flux d'adoption. */
    private function newExternalPost(SocialAccount $account, array $tweet, array $metrics): array
    {
        $unknown = ($tweet['classification_known'] ?? true) === false;
        $isNoise = $unknown || ! empty($tweet['is_reply']) || ! empty($tweet['is_repost']);

        return [
            'social_account_id' => $account->id,
            'platform_id' => $account->platform_id,
            'external_id' => $tweet['id'],
            'content' => $tweet['text'] ?? null,
            'media' => [],
            'post_url' => "https://x.com/i/web/status/{$tweet['id']}",
            'published_at' => $tweet['published_at'] ?? null,
            'metrics' => $metrics,
            'metrics_synced_at' => now(),
            'ignored_at' => $isNoise ? now() : null,
            'ignored_reason' => $unknown ? 'browser_unclassified' : ($isNoise ? 'auto_noise' : null),
        ];
    }

    private function log(SocialAccount $account, array $result): void
    {
        BotActionLog::create([
            'social_account_id' => $account->id,
            'action_type' => 'x.observation',
            'source' => 'browser',
            'target_uri' => 'https://x.com/i/user/'.$account->platform_account_id,
            'success' => true,
            'metadata' => $result,
            'performed_at' => now(),
        ]);
    }
}
