<?php

namespace App\Services\Inbox;

use App\Models\InboxItem;
use App\Models\SocialAccount;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookInboxService implements PlatformInboxInterface
{
    private const API_BASE = 'https://graph.facebook.com/v21.0';

    public function fetchInbox(SocialAccount $account, ?Carbon $since = null): Collection
    {
        $items = collect();
        $credentials = $account->credentials;
        $pageId = $credentials['page_id'];
        $accessToken = $credentials['access_token'];

        try {
            $params = [
                'fields' => 'id,message,created_time,comments{id,message,from,created_time,attachment{type,media{image{src}}}}',
                'access_token' => $accessToken,
                'limit' => 20,
            ];

            $feedResponse = Http::get(self::API_BASE . "/{$pageId}/feed", $params);

            if (! $feedResponse->successful()) {
                Log::warning('FacebookInboxService: failed to fetch feed', [
                    'status' => $feedResponse->status(),
                ]);

                return $items;
            }

            $posts = $feedResponse->json('data', []);

            foreach ($posts as $post) {
                $postId = $post['id'];
                $comments = $post['comments']['data'] ?? [];

                foreach ($comments as $comment) {
                    // Skip comments from the page itself
                    $fromId = $comment['from']['id'] ?? null;
                    if ($fromId === $pageId) {
                        continue;
                    }

                    $attachment = $comment['attachment'] ?? null;
                    $mediaUrl = $attachment['media']['image']['src'] ?? null;
                    $mediaType = match ($attachment['type'] ?? null) {
                        'animated_image_autoplay' => 'gif',
                        'sticker' => 'sticker',
                        'photo' => 'image',
                        'video_inline' => 'video',
                        default => $mediaUrl ? 'image' : null,
                    };

                    $items->push([
                        'type' => 'comment',
                        'external_id' => $comment['id'],
                        'external_post_id' => $postId,
                        'conversation_key' => $comment['id'],
                        'author_name' => $comment['from']['name'] ?? null,
                        'author_external_id' => $fromId,
                        'content' => $comment['message'] ?? null,
                        'media_url' => $mediaUrl,
                        'media_type' => $mediaType,
                        'post_url' => "https://facebook.com/{$comment['id']}",
                        'posted_at' => isset($comment['created_time']) ? Carbon::parse($comment['created_time']) : null,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('FacebookInboxService: fetchInbox failed', [
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

            $response = Http::asForm()->post(self::API_BASE . "/{$item->external_id}/comments", [
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
            Log::error('FacebookInboxService: sendReply failed', [
                'account_id' => $account->id,
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'external_id' => null, 'error' => $e->getMessage()];
        }
    }
}
