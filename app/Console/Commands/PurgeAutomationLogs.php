<?php

namespace App\Console\Commands;

use App\Models\BotActionLog;
use Illuminate\Console\Command;

class PurgeAutomationLogs extends Command
{
    protected $signature = 'agent:purge-logs';

    protected $description = 'Supprime automatiquement les journaux d’automatisation de plus de sept jours.';

    public function handle(): int
    {
        $count = BotActionLog::where('created_at', '<', now()->subDays(7))->delete();
        $this->info($count.' journal(aux) supprimé(s).');

        return self::SUCCESS;
    }
}
