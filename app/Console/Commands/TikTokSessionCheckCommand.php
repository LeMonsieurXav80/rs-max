<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Services\Browser\BrowserException;
use App\Services\Browser\TikTokSessionService;
use Illuminate\Console\Command;

class TikTokSessionCheckCommand extends Command
{
    protected $signature = 'tiktok:session-check {--account= : Limiter à un compte}';

    protected $description = 'Contrôle les sessions TikTok du navigateur du VPS et les restaure si besoin';

    public function handle(TikTokSessionService $sessions): int
    {
        $accounts = SocialAccount::where('driver', 'browser')
            ->whereHas('platform', fn ($q) => $q->where('slug', 'tiktok'))
            ->whereNotNull('browser_username')
            ->whereNotNull('browser_session')
            ->when($this->option('account'), fn ($q, $id) => $q->where('id', (int) $id))
            ->get();

        foreach ($accounts as $account) {
            try {
                $connected = $sessions->check($account);
                $this->line("{$account->name} : ".($connected ? "connecté (@{$account->browser_username})" : 'session expirée'));
            } catch (BrowserException $e) {
                $this->error("{$account->name} : {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
