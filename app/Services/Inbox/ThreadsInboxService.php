<?php

namespace App\Services\Inbox;

use App\Models\InboxItem;
use App\Models\SocialAccount;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ThreadsInboxService implements PlatformInboxInterface
{
    private const API_BASE = 'https://graph.threads.net/v1.0';

    public function fetchInbox(SocialAccount $account, ?Carbon $since = null): Collection
    {
        $items = collect();
        $credentials = $account->credentials;
        $userId = $credentials['user_id'];
        $accessToken = $credentials['access_token'];

        try {
            // Fetch our recent threads
            $params = [
                'fields' => 'id,text,timestamp,permalink',
                'access_token' => $accessToken,
                'limit' => 25,
            ];
            if ($since) {
                $params['since'] = $since->timestamp;
            }

            $threadsResponse = Http::get(self::API_BASE . "/{$userId}/threads", $params);

            if (! $threadsResponse->successful()) {
                Log::warning('ThreadsInboxService: failed to fetch threads', [
                    'status' => $threadsResponse->status(),
                    'body' => $threadsResponse->body(),
                ]);

                return $items;
            }

            $threads = $threadsResponse->json('data', []);

            foreach ($threads as $thread) {
                $threadId = $thread['id'];
                $permalink = $thread['permalink'] ?? null;

                // Fetch replies for this thread
                $repliesResponse = Http::get(self::API_BASE . "/{$threadId}/replies", [
                    'fields' => 'id,text,username,timestamp',
                    'access_token' => $accessToken,
                    'limit' => 50,
                ]);

                if (! $repliesResponse->successful()) {
                    continue;
                }

                $replies = $repliesResponse->json('data', []);

                foreach ($replies as $reply) {
                    // Skip our own replies
                    if (($reply['username'] ?? '') === ($account->name ?? '')) {
                        continue;
                    }

                    $items->push([
                        'type' => 'reply',
                        'external_id' => $reply['id'],
                        'external_post_id' => $threadId,
                        'author_username' => $reply['username'] ?? null,
                        'author_name' => $reply['username'] ?? null,
                        'content' => $reply['text'] ?? null,
                        'post_url' => $permalink,
                        'posted_at' => isset($reply['timestamp']) ? Carbon::parse($reply['timestamp']) : null,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('ThreadsInboxService: fetchInbox failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $items;
    }

    public function sendReply(SocialAccount $account, InboxItem $item, string $replyText): array
    {
        try {
            $credentials = $account->credentials;
            $userId = $credentials['user_id'];
            $accessToken = $credentials['access_token'];

            // Create reply container
            $container = Http::post(self::API_BASE . "/{$userId}/threads", [
                'media_type' => 'TEXT',
                'text' => $replyText,
                'reply_to_id' => $item->external_id,
                'access_token' => $accessToken,
            ]);

            $containerId = $container->json('id');

            if (! $containerId) {
                $error = $container->json('error.message', 'Failed to create reply container');

                return ['success' => false, 'external_id' => null, 'error' => $error];
            }

            // Wait briefly for container to be ready
            sleep(2);

            // Publish the container
            $publish = Http::post(self::API_BASE . "/{$userId}/threads_publish", [
                'creation_id' => $containerId,
                'access_token' => $accessToken,
            ]);

            $publishedId = $publish->json('id');

            if ($publishedId) {
                return ['success' => true, 'external_id' => (string) $publishedId, 'error' => null];
            }

            $error = $publish->json('error.message', 'Failed to publish reply');

            return ['success' => false, 'external_id' => null, 'error' => $error];
        } catch (\Throwable $e) {
            Log::error('ThreadsInboxService: sendReply failed', [
                'account_id' => $account->id,
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'external_id' => null, 'error' => $e->getMessage()];
        }
    }
}
