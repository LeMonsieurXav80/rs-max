<?php

namespace App\Services\Bot;

use Carbon\Carbon;
use App\Models\BotActionLog;
use App\Models\BotSearchTerm;
use App\Models\Setting;
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

        // 3. Like comments on own posts
        $commentLikes = 0;
        if (! $this->shouldStop() && Setting::get("bot_like_comments_bluesky_{$account->id}") === '1') {
            $commentLikes = $this->likeOwnPostComments($account, $auth);
        }
        $totalLikes += $commentLikes;

        // 4. Random feed likes
        $feedLikes = 0;
        if (! $this->shouldStop() && Setting::get("bot_feed_likes_bluesky_{$account->id}") === '1') {
            $feedLikes = $this->likeRandomFeedPosts($account, $auth);
        }
        $totalLikes += $feedLikes;

        // 5. Unfollow non-followers
        $unfollows = 0;
        if (! $this->shouldStop() && Setting::get("bot_unfollow_bluesky_{$account->id}") === '1') {
            $unfollows = $this->unfollowNonFollowers($account, $auth);
        }

        return [
            'total_likes' => $totalLikes,
            'terms_processed' => $termsProcessed,
            'likeback_likes' => $likebackLikes,
            'comment_likes' => $commentLikes,
            'feed_likes' => $feedLikes,
            'unfollows' => $unfollows,
        ];
    }

    // ─── 1. Search terms ─────────────────────────────────────────────

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

            $alreadyLiked = $this->alreadyActioned($account->id, $postUri);

            if (! $alreadyLiked) {
                // Like the post
                $success = $this->likeRecord($auth, $postUri, $postCid);
                $this->logAction($account, 'like_post', $postUri, $post, $term->term, $success);

                if ($success) {
                    $likesCount++;
                }

                usleep(500_000);
            }

            // Like new replies (even on already-liked posts — revisit for new interactions)
            if ($term->like_replies && ! $this->shouldStop()) {
                $likesCount += $this->likePostReplies($account, $auth, $postUri, $term->term);
            }
        }

        return $likesCount;
    }

    private function searchPosts(string $query, int $limit = 10): array
    {
        $response = Http::get(self::PUBLIC_API . '/xrpc/app.bsky.feed.searchPosts', [
            'q' => $query,
            'sort' => 'top',
            'limit' => min($limit, 50),
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

            // Follow reply author if they follow > 500 people (likely to follow back)
            if ($authorDid && ! $this->alreadyActioned($account->id, "follow:{$authorDid}")) {
                $this->followIfHighFollowing($account, $auth, $authorDid, $replyPost['author']['handle'] ?? $authorDid);
            }

            usleep(300_000); // 300ms between reply likes
        }

        return $likesCount;
    }

    private function followIfHighFollowing(SocialAccount $account, array $auth, string $did, string $handle): void
    {
        $response = Http::get(self::PUBLIC_API . '/xrpc/app.bsky.actor.getProfile', [
            'actor' => $did,
        ]);

        if (! $response->successful()) {
            return;
        }

        $followsCount = $response->json('followsCount', 0);

        if ($followsCount < 500) {
            return;
        }

        // Check if we already follow them
        if ($response->json('viewer.following')) {
            return;
        }

        $success = $this->followActor($auth, $did);

        BotActionLog::create([
            'social_account_id' => $account->id,
            'action_type' => 'follow_active_user',
            'target_uri' => "follow:{$did}",
            'target_author' => $handle,
            'target_text' => "followsCount: {$followsCount}",
            'search_term' => null,
            'success' => $success,
        ]);

        usleep(300_000);
    }

    private function followActor(array $auth, string $did): bool
    {
        $response = Http::withToken($auth['accessJwt'])
            ->post(self::PDS_BASE . '/xrpc/com.atproto.repo.createRecord', [
                'repo' => $auth['did'],
                'collection' => 'app.bsky.graph.follow',
                'record' => [
                    '$type' => 'app.bsky.graph.follow',
                    'subject' => $did,
                    'createdAt' => now()->toIso8601ZuluString(),
                ],
            ]);

        if (! $response->successful()) {
            Log::warning('BlueskyBotService: follow failed', [
                'did' => $did,
                'status' => $response->status(),
                'error' => $response->json('message'),
            ]);

            return false;
        }

        return true;
    }

    // ─── 2. Like-back ────────────────────────────────────────────────

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

    // ─── 3. Like comments on own posts ───────────────────────────────

    private function likeOwnPostComments(SocialAccount $account, array $auth): int
    {
        $response = Http::get(self::PUBLIC_API . '/xrpc/app.bsky.feed.getAuthorFeed', [
            'actor' => $auth['did'],
            'limit' => 20,
        ]);

        if (! $response->successful()) {
            return 0;
        }

        $feed = $response->json('feed', []);
        $likesCount = 0;
        $maxCommentLikes = 30;

        foreach ($feed as $feedItem) {
            if ($likesCount >= $maxCommentLikes || $this->shouldStop()) {
                break;
            }

            $post = $feedItem['post'] ?? null;
            if (! $post) {
                continue;
            }

            // Only process our own posts (not reposts)
            if (($post['author']['did'] ?? null) !== $auth['did']) {
                continue;
            }

            $replyCount = $post['replyCount'] ?? 0;
            if ($replyCount === 0) {
                continue;
            }

            // Fetch thread to get replies
            $threadResponse = Http::get(self::PUBLIC_API . '/xrpc/app.bsky.feed.getPostThread', [
                'uri' => $post['uri'],
                'depth' => 2,
                'parentHeight' => 0,
            ]);

            if (! $threadResponse->successful()) {
                continue;
            }

            $replies = $threadResponse->json('thread.replies', []);

            foreach ($replies as $reply) {
                if ($likesCount >= $maxCommentLikes || $this->shouldStop()) {
                    break;
                }

                $replyPost = $reply['post'] ?? null;
                if (! $replyPost) {
                    continue;
                }

                $replyUri = $replyPost['uri'] ?? null;
                $replyCid = $replyPost['cid'] ?? null;
                $authorDid = $replyPost['author']['did'] ?? null;

                // Skip own replies
                if (! $replyUri || ! $replyCid || $authorDid === $auth['did']) {
                    continue;
                }

                if ($this->alreadyActioned($account->id, $replyUri)) {
                    continue;
                }

                $success = $this->likeRecord($auth, $replyUri, $replyCid);

                BotActionLog::create([
                    'social_account_id' => $account->id,
                    'action_type' => 'like_own_comment',
                    'target_uri' => $replyUri,
                    'target_author' => $replyPost['author']['handle'] ?? null,
                    'target_text' => mb_substr($replyPost['record']['text'] ?? '', 0, 500),
                    'search_term' => null,
                    'success' => $success,
                ]);

                if ($success) {
                    $likesCount++;
                }

                usleep(300_000);
            }

            usleep(200_000);
        }

        return $likesCount;
    }

    // ─── 4. Random feed likes ────────────────────────────────────────

    private function likeRandomFeedPosts(SocialAccount $account, array $auth): int
    {
        $response = Http::withToken($auth['accessJwt'])
            ->get(self::PDS_BASE . '/xrpc/app.bsky.feed.getTimeline', [
                'limit' => 50,
            ]);

        if (! $response->successful()) {
            Log::warning('BlueskyBotService: getTimeline failed', [
                'account_id' => $account->id,
                'status' => $response->status(),
            ]);

            return 0;
        }

        $feed = $response->json('feed', []);
        if (empty($feed)) {
            return 0;
        }

        // Randomly select ~20% of posts to like (between 3-8 posts)
        $maxFeedLikes = (int) Setting::get("bot_feed_likes_max_bluesky_{$account->id}", 5);
        $candidates = [];

        foreach ($feed as $feedItem) {
            $post = $feedItem['post'] ?? null;
            if (! $post) {
                continue;
            }

            $postUri = $post['uri'] ?? null;
            $postCid = $post['cid'] ?? null;
            $authorDid = $post['author']['did'] ?? null;

            // Skip own posts and already liked
            if (! $postUri || ! $postCid || $authorDid === $auth['did']) {
                continue;
            }

            // Skip if viewer already liked
            if (! empty($post['viewer']['like'])) {
                continue;
            }

            if ($this->alreadyActioned($account->id, $postUri)) {
                continue;
            }

            $candidates[] = $post;
        }

        if (empty($candidates)) {
            return 0;
        }

        // Shuffle and take random subset
        shuffle($candidates);
        $tolike = array_slice($candidates, 0, min($maxFeedLikes, count($candidates)));
        $likesCount = 0;

        foreach ($tolike as $post) {
            if ($this->shouldStop()) {
                break;
            }

            $success = $this->likeRecord($auth, $post['uri'], $post['cid']);

            BotActionLog::create([
                'social_account_id' => $account->id,
                'action_type' => 'like_feed',
                'target_uri' => $post['uri'],
                'target_author' => $post['author']['handle'] ?? null,
                'target_text' => mb_substr($post['record']['text'] ?? '', 0, 500),
                'search_term' => null,
                'success' => $success,
            ]);

            if ($success) {
                $likesCount++;
            }

            usleep(500_000);
        }

        return $likesCount;
    }

    // ─── 5. Unfollow non-followers ───────────────────────────────────

    private function unfollowNonFollowers(SocialAccount $account, array $auth): int
    {
        $maxUnfollows = (int) Setting::get("bot_unfollow_max_bluesky_{$account->id}", 10);

        // Get who we follow
        $following = $this->getAllFollowsOrFollowers($auth['did'], 'follows');
        if (empty($following)) {
            return 0;
        }

        // Get our followers
        $followers = $this->getAllFollowsOrFollowers($auth['did'], 'followers');
        $followerDids = array_flip(array_column($followers, 'did'));

        // Find people we follow who don't follow us back
        $nonFollowers = [];
        foreach ($following as $follow) {
            $did = $follow['did'];
            if (! isset($followerDids[$did])) {
                $nonFollowers[] = $follow;
            }
        }

        if (empty($nonFollowers)) {
            Log::info('BlueskyBotService: no non-followers to unfollow', [
                'account_id' => $account->id,
                'following' => count($following),
                'followers' => count($followers),
            ]);

            return 0;
        }

        // Grace period: don't unfollow accounts followed less than 7 days ago
        $graceDays = (int) Setting::get("bot_unfollow_grace_days_bluesky_{$account->id}", 7);
        $graceLimit = now()->subDays($graceDays);

        // Get follow dates from BotActionLog to sort by oldest first
        // Includes both 'follow_active_user' (bot:run) and 'prospect_follow' (bot:prospect)
        $followDates = BotActionLog::where('social_account_id', $account->id)
            ->whereIn('action_type', ['follow_active_user', 'prospect_follow'])
            ->where('success', true)
            ->pluck('created_at', 'target_uri')
            ->mapWithKeys(fn ($date, $uri) => [str_replace('follow:', '', $uri) => $date]);

        // Filter out recently followed accounts and sort oldest first
        $eligible = [];
        $skippedGrace = 0;
        foreach ($nonFollowers as $nf) {
            $followedAt = $followDates[$nf['did']] ?? null;

            // If we have a follow date and it's within the grace period, skip
            if ($followedAt && $followedAt->greaterThan($graceLimit)) {
                $skippedGrace++;
                continue;
            }

            // Use follow date for sorting (null = unknown/old = epoch for oldest-first)
            $nf['followed_at'] = $followedAt;
            $eligible[] = $nf;
        }

        // Sort: oldest follows first (unknown dates treated as very old)
        usort($eligible, function ($a, $b) {
            $dateA = $a['followed_at'] ?? Carbon::createFromTimestamp(0);
            $dateB = $b['followed_at'] ?? Carbon::createFromTimestamp(0);

            return $dateA->timestamp <=> $dateB->timestamp;
        });

        Log::info('BlueskyBotService: found non-followers', [
            'account_id' => $account->id,
            'following' => count($following),
            'followers' => count($followers),
            'non_followers' => count($nonFollowers),
            'eligible_after_grace' => count($eligible),
            'skipped_grace_period' => $skippedGrace,
            'grace_days' => $graceDays,
        ]);

        if (empty($eligible)) {
            return 0;
        }

        $unfollowCount = 0;

        foreach ($eligible as $nf) {
            if ($unfollowCount >= $maxUnfollows || $this->shouldStop()) {
                break;
            }

            // Skip if already unfollowed recently (prevent re-unfollowing)
            $unfollowUri = "unfollow:{$nf['did']}";
            if ($this->alreadyActioned($account->id, $unfollowUri)) {
                continue;
            }

            // Find the follow record to delete
            $followUri = $this->findFollowRecord($auth, $nf['did']);
            if (! $followUri) {
                continue;
            }

            $success = $this->deleteRecord($auth, $followUri);

            BotActionLog::create([
                'social_account_id' => $account->id,
                'action_type' => 'unfollow',
                'target_uri' => $unfollowUri,
                'target_author' => $nf['handle'] ?? $nf['did'],
                'target_text' => $nf['displayName'] ?? null,
                'search_term' => null,
                'success' => $success,
            ]);

            if ($success) {
                $unfollowCount++;
            }

            usleep(500_000);
        }

        return $unfollowCount;
    }

    private function getAllFollowsOrFollowers(string $did, string $type): array
    {
        $endpoint = $type === 'follows'
            ? '/xrpc/app.bsky.graph.getFollows'
            : '/xrpc/app.bsky.graph.getFollowers';

        $all = [];
        $cursor = null;
        $maxPages = 10; // Safety limit (1000 accounts max)

        for ($i = 0; $i < $maxPages; $i++) {
            $params = ['actor' => $did, 'limit' => 100];
            if ($cursor) {
                $params['cursor'] = $cursor;
            }

            $response = Http::get(self::PUBLIC_API . $endpoint, $params);
            if (! $response->successful()) {
                break;
            }

            $key = $type === 'follows' ? 'follows' : 'followers';
            $items = $response->json($key, []);
            foreach ($items as $item) {
                $all[] = [
                    'did' => $item['did'],
                    'handle' => $item['handle'] ?? '',
                    'displayName' => $item['displayName'] ?? '',
                ];
            }

            $cursor = $response->json('cursor');
            if (! $cursor || empty($items)) {
                break;
            }

            usleep(200_000);
        }

        return $all;
    }

    private function findFollowRecord(array $auth, string $targetDid): ?string
    {
        // List follow records to find the one for this DID
        $response = Http::withToken($auth['accessJwt'])
            ->get(self::PDS_BASE . '/xrpc/com.atproto.repo.listRecords', [
                'repo' => $auth['did'],
                'collection' => 'app.bsky.graph.follow',
                'limit' => 100,
            ]);

        if (! $response->successful()) {
            return null;
        }

        $records = $response->json('records', []);
        foreach ($records as $record) {
            $subject = $record['value']['subject'] ?? null;
            if ($subject === $targetDid) {
                return $record['uri'];
            }
        }

        // If not found in first page, try paginating
        $cursor = $response->json('cursor');
        if ($cursor) {
            $response = Http::withToken($auth['accessJwt'])
                ->get(self::PDS_BASE . '/xrpc/com.atproto.repo.listRecords', [
                    'repo' => $auth['did'],
                    'collection' => 'app.bsky.graph.follow',
                    'limit' => 100,
                    'cursor' => $cursor,
                ]);

            if ($response->successful()) {
                foreach ($response->json('records', []) as $record) {
                    if (($record['value']['subject'] ?? null) === $targetDid) {
                        return $record['uri'];
                    }
                }
            }
        }

        return null;
    }

    private function deleteRecord(array $auth, string $uri): bool
    {
        // Parse URI: at://did/collection/rkey
        $parts = explode('/', $uri);
        $rkey = end($parts);
        $collection = $parts[count($parts) - 2] ?? null;

        if (! $rkey || ! $collection) {
            return false;
        }

        $response = Http::withToken($auth['accessJwt'])
            ->post(self::PDS_BASE . '/xrpc/com.atproto.repo.deleteRecord', [
                'repo' => $auth['did'],
                'collection' => $collection,
                'rkey' => $rkey,
            ]);

        if (! $response->successful()) {
            Log::warning('BlueskyBotService: deleteRecord failed', [
                'uri' => $uri,
                'status' => $response->status(),
                'error' => $response->json('message'),
            ]);

            return false;
        }

        return true;
    }

    // ─── Common helpers ──────────────────────────────────────────────

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

    private function shouldStop(): bool
    {
        return $this->currentAccountId && Cache::has("bot_stop_bluesky_{$this->currentAccountId}");
    }
}
