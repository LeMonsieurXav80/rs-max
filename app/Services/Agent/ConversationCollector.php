<?php

namespace App\Services\Agent;

use App\Models\AgentProposal;
use App\Models\ExternalPost;
use App\Models\InboxItem;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Browser\BrowserException;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Met chaque réseau au même format : une conversation est une suite de messages, du
 * message d'origine du compte jusqu'au dernier message reçu. Le reste du circuit
 * (génération, validation) ne connaît que ce format.
 */
class ConversationCollector
{
    private const TIMEOUT = 80;

    private const TIKTOK_COMMENTS = 'https://www.tiktok.com/tiktokstudio/comment';

    public function __construct(private BrowserTaskQueue $queue) {}

    /** @return list<array{key: string, inbox_item_id: ?int, author: ?string, target: ?array, thread: list<array{author: string, mine: bool, text: string}>}> */
    public function collect(SocialAccount $account, int $limit): array
    {
        return $account->platform->slug === 'tiktok' ? $this->tiktok($account, $limit) : $this->inbox($account, $limit);
    }

    private function known(SocialAccount $account): array
    {
        return AgentProposal::where('social_account_id', $account->id)->pluck('target_key')->all();
    }

    private function tiktok(SocialAccount $account, int $limit): array
    {
        $payload = ['url' => self::TIKTOK_COMMENTS, 'username' => $account->browser_username, 'limit' => config('agent.read_limit')];
        $items = $this->queue->run($account, 'tiktok.comments', $payload, self::TIMEOUT)['items'] ?? [];
        $known = $this->known($account);

        return collect($items)
            ->filter(fn (array $c) => ! $c['replied'] && filled($c['content']) && filled($c['caption']) && $c['author'] !== $account->browser_username)
            ->map(fn (array $c) => [
                'key' => sha1($c['author'].'|'.$c['content'].'|'.$c['caption']),
                'inbox_item_id' => null,
                'author' => $c['author'],
                'target' => ['author' => $c['author'], 'content' => $c['content'], 'caption' => $c['caption']],
                'thread' => [$this->message($account->name, true, $c['caption']), $this->message($c['author'], false, $c['content'])],
            ])
            ->reject(fn (array $c) => in_array($c['key'], $known, true))
            ->take($limit)->values()->all();
    }

    private function inbox(SocialAccount $account, int $limit): array
    {
        $items = InboxItem::where('social_account_id', $account->id)
            ->whereIn('status', ['unread', 'read'])->whereNull('replied_at')->whereNull('reply_scheduled_at')
            ->whereNotIn('external_id', collect($this->known($account))->map(fn ($k) => $this->externalId($k))->all())
            ->orderByDesc('posted_at')->limit($limit)->get();

        return $items->map(fn (InboxItem $item) => $this->fromInbox($account, $item))->filter()->values()->all();
    }

    private function key(InboxItem $item): string
    {
        return 'inbox:'.$item->external_id;
    }

    private function externalId(string $key): string
    {
        return Str::after($key, 'inbox:');
    }

    private function fromInbox(SocialAccount $account, InboxItem $item): ?array
    {
        $thread = $account->usesBrowser() && $account->platform->slug === 'twitter' ? $this->xThread($account, $item) : $this->inboxThread($account, $item);

        return $thread === null ? null : [
            'key' => $this->key($item), 'inbox_item_id' => $item->id, 'author' => $item->author_username ?? $item->author_name, 'target' => null, 'thread' => $thread,
        ];
    }

    /** Sur X, seul le fil affiché donne le tweet d'origine : sans lui, pas de proposition. */
    private function xThread(SocialAccount $account, InboxItem $item): ?array
    {
        try {
            $conversation = $this->queue->run($account, 'x.thread', ['url' => $item->post_url, 'id' => (string) $item->external_id], self::TIMEOUT)['conversation'] ?? [];
        } catch (BrowserException $e) {
            Log::info('Agent : fil X illisible', ['item' => $item->id, 'error' => $e->getMessage()]);

            return null;
        }

        return array_map(fn (array $p) => $this->message($p['author'], strtolower($p['author']) === strtolower((string) $account->browser_username),
            trim($p['text'].($p['quote'] ? ' [cite : '.$p['quote'].']' : ''))), $conversation);
    }

    private function inboxThread(SocialAccount $account, InboxItem $item): array
    {
        $original = $this->originalText($account, $item);
        $thread = $original ? [$this->message($account->name, true, $original)] : [];
        $root = $item->parent_id ?: $item->external_id;

        $previous = InboxItem::where('social_account_id', $account->id)->where('id', '!=', $item->id)
            ->where(fn ($q) => $item->type === 'dm' && $item->external_post_id
                ? $q->where('external_post_id', $item->external_post_id)
                : $q->where('external_id', $root)->orWhere('parent_id', $root))
            ->where(fn ($q) => $q->whereNull('posted_at')->orWhere('posted_at', '<=', $item->posted_at))
            ->orderBy('posted_at')->limit(config('agent.thread_messages'))->get();

        foreach ($previous as $earlier) {
            $thread[] = $this->message($earlier->author_username ?? $earlier->author_name ?? 'inconnu', false, (string) $earlier->content);
            if ($earlier->reply_content) {
                $thread[] = $this->message($account->name, true, $earlier->reply_content);
            }
        }

        $thread[] = $this->message($item->author_username ?? $item->author_name ?? 'inconnu', false, (string) $item->content);

        return array_slice($thread, -config('agent.thread_messages'));
    }

    private function originalText(SocialAccount $account, InboxItem $item): ?string
    {
        if (! $item->external_post_id || $item->type === 'dm') {
            return null;
        }

        return ExternalPost::where('social_account_id', $account->id)->where('external_id', $item->external_post_id)->value('content')
            ?? PostPlatform::with('post')->where('social_account_id', $account->id)->where('external_id', $item->external_post_id)->first()?->post?->content_fr;
    }

    private function message(string $author, bool $mine, string $text): array
    {
        return ['author' => $author, 'mine' => $mine, 'text' => Str::limit($text, config('agent.thread_text_limit'), '…')];
    }
}
