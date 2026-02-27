<?php

namespace App\Services;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FollowersService
{
    /**
     * Sync follower count for a social account.
     *
     * @return int|null The follower count, or null on failure
     */
    public function syncFollowers(SocialAccount $account): ?int
    {
        $slug = $account->platform->slug;

        $count = match ($slug) {
            'facebook' => $this->fetchFacebookFollowers($account),
            'instagram' => $this->fetchInstagramFollowers($account),
            'twitter' => $this->fetchTwitterFollowers($account),
            'youtube' => $this->fetchYouTubeFollowers($account),
            'telegram' => $this->fetchTelegramFollowers($account),
            'threads' => $this->fetchThreadsFollowers($account),
            default => null,
        };

        if ($count !== null) {
            $account->update([
                'followers_count' => $count,
                'followers_synced_at' => now(),
            ]);
        }

        return $count;
    }

    /**
     * Sync followers for all active accounts.
     */
    public function syncAllActive(): array
    {
        $accounts = SocialAccount::with('platform')->get();
        $results = [];

        foreach ($accounts as $account) {
            $count = $this->syncFollowers($account);
            $results[$account->id] = [
                'name' => $account->name,
                'platform' => $account->platform->slug,
                'followers' => $count,
            ];
        }

        return $results;
    }

    // ── Facebook ─────────────────────────────────────────────

    private function fetchFacebookFollowers(SocialAccount $account): ?int
    {
        $token = $account->credentials['access_token'] ?? null;
        $pageId = $account->platform_account_id;

        if (! $token) {
            return null;
        }

        try {
            $response = Http::get("https://graph.facebook.com/v21.0/{$pageId}", [
                'fields' => 'followers_count',
                'access_token' => $token,
            ]);

            if ($response->successful()) {
                return (int) $response->json('followers_count', 0);
            }

            Log::warning('FollowersService: Facebook API error', [
                'account' => $account->name,
                'status' => $response->status(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('FollowersService: Facebook exception', ['error' => $e->getMessage()]);
        }

        return null;
    }

    // ── Instagram ────────────────────────────────────────────

    private function fetchInstagramFollowers(SocialAccount $account): ?int
    {
        $token = $account->credentials['access_token'] ?? null;
        $igUserId = $account->platform_account_id;

        if (! $token) {
            return null;
        }

        try {
            $response = Http::get("https://graph.facebook.com/v21.0/{$igUserId}", [
                'fields' => 'followers_count',
                'access_token' => $token,
            ]);

            if ($response->successful()) {
                return (int) $response->json('followers_count', 0);
            }

            Log::warning('FollowersService: Instagram API error', [
                'account' => $account->name,
                'status' => $response->status(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('FollowersService: Instagram exception', ['error' => $e->getMessage()]);
        }

        return null;
    }

    // ── Twitter / X ──────────────────────────────────────────

    private function fetchTwitterFollowers(SocialAccount $account): ?int
    {
        $creds = $account->credentials;
        $consumerKey = $creds['api_key'] ?? null;
        $consumerSecret = $creds['api_secret'] ?? null;
        $accessToken = $creds['access_token'] ?? null;
        $accessTokenSecret = $creds['access_token_secret'] ?? null;

        if (! $consumerKey || ! $accessToken) {
            return null;
        }

        try {
            $url = 'https://api.twitter.com/2/users/me';
            $params = ['user.fields' => 'public_metrics'];

            $authHeader = $this->buildTwitterOAuthHeader(
                'GET',
                $url,
                $params,
                $consumerKey,
                $consumerSecret,
                $accessToken,
                $accessTokenSecret
            );

            $response = Http::withHeaders([
                'Authorization' => $authHeader,
            ])->get($url, $params);

            if ($response->successful()) {
                return (int) ($response->json('data.public_metrics.followers_count') ?? 0);
            }

            Log::warning('FollowersService: Twitter API error', [
                'account' => $account->name,
                'status' => $response->status(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('FollowersService: Twitter exception', ['error' => $e->getMessage()]);
        }

        return null;
    }

    // ── YouTube ──────────────────────────────────────────────

    private function fetchYouTubeFollowers(SocialAccount $account): ?int
    {
        $token = $account->credentials['access_token'] ?? null;
        $channelId = $account->platform_account_id;

        if (! $token) {
            return null;
        }

        try {
            $response = Http::withToken($token)
                ->get('https://www.googleapis.com/youtube/v3/channels', [
                    'part' => 'statistics',
                    'id' => $channelId,
                ]);

            if ($response->successful()) {
                $stats = $response->json('items.0.statistics');

                if ($stats && ! ($stats['hiddenSubscriberCount'] ?? false)) {
                    return (int) ($stats['subscriberCount'] ?? 0);
                }
            }

            Log::warning('FollowersService: YouTube API error', [
                'account' => $account->name,
                'status' => $response->status(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('FollowersService: YouTube exception', ['error' => $e->getMessage()]);
        }

        return null;
    }

    // ── Telegram ─────────────────────────────────────────────

    private function fetchTelegramFollowers(SocialAccount $account): ?int
    {
        $creds = $account->credentials;
        $botToken = $creds['bot_token'] ?? null;
        $chatId = $creds['chat_id'] ?? null;

        if (! $botToken || ! $chatId) {
            return null;
        }

        try {
            $response = Http::get("https://api.telegram.org/bot{$botToken}/getChatMemberCount", [
                'chat_id' => $chatId,
            ]);

            if ($response->successful() && $response->json('ok')) {
                return (int) $response->json('result', 0);
            }

            Log::warning('FollowersService: Telegram API error', [
                'account' => $account->name,
                'body' => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('FollowersService: Telegram exception', ['error' => $e->getMessage()]);
        }

        return null;
    }

    // ── Threads ──────────────────────────────────────────────

    private function fetchThreadsFollowers(SocialAccount $account): ?int
    {
        $token = $account->credentials['access_token'] ?? null;
        $userId = $account->platform_account_id;

        if (! $token) {
            return null;
        }

        try {
            $response = Http::get("https://graph.threads.net/v1.0/{$userId}/threads_insights", [
                'metric' => 'followers_count',
                'access_token' => $token,
            ]);

            if ($response->successful()) {
                return (int) ($response->json('data.0.total_value.value') ?? 0);
            }

            Log::warning('FollowersService: Threads API error', [
                'account' => $account->name,
                'status' => $response->status(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('FollowersService: Threads exception', ['error' => $e->getMessage()]);
        }

        return null;
    }

    // ── Twitter OAuth 1.0a helper ────────────────────────────

    private function buildTwitterOAuthHeader(
        string $method,
        string $url,
        array $extraParams,
        string $consumerKey,
        string $consumerSecret,
        string $accessToken,
        string $accessTokenSecret,
    ): string {
        $oauthParams = [
            'oauth_consumer_key' => $consumerKey,
            'oauth_nonce' => bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => (string) time(),
            'oauth_token' => $accessToken,
            'oauth_version' => '1.0',
        ];

        $signatureParams = array_merge($oauthParams, $extraParams);
        ksort($signatureParams);

        $parameterString = http_build_query($signatureParams, '', '&', PHP_QUERY_RFC3986);

        $signatureBaseString = implode('&', [
            strtoupper($method),
            rawurlencode($url),
            rawurlencode($parameterString),
        ]);

        $signingKey = rawurlencode($consumerSecret) . '&' . rawurlencode($accessTokenSecret);
        $signature = base64_encode(hash_hmac('sha1', $signatureBaseString, $signingKey, true));

        $oauthParams['oauth_signature'] = $signature;

        $headerParts = [];
        foreach ($oauthParams as $key => $value) {
            $headerParts[] = rawurlencode($key) . '="' . rawurlencode($value) . '"';
        }

        return 'OAuth ' . implode(', ', $headerParts);
    }
}
