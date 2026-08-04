<?php

namespace App\Services\Twitter;

use App\Models\SocialAccount;

/**
 * Signature OAuth 1.0a (HMAC-SHA1) pour l'API X.
 *
 * La logique existait déjà en trois exemplaires (TwitterAdapter, FollowersService,
 * PlatformController) ; celle-ci est la version partagée pour les nouveaux appels.
 * Les trois copies historiques ne sont volontairement pas touchées : elles sont sur
 * le chemin de publication et n'ont rien demandé.
 */
class TwitterOAuth1Signer
{
    /**
     * @param  array<string, string>  $params  Paramètres de requête (query ou form), hors corps JSON.
     */
    public function header(SocialAccount $account, string $method, string $url, array $params = []): ?string
    {
        $creds = $account->credentials ?? [];

        $consumerKey = $creds['api_key'] ?? null;
        $consumerSecret = $creds['api_secret'] ?? null;
        $token = $creds['access_token'] ?? null;
        $tokenSecret = $creds['access_token_secret'] ?? null;

        if (! $consumerKey || ! $consumerSecret || ! $token || ! $tokenSecret) {
            return null;
        }

        $oauthParams = [
            'oauth_consumer_key' => $consumerKey,
            'oauth_nonce' => bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => (string) time(),
            'oauth_token' => $token,
            'oauth_version' => '1.0',
        ];

        // Le corps JSON n'entre pas dans la signature : seuls les paramètres OAuth
        // et les paramètres de requête sont signés.
        $signatureParams = array_merge($oauthParams, $params);
        ksort($signatureParams);

        $parameterString = http_build_query($signatureParams, '', '&', PHP_QUERY_RFC3986);

        $signatureBaseString = implode('&', [
            strtoupper($method),
            rawurlencode($url),
            rawurlencode($parameterString),
        ]);

        $signingKey = rawurlencode($consumerSecret).'&'.rawurlencode($tokenSecret);

        $oauthParams['oauth_signature'] = base64_encode(
            hash_hmac('sha1', $signatureBaseString, $signingKey, true)
        );

        $headerParts = [];
        foreach ($oauthParams as $key => $value) {
            $headerParts[] = rawurlencode($key).'="'.rawurlencode($value).'"';
        }

        return 'OAuth '.implode(', ', $headerParts);
    }
}
