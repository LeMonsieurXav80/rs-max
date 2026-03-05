<?php

namespace App\Services\Inbox;

use App\Models\InboxItem;
use App\Models\SocialAccount;
use App\Services\YouTubeTokenHelper;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YouTubeInboxService implements PlatformInboxInterface
{
    private const API_BASE = 'https://www.googleapis.com/youtube/v3';

    public function fetchInbox(SocialAccount $account, ?Carbon $since = null): Collection
    {
        $items = collect();
        $credentials = $account->credentials;
        $channelId = $credentials['channel_id'];
        $accessToken = YouTubeTokenHelper::getFreshAccessToken($account);

        if (! $accessToken) {
            Log::warning('YouTubeInboxService: no access token', ['account_id' => $account->id]);

            return $items;
        }

        try {
            // Fetch recent videos (always fetch latest to catch new comments on existing videos)
            $searchParams = [
                'part' => 'id',
                'channelId' => $channelId,
                'maxResults' => 15,
                'order' => 'date',
                'type' => 'video',
            ];

            $searchResponse = Http::withToken($accessToken)->get(self::API_BASE . '/search', $searchParams);

            if (! $searchResponse->successful()) {
                Log::warning('YouTubeInboxService: search failed', ['status' => $searchResponse->status()]);

                return $items;
            }

            $videos = $searchResponse->json('items', []);

            foreach ($videos as $video) {
                $videoId = $video['id']['videoId'] ?? null;
                if (! $videoId) {
                    continue;
                }

                // Fetch comment threads for this video
                $commentsResponse = Http::withToken($accessToken)->get(self::API_BASE . '/commentThreads', [
                    'part' => 'snippet,replies',
                    'videoId' => $videoId,
                    'maxResults' => 50,
                    'order' => 'time',
                ]);

                if (! $commentsResponse->successful()) {
                    continue;
                }

                $threads = $commentsResponse->json('items', []);

                foreach ($threads as $thread) {
                    $topComment = $thread['snippet']['topLevelComment'] ?? null;
                    if (! $topComment) {
                        continue;
                    }

                    $snippet = $topComment['snippet'];
                    $authorChannelId = $snippet['authorChannelId']['value'] ?? null;

                    // Skip our own comments
                    if ($authorChannelId === $channelId) {
                        continue;
                    }

                    $topCommentId = $topComment['id'];

                    $items->push([
                        'type' => 'comment',
                        'external_id' => $topCommentId,
                        'external_post_id' => $videoId,
                        'conversation_key' => $topCommentId,
                        'author_name' => $snippet['authorDisplayName'] ?? null,
                        'author_avatar_url' => $snippet['authorProfileImageUrl'] ?? null,
                        'author_external_id' => $authorChannelId,
                        'content' => $snippet['textOriginal'] ?? $snippet['textDisplay'] ?? null,
                        'post_url' => "https://youtube.com/watch?v={$videoId}&lc={$topCommentId}",
                        'posted_at' => isset($snippet['publishedAt']) ? Carbon::parse($snippet['publishedAt']) : null,
                    ]);

                    // Nested replies
                    $replies = $thread['replies']['comments'] ?? [];
                    foreach ($replies as $reply) {
                        $replySnippet = $reply['snippet'];
                        $replyAuthorId = $replySnippet['authorChannelId']['value'] ?? null;

                        if ($replyAuthorId === $channelId) {
                            continue;
                        }

                        $items->push([
                            'type' => 'reply',
                            'external_id' => $reply['id'],
                            'external_post_id' => $videoId,
                            'parent_id' => $topCommentId,
                            'conversation_key' => $topCommentId,
                            'author_name' => $replySnippet['authorDisplayName'] ?? null,
                            'author_avatar_url' => $replySnippet['authorProfileImageUrl'] ?? null,
                            'author_external_id' => $replyAuthorId,
                            'content' => $replySnippet['textOriginal'] ?? $replySnippet['textDisplay'] ?? null,
                            'post_url' => "https://youtube.com/watch?v={$videoId}&lc={$reply['id']}",
                            'posted_at' => isset($replySnippet['publishedAt']) ? Carbon::parse($replySnippet['publishedAt']) : null,
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('YouTubeInboxService: fetchInbox failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $items;
    }

    public function sendReply(SocialAccount $account, InboxItem $item, string $replyText): array
    {
        try {
            $accessToken = YouTubeTokenHelper::getFreshAccessToken($account);

            if (! $accessToken) {
                return ['success' => false, 'external_id' => null, 'error' => 'No access token'];
            }

            // Reply to a comment — parentId must be the top-level comment
            $parentId = $item->parent_id ?? $item->external_id;

            $response = Http::withToken($accessToken)
                ->post(self::API_BASE . '/comments', [
                    'part' => 'snippet',
                    'snippet' => [
                        'parentId' => $parentId,
                        'textOriginal' => $replyText,
                    ],
                ]);

            if ($response->successful() && $response->json('id')) {
                return ['success' => true, 'external_id' => $response->json('id'), 'error' => null];
            }

            $error = $response->json('error.message', 'Failed to reply to comment');

            return ['success' => false, 'external_id' => null, 'error' => $error];
        } catch (\Throwable $e) {
            Log::error('YouTubeInboxService: sendReply failed', [
                'account_id' => $account->id,
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'external_id' => null, 'error' => $e->getMessage()];
        }
    }
}
