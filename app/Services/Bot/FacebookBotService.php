<?php

namespace App\Services\Bot;

use App\Models\BotActionLog;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookBotService
{
    private const API_BASE = 'https://graph.facebook.com/v21.0';

    private const MAX_LIKES_PER_RUN = 50;

    private ?int $currentAccountId = null;

    public function runForAccount(SocialAccount $account): array
    {
        $this->currentAccountId = $account->id;
        $credentials = $account->credentials;
        $pageId = $credentials['page_id'] ?? $account->platform_account_id;
        $accessToken = $credentials['access_token'] ?? null;

        if (! $accessToken) {
            return ['total_likes' => 0, 'error' => 'Pas de token d\'accès configuré'];
        }

        $params = [
            'fields' => 'id,comments.limit(50){id,from,message,created_time,user_likes}',
            'access_token' => $accessToken,
            'limit' => 25,
        ];

        $response = Http::timeout(30)->get(self::API_BASE . "/{$pageId}/feed", $params);

        if (! $response->successful()) {
            $errorMsg = $response->json('error.message', 'Feed fetch failed');
            Log::warning('FacebookBotService: failed to fetch feed', [
                'account_id' => $account->id,
                'status' => $response->status(),
                'error' => $errorMsg,
            ]);

            return ['total_likes' => 0, 'error' => $errorMsg];
        }

        $posts = $response->json('data', []);
        $totalLikes = 0;
        $errors = 0;

        foreach ($posts as $post) {
            if ($totalLikes >= self::MAX_LIKES_PER_RUN || $this->shouldStop()) {
                break;
            }

            $comments = $post['comments']['data'] ?? [];

            foreach ($comments as $comment) {
                if ($totalLikes >= self::MAX_LIKES_PER_RUN || $this->shouldStop()) {
                    break;
                }

                $commentId = $comment['id'];
                $fromId = $comment['from']['id'] ?? null;

                // Skip comments from the page itself
                if ($fromId && $fromId === $pageId) {
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
                } else {
                    $errors++;
                    // Stop if too many consecutive errors (likely permission/rate limit issue)
                    if ($errors >= 5 && $totalLikes === 0) {
                        return ['total_likes' => 0, 'error' => 'Trop d\'erreurs consécutives - vérifiez la permission pages_manage_engagement'];
                    }
                }

                usleep(300_000); // 300ms between likes
            }
        }

        $result = ['total_likes' => $totalLikes];
        if ($errors > 0) {
            $result['error'] = "{$errors} commentaire(s) n'ont pas pu être likés";
        }

        return $result;
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

    private function shouldStop(): bool
    {
        return $this->currentAccountId && Cache::has("bot_stop_facebook_{$this->currentAccountId}");
    }
}
