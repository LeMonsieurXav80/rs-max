<?php

namespace App\Services\Inbox;

use App\Models\InboxItem;
use App\Models\SocialAccount;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TwitterInboxService implements PlatformInboxInterface
{
    private const API_BASE = 'https://api.twitter.com/2';

    public function fetchInbox(SocialAccount $account, ?Carbon $since = null): Collection
    {
        $items = collect();
        $credentials = $account->credentials;

        // Skip accounts without API credentials
        if (empty($credentials['api_key']) || empty($credentials['access_token'])) {
            return $items;
        }

        $userId = $account->platform_account_id;

        try {
            $params = [
                'max_results' => '100',
                'tweet.fields' => 'created_at,author_id,in_reply_to_user_id,conversation_id,public_metrics',
                'user.fields' => 'name,username,profile_image_url',
                'expansions' => 'author_id',
            ];

            // Only fetch since last sync if we have a timestamp
            if ($since) {
                $params['start_time'] = $since->toIso8601ZuluString();
            }

            $url = self::API_BASE . "/users/{$userId}/mentions";
            $authHeader = $this->buildOAuthHeader('GET', $url, $params, $credentials);

            $response = Http::withHeaders(['Authorization' => $authHeader])
                ->get($url, $params);

            if (! $response->successful()) {
                Log::warning('TwitterInboxService: mentions fetch failed', [
                    'account_id' => $account->id,
                    'status' => $response->status(),
                    'error' => $response->json('detail') ?? $response->json('title'),
                ]);

                return $items;
            }

            $tweets = $response->json('data', []);
            $users = collect($response->json('includes.users', []))->keyBy('id');

            foreach ($tweets as $tweet) {
                $authorId = $tweet['author_id'] ?? null;

                // Skip own tweets
                if ($authorId === $userId) {
                    continue;
                }

                $author = $users->get($authorId, []);
                $tweetId = $tweet['id'];
                $conversationId = $tweet['conversation_id'] ?? $tweetId;
                $username = $author['username'] ?? null;

                $items->push([
                    'type' => 'comment',
                    'external_id' => $tweetId,
                    'external_post_id' => $conversationId,
                    'author_name' => $author['name'] ?? null,
                    'author_username' => $username,
                    'author_avatar_url' => $author['profile_image_url'] ?? null,
                    'author_external_id' => $authorId,
                    'content' => $tweet['text'] ?? null,
                    'post_url' => $username ? "https://x.com/{$username}/status/{$tweetId}" : null,
                    'posted_at' => isset($tweet['created_at']) ? Carbon::parse($tweet['created_at']) : null,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('TwitterInboxService: fetchInbox failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $items;
    }

    public function sendReply(SocialAccount $account, InboxItem $item, string $replyText): array
    {
        $credentials = $account->credentials;

        if (empty($credentials['api_key']) || empty($credentials['access_token'])) {
            return ['success' => false, 'external_id' => null, 'error' => 'No API credentials'];
        }

        try {
            $url = self::API_BASE . '/tweets';
            $body = [
                'text' => $replyText,
                'reply' => [
                    'in_reply_to_tweet_id' => $item->external_id,
                ],
            ];

            // OAuth 1.0a: JSON body params are NOT included in signature
            $authHeader = $this->buildOAuthHeader('POST', $url, [], $credentials);

            $response = Http::withHeaders(['Authorization' => $authHeader])
                ->post($url, $body);

            if ($response->successful() && $response->json('data.id')) {
                return [
                    'success' => true,
                    'external_id' => $response->json('data.id'),
                    'error' => null,
                ];
            }

            $error = $response->json('detail') ?? $response->json('title') ?? 'Failed to send reply';

            return ['success' => false, 'external_id' => null, 'error' => $error];
        } catch (\Throwable $e) {
            Log::error('TwitterInboxService: sendReply failed', [
                'account_id' => $account->id,
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'external_id' => null, 'error' => $e->getMessage()];
        }
    }

    private function buildOAuthHeader(string $method, string $url, array $extraParams, array $credentials): string
    {
        $oauthParams = [
            'oauth_consumer_key' => $credentials['api_key'],
            'oauth_nonce' => bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => (string) time(),
            'oauth_token' => $credentials['access_token'],
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

        $signingKey = rawurlencode($credentials['api_secret']) . '&' . rawurlencode($credentials['access_token_secret']);
        $signature = base64_encode(hash_hmac('sha1', $signatureBaseString, $signingKey, true));

        $oauthParams['oauth_signature'] = $signature;

        $headerParts = [];
        foreach ($oauthParams as $key => $value) {
            $headerParts[] = rawurlencode($key) . '="' . rawurlencode($value) . '"';
        }

        return 'OAuth ' . implode(', ', $headerParts);
    }
}
