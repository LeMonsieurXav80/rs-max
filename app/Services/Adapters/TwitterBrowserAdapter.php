<?php

namespace App\Services\Adapters;

use App\Models\SocialAccount;
use App\Services\Browser\BrowserException;
use App\Services\Browser\XPage;
use App\Services\Browser\XSessionService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Publie sur X depuis le navigateur du VPS, avec la session du compte. */
class TwitterBrowserAdapter implements PlatformAdapterInterface, ResharingAdapterInterface, ThreadableAdapterInterface
{
    private const COMPOSE_URL = 'https://x.com/compose/post';

    private const INTENT_URL = 'https://x.com/intent/post';

    private const MAX_IMAGES = 4;

    private const CONFIRM_MINUTES = 10;

    private const CONFIRM_ATTEMPTS = 4;

    private const CONFIRM_PAUSE_SECONDS = 4;

    public function __construct(private XSessionService $sessions) {}

    public function publish(SocialAccount $account, string $content, ?array $media = null, ?array $options = null): array
    {
        // L'éditeur d'articles n'a pas de parcours ici : le texte d'un article reste sur l'API.
        if (trim((string) ($options['article_title'] ?? '')) !== '') {
            return (new TwitterAdapter)->publish($account, $content, $media, $options);
        }

        return $this->post($account, self::COMPOSE_URL, $content, $media, 'https://x.com/%s');
    }

    public function publishReply(SocialAccount $account, string $content, string $replyToId, ?array $media = null, ?array $options = null): array
    {
        return $this->post($account, self::INTENT_URL.'?in_reply_to='.$replyToId, $content, $media, 'https://x.com/%s/with_replies');
    }

    public function nativeQuote(SocialAccount $account, string $text, string $sourceExternalId, ?array $media = null): array
    {
        $source = rawurlencode("https://x.com/i/web/status/{$sourceExternalId}");

        return $this->post($account, self::INTENT_URL.'?url='.$source, $text, $media, 'https://x.com/%s');
    }

    public function nativeRepost(SocialAccount $account, string $sourceExternalId): array
    {
        return $this->inBrowser($account, 'https://x.com/intent/retweet?tweet_id='.$sourceExternalId, function (XPage $page) use ($sourceExternalId) {
            $confirm = '[data-testid="confirmationSheetConfirm"]';

            if (! $page->waitFor($confirm, 20)) {
                return $this->failure('Bouton de repost introuvable.');
            }

            $page->click($confirm);

            return ['success' => true, 'external_id' => $sourceExternalId, 'error' => null];
        });
    }

    public function deleteTweet(SocialAccount $account, string $tweetId): array
    {
        return $this->inBrowser($account, "https://x.com/i/web/status/{$tweetId}", function (XPage $page) {
            $caret = 'article[data-testid="tweet"] [data-testid="caret"]';
            $confirm = '[data-testid="confirmationSheetConfirm"]';

            if (! $page->waitFor($caret, 20)) {
                return $this->failure('Tweet introuvable.');
            }

            $page->click($caret);

            if (! $page->clickMenuItem('Supprimer|Delete') || ! $page->waitFor($confirm, 10)) {
                return $this->failure('Suppression indisponible : ce tweet n’est sans doute pas celui du compte.');
            }

            $page->click($confirm);

            return $page->waitUntilGone($confirm, 15)
                ? ['success' => true, 'external_id' => null, 'error' => null]
                : $this->failure('Suppression non confirmée.');
        });
    }

    /** Parcours complet sans le clic final : prouve la session et les médias sans rien publier. */
    public function rehearse(SocialAccount $account, string $content, ?array $media = null): array
    {
        return $this->inBrowser($account, self::COMPOSE_URL, function (XPage $page) use ($content, $media) {
            $this->prepare($page, $content, $media);

            return ['success' => true, 'external_id' => null, 'error' => null, 'screenshot' => $page->screenshot()];
        });
    }

    private function post(SocialAccount $account, string $url, string $content, ?array $media, string $timeline): array
    {
        return $this->inBrowser($account, $url, function (XPage $page) use ($account, $content, $media, $timeline) {
            $this->prepare($page, $content, $media);
            $page->submit();

            // À partir d'ici, le tweet est peut-être parti : un échec ne doit jamais relancer l'envoi.
            if (! $page->waitForPublished(30)) {
                return $this->failure('X n’a pas accepté la publication : '.($page->toast() ?? 'raison inconnue'));
            }

            $tweetId = $this->confirm($page, sprintf($timeline, $account->browser_username), $account->browser_username, $content);

            return $tweetId
                ? ['success' => true, 'external_id' => $tweetId, 'error' => null, 'permalink' => "https://x.com/{$account->browser_username}/status/{$tweetId}"]
                : $this->failure('Envoi non confirmé : vérifier sur X avant de relancer, le tweet est peut-être parti.');
        });
    }

    private function prepare(XPage $page, string $content, ?array $media): void
    {
        if (! $page->waitFor(XPage::EDITOR, 25)) {
            throw $page->identity() === null ? BrowserException::sessionLost() : new BrowserException('Éditeur X introuvable.');
        }

        // Les médias d'abord : le bouton ne s'active qu'une fois X d'accord. Avec du texte
        // déjà saisi, il serait actif même après un refus, et le tweet partirait sans eux.
        $temporary = [];

        try {
            $paths = $this->uploadPaths($media ?? [], $temporary);

            if ($paths !== []) {
                $page->upload($paths);

                if (! $page->waitForSubmit((int) config('browser.media_timeout')) || ! $page->hasAttachments(count($paths))) {
                    throw new BrowserException('Média refusé par X : '.($page->toast() ?? 'raison inconnue'));
                }
            }

            if ($content !== '' && ! $page->fill($content)) {
                throw new BrowserException('Le texte saisi diffère du texte prévu : publication annulée.');
            }

            if (! $page->waitForSubmit(15)) {
                throw new BrowserException('Bouton Poster inactif : '.($page->toast() ?? 'raison inconnue'));
            }
        } finally {
            Storage::disk('local')->delete($temporary);
        }
    }

    /** Le tweet envoyé est reconnu sur la timeline du compte : X ne donne son id qu'à sa page. */
    private function confirm(XPage $page, string $timeline, string $username, string $content): ?string
    {
        $wanted = self::fingerprint($content);
        $since = now()->subMinutes(self::CONFIRM_MINUTES);

        // Juste après l'envoi, le profil affiche encore l'ancienne liste.
        for ($attempt = 0; $attempt < self::CONFIRM_ATTEMPTS; $attempt++) {
            $page->goTo($timeline);
            $page->waitFor('article[data-testid="tweet"]', 15);
            sleep(self::CONFIRM_PAUSE_SECONDS);

            foreach ($page->tweets() as $tweet) {
                $recent = $tweet['published_at'] && now()->parse($tweet['published_at'])->gte($since);

                if ($tweet['author'] === strtolower($username) && $recent && ! $tweet['pinned']
                    && ($wanted === '' || str_starts_with(self::fingerprint($tweet['text']), $wanted))) {
                    return $tweet['id'];
                }
            }
        }

        return null;
    }

    /** Début du texte sans liens : X les raccourcit à l'affichage. */
    private static function fingerprint(string $text): string
    {
        $text = preg_replace('~https?://\S+~u', '', $text);

        return mb_substr(trim(preg_replace('/\s+/u', ' ', $text)), 0, 40);
    }

    /**
     * Chemins vus par Camofox. Un fichier hors médiathèque (fil publié par URL) est
     * d'abord copié dans le dossier partagé ; `$temporary` liste ce qu'il faudra retirer.
     *
     * @return array<int, string>
     */
    private function uploadPaths(array $media, array &$temporary): array
    {
        $videos = array_values(array_filter($media, fn ($m) => str_starts_with((string) ($m['mimetype'] ?? ''), 'video/')));
        $selected = $videos !== [] ? array_slice($videos, 0, 1) : array_slice($media, 0, self::MAX_IMAGES);
        $root = storage_path('app/private/media/');

        return array_map(function (array $item) use ($root, &$temporary) {
            $local = $item['local_path'] ?? null;

            if ($local && str_starts_with($local, $root) && is_file($local)) {
                return rtrim(config('browser.uploads_dir'), '/').'/'.substr($local, strlen($root));
            }

            $relative = 'browser/'.Str::uuid().'.'.Str::afterLast((string) ($item['mimetype'] ?? 'image/jpeg'), '/');
            $response = Http::timeout(120)->get((string) ($item['url'] ?? ''));

            if (! $response->successful()) {
                throw new BrowserException('Média inaccessible pour la publication.');
            }

            Storage::disk('local')->put('media/'.$relative, $response->body());
            $temporary[] = 'media/'.$relative;

            return rtrim(config('browser.uploads_dir'), '/').'/'.$relative;
        }, $selected);
    }

    private function inBrowser(SocialAccount $account, string $url, callable $action): array
    {
        try {
            if (! $this->sessions->check($account)) {
                throw BrowserException::sessionLost();
            }

            $page = $this->sessions->open($account, $url);

            try {
                return $action($page);
            } finally {
                $page->close();
            }
        } catch (BrowserException $e) {
            Log::error('TwitterBrowserAdapter: échec', ['account_id' => $account->id, 'error' => $e->getMessage()]);

            return $this->failure($e->getMessage());
        }
    }

    private function failure(string $error): array
    {
        return ['success' => false, 'external_id' => null, 'error' => $error];
    }
}
