<?php

namespace App\Services\Inbox;

use App\Models\ExternalPost;
use App\Models\InboxItem;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Browser\BrowserException;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Met chaque réseau au même format : une conversation est une suite de messages, du
 * message d'origine du compte jusqu'au dernier message reçu.
 */
class InboxConversationBuilder
{
    private const TIMEOUT = 80;

    public function __construct(private BrowserTaskQueue $queue) {}

    /**
     * @param  Collection<int, InboxItem>  $items
     * @return list<array{inbox_item_id: int, thread: list<array{author: string, mine: bool, text: string}>}>
     */
    public function build(SocialAccount $account, Collection $items): array
    {
        return $items->map(function (InboxItem $item) use ($account) {
            $thread = $account->usesBrowser() && $account->platform->slug === 'twitter' ? $this->xThread($account, $item) : $this->inboxThread($account, $item);

            return $thread === null ? null : ['inbox_item_id' => $item->id, 'thread' => $thread];
        })->filter()->values()->all();
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
                : $q->where('external_id', $root)->orWhere('parent_id', $root)->orWhere('reply_external_id', $root))
            ->where(fn ($q) => $q->whereNull('posted_at')->orWhere('posted_at', '<=', $item->posted_at))
            ->orderBy('posted_at')->limit(config('agent.thread_messages'))->get();

        $policy = app(InboxFollowup::class);
        foreach ($previous as $earlier) {
            $thread[] = $this->message($earlier->author_username ?? $earlier->author_name ?? 'inconnu', $policy->isOwn($earlier, $account), (string) $earlier->content);
            if ($earlier->reply_content) {
                $thread[] = $this->message($account->name, true, $earlier->reply_content);
            }
        }

        $thread[] = $this->message($item->author_username ?? $item->author_name ?? 'inconnu', false, (string) $item->content);

        return array_slice($thread, -config('agent.thread_messages'));
    }

    public function originalText(SocialAccount $account, InboxItem $item): ?string
    {
        if ($item->post_content) {
            return $item->post_content;
        }

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
