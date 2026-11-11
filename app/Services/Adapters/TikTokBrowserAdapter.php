<?php

namespace App\Services\Adapters;

use App\Models\SocialAccount;
use App\Services\Browser\BrowserException;
use App\Services\Browser\BrowserTaskQueue;
use App\Services\Browser\BrowserUncertainException;
use App\Services\Browser\TikTokSessionService;
use App\Services\Media\TikTokPostMedia;

class TikTokBrowserAdapter implements PlatformAdapterInterface
{
    private const UPLOAD_URL = 'https://www.tiktok.com/tiktokstudio/upload';

    public function __construct(private BrowserTaskQueue $queue, private TikTokPostMedia $mediaRules) {}

    public function publish(SocialAccount $account, string $content, ?array $media = null, ?array $options = null): array
    {
        try {
            $username = strtolower((string) $account->browser_username);
            if ($account->browser_session_status !== TikTokSessionService::CONNECTED || ! preg_match('/^[a-z0-9_.]{1,32}$/', $username)) {
                throw new BrowserException('Session TikTok du NAS absente ou expirée.');
            }
            $key = $options['publication_key'] ?? null;
            if (! is_string($key) || ! preg_match('/^post-platform:\\d+$/', $key)) {
                throw new BrowserException('Une diffusion RS-Max est requise pour publier sur TikTok.');
            }

            $caption = trim(str_replace(["\r\n", "\r"], "\n", $content));
            if ($caption === '' || strlen(mb_convert_encoding($caption, 'UTF-16LE', 'UTF-8')) > 8000) {
                throw new BrowserException('La description TikTok doit contenir entre 1 et 4 000 caractères.');
            }
            $files = $this->files($media ?? []);
            $type = $this->mediaRules->type($files);

            $result = $this->queue->run($account, 'tiktok.publish', [
                'url' => self::UPLOAD_URL,
                'username' => $username,
                'publication_key' => $key,
                'text' => $caption,
                'caption' => $caption,
                'title' => '',
                'media_type' => $type,
                'media' => $files,
                'visibility' => 'public',
                'deadline' => now()->addMinutes(15)->toIso8601String(),
            ], 900);

            $id = (string) ($result['id'] ?? '');
            $permalink = "https://www.tiktok.com/@{$username}/{$type}/{$id}";
            if (! preg_match('/^\\d{15,25}$/', $id) || ($result['url'] ?? null) !== $permalink
                || ($result['confirmed'] ?? false) !== true || ($result['proof'] ?? null) !== 'after_reload') {
                throw new BrowserUncertainException('Publication TikTok à vérifier dans Studio. Aucun nouvel envoi automatique.');
            }

            return ['success' => true, 'external_id' => $id, 'permalink' => $permalink, 'error' => null];
        } catch (BrowserException $e) {
            return ['success' => false, 'external_id' => null, 'error' => $e->getMessage(), 'uncertain' => $e instanceof BrowserUncertainException];
        }
    }

    private function files(array $media): array
    {
        $files = [];
        foreach ($media as $item) {
            $path = $item['local_path'] ?? null;
            $url = $item['url'] ?? null;
            if (! is_string($path) || ! is_file($path) || ! is_string($url)) {
                throw new BrowserException('Un fichier de la médiathèque TikTok est indisponible.');
            }
            $catalog = $this->mediaRules->catalogFile(basename($path));
            if (! $catalog) {
                throw new BrowserException('Média TikTok absent de la médiathèque.');
            }
            $size = filesize($path);
            if (! $size || $size > TikTokPostMedia::MAX_BATCH_BYTES) {
                throw new BrowserException('Fichier TikTok vide ou supérieur à 250 Mo.');
            }
            $digest = hash_file('sha256', $path);
            if ($digest === false) {
                throw new BrowserException('Fichier TikTok illisible.');
            }
            $files[] = ['url' => $url, 'mimetype' => $catalog->mime_type, 'size' => $size,
                'name' => basename($path), 'sha256' => $digest];
        }

        return $files;
    }
}
