<?php

namespace App\Services\Inbox;

use App\Models\InboxItem;
use App\Models\SocialAccount;
use App\Services\Adapters\TwitterBrowserAdapter;
use App\Services\Browser\BrowserTaskQueue;
use App\Services\Browser\XSessionService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/** Mentions X lues et traitées depuis le Chromium du NAS. */
class TwitterBrowserInboxService implements PlatformInboxInterface
{
    public function __construct(private XSessionService $sessions, private TwitterBrowserAdapter $adapter, private BrowserTaskQueue $queue) {}

    public function fetchInbox(SocialAccount $account, ?Carbon $since = null): Collection
    {
        if (! $this->sessions->check($account)) {
            return collect();
        }

        $tweets = $this->queue->run($account, 'x.tweets', ['url' => 'https://x.com/notifications/mentions'])['tweets'] ?? [];

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
        app(\App\Services\Agent\XEngagementService::class)->assertReplyAvailable($account, (string) $item->external_id, ignoreInbox: $item->id);

        return $this->adapter->publishReply($account, $replyText, (string) $item->external_id, options: ['publication_key' => 'inbox-reply:'.$item->id, 'activity_window' => $item->reply_activity_window]);
    }

    public function like(SocialAccount $account, InboxItem $item): array
    {
        try {
            $payload = ['url' => 'https://x.com/'.ltrim($item->author_username, '@').'/status/'.$item->external_id,
                'username' => $account->browser_username, 'action' => 'like', 'publication_key' => 'inbox-like:'.$item->id,
                'target' => ['id' => (string) $item->external_id, 'author' => strtolower(ltrim($item->author_username, '@')), 'text' => $item->content]];
            if ($item->reply_activity_window) {
                $payload['activity_window'] = $item->reply_activity_window;
            }
            $result = $this->queue->run($account, 'x.like', $payload, 120);
            if (($result['confirmed'] ?? false) !== true || ($result['proof'] ?? null) !== 'after_reload') {
                throw new \App\Services\Browser\BrowserUncertainException('Like X non prouvé après rechargement.');
            }

            return ['success' => true, 'confirmed' => true];
        } catch (\App\Services\Browser\BrowserException $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'uncertain' => $e instanceof \App\Services\Browser\BrowserUncertainException];
        }
    }
}
