<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\SocialAccount;
use App\Services\Bot\BlueskyBotService;
use App\Services\Bot\FacebookBotService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RunBotActions extends Command
{
    protected $signature = 'bot:run
                            {--platform= : Run only for a specific platform (bluesky, facebook)}
                            {--account= : Run only for a specific account ID}';

    protected $description = 'Run automated bot actions (Bluesky auto-like, Facebook comment likes)';

    private const FREQUENCY_MINUTES = [
        'every_15_min' => 15,
        'every_30_min' => 30,
        'hourly' => 60,
        'every_2_hours' => 120,
        'every_6_hours' => 360,
        'every_12_hours' => 720,
        'daily' => 1440,
    ];

    public function handle(): int
    {
        $platform = $this->option('platform');
        $accountId = $this->option('account');

        if (! $platform || $platform === 'bluesky') {
            $this->runBluesky($accountId);
        }

        if (! $platform || $platform === 'facebook') {
            $this->runFacebook($accountId);
        }

        return self::SUCCESS;
    }

    private function runBluesky(?string $accountId): void
    {
        $this->info('Running Bluesky bot...');

        $query = SocialAccount::whereHas('platform', fn ($q) => $q->where('slug', 'bluesky'));

        if ($accountId) {
            $query->where('id', $accountId);
        }

        $accounts = $query->get();
        $service = new BlueskyBotService;

        foreach ($accounts as $account) {
            // When running via scheduler (no --account), check if bot is active
            if (! $accountId && Setting::get("bot_active_bluesky_{$account->id}") !== '1') {
                $this->line("  Skipping {$account->name} (not active)");

                continue;
            }

            // Skip if not due (unless manually triggered with --account)
            if (! $accountId && ! $this->isDue('bluesky', $account->id, 'every_30_min')) {
                $this->line("  Skipping {$account->name} (not due yet)");

                continue;
            }

            $cacheKey = "bot_running_bluesky_{$account->id}";
            Cache::put($cacheKey, true, 600);
            Cache::forget("bot_stop_bluesky_{$account->id}");

            $this->line("  Processing: {$account->name}");

            try {
                $result = $service->runForAccount($account);
                $this->line("  Likes: {$result['total_likes']} | Terms: " . ($result['terms_processed'] ?? 0));

                if (isset($result['error'])) {
                    $this->error("  Error: {$result['error']}");
                }

                // Mark last run time
                Setting::set("bot_last_run_bluesky_{$account->id}", now()->toIso8601String());
            } finally {
                Cache::forget($cacheKey);
            }
        }
    }

    private function runFacebook(?string $accountId): void
    {
        $this->info('Running Facebook comment likes...');

        $query = SocialAccount::whereHas('platform', fn ($q) => $q->where('slug', 'facebook'));

        if ($accountId) {
            $query->where('id', $accountId);
        }

        $accounts = $query->get();
        $service = new FacebookBotService;

        foreach ($accounts as $account) {
            // When running via scheduler (no --account), check if bot is active
            if (! $accountId && Setting::get("bot_active_facebook_{$account->id}") !== '1') {
                $this->line("  Skipping {$account->name} (not active)");

                continue;
            }

            if (! $accountId && ! $this->isDue('facebook', $account->id, 'every_30_min')) {
                $this->line("  Skipping {$account->name} (not due yet)");

                continue;
            }

            $cacheKey = "bot_running_facebook_{$account->id}";
            Cache::put($cacheKey, true, 600);
            Cache::forget("bot_stop_facebook_{$account->id}");

            $this->line("  Processing: {$account->name}");

            try {
                $result = $service->runForAccount($account);
                $this->line("  Comments liked: {$result['total_likes']}");

                if (isset($result['error'])) {
                    $this->error("  Error: {$result['error']}");
                }

                Setting::set("bot_last_run_facebook_{$account->id}", now()->toIso8601String());
            } finally {
                Cache::forget($cacheKey);
            }
        }
    }

    private function isDue(string $platform, int $accountId, string $defaultFreq): bool
    {
        $freq = rescue(fn () => Setting::get("bot_freq_{$platform}_{$accountId}", $defaultFreq), $defaultFreq, false);

        if ($freq === 'disabled') {
            return false;
        }

        $minutes = self::FREQUENCY_MINUTES[$freq] ?? 60;

        $lastRun = rescue(fn () => Setting::get("bot_last_run_{$platform}_{$accountId}"), null, false);

        if (! $lastRun) {
            return true;
        }

        return abs(now()->diffInMinutes(\Carbon\Carbon::parse($lastRun))) >= $minutes;
    }
}
