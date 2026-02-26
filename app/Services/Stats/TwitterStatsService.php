<?php

namespace App\Services\Stats;

use App\Models\PostPlatform;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TwitterStatsService implements PlatformStatsInterface
{
    private const API_BASE = 'https://api.twitter.com/2';

    public function fetchMetrics(PostPlatform $postPlatform): ?array
    {
        try {
            $tweetId = $postPlatform->external_id;
            $credentials = $postPlatform->socialAccount->credentials;

            if (! $tweetId) {
                return null;
            }

            // Build OAuth 1.0a header
            $apiKey = $credentials['api_key'] ?? null;
            $apiSecret = $credentials['api_secret'] ?? null;
            $accessToken = $credentials['access_token'] ?? null;
            $accessTokenSecret = $credentials['access_token_secret'] ?? null;

            if (! $apiKey || ! $apiSecret || ! $accessToken || ! $accessTokenSecret) {
                return null;
            }

            $url = self::API_BASE.'/tweets';
            $params = [
                'ids' => $tweetId,
                'tweet.fields' => 'public_metrics',
                'user.fields' => 'public_metrics',
                'expansions' => 'author_id',
            ];

            $oauthHeader = $this->buildOAuth1Header('GET', $url, $params, $apiKey, $apiSecret, $accessToken, $accessTokenSecret);

            $response = Http::withHeaders([
                'Authorization' => $oauthHeader,
            ])->get($url, $params);

            if (! $response->successful()) {
                Log::error('TwitterStatsService: Failed to fetch metrics', [
                    'post_platform_id' => $postPlatform->id,
                    'external_id' => $tweetId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $data = $response->json();
            $tweet = $data['data'][0] ?? null;
            $user = $data['includes']['users'][0] ?? null;

            if (! $tweet) {
                return null;
            }

            $metrics = $tweet['public_metrics'] ?? [];

            return [
                'views' => $metrics['impression_count'] ?? null,
                'likes' => $metrics['like_count'] ?? 0,
                'comments' => $metrics['reply_count'] ?? 0,
                'shares' => $metrics['retweet_count'] ?? 0,
                'followers' => $user['public_metrics']['followers_count'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::error('TwitterStatsService: Exception', [
                'post_platform_id' => $postPlatform->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Build OAuth 1.0a Authorization header (HMAC-SHA1).
     */
    private function buildOAuth1Header(string $method, string $url, array $extraParams, string $consumerKey, string $consumerSecret, string $token, string $tokenSecret): string
    {
        $oauthParams = [
            'oauth_consumer_key' => $consumerKey,
            'oauth_nonce' => bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => (string) time(),
            'oauth_token' => $token,
            'oauth_version' => '1.0',
        ];

        // Merge OAuth params + query params for signature base
        $allParams = array_merge($oauthParams, $extraParams);
        ksort($allParams);

        $paramString = http_build_query($allParams, '', '&', PHP_QUERY_RFC3986);
        $baseString = strtoupper($method).'&'.rawurlencode($url).'&'.rawurlencode($paramString);
        $signingKey = rawurlencode($consumerSecret).'&'.rawurlencode($tokenSecret);

        $signature = base64_encode(hash_hmac('sha1', $baseString, $signingKey, true));
        $oauthParams['oauth_signature'] = $signature;

        $headerParts = [];
        foreach ($oauthParams as $k => $v) {
            $headerParts[] = rawurlencode($k).'="'.rawurlencode($v).'"';
        }

        return 'OAuth '.implode(', ', $headerParts);
    }
}
