<?php

namespace App\Services\Adapters;

use App\Models\SocialAccount;

interface PlatformAdapterInterface
{
    /**
     * Publish content to the platform.
     *
     * @param  SocialAccount  $account  The social account with credentials.
     * @param  string  $content  The text content to publish.
     * @param  array|null  $media  Optional media items (each with url, mimetype, size, title).
     * @return array{success: bool, external_id: string|null, error: string|null}
     */
    public function publish(SocialAccount $account, string $content, ?array $media = null): array;
}
