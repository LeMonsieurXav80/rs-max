<?php

namespace App\Services\Bot;

use App\Models\BotActionLog;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookBotService
{
    private const API_BASE = 'https://graph.facebook.com/v21.0';

    public function runForAccount(SocialAccount $account): array
    {
        $credentials = $account->credentials;
        $pageId = $credentials['page_id'];
        $accessToken = $credentials['access_token'];

        $params = [
            'fields' => 'id,comments{id,from,message,created_time,user_likes}',
            'access_token' => $accessToken,
            'limit' => 25,
        ];

        $response = Http::get(self::API_BASE . "/{$pageId}/feed", $params);

        if (! $response->successful()) {
            Log::warning('FacebookBotService: failed to fetch feed', [
                'account_id' => $account->id,
                'status' => $response->status(),
                'error' => $response->json('error.message'),
            ]);

            return ['total_likes' => 0, 'error' => $response->json('error.message', 'Feed fetch failed')];
        }

        $posts = $response->json('data', []);
        $totalLikes = 0;

        foreach ($posts as $post) {
            $comments = $post['comments']['data'] ?? [];

            foreach ($comments as $comment) {
                $commentId = $comment['id'];
                $fromId = $comment['from']['id'] ?? null;

                // Skip comments from the page itself
                if ($fromId === $pageId) {
                    continue;
                }

                // Skip if already liked by the page
                if (! empty($comment['user_likes'])) {
                    continue;
                }

                // Skip if already in our logs
                if ($this->alreadyActioned($account->id, $commentId)) {
                    continue;
                }

                $success = $this->likeComment($commentId, $accessToken);

                BotActionLog::create([
                    'social_account_id' => $account->id,
                    'action_type' => 'like_comment',
                    'target_uri' => $commentId,
                    'target_author' => $comment['from']['name'] ?? null,
                    'target_text' => mb_substr($comment['message'] ?? '', 0, 500),
                    'success' => $success,
                ]);

                if ($success) {
                    $totalLikes++;
                }

                usleep(300_000); // 300ms between likes
            }
        }

        return ['total_likes' => $totalLikes];
    }

    private function likeComment(string $commentId, string $accessToken): bool
    {
        $response = Http::asForm()->post(self::API_BASE . "/{$commentId}/likes", [
            'access_token' => $accessToken,
        ]);

        if (! $response->successful()) {
            Log::warning('FacebookBotService: like comment failed', [
                'comment_id' => $commentId,
                'status' => $response->status(),
                'error' => $response->json('error.message'),
            ]);

            return false;
        }

        return true;
    }

    private function alreadyActioned(int $accountId, string $commentId): bool
    {
        return BotActionLog::where('social_account_id', $accountId)
            ->where('target_uri', $commentId)
            ->where('success', true)
            ->exists();
    }
}
