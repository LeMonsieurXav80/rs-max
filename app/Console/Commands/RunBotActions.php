<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Services\Bot\BlueskyBotService;
use App\Services\Bot\FacebookBotService;
use Illuminate\Console\Command;

class RunBotActions extends Command
{
    protected $signature = 'bot:run
                            {--platform= : Run only for a specific platform (bluesky, facebook)}
                            {--account= : Run only for a specific account ID}';

    protected $description = 'Run automated bot actions (Bluesky auto-like, Facebook comment likes)';

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
            $this->line("  Processing: {$account->name}");
            $result = $service->runForAccount($account);
            $this->line("  Likes: {$result['total_likes']} | Terms: " . ($result['terms_processed'] ?? 0));

            if (isset($result['error'])) {
                $this->error("  Error: {$result['error']}");
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
            $this->line("  Processing: {$account->name}");
            $result = $service->runForAccount($account);
            $this->line("  Comments liked: {$result['total_likes']}");

            if (isset($result['error'])) {
                $this->error("  Error: {$result['error']}");
            }
        }
    }
}
