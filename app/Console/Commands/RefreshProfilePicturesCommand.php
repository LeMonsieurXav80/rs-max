<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Services\ProfilePictureService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class RefreshProfilePicturesCommand extends Command
{
    protected $signature = 'accounts:refresh-pictures
                            {--account= : Refresh a specific social account ID}
                            {--platform= : Refresh only accounts for a specific platform slug}
                            {--force : Re-download even if already stored locally}';

    protected $description = 'Download and store profile pictures locally for all social accounts';

    public function handle(): int
    {
        $query = SocialAccount::with('platform');

        if ($accountId = $this->option('account')) {
            $query->where('id', $accountId);
        }

        if ($platformSlug = $this->option('platform')) {
            $query->whereHas('platform', fn ($q) => $q->where('slug', $platformSlug));
        }

        // Skip Telegram bot records
        $accounts = $query->get()->reject(fn ($a) => str_starts_with($a->platform_account_id ?? '', 'bot_'));

        if ($accounts->isEmpty()) {
            $this->warn('No accounts found.');

            return self::SUCCESS;
        }

        $this->info("Processing {$accounts->count()} account(s)...");

        $bar = $this->output->createProgressBar($accounts->count());
        $bar->start();

        $downloaded = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($accounts as $account) {
            $slug = $account->platform->slug;

            // Skip if already local and not forcing
            if (! $this->option('force') && str_starts_with($account->profile_picture_url ?? '', '/media/')) {
                $skipped++;
                $bar->advance();

                continue;
            }

            $localPath = $this->refreshForPlatform($account, $slug);

            if ($localPath) {
                $account->update(['profile_picture_url' => $localPath]);
                $downloaded++;
            } else {
                $failed++;
            }

            $bar->advance();
            usleep(200000); // 200ms delay
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Downloaded: {$downloaded} | Skipped: {$skipped} | Failed: {$failed}");

        return self::SUCCESS;
    }

    private function refreshForPlatform(SocialAccount $account, string $slug): ?string
    {
        // First try: if current URL is remote, download it directly
        $currentUrl = $account->profile_picture_url;
        if ($currentUrl && ! str_starts_with($currentUrl, '/media/')) {
            $result = ProfilePictureService::download($currentUrl, $slug, $account->platform_account_id);
            if ($result) {
                return $result;
            }
        }

        // Second try: fetch fresh URL from platform API
        $remoteUrl = $this->fetchFreshProfilePicture($account, $slug);
        if ($remoteUrl) {
            return ProfilePictureService::download($remoteUrl, $slug, $account->platform_account_id);
        }

        return null;
    }

    private function fetchFreshProfilePicture(SocialAccount $account, string $slug): ?string
    {
        return match ($slug) {
            'facebook' => $this->fetchFacebookPicture($account),
            'instagram' => $this->fetchInstagramPicture($account),
            'threads' => $this->fetchThreadsPicture($account),
            'youtube' => $this->fetchYouTubePicture($account),
            'bluesky' => $this->fetchBlueskyPicture($account),
            'telegram' => $this->fetchTelegramPicture($account),
            'twitter' => null, // Twitter pay-per-use — don't waste API calls
            default => null,
        };
    }

    private function fetchFacebookPicture(SocialAccount $account): ?string
    {
        $token = $account->credentials['access_token'] ?? null;
        $pageId = $account->platform_account_id;
        if (! $token || ! $pageId) {
            return null;
        }

        try {
            $response = Http::get("https://graph.facebook.com/v21.0/{$pageId}/picture", [
                'redirect' => 'false',
                'type' => 'large',
                'access_token' => $token,
            ]);

            return $response->json('data.url');
        } catch (\Throwable) {
            return null;
        }
    }

    private function fetchInstagramPicture(SocialAccount $account): ?string
    {
        $token = $account->credentials['access_token'] ?? null;
        $igId = $account->platform_account_id;
        if (! $token || ! $igId) {
            return null;
        }

        try {
            $response = Http::get("https://graph.facebook.com/v21.0/{$igId}", [
                'fields' => 'profile_picture_url',
                'access_token' => $token,
            ]);

            return $response->json('profile_picture_url');
        } catch (\Throwable) {
            return null;
        }
    }

    private function fetchThreadsPicture(SocialAccount $account): ?string
    {
        $token = $account->credentials['access_token'] ?? null;
        if (! $token) {
            return null;
        }

        try {
            $response = Http::get('https://graph.threads.net/v1.0/me', [
                'fields' => 'threads_profile_picture_url',
                'access_token' => $token,
            ]);

            return $response->json('threads_profile_picture_url');
        } catch (\Throwable) {
            return null;
        }
    }

    private function fetchYouTubePicture(SocialAccount $account): ?string
    {
        $channelId = $account->credentials['channel_id'] ?? null;
        $accessToken = $account->credentials['access_token'] ?? null;
        if (! $channelId || ! $accessToken) {
            return null;
        }

        try {
            $response = Http::get('https://www.googleapis.com/youtube/v3/channels', [
                'part' => 'snippet',
                'id' => $channelId,
                'access_token' => $accessToken,
            ]);

            return $response->json('items.0.snippet.thumbnails.high.url')
                ?? $response->json('items.0.snippet.thumbnails.default.url');
        } catch (\Throwable) {
            return null;
        }
    }

    private function fetchBlueskyPicture(SocialAccount $account): ?string
    {
        $did = $account->credentials['did'] ?? $account->platform_account_id;
        if (! $did) {
            return null;
        }

        try {
            $response = Http::get('https://public.api.bsky.app/xrpc/app.bsky.actor.getProfile', [
                'actor' => $did,
            ]);

            return $response->json('avatar');
        } catch (\Throwable) {
            return null;
        }
    }

    private function fetchTelegramPicture(SocialAccount $account): ?string
    {
        $botToken = $account->credentials['bot_token'] ?? null;
        $chatId = $account->credentials['chat_id'] ?? $account->platform_account_id;
        if (! $botToken || ! $chatId) {
            return null;
        }

        try {
            $chatResponse = Http::get("https://api.telegram.org/bot{$botToken}/getChat", [
                'chat_id' => $chatId,
            ]);

            if (! $chatResponse->successful() || ! $chatResponse->json('ok')) {
                return null;
            }

            $fileId = $chatResponse->json('result.photo.big_file_id');
            if (! $fileId) {
                return null;
            }

            $fileResponse = Http::get("https://api.telegram.org/bot{$botToken}/getFile", [
                'file_id' => $fileId,
            ]);

            $filePath = $fileResponse->json('result.file_path');
            if (! $filePath) {
                return null;
            }

            return "https://api.telegram.org/file/bot{$botToken}/{$filePath}";
        } catch (\Throwable) {
            return null;
        }
    }
}
