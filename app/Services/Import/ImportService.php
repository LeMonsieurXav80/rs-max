<?php

namespace App\Services\Import;

use App\Models\SocialAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ImportService
{
    /**
     * Import historical posts for a social account.
     *
     * @param  SocialAccount  $account  The social account to import from
     * @param  int  $limit  Maximum number of posts to import
     * @return array ['success' => bool, 'imported' => int, 'error' => ?string]
     */
    public function import(SocialAccount $account, int $limit = 50): array
    {
        $platformSlug = $account->platform->slug;
        $service = $this->getServiceForPlatform($platformSlug);

        if (! $service) {
            return [
                'success' => false,
                'imported' => 0,
                'error' => "No import service available for platform: {$platformSlug}",
            ];
        }

        try {
            $imported = $service->importHistory($account, $limit);

            return [
                'success' => true,
                'imported' => $imported->count(),
                'error' => null,
            ];
        } catch (\Exception $e) {
            Log::error("Import failed for account {$account->id}", [
                'platform' => $platformSlug,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'imported' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get quota cost estimate for importing from an account.
     *
     * @param  SocialAccount  $account
     * @param  int  $postCount  Number of posts to import
     * @return array ['cost' => int, 'description' => string]
     */
    public function getQuotaCost(SocialAccount $account, int $postCount = 50): array
    {
        $platformSlug = $account->platform->slug;
        $service = $this->getServiceForPlatform($platformSlug);

        if (! $service) {
            return [
                'cost' => 0,
                'description' => 'Service non disponible',
            ];
        }

        return $service->getQuotaCost($postCount);
    }

    /**
     * Check if import should be allowed based on cooldown rules.
     *
     * @param  SocialAccount  $account
     * @return array ['allowed' => bool, 'reason' => ?string]
     */
    public function canImport(SocialAccount $account): array
    {
        // TODO: Re-enable cooldowns for production
        // $platformSlug = $account->platform->slug;
        // $lastImport = $account->last_history_import_at;
        //
        // // YouTube: enforce 7-day cooldown
        // if ($platformSlug === 'youtube') {
        //     if ($lastImport && $lastImport->diffInDays(now()) < 7) {
        //         $nextAllowed = $lastImport->addDays(7)->diffForHumans();
        //         return [
        //             'allowed' => false,
        //             'reason' => "Pour protéger le quota YouTube, vous devez attendre {$nextAllowed} avant de ré-importer.",
        //         ];
        //     }
        // }
        //
        // // Other platforms: 24-hour cooldown
        // if ($lastImport && $lastImport->diffInHours(now()) < 24) {
        //     $nextAllowed = $lastImport->addHours(24)->diffForHumans();
        //     return [
        //         'allowed' => false,
        //         'reason' => "Veuillez attendre {$nextAllowed} avant de ré-importer.",
        //     ];
        // }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * Get the appropriate import service for a platform.
     */
    private function getServiceForPlatform(string $slug): ?PlatformImportInterface
    {
        return match ($slug) {
            'facebook' => app(FacebookImportService::class),
            'instagram' => app(InstagramImportService::class),
            'twitter' => app(TwitterImportService::class),
            'youtube' => app(YouTubeImportService::class),
            'threads' => app(ThreadsImportService::class),
            default => null,
        };
    }
}
