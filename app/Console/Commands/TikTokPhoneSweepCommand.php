<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Services\Browser\BrowserTaskQueue;
use App\Services\Inbox\PhoneInboxSweep;
use Illuminate\Console\Command;

/** Regroupe les réponses et likes TikTok mis de côté pour un téléphone en un passage dans Studio (`PhoneInboxSweep`). */
class TikTokPhoneSweepCommand extends Command
{
    protected $signature = 'tiktok:phone-sweep {--now : Sans attendre la fin de la salve en cours}';

    protected $description = 'Regroupe les réponses et likes TikTok d\'un téléphone en un seul passage dans TikTok Studio';

    public function handle(PhoneInboxSweep $sweep, BrowserTaskQueue $queue): int
    {
        $accounts = SocialAccount::with('platform')->where('driver', 'browser')
            ->whereHas('platform', fn ($q) => $q->where('slug', 'tiktok'))->get()->filter->usesPhone();
        foreach ($accounts as $account) {
            $task = $sweep->bundle($account, $queue, ! $this->option('now'));
            if ($task) {
                $this->line("{$account->name} : passage #{$task->id}, ".count($task->payload['items']).' commentaire(s).');
            }
        }

        return self::SUCCESS;
    }
}
