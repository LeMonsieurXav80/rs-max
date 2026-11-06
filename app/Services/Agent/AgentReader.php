<?php

namespace App\Services\Agent;

use App\Models\BotActionLog;
use App\Models\SocialAccount;
use App\Services\Browser\BrowserException;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Support\Str;

/**
 * Lectures de l'agent. Chaque ligne est réduite au strict utile : c'est ce qui décide
 * du nombre de jetons consommés à chaque appel.
 */
class AgentReader
{
    private const TIMEOUT = 80;

    private const TIKTOK_COMMENTS = 'https://www.tiktok.com/tiktokstudio/comment';

    private const X_MENTIONS = 'https://x.com/notifications/mentions';

    public function __construct(private BrowserTaskQueue $queue, private AgentCatalog $catalog) {}

    /** @return list<array<string, mixed>> */
    public function read(SocialAccount $account, string $action, int $limit): array
    {
        $items = match ($action) {
            'mentions.read' => $this->xMentions($account),
            'comments.read' => $this->tiktokComments($account, $limit),
            default => throw new BrowserException('Lecture inconnue.'),
        };

        BotActionLog::create([
            'social_account_id' => $account->id, 'action_type' => $this->catalog->logType($action), 'source' => AgentCatalog::SOURCE,
            'target_uri' => '', 'success' => true, 'metadata' => ['items' => count($items)], 'performed_at' => now(),
        ]);

        return array_slice($items, 0, $limit);
    }

    private function xMentions(SocialAccount $account): array
    {
        $tweets = $this->queue->run($account, 'x.tweets', ['url' => self::X_MENTIONS], self::TIMEOUT)['tweets'] ?? [];

        return array_map(fn (array $t) => [
            'id' => $t['id'], 'author' => $t['author'], 'text' => $this->cut($t['text'] ?? ''), 'at' => $t['published_at'] ?? null,
        ], $tweets);
    }

    private function tiktokComments(SocialAccount $account, int $limit): array
    {
        $payload = ['url' => self::TIKTOK_COMMENTS, 'username' => $account->browser_username, 'limit' => $limit];
        $items = $this->queue->run($account, 'tiktok.comments', $payload, self::TIMEOUT)['items'] ?? [];

        return array_map(fn (array $c) => [
            'author' => $c['author'], 'text' => $this->cut($c['content'] ?? ''), 'video' => $this->cut($c['caption'] ?? '', 60),
            'replied' => $c['replied'], 'liked' => $c['liked'],
        ], $items);
    }

    private function cut(string $text, ?int $limit = null): string
    {
        return Str::limit($text, $limit ?? config('agent.text_limit'), '…');
    }
}
