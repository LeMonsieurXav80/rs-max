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
use Illuminate\Support\Sleep;

/** Commentaires TikTok lus et traités depuis le Chromium du VPS : aucune API publique. */
class TikTokInboxService implements PlatformInboxInterface
{
    private const COMMENTS_URL = 'https://www.tiktok.com/tiktokstudio/comment';

    private const READ_TIMEOUT = 50;

    private const SEND_TIMEOUT = 60;

    private const CONFIRM_DELAY = 5;

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
        return $this->act($account, $item, 'tiktok.reply', ['text' => $replyText], 'replied', 'TikTok n’affiche pas la réponse : à vérifier, ne pas renvoyer sans contrôle.');
    }

    public function like(SocialAccount $account, InboxItem $item): array
    {
        return $this->act($account, $item, 'tiktok.like', [], 'liked', 'TikTok n’affiche pas le like : à vérifier.');
    }

    /** Le 06/11/2026, la page a montré des réponses que TikTok n'a jamais gardées : seule une relecture prouve l'action. */
    private function act(SocialAccount $account, InboxItem $item, string $kind, array $payload, string $proof, string $unconfirmed): array
    {
        $target = ['author' => $item->author_username, 'content' => $item->content, 'caption' => $item->post_content];

        try {
            $this->queue->run($account, $kind, $payload + ['url' => self::COMMENTS_URL, 'username' => $account->browser_username, 'target' => $target], self::SEND_TIMEOUT);
            Sleep::for(self::CONFIRM_DELAY)->seconds();
            $comments = $this->queue->run($account, 'tiktok.comments', ['url' => self::COMMENTS_URL, 'username' => $account->browser_username, 'limit' => config('agent.read_limit')], self::READ_TIMEOUT)['items'] ?? [];
        } catch (BrowserException $e) {
            return ['success' => false, 'external_id' => null, 'error' => $e->getMessage(), 'uncertain' => $e instanceof BrowserUncertainException];
        }

        $seen = collect($comments)->first(fn (array $c) => $c['author'] === $target['author'] && $c['content'] === $target['content'] && $c['caption'] === $target['caption']);

        return ($seen[$proof] ?? false) === true
            ? ['success' => true, 'external_id' => null, 'error' => null]
            : ['success' => false, 'external_id' => null, 'error' => $unconfirmed, 'uncertain' => true];
    }

    /** Studio n'affiche qu'un texte : « 1h ago », « il y a 2 j », ou une date ; à défaut, la date de lecture. */
    private function postedAt(?string $date): Carbon
    {
        if (preg_match('/(\d+)\s*(min|m|h|d|j|w|sem)/i', (string) $date, $match)) {
            $unit = match (strtolower($match[2])) {
                'min', 'm' => 'minutes',
                'h' => 'hours',
                'd', 'j' => 'days',
                default => 'weeks',
            };

            return now()->sub($unit, (int) $match[1]);
        }

        try {
            return Carbon::parse((string) $date);
        } catch (\Throwable) {
            return now();
        }
    }
}
