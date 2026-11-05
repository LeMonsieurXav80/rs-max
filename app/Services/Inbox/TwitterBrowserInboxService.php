<?php

namespace App\Services\Inbox;

use App\Models\InboxItem;
use App\Models\SocialAccount;
use App\Services\Adapters\TwitterBrowserAdapter;
use App\Services\Browser\XSessionService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/** Mentions X lues et traitées depuis le navigateur du VPS. */
class TwitterBrowserInboxService implements PlatformInboxInterface
{
    public function __construct(private XSessionService $sessions, private TwitterBrowserAdapter $adapter) {}

    public function fetchInbox(SocialAccount $account, ?Carbon $since = null): Collection
    {
        if (! $this->sessions->check($account)) {
            return collect();
        }

        $page = $this->sessions->open($account, 'https://x.com/notifications/mentions');

        try {
            $page->waitFor('article[data-testid="tweet"]', 20);
            $tweets = $page->tweets();
        } finally {
            $page->close();
        }

        return collect($tweets)
            ->reject(fn (array $t) => $t['author'] === strtolower((string) $account->browser_username))
            ->map(fn (array $t) => [
                'type' => 'comment',
                'external_id' => $t['id'],
                'external_post_id' => $t['id'],
                'parent_id' => null,
                'conversation_key' => $t['id'],
                'author_name' => $t['name'],
                'author_username' => $t['author'],
                'author_avatar_url' => $t['avatar'],
                'author_external_id' => null,
                'content' => $t['text'],
                'post_url' => "https://x.com/{$t['author']}/status/{$t['id']}",
                'posted_at' => $t['published_at'] ? Carbon::parse($t['published_at']) : null,
            ])
            ->values();
    }

    public function sendReply(SocialAccount $account, InboxItem $item, string $replyText): array
    {
        return $this->adapter->publishReply($account, $replyText, (string) $item->external_id);
    }
}
