<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Services\Adapters\TwitterBrowserAdapter;
use Illuminate\Console\Command;

class XBrowserRehearseCommand extends Command
{
    protected $signature = 'x:browser-rehearse {account} {--text=Répétition RS-Max} {--media=* : URL du média}';

    protected $description = 'Répète une publication X sans cliquer sur Poster';

    public function handle(TwitterBrowserAdapter $adapter): int
    {
        $account = SocialAccount::with('platform')->findOrFail($this->argument('account'));
        $media = array_map(fn (string $url) => ['url' => $url, 'mimetype' => str_ends_with($url, '.mp4') ? 'video/mp4' : 'image/jpeg'], $this->option('media'));

        $result = $adapter->rehearse($account, (string) $this->option('text'), $media);

        if (! $result['success']) {
            $this->error($result['error']);

            return self::FAILURE;
        }

        $this->info("Prêt à publier, rien n'a été envoyé.");

        return self::SUCCESS;
    }
}
