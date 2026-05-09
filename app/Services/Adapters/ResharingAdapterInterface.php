<?php

namespace App\Services\Adapters;

use App\Models\SocialAccount;

interface ResharingAdapterInterface
{
    /**
     * Repartage natif (sans texte) d'un post existant — type retweet pur ou repost Bluesky.
     *
     * @param  string  $sourceExternalId  L'external_id du post source (même format que celui retourné par publish()).
     * @return array{success: bool, external_id: string|null, error: string|null}
     */
    public function nativeRepost(SocialAccount $account, string $sourceExternalId): array;

    /**
     * Repartage avec citation — quote tweet / quote post Bluesky.
     *
     * @param  string  $sourceExternalId  L'external_id du post source.
     * @return array{success: bool, external_id: string|null, error: string|null}
     */
    public function nativeQuote(SocialAccount $account, string $text, string $sourceExternalId, ?array $media = null): array;
}
