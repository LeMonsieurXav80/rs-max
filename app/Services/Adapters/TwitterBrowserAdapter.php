<?php

namespace App\Services\Adapters;

use App\Models\SocialAccount;
use App\Services\Browser\BrowserException;
use App\Services\Browser\BrowserTaskQueue;
use App\Services\Browser\BrowserUncertainException;
use App\Services\Browser\XSessionService;
use Illuminate\Support\Facades\Log;

/** Publie sur X par Companion, dans le Chromium du VPS. */
class TwitterBrowserAdapter implements PlatformAdapterInterface, ResharingAdapterInterface, ThreadableAdapterInterface
{
    private const COMPOSE_URL = 'https://x.com/compose/post';

    private const MAX_IMAGES = 4;

    // Une vidéo reste « en traitement » côté X avant que le bouton Poster s'active.
    private const POST_TIMEOUT = 900;

    private const ACTION_TIMEOUT = 120;

    public function __construct(private XSessionService $sessions, private BrowserTaskQueue $queue) {}

    public function publish(SocialAccount $account, string $content, ?array $media = null, ?array $options = null): array
    {
        // L'éditeur d'articles n'a pas de parcours ici : le texte d'un article reste sur l'API.
        if (trim((string) ($options['article_title'] ?? '')) !== '') {
            return (new TwitterAdapter)->publish($account, $content, $media, $options);
        }

        return $this->post($account, self::COMPOSE_URL, $content, $media);
    }

    public function publishReply(SocialAccount $account, string $content, string $replyToId, ?array $media = null, ?array $options = null): array
    {
        return $this->post($account, 'https://x.com/intent/post?in_reply_to='.$replyToId, $content, $media);
    }

    public function nativeQuote(SocialAccount $account, string $text, string $sourceExternalId, ?array $media = null): array
    {
        // Le lien dans le texte devient la citation, comme quand on le colle à la main.
        return $this->post($account, self::COMPOSE_URL, trim($text.' https://x.com/i/web/status/'.$sourceExternalId), $media);
    }

    public function nativeRepost(SocialAccount $account, string $sourceExternalId): array
    {
        return $this->action($account, 'x.repost', ['url' => 'https://x.com/intent/retweet?tweet_id='.$sourceExternalId],
            ['external_id' => $sourceExternalId]);
    }

    public function deleteTweet(SocialAccount $account, string $tweetId): array
    {
        return $this->action($account, 'x.delete', ['url' => "https://x.com/{$account->browser_username}/status/{$tweetId}", 'id' => $tweetId]);
    }

    /** Parcours complet sans le clic final : prouve les médias sans rien publier. */
    public function rehearse(SocialAccount $account, string $content, ?array $media = null): array
    {
        return $this->post($account, self::COMPOSE_URL, $content, $media, dry: true);
    }

    private function post(SocialAccount $account, string $url, string $content, ?array $media, bool $dry = false): array
    {
        $payload = ['url' => $url, 'text' => $content, 'username' => $account->browser_username,
            'media' => $this->selectMedia($media ?? []), 'dry' => $dry];

        return $this->action($account, 'x.post', $payload, [], self::POST_TIMEOUT);
    }

    private function action(SocialAccount $account, string $kind, array $payload, array $extra = [], int $timeout = self::ACTION_TIMEOUT): array
    {
        try {
            if (! $this->sessions->check($account)) {
                throw new BrowserException('Session X absente ou expirée sur le navigateur du VPS.');
            }

            $result = $this->queue->run($account, $kind, $payload, $timeout);
            $id = $extra['external_id'] ?? ($result['id'] ?? null);

            return ['success' => true, 'external_id' => $id, 'error' => null]
                + ($id && $kind === 'x.post' ? ['permalink' => "https://x.com/{$account->browser_username}/status/{$id}"] : [])
                + $result;
        } catch (BrowserException $e) {
            Log::error('TwitterBrowserAdapter: échec', ['account_id' => $account->id, 'kind' => $kind, 'error' => $e->getMessage()]);

            return ['success' => false, 'external_id' => null, 'error' => $e->getMessage(), 'uncertain' => $e instanceof BrowserUncertainException];
        }
    }

    /** Une vidéo seule, ou jusqu'à 4 images : X refuse de mélanger les deux. */
    private function selectMedia(array $media): array
    {
        $videos = array_values(array_filter($media, fn ($m) => str_starts_with((string) ($m['mimetype'] ?? ''), 'video/')));
        $selected = $videos !== [] ? array_slice($videos, 0, 1) : array_slice($media, 0, self::MAX_IMAGES);

        return array_map(fn (array $m) => [
            'url' => $m['url'] ?? null,
            'mimetype' => $m['mimetype'] ?? 'image/jpeg',
            'name' => basename(parse_url((string) ($m['url'] ?? 'media'), PHP_URL_PATH) ?: 'media'),
        ], $selected);
    }
}
