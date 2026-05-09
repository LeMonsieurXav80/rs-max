<?php

namespace App\Services\Adapters;

class AdapterFactory
{
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
            'reddit' => new RedditAdapter,
            'linkedin' => new LinkedInAdapter,
            default => null,
        };
    }
}
