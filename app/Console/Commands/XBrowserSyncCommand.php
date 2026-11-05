<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Services\Browser\BrowserException;
use App\Services\Stats\XBrowserStatsService;
use Illuminate\Console\Command;

class XBrowserSyncCommand extends Command
{
    protected $signature = 'x:browser-sync {--account= : Limiter à un compte} {--screens=3 : Écrans de tweets à parcourir}';

    protected $description = 'Relève abonnés et stats X depuis le navigateur du VPS';

    public function handle(XBrowserStatsService $stats): int
    {
        $accounts = SocialAccount::where('driver', 'browser')
            ->whereHas('platform', fn ($q) => $q->where('slug', 'twitter'))
            ->when($this->option('account'), fn ($q, $id) => $q->where('id', (int) $id))
            ->get();

        foreach ($accounts as $account) {
            try {
                $result = $stats->sync($account, (int) $this->option('screens'));
                $this->line("{$account->name} : ".($result ? json_encode($result) : 'session expirée'));
            } catch (BrowserException $e) {
                $this->error("{$account->name} : {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
