<?php

namespace App\Services\Inbox;

use App\Models\InboxItem;
use App\Models\Setting;
use App\Models\SocialAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class InboxSyncService
{
    /**
     * Sync inbox for all active accounts on enabled platforms.
     *
     * @return array{fetched: int, failed: int, accounts: int}
     */
    public function syncAll(): array
    {
        $total = ['fetched' => 0, 'failed' => 0, 'accounts' => 0];

        $enabledPlatforms = $this->getEnabledPlatforms();

        if (empty($enabledPlatforms)) {
            return $total;
        }

        $accounts = SocialAccount::whereHas('platform', function ($q) use ($enabledPlatforms) {
            $q->whereIn('slug', $enabledPlatforms);
        })->with('platform')->get();

        foreach ($accounts as $account) {
            try {
                $result = $this->syncAccount($account);
                $total['fetched'] += $result['fetched'];
                $total['accounts']++;
            } catch (\Throwable $e) {
                Log::error('InboxSyncService: sync failed', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);
                $total['failed']++;
            }

            usleep(200000); // 200ms between accounts
        }

        return $total;
    }

    /**
     * Sync inbox for specific platforms only.
     *
     * @return array{fetched: int, failed: int, accounts: int}
     */
    public function syncPlatforms(array $slugs): array
    {
        $total = ['fetched' => 0, 'failed' => 0, 'accounts' => 0];

        $enabledPlatforms = array_intersect($slugs, $this->getEnabledPlatforms());

        if (empty($enabledPlatforms)) {
            return $total;
        }

        $accounts = SocialAccount::whereHas('platform', function ($q) use ($enabledPlatforms) {
            $q->whereIn('slug', $enabledPlatforms);
        })->with('platform')->get();

        foreach ($accounts as $account) {
            try {
                $result = $this->syncAccount($account);
                $total['fetched'] += $result['fetched'];
                $total['accounts']++;
            } catch (\Throwable $e) {
                Log::error('InboxSyncService: sync failed', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);
                $total['failed']++;
            }

            usleep(200000);
        }

        return $total;
    }

    /**
     * Sync inbox for a single account.
     *
     * @return array{fetched: int, error: ?string}
     */
    public function syncAccount(SocialAccount $account): array
    {
        $service = $this->getServiceForPlatform($account->platform->slug);

        if (! $service) {
            return ['fetched' => 0, 'error' => "No inbox service for {$account->platform->slug}"];
        }

        $items = $service->fetchInbox($account);
        $stored = $this->storeItems($account, $items);

        Setting::set("inbox_last_fetch_{$account->id}", now()->toIso8601String());

        return ['fetched' => $stored, 'error' => null];
    }

    /**
     * Get the inbox service for a platform slug. Public method for use by InboxController.
     */
    public function getServiceForPlatform(string $slug): ?PlatformInboxInterface
    {
        return match ($slug) {
            'facebook' => app(FacebookInboxService::class),
            'instagram' => app(InstagramInboxService::class),
            'threads' => app(ThreadsInboxService::class),
            'youtube' => app(YouTubeInboxService::class),
            'bluesky' => app(BlueskyInboxService::class),
            'telegram' => app(TelegramInboxService::class),
            'reddit' => app(RedditInboxService::class),
            'twitter' => app(TwitterInboxService::class),
            default => null,
        };
    }

    /**
     * Store fetched items, skipping those already in the database.
     */
    private function storeItems(SocialAccount $account, Collection $items): int
    {
        $externalIds = $items->pluck('external_id')->filter()->unique()->values();

        if ($externalIds->isEmpty()) {
            return 0;
        }

        // Fetch existing external_ids in one query
        $existing = InboxItem::where('platform_id', $account->platform_id)
            ->whereIn('external_id', $externalIds)
            ->pluck('external_id')
            ->flip();

        $stored = 0;

        foreach ($items as $itemData) {
            $externalId = $itemData['external_id'] ?? null;

            if (! $externalId || $existing->has($externalId)) {
                continue;
            }

            InboxItem::create(array_merge($itemData, [
                'social_account_id' => $account->id,
                'platform_id' => $account->platform_id,
            ]));

            $existing[$externalId] = true;
            $stored++;
        }

        return $stored;
    }

    /**
     * Get the list of platforms enabled for inbox sync.
     */
    private function getEnabledPlatforms(): array
    {
        $allPlatforms = ['facebook', 'instagram', 'threads', 'youtube', 'bluesky', 'telegram', 'reddit', 'twitter'];

        return array_values(array_filter($allPlatforms, function ($slug) {
            return (bool) Setting::get("inbox_platform_{$slug}_enabled", true);
        }));
    }

}
