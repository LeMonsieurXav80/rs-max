<?php

namespace App\Services\Inbox;

use App\Models\InboxItem;
use App\Models\SocialAccount;
use App\Services\Browser\BrowserException;
use App\Services\Browser\BrowserTaskQueue;
use App\Services\Browser\BrowserUncertainException;
use App\Services\Browser\TikTokSessionService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/** Commentaires TikTok lus et traités depuis le Chromium du VPS : aucune API publique. */
class TikTokInboxService implements PlatformInboxInterface
{
    private const COMMENTS_URL = 'https://www.tiktok.com/tiktokstudio/comment';

    private const READ_TIMEOUT = 80;

    private const SEND_TIMEOUT = 100;

    public function __construct(private TikTokSessionService $sessions, private BrowserTaskQueue $queue) {}

    public function fetchInbox(SocialAccount $account, ?Carbon $since = null): Collection
    {
        try {
            if (! $this->sessions->check($account)) {
                return collect();
            }

            $payload = ['url' => self::COMMENTS_URL, 'username' => $account->browser_username, 'limit' => config('agent.read_limit')];
            $comments = $this->queue->run($account, 'tiktok.comments', $payload, self::READ_TIMEOUT)['items'] ?? [];
        } catch (BrowserException $e) {
            Log::warning('TikTok : commentaires illisibles', ['account_id' => $account->id, 'error' => $e->getMessage()]);

            return collect();
        }

        return collect($comments)
            ->filter(fn (array $c) => ! $c['replied'] && filled($c['content']) && filled($c['caption']) && $c['author'] !== $account->browser_username)
            ->map(function (array $c) use ($account) {
                // TikTok Studio n'expose aucun identifiant : le commentaire se reconnaît à son contenu.
                $id = sha1($account->id.'|'.$c['author'].'|'.$c['content'].'|'.$c['caption']);

                return [
                    'type' => 'comment',
                    'external_id' => $id,
                    'external_post_id' => sha1($c['caption']),
                    'parent_id' => null,
                    'conversation_key' => $id,
                    'author_name' => $c['author'],
                    'author_username' => $c['author'],
                    'author_avatar_url' => $c['avatar'] ?? null,
                    'author_external_id' => null,
                    'content' => $c['content'],
                    'post_content' => $c['caption'],
                    'post_url' => self::COMMENTS_URL,
                    'posted_at' => $this->postedAt($c['date'] ?? null),
                ];
            })
            ->values();
    }

    public function sendReply(SocialAccount $account, InboxItem $item, string $replyText): array
    {
        $payload = ['url' => self::COMMENTS_URL, 'username' => $account->browser_username, 'text' => $replyText,
            'target' => ['author' => $item->author_username, 'content' => $item->content, 'caption' => $item->post_content]];

        try {
            $this->queue->run($account, 'tiktok.reply', $payload, self::SEND_TIMEOUT);
        } catch (BrowserException $e) {
            return ['success' => false, 'external_id' => null, 'error' => $e->getMessage(), 'uncertain' => $e instanceof BrowserUncertainException];
        }

        return ['success' => true, 'external_id' => null, 'error' => null];
    }

    private function postedAt(?string $date): Carbon
    {
        return now();
    }
}
