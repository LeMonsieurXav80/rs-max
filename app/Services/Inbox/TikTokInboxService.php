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

/** Commentaires TikTok lus et traités depuis le navigateur Companion. */
class TikTokInboxService implements PlatformInboxInterface
{
    public const COMMENTS_URL = 'https://www.tiktok.com/tiktokstudio/comment';

    private const READ_TIMEOUT = 50;

    private const ACTION_TIMEOUT = 120;

    private const REPLY_TIMEOUT = 330;

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
                $id = self::commentId($account, $c['author'], $c['content'], $c['caption']);

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

    public static function commentId(SocialAccount $account, string $author, string $content, string $caption): string
    {
        return sha1($account->id.'|'.$author.'|'.$content.'|'.$caption);
    }

    public function sendReply(SocialAccount $account, InboxItem $item, string $replyText): array
    {
        return $this->act($account, $item, 'tiktok.reply', ['text' => $replyText]);
    }

    public function like(SocialAccount $account, InboxItem $item): array
    {
        return $this->act($account, $item, 'tiktok.like', []);
    }

    /** Companion garde le profil occupé jusqu'à la vérification après rechargement. */
    private function act(SocialAccount $account, InboxItem $item, string $kind, array $payload): array
    {
        if ($item->reply_activity_window) {
            $payload['activity_window'] = $item->reply_activity_window;
        }
        $target = ['author' => $item->author_username, 'content' => $item->content, 'caption' => $item->post_content];

        try {
            $result = $this->queue->run($account, $kind, $payload + [
                'url' => self::COMMENTS_URL,
                'username' => $account->browser_username,
                'target' => $target,
                'confirm_after_reload' => true,
            ], $kind === 'tiktok.reply' ? self::REPLY_TIMEOUT : self::ACTION_TIMEOUT);
        } catch (BrowserException $e) {
            return ['success' => false, 'external_id' => null, 'error' => $e->getMessage(), 'uncertain' => $e instanceof BrowserUncertainException];
        }

        return ($result['confirmed'] ?? false) === true
            ? ['success' => true, 'external_id' => ($result['verification_source'] ?? null) === 'anonymous_browser' ? ($result['comment_id'] ?? null) : null, 'error' => null]
            : ['success' => false, 'external_id' => null, 'error' => 'Action TikTok non confirmée après rechargement. Vérifiez le fil avant toute relance.', 'uncertain' => true];
    }

    /** Studio fournit une date affichée ou un âge relatif. */
    private function postedAt(?string $date): ?Carbon
    {
        $date = trim((string) $date);
        if (preg_match('/^(?:il y a\s+)?(\d+)\s*([a-zéû.]+)(?:\s+ago)?$/iu', $date, $match)) {
            $unit = match (mb_strtolower(rtrim($match[2], '.'))) {
                's', 'sec', 'second', 'seconds', 'seconde', 'secondes' => 'seconds',
                'm', 'min', 'minute', 'minutes' => 'minutes',
                'h', 'hour', 'hours', 'heure', 'heures' => 'hours',
                'd', 'j', 'day', 'days', 'jour', 'jours' => 'days',
                'w', 'sem', 'week', 'weeks', 'semaine', 'semaines' => 'weeks',
                'mo', 'month', 'months', 'mois' => 'months',
                'y', 'a', 'an', 'ans', 'year', 'years', 'année', 'années' => 'years',
                default => null,
            };

            return $unit === null ? null : now()->sub($unit, (int) $match[1]);
        }
        if (in_array(mb_strtolower($date), ['just now', 'à l’instant', "à l'instant"], true)) {
            return now();
        }
        if (in_array(mb_strtolower($date), ['yesterday', 'hier'], true)) {
            return now()->subDay()->startOfDay();
        }
        if (! preg_match('/\b\d{4}\b/', $date)) {
            return null;
        }

        try {
            return Carbon::parse($date)->setTimezone(config('app.timezone'));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
