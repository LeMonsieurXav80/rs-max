<?php

namespace App\Services\Agent;

use App\Services\Browser\BrowserException;

/** L'horaire est vérifié au départ ET à la prise du geste, pas seulement dans le cron. */
class CommunityManagerWindow
{
    public function specification(): array
    {
        return config('community_manager') + ['date' => now(config('community_manager.timezone'))->toDateString()];
    }

    public function isOpen(?array $specification = null): bool
    {
        $config = config('community_manager');
        $now = now($config['timezone']);
        if ($specification !== null) {
            foreach ($config as $key => $value) {
                if (($specification[$key] ?? null) !== $value) {
                    return false;
                }
            }
            if (($specification['date'] ?? '') !== $now->toDateString()) {
                return false;
            }
        }

        return $now->hour >= $config['start_hour'] && $now->hour < $config['end_hour'];
    }

    public function assertOpen(?array $specification = null): void
    {
        if (! $this->isOpen($specification)) {
            throw new BrowserException('Community Manager hors plage de journée ou tâche périmée : aucun geste.');
        }
    }
}
