<?php

namespace App\Services\Inbox;

use App\Models\InboxItem;
use App\Models\Setting;
use App\Models\SocialAccount;
use Carbon\Carbon;
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

        $since = $this->getLastFetchTime($account);
        $items = $service->fetchInbox($account, $since);
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
            default => null,
        };
    }

    /**
     * Store fetched items using updateOrCreate for deduplication.
     */
    private function storeItems(SocialAccount $account, Collection $items): int
    {
        $stored = 0;

        foreach ($items as $itemData) {
            $externalId = $itemData['external_id'] ?? null;

            if (! $externalId) {
                continue;
            }

            InboxItem::updateOrCreate(
                [
                    'platform_id' => $account->platform_id,
                    'external_id' => $externalId,
                ],
                array_merge($itemData, [
                    'social_account_id' => $account->id,
                    'platform_id' => $account->platform_id,
                ])
            );

            $stored++;
        }

        return $stored;
    }

    /**
     * Get the list of platforms enabled for inbox sync.
     */
    private function getEnabledPlatforms(): array
    {
        $allPlatforms = ['facebook', 'instagram', 'threads', 'youtube', 'bluesky', 'telegram', 'reddit'];

        return array_values(array_filter($allPlatforms, function ($slug) {
            return (bool) Setting::get("inbox_platform_{$slug}_enabled", true);
        }));
    }

    /**
     * Get the last fetch time for an account.
     */
    private function getLastFetchTime(SocialAccount $account): ?Carbon
    {
        $timestamp = Setting::get("inbox_last_fetch_{$account->id}");

        return $timestamp ? Carbon::parse($timestamp) : null;
    }
}
