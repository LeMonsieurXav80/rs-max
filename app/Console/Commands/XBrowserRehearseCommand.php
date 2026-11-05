<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Services\Adapters\TwitterBrowserAdapter;
use Illuminate\Console\Command;

class XBrowserRehearseCommand extends Command
{
    protected $signature = 'x:browser-rehearse {account} {--text=Répétition RS-Max} {--media=* : Fichier relatif au dossier des médias}';

    protected $description = 'Répète une publication X sans cliquer sur Poster, et enregistre une capture';

    public function handle(TwitterBrowserAdapter $adapter): int
    {
        $account = SocialAccount::with('platform')->findOrFail($this->argument('account'));
        $media = array_map(fn (string $file) => [
            'local_path' => storage_path("app/private/media/{$file}"),
            'mimetype' => mime_content_type(storage_path("app/private/media/{$file}")),
        ], $this->option('media'));

        $result = $adapter->rehearse($account, (string) $this->option('text'), $media);

        if (! $result['success']) {
            $this->error($result['error']);

            return self::FAILURE;
        }

        $path = storage_path('logs/x-rehearsal.png');
        file_put_contents($path, $result['screenshot']);
        $this->info("Prêt à publier, rien n'a été envoyé. Capture : {$path}");

        return self::SUCCESS;
    }
}
