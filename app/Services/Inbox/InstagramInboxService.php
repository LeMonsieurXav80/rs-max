<?php

namespace App\Services\Inbox;

use App\Models\InboxItem;
use App\Models\SocialAccount;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InstagramInboxService implements PlatformInboxInterface
{
    private const API_BASE = 'https://graph.facebook.com/v21.0';

    public function fetchInbox(SocialAccount $account, ?Carbon $since = null): Collection
    {
        $items = collect();
        $credentials = $account->credentials;
        $accountId = $credentials['account_id'];
        $accessToken = $credentials['access_token'];

        try {
            // Fetch recent media
            $params = [
                'fields' => 'id,timestamp,permalink',
                'access_token' => $accessToken,
                'limit' => 20,
            ];
            if ($since) {
                $params['since'] = $since->timestamp;
            }

            $mediaResponse = Http::get(self::API_BASE . "/{$accountId}/media", $params);

            if (! $mediaResponse->successful()) {
                Log::warning('InstagramInboxService: failed to fetch media', [
                    'status' => $mediaResponse->status(),
                ]);

                return $items;
            }

            $mediaList = $mediaResponse->json('data', []);

            foreach ($mediaList as $media) {
                $mediaId = $media['id'];
                $permalink = $media['permalink'] ?? null;

                // Fetch comments for this media
                $commentsResponse = Http::get(self::API_BASE . "/{$mediaId}/comments", [
                    'fields' => 'id,text,username,timestamp,replies{id,text,username,timestamp}',
                    'access_token' => $accessToken,
                    'limit' => 50,
                ]);

                if (! $commentsResponse->successful()) {
                    continue;
                }

                $comments = $commentsResponse->json('data', []);

                foreach ($comments as $comment) {
                    $items->push([
                        'type' => 'comment',
                        'external_id' => $comment['id'],
                        'external_post_id' => $mediaId,
                        'author_username' => $comment['username'] ?? null,
                        'author_name' => $comment['username'] ?? null,
                        'content' => $comment['text'] ?? null,
                        'post_url' => $permalink,
                        'posted_at' => isset($comment['timestamp']) ? Carbon::parse($comment['timestamp']) : null,
                    ]);

                    // Nested replies
                    $replies = $comment['replies']['data'] ?? [];
                    foreach ($replies as $reply) {
                        $items->push([
                            'type' => 'reply',
                            'external_id' => $reply['id'],
                            'external_post_id' => $mediaId,
                            'parent_id' => $comment['id'],
                            'author_username' => $reply['username'] ?? null,
                            'author_name' => $reply['username'] ?? null,
                            'content' => $reply['text'] ?? null,
                            'post_url' => $permalink,
                            'posted_at' => isset($reply['timestamp']) ? Carbon::parse($reply['timestamp']) : null,
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('InstagramInboxService: fetchInbox failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $items;
    }

    public function sendReply(SocialAccount $account, InboxItem $item, string $replyText): array
    {
        try {
            $accessToken = $account->credentials['access_token'];

            $response = Http::asForm()->post(self::API_BASE . "/{$item->external_id}/replies", [
                'message' => $replyText,
                'access_token' => $accessToken,
            ]);

            $replyId = $response->json('id');

            if ($replyId) {
                return ['success' => true, 'external_id' => (string) $replyId, 'error' => null];
            }

            $error = $response->json('error.message', 'Failed to reply to comment');

            return ['success' => false, 'external_id' => null, 'error' => $error];
        } catch (\Throwable $e) {
            Log::error('InstagramInboxService: sendReply failed', [
                'account_id' => $account->id,
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'external_id' => null, 'error' => $e->getMessage()];
        }
    }
}
