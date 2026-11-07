<?php

namespace App\Console\Commands;

use App\Models\AccountActionEvent;
use App\Models\BotActionLog;
use App\Models\BrowserTask;
use App\Services\Agent\AutomationJournal;
use Illuminate\Console\Command;

class BackfillAutomationLogs extends Command
{
    protected $signature = 'agent:backfill-logs';

    protected $description = 'Rapproche les journaux des tâches existantes sans exécuter de geste.';

    public function handle(AutomationJournal $journal): int
    {
        BrowserTask::where('created_at', '>=', now()->subDays(7))->chunkById(100, function ($tasks) use ($journal) {
            foreach ($tasks as $task) {
                $journal->task($task);
            }
        });
        AccountActionEvent::whereNull('browser_task_id')->where('created_at', '>=', now()->subDays(7))->chunkById(100, function ($events) use ($journal) {
            foreach ($events as $event) {
                $journal->event($event);
            }
        });
        BotActionLog::where('source', 'browser')->whereNull('browser_task_id')->where('created_at', '>=', now()->subDays(7))->delete();

        return self::SUCCESS;
    }
}
