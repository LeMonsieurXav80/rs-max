<?php

namespace App\Services\Bot;

use App\Models\BotActionLog;
use App\Models\BotTargetAccount;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BlueskyProspectService
{
    private const PDS_BASE = 'https://bsky.social';

    private const PUBLIC_API = 'https://public.api.bsky.app';

    private const POSTS_TO_ANALYZE = 10;

    private const LIKES_PER_LIKER = 4; // average of 3-5

    private const FOLLOW_RATIO = 5; // follow 1 out of 5 likers

    private const ACTION_DELAY_MS = 2_400_000; // ~2.4s between actions to spread over 1h

    private const HTTP_TIMEOUT = 15;

    private const MAX_CONSECUTIVE_ERRORS = 10;

    private SocialAccount $account;

    private array $auth;

    private int $consecutiveErrors = 0;

    private BotTargetAccount $target;

    public function run(SocialAccount $account, BotTargetAccount $target): array
    {
        $this->account = $account;
        $this->target = $target;

        if (! $this->refreshAuth()) {
            Log::warning('BlueskyProspectService: auth failed', ['account_id' => $account->id]);

            return ['error' => 'Auth failed'];
        }

        // Resolve DID if not yet done
        if (! $target->did) {
            $did = $this->resolveHandle($target->handle);
            if (! $did) {
                Log::warning('BlueskyProspectService: could not resolve handle', ['handle' => $target->handle]);
                $target->update(['status' => 'completed']);

                return ['error' => "Could not resolve handle: {$target->handle}"];
            }
            $target->update(['did' => $did]);
        }

        Log::info('BlueskyProspectService: resolved DID', ['handle' => $target->handle, 'did' => $target->did]);

        $target->update(['status' => 'running', 'started_at' => $target->started_at ?? now()]);

        // Get target's recent posts (only original posts, not reposts)
        $posts = $this->getRecentPosts($target->did, self::POSTS_TO_ANALYZE);
        Log::info('BlueskyProspectService: fetched posts', ['did' => $target->did, 'count' => count($posts)]);

        if (empty($posts)) {
            $target->update(['status' => 'completed', 'completed_at' => now()]);

            return ['error' => 'No posts found for target'];
        }

        $totalLikes = 0;
        $totalFollows = 0;
        $likersProcessed = $target->likers_processed;
        $likerCounter = 0;

        // Find which post to resume from
        $startPostIndex = 0;
        if ($target->current_post_uri) {
            foreach ($posts as $i => $post) {
                if (($post['uri'] ?? null) === $target->current_post_uri) {
                    $startPostIndex = $i;
                    break;
                }
            }
        }

        for ($i = $startPostIndex; $i < count($posts); $i++) {
            $post = $posts[$i];
            $postUri = $post['uri'];

            $target->update(['current_post_uri' => $postUri]);

            // Paginate through likers of this post
            $cursor = ($i === $startPostIndex) ? $target->current_cursor : null;

            do {
                if ($this->shouldStop()) {
                    return $this->saveAndReturn($target, $likersProcessed, $totalLikes, $totalFollows, $cursor, 'paused');
                }

                // Too many consecutive errors — likely rate limited or network issue
                if ($this->consecutiveErrors >= self::MAX_CONSECUTIVE_ERRORS) {
                    Log::warning('BlueskyProspectService: too many consecutive errors, pausing', [
                        'errors' => $this->consecutiveErrors,
                        'account_id' => $account->id,
                    ]);

                    return $this->saveAndReturn($target, $likersProcessed, $totalLikes, $totalFollows, $cursor, 'paused');
                }

                $likesResult = $this->fetchLikers($postUri, $cursor);
                if ($likesResult === null) {
                    // Network error fetching likers — skip to next page/post
                    $cursor = null;

                    continue;
                }

                $likers = $likesResult['likers'];
                $cursor = $likesResult['cursor'];

                foreach ($likers as $liker) {
                    if ($this->shouldStop()) {
                        break 3;
                    }

                    if ($this->consecutiveErrors >= self::MAX_CONSECUTIVE_ERRORS) {
                        break 3;
                    }

                    $likerDid = $liker['did'] ?? null;
                    if (! $likerDid || $likerDid === $this->auth['did']) {
                        continue;
                    }

                    $likerHandle = $liker['handle'] ?? $likerDid;

                    // Skip if we already prospected this user
                    if ($this->alreadyProspected($likerDid)) {
                        continue;
                    }

                    // Refresh auth token periodically (every 50 likers)
                    if ($likersProcessed > 0 && $likersProcessed % 50 === 0) {
                        $this->refreshAuth();
                    }

                    // Like their recent posts (only originals, not reposts)
                    $likesForUser = $this->likeRecentPosts($likerDid, $likerHandle);
                    $totalLikes += $likesForUser;

                    // Follow 1 out of FOLLOW_RATIO
                    $likerCounter++;
                    if ($likerCounter % self::FOLLOW_RATIO === 0) {
                        $followed = $this->followUser($likerDid, $likerHandle);
                        if ($followed) {
                            $totalFollows++;
                        }
                    }

                    $likersProcessed++;

                    // Save progress periodically
                    if ($likersProcessed % 10 === 0) {
                        $target->update([
                            'current_cursor' => $cursor,
                            'likers_processed' => $likersProcessed,
                            'likes_given' => $target->likes_given + $totalLikes,
                            'follows_given' => $target->follows_given + $totalFollows,
                        ]);
                        $totalLikes = 0;
                        $totalFollows = 0;
                    }
                }
            } while ($cursor);

            // Done with this post, reset cursor for next
            $target->update(['current_cursor' => null]);
        }

        // All posts processed
        $target->update([
            'status' => 'completed',
            'completed_at' => now(),
            'current_post_uri' => null,
            'current_cursor' => null,
            'likers_processed' => $likersProcessed,
            'likes_given' => $target->likes_given + $totalLikes,
            'follows_given' => $target->follows_given + $totalFollows,
        ]);

        return [
            'completed' => true,
            'likers_processed' => $likersProcessed,
            'likes' => $target->likes_given + $totalLikes,
            'follows' => $target->follows_given + $totalFollows,
        ];
    }

    private function saveAndReturn(BotTargetAccount $target, int $likersProcessed, int $totalLikes, int $totalFollows, ?string $cursor, string $status): array
    {
        $target->update([
            'status' => $status,
            'current_cursor' => $cursor,
            'likers_processed' => $likersProcessed,
            'likes_given' => $target->likes_given + $totalLikes,
            'follows_given' => $target->follows_given + $totalFollows,
        ]);

        return [
            'paused' => true,
            'likers_processed' => $likersProcessed,
            'likes' => $totalLikes,
            'follows' => $totalFollows,
        ];
    }

    private function getRecentPosts(string $did, int $count): array
    {
        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->get(self::PUBLIC_API . '/xrpc/app.bsky.feed.getAuthorFeed', [
                    'actor' => $did,
                    'limit' => 30,
                    'filter' => 'posts_no_replies',
                ]);
        } catch (\Throwable $e) {
            Log::warning('BlueskyProspectService: getAuthorFeed exception', [
                'did' => $did,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if (! $response->successful()) {
            Log::warning('BlueskyProspectService: getAuthorFeed failed', [
                'did' => $did,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            return [];
        }

        $posts = [];
        foreach ($response->json('feed', []) as $feedItem) {
            $post = $feedItem['post'] ?? null;
            if (! $post) {
                continue;
            }

            // Only original posts (author matches target DID)
            if (($post['author']['did'] ?? null) !== $did) {
                continue;
            }

            // Skip posts with 0 likes
            if (($post['likeCount'] ?? 0) === 0) {
                continue;
            }

            $posts[] = $post;
            if (count($posts) >= $count) {
                break;
            }
        }

        return $posts;
    }

    private function fetchLikers(string $postUri, ?string $cursor): ?array
    {
        $params = ['uri' => $postUri, 'limit' => 100];
        if ($cursor) {
            $params['cursor'] = $cursor;
        }

        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->get(self::PUBLIC_API . '/xrpc/app.bsky.feed.getLikes', $params);
        } catch (\Throwable $e) {
            Log::warning('BlueskyProspectService: getLikes exception', [
                'uri' => $postUri,
                'error' => $e->getMessage(),
            ]);
            $this->consecutiveErrors++;

            return null;
        }

        if (! $response->successful()) {
            $this->consecutiveErrors++;

            return ['likers' => [], 'cursor' => null];
        }

        $this->consecutiveErrors = 0;

        return [
            'likers' => collect($response->json('likes', []))
                ->map(fn ($like) => $like['actor'] ?? null)
                ->filter()
                ->values()
                ->toArray(),
            'cursor' => $response->json('cursor'),
        ];
    }

    private function likeRecentPosts(string $did, string $handle): int
    {
        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->get(self::PUBLIC_API . '/xrpc/app.bsky.feed.getAuthorFeed', [
                    'actor' => $did,
                    'limit' => 15,
                    'filter' => 'posts_no_replies',
                ]);
        } catch (\Throwable $e) {
            Log::warning('BlueskyProspectService: likeRecentPosts feed exception', [
                'did' => $did,
                'error' => $e->getMessage(),
            ]);
            $this->consecutiveErrors++;

            return 0;
        }

        if (! $response->successful()) {
            $this->consecutiveErrors++;

            return 0;
        }

        $likesCount = 0;
        $maxLikes = rand(3, 5);

        foreach ($response->json('feed', []) as $feedItem) {
            if ($likesCount >= $maxLikes) {
                break;
            }

            $post = $feedItem['post'] ?? null;
            if (! $post) {
                continue;
            }

            // Only their own posts, not reposts
            if (($post['author']['did'] ?? null) !== $did) {
                continue;
            }

            $postUri = $post['uri'];
            $postCid = $post['cid'];

            if ($this->alreadyActioned($postUri)) {
                continue;
            }

            $success = $this->likeRecord($postUri, $postCid);

            BotActionLog::create([
                'social_account_id' => $this->account->id,
                'action_type' => 'prospect_like',
                'target_uri' => $postUri,
                'target_author' => $handle,
                'target_text' => mb_substr($post['record']['text'] ?? '', 0, 500),
                'search_term' => null,
                'success' => $success,
            ]);

            if ($success) {
                $likesCount++;
                $this->consecutiveErrors = 0;
            } else {
                $this->consecutiveErrors++;
            }

            usleep(self::ACTION_DELAY_MS);
        }

        return $likesCount;
    }

    private function followUser(string $did, string $handle): bool
    {
        if ($this->alreadyActioned("follow:{$did}")) {
            return false;
        }

        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->withToken($this->auth['accessJwt'])
                ->post(self::PDS_BASE . '/xrpc/com.atproto.repo.createRecord', [
                    'repo' => $this->auth['did'],
                    'collection' => 'app.bsky.graph.follow',
                    'record' => [
                        '$type' => 'app.bsky.graph.follow',
                        'subject' => $did,
                        'createdAt' => now()->toIso8601ZuluString(),
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::warning('BlueskyProspectService: follow exception', [
                'did' => $did,
                'error' => $e->getMessage(),
            ]);

            BotActionLog::create([
                'social_account_id' => $this->account->id,
                'action_type' => 'prospect_follow',
                'target_uri' => "follow:{$did}",
                'target_author' => $handle,
                'target_text' => null,
                'search_term' => null,
                'success' => false,
                'error' => $e->getMessage(),
            ]);

            $this->consecutiveErrors++;
            usleep(self::ACTION_DELAY_MS);

            return false;
        }

        $success = $response->successful();

        BotActionLog::create([
            'social_account_id' => $this->account->id,
            'action_type' => 'prospect_follow',
            'target_uri' => "follow:{$did}",
            'target_author' => $handle,
            'target_text' => null,
            'search_term' => null,
            'success' => $success,
            'error' => $success ? null : $response->json('message'),
        ]);

        if ($success) {
            $this->consecutiveErrors = 0;
        } else {
            $this->consecutiveErrors++;
            Log::warning('BlueskyProspectService: follow failed', [
                'did' => $did,
                'status' => $response->status(),
                'error' => $response->json('message'),
            ]);
        }

        usleep(self::ACTION_DELAY_MS);

        return $success;
    }

    private function likeRecord(string $uri, string $cid): bool
    {
        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->withToken($this->auth['accessJwt'])
                ->post(self::PDS_BASE . '/xrpc/com.atproto.repo.createRecord', [
                    'repo' => $this->auth['did'],
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
        } catch (\Throwable $e) {
            Log::warning('BlueskyProspectService: like exception', [
                'uri' => $uri,
                'error' => $e->getMessage(),
            ]);
            $this->consecutiveErrors++;

            return false;
        }

        if (! $response->successful()) {
            Log::warning('BlueskyProspectService: like failed', [
                'uri' => $uri,
                'status' => $response->status(),
            ]);
            $this->consecutiveErrors++;

            return false;
        }

        $this->consecutiveErrors = 0;

        return true;
    }

    private function resolveHandle(string $handle): ?string
    {
        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->get(self::PUBLIC_API . '/xrpc/com.atproto.identity.resolveHandle', [
                    'handle' => $handle,
                ]);

            return $response->successful() ? $response->json('did') : null;
        } catch (\Throwable $e) {
            Log::warning('BlueskyProspectService: resolveHandle exception', [
                'handle' => $handle,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function alreadyActioned(string $uri): bool
    {
        return BotActionLog::where('social_account_id', $this->account->id)
            ->where('target_uri', $uri)
            ->where('success', true)
            ->exists();
    }

    private function alreadyProspected(string $did): bool
    {
        return BotActionLog::where('social_account_id', $this->account->id)
            ->where('action_type', 'prospect_follow')
            ->where('target_uri', "follow:{$did}")
            ->exists();
    }

    private function shouldStop(): bool
    {
        return Cache::has("bot_stop_prospect_{$this->target->id}");
    }

    private function refreshAuth(): bool
    {
        $auth = $this->getAuth($this->account);
        if (! $auth) {
            return false;
        }
        Log::info('BlueskyProspectService: auth OK', ['did' => $auth['did']]);
        $this->auth = $auth;

        return true;
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
            try {
                $response = Http::timeout(self::HTTP_TIMEOUT)
                    ->withToken($refreshJwt)
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
            } catch (\Throwable $e) {
                Log::warning('BlueskyProspectService: refresh session exception', ['error' => $e->getMessage()]);
            }
        }

        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->post(self::PDS_BASE . '/xrpc/com.atproto.server.createSession', [
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
        } catch (\Throwable $e) {
            Log::warning('BlueskyProspectService: create session exception', ['error' => $e->getMessage()]);
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
