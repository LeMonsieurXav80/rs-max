<?php

namespace App\Services\Stats;

use App\Models\SocialAccount;
use App\Services\Browser\BrowserTaskQueue;
use App\Services\Browser\XSessionService;
use Illuminate\Support\Facades\DB;

/** Relève les abonnés et les stats sur le profil X, dans le Chromium du NAS. */
class XBrowserStatsService
{
    public function __construct(private XSessionService $sessions, private BrowserTaskQueue $queue, private XObservationService $observations) {}

    /** @return array<string, mixed>|null `null` si la session n'est plus valide. */
    public function sync(SocialAccount $account, int $screens = 3, ?int $limit = null): ?array
    {
        if (! $this->sessions->check($account)) {
            return null;
        }

        $username = strtolower((string) $account->browser_username);
        $page = $this->queue->run($account, 'x.tweets', ['url' => "https://x.com/{$username}", 'screens' => $screens, 'followers' => true], 180);

        return $this->recordPage($account, $username, $page, $limit);
    }

    public function recordPage(SocialAccount $account, string $username, array $page, ?int $limit = null): array
    {
        $tweets = collect($page['tweets'] ?? [])
            ->filter(fn (array $t) => strcasecmp($t['author'], $username) === 0)
            ->unique('id', true)
            ->when($limit !== null, fn ($posts) => $posts->take($limit))
            ->map(fn (array $t) => [
                'id' => $t['id'],
                'text' => $t['text'],
                'published_at' => $t['published_at'] ?? null,
                'is_reply' => $t['reply'],
                'is_repost' => $t['reposted'],
                'classification_known' => $t['classification_known'] ?? true,
            ] + self::parseStats((string) $t['stats']))
            ->values()->all();

        return DB::transaction(function () use ($account, $page, $tweets) {
            $locked = SocialAccount::lockForUpdate()->findOrFail($account->id);
            $observation = $page['followers_observation'] ?? null;
            if ($observation !== null) {
                $observation['value'] = (int) $observation['value'];
                $locked->update(['browser_followers_observation' => $observation + ['read_at' => now()->toIso8601String()]]);
            }
            $exact = $observation === null ? ($page['followers'] ?? null)
                : ($observation['precision'] === 'exact' ? $observation['value'] : null);

            return $this->observations->record($locked, $exact, $tweets) + [
                'followers_observation' => $locked->browser_followers_observation,
                'followers_observed' => $observation !== null,
            ];
        });
    }

    /** « 3 réponses, 2 reposts, 12 J'aime, 1 signet, 900 vues », selon la langue du compte. */
    public static function parseStats(string $label): array
    {
        $patterns = ['replies' => 'r[ée]ponses?|r[ée]pondre|repl(?:y|ies)', 'reposts' => 'reposts?|retweets?', 'likes' => "j[’']+aime|aimer|likes?",
            'bookmarks' => 'signets?|bookmarks?', 'views' => 'vues?|views?'];
        $stats = [];

        foreach ($patterns as $key => $pattern) {
            if (preg_match('/([\d\s\x{202F}\x{A0},.]*\d)\s*(?:'.$pattern.')/iu', $label, $m)) {
                $stats[$key] = (int) preg_replace('/\D/', '', $m[1]);
            }
        }

        return $stats;
    }
}
