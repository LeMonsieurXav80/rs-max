<?php

namespace App\Services\Bot;

use App\Models\BotActionLog;
use App\Models\BotSearchTerm;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BlueskyBotService
{
    private const PDS_BASE = 'https://bsky.social';

    private const PUBLIC_API = 'https://public.api.bsky.app';

    public function runForAccount(SocialAccount $account): array
    {
        $terms = BotSearchTerm::where('social_account_id', $account->id)
            ->where('is_active', true)
            ->get();

        if ($terms->isEmpty()) {
            return ['total_likes' => 0, 'terms_processed' => 0];
        }

        $auth = $this->getAuth($account);
        if (! $auth) {
            Log::error('BlueskyBotService: authentication failed', ['account_id' => $account->id]);

            return ['total_likes' => 0, 'terms_processed' => 0, 'error' => 'Auth failed'];
        }

        $totalLikes = 0;

        foreach ($terms as $term) {
            $likes = $this->processSearchTerm($account, $auth, $term);
            $totalLikes += $likes;
            $term->update(['last_run_at' => now()]);
        }

        return ['total_likes' => $totalLikes, 'terms_processed' => $terms->count()];
    }

    private function processSearchTerm(SocialAccount $account, array $auth, BotSearchTerm $term): int
    {
        $posts = $this->searchPosts($term->term, $term->max_likes_per_run);
        if (empty($posts)) {
            return 0;
        }

        $likesCount = 0;

        foreach ($posts as $post) {
            $postUri = $post['uri'] ?? null;
            $postCid = $post['cid'] ?? null;
            $authorDid = $post['author']['did'] ?? null;

            if (! $postUri || ! $postCid) {
                continue;
            }

            // Skip own posts
            if ($authorDid === $auth['did']) {
                continue;
            }

            // Skip already liked
            if ($this->alreadyActioned($account->id, $postUri)) {
                continue;
            }

            // Like the post
            $success = $this->likeRecord($auth, $postUri, $postCid);
            $this->logAction($account, 'like_post', $postUri, $post, $term->term, $success);

            if ($success) {
                $likesCount++;
            }

            // Like replies if enabled
            if ($term->like_replies) {
                $likesCount += $this->likePostReplies($account, $auth, $postUri, $term->term);
            }

            // Respect rate limits
            usleep(500_000); // 500ms between actions
        }

        return $likesCount;
    }

    private function searchPosts(string $query, int $limit = 10): array
    {
        $response = Http::get(self::PUBLIC_API . '/xrpc/app.bsky.feed.searchPosts', [
            'q' => $query,
            'sort' => 'latest',
            'limit' => min($limit, 25),
        ]);

        if (! $response->successful()) {
            Log::warning('BlueskyBotService: searchPosts failed', [
                'query' => $query,
                'status' => $response->status(),
            ]);

            return [];
        }

        return $response->json('posts', []);
    }

    private function likePostReplies(SocialAccount $account, array $auth, string $postUri, string $searchTerm): int
    {
        $response = Http::get(self::PUBLIC_API . '/xrpc/app.bsky.feed.getPostThread', [
            'uri' => $postUri,
            'depth' => 2,
            'parentHeight' => 0,
        ]);

        if (! $response->successful()) {
            return 0;
        }

        $replies = $response->json('thread.replies', []);
        $likesCount = 0;

        foreach ($replies as $reply) {
            $replyPost = $reply['post'] ?? null;
            if (! $replyPost) {
                continue;
            }

            $replyUri = $replyPost['uri'] ?? null;
            $replyCid = $replyPost['cid'] ?? null;
            $authorDid = $replyPost['author']['did'] ?? null;

            if (! $replyUri || ! $replyCid || $authorDid === $auth['did']) {
                continue;
            }

            if ($this->alreadyActioned($account->id, $replyUri)) {
                continue;
            }

            $success = $this->likeRecord($auth, $replyUri, $replyCid);
            $this->logAction($account, 'like_reply', $replyUri, $replyPost, $searchTerm, $success);

            if ($success) {
                $likesCount++;
            }

            usleep(300_000); // 300ms between reply likes
        }

        return $likesCount;
    }

    private function likeRecord(array $auth, string $uri, string $cid): bool
    {
        $response = Http::withToken($auth['accessJwt'])
            ->post(self::PDS_BASE . '/xrpc/com.atproto.repo.createRecord', [
                'repo' => $auth['did'],
                'collection' => 'app.bsky.feed.like',
                'record' => [
                    '$type' => 'app.bsky.feed.like',
                    'subject' => [
                        'uri' => $uri,
                        'cid' => $cid,
                    ],
                    'createdAt' => now()->toIso8601ZuluString(),
                ],
            ]);

        if (! $response->successful()) {
            Log::warning('BlueskyBotService: like failed', [
                'uri' => $uri,
                'status' => $response->status(),
                'error' => $response->json('message'),
            ]);

            return false;
        }

        return true;
    }

    private function alreadyActioned(int $accountId, string $uri): bool
    {
        return BotActionLog::where('social_account_id', $accountId)
            ->where('target_uri', $uri)
            ->where('success', true)
            ->exists();
    }

    private function logAction(SocialAccount $account, string $type, string $uri, array $post, string $searchTerm, bool $success, ?string $error = null): void
    {
        BotActionLog::create([
            'social_account_id' => $account->id,
            'action_type' => $type,
            'target_uri' => $uri,
            'target_author' => $post['author']['handle'] ?? $post['author']['displayName'] ?? null,
            'target_text' => mb_substr($post['record']['text'] ?? $post['value']['text'] ?? '', 0, 500),
            'search_term' => $searchTerm,
            'success' => $success,
            'error' => $error,
        ]);
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
}
