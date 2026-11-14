<?php

namespace App\Services\Agent\Feed;

use App\Models\SocialAccount;

final class FeedNetworks
{
    public static function for(SocialAccount $account): FeedNetwork
    {
        return match ($account->platform->slug) {
            'twitter' => app(XFeedNetwork::class),
            'threads' => app(ThreadsFeedNetwork::class),
        };
    }
}
