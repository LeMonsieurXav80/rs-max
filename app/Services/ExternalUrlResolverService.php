<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Résout une URL collée d'un post externe (X, Bluesky, Threads) en :
 *  - slug de plateforme
 *  - external_id (au format attendu par l'adapter cible : tweet_id pour X, "uri|cid" pour Bluesky, post_id pour Threads)
 *  - URL canonisée
 */
class ExternalUrlResolverService
{
    /**
     * @return array{platform: string, external_id: ?string, url: string, supports_native: bool}|null
     */
    public function resolve(string $url): ?array
    {
        $url = trim($url);

        if (preg_match('#^https?://(?:www\.|mobile\.)?(?:x\.com|twitter\.com)/[^/]+/status/(\d+)#i', $url, $m)) {
            return [
                'platform' => 'twitter',
                'external_id' => $m[1],
                'url' => $url,
                'supports_native' => true,
            ];
        }

        if (preg_match('#^https?://(?:www\.)?bsky\.app/profile/([^/]+)/post/([a-z0-9]+)#i', $url, $m)) {
            $resolved = $this->resolveBlueskyPost($m[1], $m[2]);
            if ($resolved) {
                return [
                    'platform' => 'bluesky',
                    'external_id' => $resolved['uri'] . '|' . $resolved['cid'],
                    'url' => $url,
                    'supports_native' => true,
                ];
            }

            return null;
        }

        if (preg_match('#^https?://(?:www\.)?threads\.net/@?[^/]+/post/([A-Za-z0-9_-]+)#i', $url, $m)) {
            return [
                'platform' => 'threads',
                'external_id' => $m[1],
                'url' => $url,
                'supports_native' => false,
            ];
        }

        return null;
    }

    /**
     * Résout un post Bluesky via l'API publique pour récupérer son AT-URI + CID.
     *
     * @return array{uri: string, cid: string}|null
     */
    private function resolveBlueskyPost(string $handle, string $rkey): ?array
    {
        $profile = Http::get('https://public.api.bsky.app/xrpc/app.bsky.actor.getProfile', [
            'actor' => $handle,
        ]);

        if (! $profile->successful()) {
            Log::warning('ExternalUrlResolverService: Bluesky profile lookup failed', [
                'handle' => $handle,
                'status' => $profile->status(),
            ]);

            return null;
        }

        $did = $profile->json('did');
        if (! $did) {
            return null;
        }

        $uri = "at://{$did}/app.bsky.feed.post/{$rkey}";
        $thread = Http::get('https://public.api.bsky.app/xrpc/app.bsky.feed.getPostThread', [
            'uri' => $uri,
            'depth' => 0,
            'parentHeight' => 0,
        ]);

        if (! $thread->successful()) {
            return null;
        }

        $cid = $thread->json('thread.post.cid');

        return $cid ? ['uri' => $uri, 'cid' => $cid] : null;
    }
}
