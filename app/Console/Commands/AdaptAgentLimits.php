<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Services\Agent\AdaptiveAccountLimits;
use Illuminate\Console\Command;

class AdaptAgentLimits extends Command
{
    protected $signature = 'agent:adapt-limits';

    protected $description = 'Adapte chaque compte dans ses plafonds explicites, à partir des gestes confirmés.';

    public function handle(AdaptiveAccountLimits $limits): int
    {
        foreach (SocialAccount::whereNotNull('agent_adaptation')->cursor() as $account) {
            if ($row = $limits->evaluate($account)) {
                \App\Jobs\NotifyAccountLimitAdjustment::dispatch($row->id);
            }
        }

        return self::SUCCESS;
    }
}
