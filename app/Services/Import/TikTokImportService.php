<?php

namespace App\Services\Import;

use App\Models\ExternalPost;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\SocialAccountSnapshot;
use App\Services\Browser\BrowserException;
use App\Services\Browser\BrowserTaskQueue;
use App\Services\Browser\TikTokSessionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Les lectures de Studio n'utilisent que le navigateur NAS associé au compte. */
class TikTokImportService implements PlatformImportInterface
{
    public function __construct(private BrowserTaskQueue $queue) {}

    public function importHistory(SocialAccount $account, int $limit = 50): Collection
    {
        if (! $account->usesBrowser() || $account->browser_session_status !== TikTokSessionService::CONNECTED
            || ! preg_match('/^[a-z0-9_.]{1,32}$/i', (string) $account->browser_username)) {
            throw new BrowserException('Connectez la session TikTok dans Companion sur le NAS.');
        }

        $username = strtolower($account->browser_username);
        $limit = max(1, min(200, $limit));
        $result = $this->queue->run($account, 'tiktok.studio.read', [
            'username' => $username,
            'limit' => $limit,
        ], 100);

        if (($result['username'] ?? null) !== $username || ! is_array($result['posts'] ?? null)) {
            throw new BrowserException('Lecture Studio incomplète ou compte différent.');
        }

        $avatar = TikTokSessionService::localAvatar($account, $result['avatar'] ?? null);
        $followers = $this->count($result['followers_count'] ?? null);

        return DB::transaction(function () use ($account, $result, $username, $limit, $avatar, $followers) {
            if ($avatar) {
                $account->profile_picture_url = $avatar;
            }
            if ($followers !== null) {
                $account->followers_count = $followers;
                SocialAccountSnapshot::updateOrCreate([
                    'social_account_id' => $account->id,
                    'date' => now()->toDateString(),
                    'granularity' => 'daily',
                ], ['followers_count' => $followers, 'created_at' => now()]);
            }
            $account->last_history_import_at = now();
            $account->save();

            return collect(array_slice($result['posts'], 0, $limit))
                ->filter(fn ($row) => is_array($row))
                ->map(fn ($row) => $this->storePost($account, $username, $row))
                ->filter()->values();
        });
    }

    private function storePost(SocialAccount $account, string $username, array $row): ?ExternalPost
    {
        $id = $row['id'] ?? null;
        $type = $row['type'] ?? null;
        $timestamp = $this->count($row['published_at'] ?? null);
        if (! is_string($id) || ! preg_match('/^\d{15,25}$/', $id)
            || ! in_array($type, ['photo', 'video'], true) || ! $timestamp || $timestamp > now()->timestamp) {
            return null;
        }

        $url = "https://www.tiktok.com/@{$username}/{$type}/{$id}";
        $metrics = [];
        foreach (['views', 'likes', 'comments', 'shares', 'bookmarks'] as $key) {
            $value = $this->count($row['metrics'][$key] ?? null);
            if ($value !== null) {
                $metrics[$key] = $value;
            }
        }

        $cover = TikTokSessionService::cdnUrl($row['cover'] ?? null);
        $video = $type === 'video' ? TikTokSessionService::cdnUrl($row['video'] ?? null) : null;
        $media = $video
            ? [['url' => $video, 'type' => 'video', 'thumbnail_url' => $cover]]
            : ($cover ? [['url' => $cover, 'type' => 'image']] : []);

        $item = ExternalPost::firstOrNew(['platform_id' => $account->platform_id, 'external_id' => $id]);
        if ($item->exists && $item->social_account_id !== $account->id) {
            return null;
        }
        $item->fill([
            'social_account_id' => $account->id,
            'content' => mb_substr((string) ($row['caption'] ?? ''), 0, 8000),
            'post_url' => $url,
            'published_at' => CarbonImmutable::createFromTimestamp($timestamp, config('app.timezone')),
        ]);
        if ($cover || $video) {
            $item->fill(['media_url' => $cover && strlen($cover) <= 255 ? $cover : null, 'media' => $media]);
        }
        if ($metrics !== []) {
            $item->fill(['metrics' => array_replace($item->metrics ?? [], $metrics), 'metrics_synced_at' => now()]);
        }
        $item->save();

        if ($metrics !== []) {
            $delivery = PostPlatform::where('social_account_id', $account->id)->where('external_id', $id)->where('status', 'published')->first();
            if ($delivery) {
                $delivery->update(['metrics' => array_replace($delivery->metrics ?? [], $metrics), 'metrics_synced_at' => now()]);
                $delivery->snapshots()->create(['measured_at' => now()] + $metrics);
            }
        }

        return $item;
    }

    private function count(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    public function getQuotaCost(int $postCount): array
    {
        return ['cost' => 0, 'description' => 'Lecture de TikTok Studio par Companion sur le NAS.'];
    }
}
