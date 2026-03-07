<?php

namespace App\Services\Inbox;

use App\Models\InboxItem;
use App\Models\SocialAccount;
use App\Services\Adapters\BlueskyAdapter;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BlueskyInboxService implements PlatformInboxInterface
{
    private const PUBLIC_API = 'https://public.api.bsky.app';

    private const PDS_BASE = 'https://bsky.social';

    public function fetchInbox(SocialAccount $account, ?Carbon $since = null): Collection
    {
        $items = collect();
        $credentials = $account->credentials;
        $did = $credentials['did'];
        $handle = $credentials['handle'];

        try {
            // Fetch author's original posts only (no replies/reposts)
            $feedResponse = Http::get(self::PUBLIC_API . '/xrpc/app.bsky.feed.getAuthorFeed', [
                'actor' => $did,
                'limit' => 25,
                'filter' => 'posts_no_replies',
            ]);

            if (! $feedResponse->successful()) {
                Log::warning('BlueskyInboxService: failed to fetch feed', ['status' => $feedResponse->status()]);

                return $items;
            }

            $feed = $feedResponse->json('feed', []);

            foreach ($feed as $feedItem) {
                $post = $feedItem['post'] ?? null;
                if (! $post) {
                    continue;
                }

                // Skip posts that are replies to other posts (only process original posts)
                if (isset($post['record']['reply'])) {
                    continue;
                }

                $postUri = $post['uri'];
                $postCid = $post['cid'];

                $replyCount = $post['replyCount'] ?? 0;
                if ($replyCount === 0) {
                    continue;
                }

                // Fetch thread to get replies
                $threadResponse = Http::get(self::PUBLIC_API . '/xrpc/app.bsky.feed.getPostThread', [
                    'uri' => $postUri,
                    'depth' => 2,
                    'parentHeight' => 0,
                ]);

                if (! $threadResponse->successful()) {
                    continue;
                }

                $replies = $threadResponse->json('thread.replies', []);
                $this->extractReplies($items, $replies, $postUri, $postCid, $did, $handle, null, null);
            }

            // Fetch DMs (authenticated)
            $this->fetchDMs($items, $account, $since);
        } catch (\Throwable $e) {
            Log::error('BlueskyInboxService: fetchInbox failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $items;
    }

    private function extractReplies(Collection $items, array $replies, string $postUri, string $postCid, string $ownDid, string $handle, ?string $threadConversationKey, ?string $parentExternalId): void
    {
        foreach ($replies as $reply) {
            $replyPost = $reply['post'] ?? null;
            if (! $replyPost) {
                continue;
            }

            $authorDid = $replyPost['author']['did'] ?? null;
            $replyUri = $replyPost['uri'];
            $replyCid = $replyPost['cid'];
            $externalId = "{$replyUri}|{$replyCid}";

            // For top-level replies (direct replies to the post), each starts its own conversation thread.
            // For nested replies, they inherit the conversation key of their top-level ancestor.
            $isTopLevel = $threadConversationKey === null;
            $conversationKey = $isTopLevel ? "thread:{$externalId}" : $threadConversationKey;

            // Skip our own replies but still recurse into their children
            // (someone might reply to our reply)
            if ($authorDid === $ownDid) {
                $nestedReplies = $reply['replies'] ?? [];
                if (! empty($nestedReplies)) {
                    $this->extractReplies($items, $nestedReplies, $postUri, $postCid, $ownDid, $handle, $conversationKey, $externalId);
                }

                continue;
            }

            $rkey = basename($replyUri);
            $replyHandle = $replyPost['author']['handle'] ?? '';

            // Check for embedded images
            $embed = $replyPost['embed'] ?? null;
            $mediaUrl = null;
            $mediaType = null;
            if ($embed) {
                $embedType = $embed['$type'] ?? '';
                if ($embedType === 'app.bsky.embed.images#view' && ! empty($embed['images'])) {
                    $mediaUrl = $embed['images'][0]['fullsize'] ?? $embed['images'][0]['thumb'] ?? null;
                    $mediaType = 'image';
                }
            }

            $items->push([
                'type' => $isTopLevel ? 'comment' : 'reply',
                'external_id' => $externalId,
                'external_post_id' => "{$postUri}|{$postCid}",
                'parent_id' => $parentExternalId,
                'conversation_key' => $conversationKey,
                'author_name' => $replyPost['author']['displayName'] ?? $replyHandle,
                'author_username' => $replyHandle,
                'author_avatar_url' => $replyPost['author']['avatar'] ?? null,
                'author_external_id' => $authorDid,
                'content' => $replyPost['record']['text'] ?? null,
                'media_url' => $mediaUrl,
                'media_type' => $mediaType,
                'post_url' => "https://bsky.app/profile/{$replyHandle}/post/{$rkey}",
                'posted_at' => isset($replyPost['record']['createdAt']) ? Carbon::parse($replyPost['record']['createdAt']) : null,
            ]);

            // Recursive nested replies — pass this reply's conversation key and external_id as parent
            $nestedReplies = $reply['replies'] ?? [];
            if (! empty($nestedReplies)) {
                $this->extractReplies($items, $nestedReplies, $postUri, $postCid, $ownDid, $handle, $conversationKey, $externalId);
            }
        }
    }

    private function fetchDMs(Collection $items, SocialAccount $account, ?Carbon $since): void
    {
        try {
            $auth = $this->getAuth($account);
            if (! $auth) {
                return;
            }

            $convosResponse = Http::withToken($auth['accessJwt'])
                ->get(self::PDS_BASE . '/xrpc/chat.bsky.convo.listConvos', ['limit' => 20]);

            if (! $convosResponse->successful()) {
                return;
            }

            $convos = $convosResponse->json('convos', []);

            foreach ($convos as $convo) {
                $convoId = $convo['id'];

                $messagesResponse = Http::withToken($auth['accessJwt'])
                    ->get(self::PDS_BASE . '/xrpc/chat.bsky.convo.getMessages', [
                        'convoId' => $convoId,
                        'limit' => 20,
                    ]);

                if (! $messagesResponse->successful()) {
                    continue;
                }

                $messages = $messagesResponse->json('messages', []);

                foreach ($messages as $msg) {
                    $senderDid = $msg['sender']['did'] ?? null;

                    // Skip our own messages
                    if ($senderDid === $auth['did']) {
                        continue;
                    }

                    $sentAt = isset($msg['sentAt']) ? Carbon::parse($msg['sentAt']) : null;

                    $items->push([
                        'type' => 'dm',
                        'external_id' => $msg['id'],
                        'external_post_id' => $convoId,
                        'conversation_key' => "dm:{$convoId}",
                        'author_external_id' => $senderDid,
                        'author_name' => $senderDid,
                        'content' => $msg['text'] ?? null,
                        'posted_at' => $sentAt,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('BlueskyInboxService: DM fetch failed', ['error' => $e->getMessage()]);
        }
    }

    private function getAuth(SocialAccount $account): ?array
    {
        $credentials = $account->credentials;
        $accessJwt = $credentials['access_jwt'] ?? null;
        $refreshJwt = $credentials['refresh_jwt'] ?? null;

        if ($accessJwt && ! $this->isJwtExpired($accessJwt)) {
            return ['did' => $credentials['did'], 'accessJwt' => $accessJwt];
        }

        if ($refreshJwt) {
            $response = Http::withToken($refreshJwt)
                ->post(self::PDS_BASE . '/xrpc/com.atproto.server.refreshSession');

            if ($response->successful()) {
                $data = $response->json();
                $account->update([
                    'credentials' => array_merge($credentials, [
                        'access_jwt' => $data['accessJwt'],
                        'refresh_jwt' => $data['refreshJwt'],
                        'did' => $data['did'],
                    ]),
                ]);

                return ['did' => $data['did'], 'accessJwt' => $data['accessJwt']];
            }
        }

        // Full re-login
        $response = Http::post(self::PDS_BASE . '/xrpc/com.atproto.server.createSession', [
            'identifier' => $credentials['handle'],
            'password' => $credentials['app_password'],
        ]);

        if ($response->successful()) {
            $data = $response->json();
            $account->update([
                'credentials' => array_merge($credentials, [
                    'did' => $data['did'],
                    'access_jwt' => $data['accessJwt'],
                    'refresh_jwt' => $data['refreshJwt'],
                ]),
            ]);

            return ['did' => $data['did'], 'accessJwt' => $data['accessJwt']];
        }

        return null;
    }

    private function isJwtExpired(string $jwt): bool
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return true;
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        return ! $payload || ! isset($payload['exp']) || $payload['exp'] < (time() + 300);
    }

    public function sendReply(SocialAccount $account, InboxItem $item, string $replyText): array
    {
        try {
            $auth = $this->getAuth($account);
            if (! $auth) {
                return ['success' => false, 'external_id' => null, 'error' => 'Authentication failed'];
            }

            // DM reply
            if ($item->type === 'dm') {
                return $this->sendDMReply($auth, $item, $replyText);
            }

            // Comment reply
            return $this->sendPostReply($auth, $account, $item, $replyText);
        } catch (\Throwable $e) {
            Log::error('BlueskyInboxService: sendReply failed', [
                'account_id' => $account->id,
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'external_id' => null, 'error' => $e->getMessage()];
        }
    }

    private function sendPostReply(array $auth, SocialAccount $account, InboxItem $item, string $replyText): array
    {
        // Parse the external_id (format: uri|cid) of the comment we're replying to
        [$replyUri, $replyCid] = explode('|', $item->external_id, 2);

        // Parse the root post (external_post_id = uri|cid)
        [$rootUri, $rootCid] = explode('|', $item->external_post_id, 2);

        $record = [
            '$type' => 'app.bsky.feed.post',
            'text' => $replyText,
            'createdAt' => now()->toIso8601ZuluString(),
            'langs' => [$account->languages[0] ?? 'fr'],
            'reply' => [
                'root' => ['uri' => $rootUri, 'cid' => $rootCid],
                'parent' => ['uri' => $replyUri, 'cid' => $replyCid],
            ],
        ];

        $response = Http::withToken($auth['accessJwt'])
            ->post(self::PDS_BASE . '/xrpc/com.atproto.repo.createRecord', [
                'repo' => $auth['did'],
                'collection' => 'app.bsky.feed.post',
                'record' => $record,
            ]);

        if ($response->successful() && $response->json('uri')) {
            $externalId = $response->json('uri') . '|' . $response->json('cid');

            return ['success' => true, 'external_id' => $externalId, 'error' => null];
        }

        $error = $response->json('message', 'Failed to send reply');

        return ['success' => false, 'external_id' => null, 'error' => $error];
    }

    private function sendDMReply(array $auth, InboxItem $item, string $replyText): array
    {
        $convoId = $item->external_post_id; // convo ID stored here

        $response = Http::withToken($auth['accessJwt'])
            ->post(self::PDS_BASE . '/xrpc/chat.bsky.convo.sendMessage', [
                'convoId' => $convoId,
                'message' => ['text' => $replyText],
            ]);

        if ($response->successful() && $response->json('id')) {
            return ['success' => true, 'external_id' => $response->json('id'), 'error' => null];
        }

        $error = $response->json('message', 'Failed to send DM');

        return ['success' => false, 'external_id' => null, 'error' => $error];
    }
}
