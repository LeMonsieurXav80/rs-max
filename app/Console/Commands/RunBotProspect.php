<?php

namespace App\Console\Commands;

use App\Models\BotTargetAccount;
use App\Models\SocialAccount;
use App\Services\Bot\BlueskyProspectService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RunBotProspect extends Command
{
    protected $signature = 'bot:prospect
                            {--account= : Run for a specific social account ID}
                            {--target= : Run for a specific target account ID}';

    protected $description = 'Prospect likers from target Bluesky accounts (one-shot task)';

    public function handle(BlueskyProspectService $service): int
    {
        // Find next pending/running target
        $query = BotTargetAccount::whereIn('status', ['pending', 'running', 'paused'])
            ->with('socialAccount.platform');

        if ($targetId = $this->option('target')) {
            $query->where('id', $targetId);
        }

        if ($accountId = $this->option('account')) {
            $query->where('social_account_id', $accountId);
        }

        $target = $query->oldest()->first();

        if (! $target) {
            $this->info('No pending targets to process.');

            return self::SUCCESS;
        }

        $account = $target->socialAccount;
        if (! $account || $account->platform->slug !== 'bluesky') {
            $this->error('Target account is not a Bluesky account.');

            return self::FAILURE;
        }

        $this->info("Processing target: @{$target->handle} for {$account->name}");

        Cache::put("bot_running_prospect_{$account->id}", true, 7200); // 2h TTL

        try {
            $result = $service->run($account, $target);

            if (isset($result['error'])) {
                $this->error("Error: {$result['error']}");
            } elseif (isset($result['paused'])) {
                $this->info("Paused. Likers: {$result['likers_processed']}, Likes: {$result['likes']}, Follows: {$result['follows']}");
            } else {
                $this->info("Completed. Likers: {$result['likers_processed']}, Likes: {$result['likes']}, Follows: {$result['follows']}");
            }
        } finally {
            Cache::forget("bot_running_prospect_{$account->id}");
            Cache::forget("bot_stop_prospect_{$account->id}");
        }

        return self::SUCCESS;
    }
}
