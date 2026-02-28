<?php

namespace App\Services\Adapters;

use App\Models\SocialAccount;

interface ThreadableAdapterInterface
{
    /**
     * Publish a reply to an existing post (for thread chaining).
     *
     * @param  SocialAccount  $account  The social account with credentials.
     * @param  string  $content  The text content to publish.
     * @param  string  $replyToId  The external_id of the post to reply to.
     * @param  array|null  $media  Optional media items (each with url, mimetype, size, title).
     * @param  array|null  $options  Optional options.
     * @return array{success: bool, external_id: string|null, error: string|null}
     */
    public function publishReply(SocialAccount $account, string $content, string $replyToId, ?array $media = null, ?array $options = null): array;
}
