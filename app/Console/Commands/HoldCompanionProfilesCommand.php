<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Console\Command;

class HoldCompanionProfilesCommand extends Command
{
    protected $signature = 'browser:maintenance {profiles* : Profils Companion}
        {--lease=90 : Durée maximale du verrou en secondes}
        {--wait=30 : Durée maximale d’attente d’une file libre en secondes}';

    protected $description = 'Réserve des profils Companion libres le temps d’un redémarrage';

    public function handle(BrowserTaskQueue $queue): int
    {
        $profiles = array_values(array_unique($this->argument('profiles')));
        $lease = filter_var($this->option('lease'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 5, 'max_range' => 180]]);
        $wait = filter_var($this->option('wait'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 300]]);
        if ($lease === false || $wait === false || collect($profiles)->contains(fn ($profile) => ! preg_match('/^[a-z0-9_-]{1,40}$/D', $profile))) {
            $this->error('Profils ou durées de maintenance invalides.');

            return self::INVALID;
        }

        $known = SocialAccount::whereIn('browser_profile', $profiles)->distinct()->pluck('browser_profile');
        if ($known->count() !== count($profiles)) {
            $this->error('Au moins un profil est inconnu de cette instance RS-Max.');

            return self::INVALID;
        }

        $store = config('cache.stores.'.config('cache.default').'.driver');
        if (! defined('STDIN') || in_array($store, ['array', 'null'], true)) {
            $this->error('La commande exige une entrée standard et le cache partagé avec RS-Max.');

            return self::INVALID;
        }

        $waitUntil = hrtime(true) + $wait * 1_000_000_000;
        do {
            $leaseUntil = hrtime(true) + $lease * 1_000_000_000;
            $confirmed = false;
            $locked = $queue->withIdleProfiles($profiles, $lease, function () use ($profiles, $leaseUntil, &$confirmed) {
                $remaining = (int) floor(($leaseUntil - hrtime(true)) / 1_000_000_000);
                if ($remaining < 1) {
                    return;
                }

                $this->line('PRÊT '.implode(',', $profiles).' '.$remaining.' s. Appuyer sur Entrée après le redémarrage.');
                $read = [STDIN];
                $write = $except = null;
                $confirmed = stream_select($read, $write, $except, $remaining) === 1 && in_array(fread(STDIN, 1), ["\n", "\r"], true);
            });
            if ($locked) {
                if (! $confirmed) {
                    $this->error('Verrou libéré sans confirmation de fin de maintenance.');

                    return self::FAILURE;
                }

                $this->info('Prise des tâches rétablie.');

                return self::SUCCESS;
            }

            $remainingWait = $waitUntil - hrtime(true);
            if ($remainingWait > 0) {
                usleep(min(500_000, intdiv($remainingWait, 1000)));
            }
        } while (hrtime(true) < $waitUntil);

        $this->error('Profil occupé ou verrou indisponible. Le navigateur peut continuer ses tâches.');

        return self::FAILURE;
    }
}
