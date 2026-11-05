<?php

namespace App\Services\Adapters;

use App\Models\SocialAccount;

class AdapterFactory
{
    /** À préférer à `make()` : seul le compte sait s'il passe par l'API ou le navigateur. */
    public static function forAccount(SocialAccount $account): ?PlatformAdapterInterface
    {
        $slug = $account->platform->slug;

        return $slug === 'twitter' && $account->usesBrowser()
            ? app(TwitterBrowserAdapter::class)
            : self::make($slug);
    }

    public static function make(string $slug): ?PlatformAdapterInterface
    {
        return match ($slug) {
            'telegram' => new TelegramAdapter,
            'facebook' => new FacebookAdapter,
            'instagram' => new InstagramAdapter,
            'threads' => new ThreadsAdapter,
            'twitter' => new TwitterAdapter,
            'youtube' => new YouTubeAdapter,
            'bluesky' => new BlueskyAdapter,
            'linkedin' => new LinkedInAdapter,
            default => null,
        };
    }
}
