<?php

namespace App\Services\Bot;

use App\Models\BotActionLog;
use App\Models\BotSearchTerm;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BlueskyBotService
{
    private const PDS_BASE = 'https://bsky.social';

    private const PUBLIC_API = 'https://public.api.bsky.app';

    private ?int $currentAccountId = null;

    public function runForAccount(SocialAccount $account): array
    {
        $this->currentAccountId = $account->id;

        $auth = $this->getAuth($account);
        if (! $auth) {
            Log::error('BlueskyBotService: authentication failed', ['account_id' => $account->id]);

            return ['total_likes' => 0, 'terms_processed' => 0, 'likeback_likes' => 0, 'error' => 'Auth failed'];
        }

        $totalLikes = 0;
        $termsProcessed = 0;

        // 1. Search terms: like posts + replies
        $terms = BotSearchTerm::where('social_account_id', $account->id)
            ->where('is_active', true)
            ->get();

        foreach ($terms as $term) {
            if ($this->shouldStop()) {
                break;
            }
            $likes = $this->processSearchTerm($account, $auth, $term);
            $totalLikes += $likes;
            $termsProcessed++;
            $term->update(['last_run_at' => now()]);
        }

        // 2. Like-back: like posts from people who liked our posts
        $likebackLikes = 0;
        if (! $this->shouldStop()) {
            $likebackLikes = $this->processLikeback($account, $auth);
        }
        $totalLikes += $likebackLikes;

        return ['total_likes' => $totalLikes, 'terms_processed' => $termsProcessed, 'likeback_likes' => $likebackLikes];
    }

    private function processSearchTerm(SocialAccount $account, array $auth, BotSearchTerm $term): int
    {
        $posts = $this->searchPosts($term->term, $term->max_likes_per_run);
        if (empty($posts)) {
            return 0;
        }

        $likesCount = 0;

        foreach ($posts as $post) {
            if ($this->shouldStop()) {
                break;
            }

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
            if ($term->like_replies && ! $this->shouldStop()) {
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

    private function processLikeback(SocialAccount $account, array $auth): int
    {
        // Fetch our recent posts
        $response = Http::get(self::PUBLIC_API . '/xrpc/app.bsky.feed.getAuthorFeed', [
            'actor' => $auth['did'],
            'limit' => 15,
        ]);

        if (! $response->successful()) {
            return 0;
        }

        $feed = $response->json('feed', []);
        $likerDids = [];

        // Collect unique likers from our posts
        foreach ($feed as $feedItem) {
            $post = $feedItem['post'] ?? null;
            if (! $post || ($post['likeCount'] ?? 0) === 0) {
                continue;
            }

            $postUri = $post['uri'];
            $likesResponse = Http::get(self::PUBLIC_API . '/xrpc/app.bsky.feed.getLikes', [
                'uri' => $postUri,
                'limit' => 20,
            ]);

            if (! $likesResponse->successful()) {
                continue;
            }

            foreach ($likesResponse->json('likes', []) as $like) {
                $did = $like['actor']['did'] ?? null;
                if ($did && $did !== $auth['did'] && ! isset($likerDids[$did])) {
                    $likerDids[$did] = $like['actor']['handle'] ?? $did;
                }
            }

            usleep(200_000);
        }

        if (empty($likerDids)) {
            return 0;
        }

        // Like recent posts from each liker
        $likesCount = 0;
        $maxLikersToProcess = 10;
        $processed = 0;

        foreach ($likerDids as $did => $handle) {
            if ($processed >= $maxLikersToProcess) {
                break;
            }

            $likesCount += $this->likeRecentPostsOf($account, $auth, $did, $handle);
            $processed++;

            usleep(500_000);
        }

        return $likesCount;
    }

    private function likeRecentPostsOf(SocialAccount $account, array $auth, string $did, string $handle): int
    {
        $response = Http::get(self::PUBLIC_API . '/xrpc/app.bsky.feed.getAuthorFeed', [
            'actor' => $did,
            'limit' => 5,
        ]);

        if (! $response->successful()) {
            return 0;
        }

        $feed = $response->json('feed', []);
        $likesCount = 0;
        $maxLikesPerUser = 3;

        foreach ($feed as $feedItem) {
            if ($likesCount >= $maxLikesPerUser) {
                break;
            }

            $post = $feedItem['post'] ?? null;
            if (! $post) {
                continue;
            }

            // Only like their own posts (not reposts)
            if (($post['author']['did'] ?? null) !== $did) {
                continue;
            }

            $postUri = $post['uri'];
            $postCid = $post['cid'];

            if ($this->alreadyActioned($account->id, $postUri)) {
                continue;
            }

            $success = $this->likeRecord($auth, $postUri, $postCid);

            BotActionLog::create([
                'social_account_id' => $account->id,
                'action_type' => 'like_back',
                'target_uri' => $postUri,
                'target_author' => $handle,
                'target_text' => mb_substr($post['record']['text'] ?? '', 0, 500),
                'search_term' => null,
                'success' => $success,
            ]);

            if ($success) {
                $likesCount++;
            }

            usleep(300_000);
        }

        return $likesCount;
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

    private function shouldStop(): bool
    {
        return $this->currentAccountId && Cache::has("bot_stop_bluesky_{$this->currentAccountId}");
    }
}
