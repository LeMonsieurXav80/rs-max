<?php

namespace App\Services\Stats;

use App\Models\SocialAccount;
use App\Services\Browser\XPage;
use App\Services\Browser\XSessionService;

/** Relève les abonnés et les stats sur le profil X, dans le navigateur du VPS. */
class XBrowserStatsService
{
    public function __construct(private XSessionService $sessions, private XObservationService $observations) {}

    /** @return array<string, mixed>|null `null` si la session n'est plus valide. */
    public function sync(SocialAccount $account, int $screens = 3): ?array
    {
        if (! $this->sessions->check($account)) {
            return null;
        }

        $username = $account->browser_username;
        $page = $this->sessions->open($account, "https://x.com/{$username}");

        try {
            $page->waitFor('article[data-testid="tweet"]', 20);
            $followers = $page->followers();
            $tweets = $this->read($page, $username, $screens);
        } finally {
            $page->close();
        }

        return $this->observations->record($account, $followers, $tweets);
    }

    private function read(XPage $page, string $username, int $screens): array
    {
        $found = [];

        for ($i = 0; $i < $screens; $i++) {
            foreach ($page->tweets() as $tweet) {
                if ($tweet['author'] === strtolower($username)) {
                    $found[$tweet['id']] = [
                        'id' => $tweet['id'],
                        'text' => $tweet['text'],
                        'published_at' => $tweet['published_at'],
                        'is_reply' => $tweet['reply'],
                        'is_repost' => $tweet['reposted'],
                    ] + XPage::parseStats($tweet['stats']);
                }
            }

            $page->scrollDown();
            usleep(1500000);
        }

        return array_values($found);
    }
}
