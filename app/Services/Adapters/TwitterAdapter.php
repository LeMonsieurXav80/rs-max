<?php

namespace App\Services\Adapters;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TwitterAdapter implements PlatformAdapterInterface
{
    private const TWEET_URL = 'https://api.twitter.com/2/tweets';

    private const MEDIA_UPLOAD_URL = 'https://upload.twitter.com/1.1/media/upload.json';

    /**
     * OAuth 1.0a credentials populated per-request from the SocialAccount.
     */
    private string $consumerKey;

    private string $consumerSecret;

    private string $accessToken;

    private string $accessTokenSecret;

    /**
     * Publish content to Twitter (X) via the v2 API.
     *
     * @param  SocialAccount  $account  Credentials: api_key, api_secret, access_token, access_token_secret.
     * @param  string  $content  The tweet text.
     * @param  array|null  $media  Optional media items (each with url, mimetype, size, title).
     * @return array{success: bool, external_id: string|null, error: string|null}
     */
    public function publish(SocialAccount $account, string $content, ?array $media = null): array
    {
        try {
            $credentials = $account->credentials;
            $this->consumerKey = $credentials['api_key'];
            $this->consumerSecret = $credentials['api_secret'];
            $this->accessToken = $credentials['access_token'];
            $this->accessTokenSecret = $credentials['access_token_secret'];

            $tweetPayload = ['text' => $content];

            // Upload media if provided.
            if (! empty($media)) {
                $mediaIds = [];

                foreach ($media as $item) {
                    $mediaId = $this->uploadMedia($item['url']);

                    if ($mediaId === null) {
                        return [
                            'success' => false,
                            'external_id' => null,
                            'error' => "Failed to upload media: {$item['url']}",
                        ];
                    }

                    $mediaIds[] = $mediaId;
                }

                $tweetPayload['media'] = ['media_ids' => $mediaIds];
            }

            return $this->postTweet($tweetPayload);

        } catch (\Throwable $e) {
            Log::error('TwitterAdapter: publish failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'external_id' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    // -------------------------------------------------------------------------
    //  Tweet creation
    // -------------------------------------------------------------------------

    /**
     * Post a tweet via the v2 API.
     */
    private function postTweet(array $payload): array
    {
        $authHeader = $this->buildOAuthHeader('POST', self::TWEET_URL, []);

        $response = Http::withHeaders([
            'Authorization' => $authHeader,
            'Content-Type' => 'application/json',
        ])->post(self::TWEET_URL, $payload);

        $body = $response->json();

        if ($response->successful() && isset($body['data']['id'])) {
            return [
                'success' => true,
                'external_id' => (string) $body['data']['id'],
                'error' => null,
            ];
        }

        $error = $body['detail'] ?? $body['title'] ?? json_encode($body['errors'] ?? $body);

        Log::error('TwitterAdapter: tweet creation failed', [
            'status' => $response->status(),
            'error' => $error,
        ]);

        return [
            'success' => false,
            'external_id' => null,
            'error' => $error,
        ];
    }

    // -------------------------------------------------------------------------
    //  Media upload (v1.1)
    // -------------------------------------------------------------------------

    /**
     * Download a media file from the given URL and upload it to Twitter via v1.1.
     *
     * @return string|null  The media_id_string on success, null on failure.
     */
    private function uploadMedia(string $mediaUrl): ?string
    {
        // Download the file to a temporary location.
        $tempFile = tempnam(sys_get_temp_dir(), 'tw_media_');

        try {
            $downloadResponse = Http::withOptions(['sink' => $tempFile])->get($mediaUrl);

            if (! $downloadResponse->successful()) {
                Log::error('TwitterAdapter: failed to download media', ['url' => $mediaUrl]);

                return null;
            }

            $authHeader = $this->buildOAuthHeader('POST', self::MEDIA_UPLOAD_URL, []);

            $response = Http::withHeaders([
                'Authorization' => $authHeader,
            ])->attach(
                'media', file_get_contents($tempFile), basename($mediaUrl)
            )->post(self::MEDIA_UPLOAD_URL);

            $body = $response->json();

            if ($response->successful() && isset($body['media_id_string'])) {
                return $body['media_id_string'];
            }

            Log::error('TwitterAdapter: media upload failed', [
                'status' => $response->status(),
                'body' => $body,
            ]);

            return null;

        } finally {
            // Clean up the temporary file.
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    // -------------------------------------------------------------------------
    //  OAuth 1.0a
    // -------------------------------------------------------------------------

    /**
     * Build an OAuth 1.0a Authorization header using HMAC-SHA1 signing.
     *
     * @param  string  $method  HTTP method (GET, POST, etc.).
     * @param  string  $url  The full request URL (without query string for signing).
     * @param  array  $extraParams  Additional parameters to include in the signature base string
     *                               (e.g. query params or form-encoded body params). Do NOT include
     *                               JSON body params here -- OAuth 1.0a does not sign JSON payloads.
     * @return string  The Authorization header value (e.g. "OAuth oauth_consumer_key=...").
     */
    private function buildOAuthHeader(string $method, string $url, array $extraParams = []): string
    {
        $oauthParams = [
            'oauth_consumer_key' => $this->consumerKey,
            'oauth_nonce' => bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => (string) time(),
            'oauth_token' => $this->accessToken,
            'oauth_version' => '1.0',
        ];

        // Merge OAuth params with any extra request params for signature computation.
        $signatureParams = array_merge($oauthParams, $extraParams);

        // Sort by key (then by value for duplicate keys) per the OAuth spec.
        ksort($signatureParams);

        // Build the parameter string.
        $parameterString = http_build_query($signatureParams, '', '&', PHP_QUERY_RFC3986);

        // Build the signature base string.
        $signatureBaseString = implode('&', [
            strtoupper($method),
            rawurlencode($url),
            rawurlencode($parameterString),
        ]);

        // Build the signing key.
        $signingKey = rawurlencode($this->consumerSecret) . '&' . rawurlencode($this->accessTokenSecret);

        // Compute the HMAC-SHA1 signature.
        $signature = base64_encode(hash_hmac('sha1', $signatureBaseString, $signingKey, true));

        $oauthParams['oauth_signature'] = $signature;

        // Build the Authorization header.
        $headerParts = [];
        foreach ($oauthParams as $key => $value) {
            $headerParts[] = rawurlencode($key) . '="' . rawurlencode($value) . '"';
        }

        return 'OAuth ' . implode(', ', $headerParts);
    }
}
