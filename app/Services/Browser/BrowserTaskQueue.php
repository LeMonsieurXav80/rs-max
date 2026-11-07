<?php

namespace App\Services\Browser;

use App\Models\BrowserTask;
use App\Models\SocialAccount;
use App\Services\Agent\CommunityManagerWindow;
use Illuminate\Support\Facades\DB;

/**
 * File d'ordres pour Companion, qui tourne dans le Chromium du NAS. RS-Max écrit
 * une tâche et attend son résultat : un profil exécute une tâche à la fois.
 */
class BrowserTaskQueue
{
    private const POLL_MICROSECONDS = 1000000;

    /** @return array<string, mixed> Résultat de la tâche. */
    public function run(SocialAccount $account, string $kind, array $payload, int $timeout = 120): array
    {
        $window = app(CommunityManagerWindow::class);
        if (app()->bound('request') && request()->attributes->get('community_manager')) {
            $payload['activity_window'] = $window->specification();
        }
        if (isset($payload['activity_window'])) {
            $window->assertOpen($payload['activity_window']);
        }
        if (blank($account->browser_profile)) {
            throw new BrowserException('Aucun profil navigateur pour ce compte.');
        }

        $task = BrowserTask::create([
            'social_account_id' => $account->id,
            'profile' => $account->browser_profile,
            'kind' => $kind,
            'payload' => $payload,
        ]);

        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            $task->refresh();

            if (in_array($task->status, [BrowserTask::DONE, BrowserTask::FAILED, BrowserTask::UNCERTAIN, BrowserTask::CANCELLED], true)) {
                return $this->outcome($task);
            }

            usleep(self::POLL_MICROSECONDS);
        }

        return $this->expire($task);
    }

    /** Une seule tâche à la fois par profil : deux onglets se voleraient la session. */
    public function claim(string $profile): ?BrowserTask
    {
        return DB::transaction(function () use ($profile) {
            if (BrowserTask::where('profile', $profile)->where('status', BrowserTask::RUNNING)->lockForUpdate()->exists()) {
                return null;
            }

            for ($skipped = 0; $skipped < 50; $skipped++) {
                $task = BrowserTask::where('profile', $profile)->where('status', BrowserTask::PENDING)
                    ->orderBy('id')->lockForUpdate()->first();
                if (! $task) {
                    return null;
                }
                $window = $task->payload['activity_window'] ?? null;
                if ($window !== null && ! app(CommunityManagerWindow::class)->isOpen($window)) {
                    $task->update(['status' => BrowserTask::CANCELLED, 'finished_at' => now(),
                        'error' => 'Plage de journée Community Manager terminée : tâche annulée sans exécution.']);

                    continue;
                }
                try {
                    app(\App\Services\Agent\AccountActionBudget::class)->forTask($task);
                } catch (BrowserException $e) {
                    $task->update(['status' => BrowserTask::CANCELLED, 'finished_at' => now(), 'error' => $e->getMessage()]);

                    continue;
                }
                $task->update(['status' => BrowserTask::RUNNING, 'claimed_at' => now()]);

                return $task;
            }

            return null;
        });
    }

    private function outcome(BrowserTask $task): array
    {
        app(\App\Services\Agent\AccountActionBudget::class)->finish($task);
        if ($task->status === BrowserTask::DONE) {
            return $task->result ?? [];
        }

        if ($task->status === BrowserTask::UNCERTAIN) {
            throw new BrowserUncertainException($task->error ?? 'Résultat inconnu : vérifier sur le réseau.');
        }

        throw new BrowserException($task->error ?? 'Tâche navigateur échouée.');
    }

    /** Une tâche prise peut avoir agi : elle reste à contrôler, elle n'est jamais relancée. */
    private function expire(BrowserTask $task): array
    {
        $cancelled = BrowserTask::whereKey($task->id)->where('status', BrowserTask::PENDING)
            ->update(['status' => BrowserTask::CANCELLED, 'finished_at' => now()]);

        if ($cancelled) {
            throw new BrowserException('Navigateur du NAS injoignable : tâche annulée, rien n’a été fait.');
        }

        BrowserTask::whereKey($task->id)->where('status', BrowserTask::RUNNING)
            ->update(['status' => BrowserTask::UNCERTAIN, 'error' => 'Délai dépassé', 'finished_at' => now()]);

        app(\App\Services\Agent\AccountActionBudget::class)->finish($task->fresh());

        throw new BrowserUncertainException('Résultat inconnu : vérifier sur le réseau avant de relancer.');
    }
}
