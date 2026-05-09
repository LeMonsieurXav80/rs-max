<?php

namespace App\Services;

use App\Models\SocialAccount;
use App\Services\Adapters\BlueskyAdapter;
use App\Services\Adapters\RedditAdapter;

class PostUrlBuilder
{
    /**
     * Construit l'URL web permanente d'un post à partir de son external_id.
     * Retourne null si la plateforme ne permet pas de construire une URL stable.
     */
    public static function build(SocialAccount $account, string $externalId): ?string
    {
        $slug = $account->platform->slug ?? null;

        return match ($slug) {
            'twitter' => "https://x.com/{$account->platform_account_id}/status/{$externalId}",
            'threads' => 'https://www.threads.net/@' . ($account->credentials['username'] ?? $account->name) . "/post/{$externalId}",
            'bluesky' => BlueskyAdapter::buildPostUrl($account->credentials['handle'] ?? $account->name, $externalId),
            'facebook' => "https://www.facebook.com/{$externalId}",
            'instagram' => "https://www.instagram.com/p/{$externalId}/",
            'linkedin' => "https://www.linkedin.com/feed/update/{$externalId}/",
            'reddit' => RedditAdapter::buildPostUrl($account->credentials['subreddit'] ?? '', $externalId),
            default => null,
        };
    }
}
