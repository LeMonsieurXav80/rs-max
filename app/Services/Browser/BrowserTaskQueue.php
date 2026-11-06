<?php

namespace App\Services\Browser;

use App\Models\BrowserTask;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\DB;

/**
 * File d'ordres pour Companion, qui tourne dans le Chromium du VPS. RS-Max écrit
 * une tâche et attend son résultat : un profil exécute une tâche à la fois.
 */
class BrowserTaskQueue
{
    private const POLL_MICROSECONDS = 1000000;

    /** @return array<string, mixed> Résultat de la tâche. */
    public function run(SocialAccount $account, string $kind, array $payload, int $timeout = 120): array
    {
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

            if (in_array($task->status, [BrowserTask::DONE, BrowserTask::FAILED, BrowserTask::UNCERTAIN], true)) {
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

            $task = BrowserTask::where('profile', $profile)->where('status', BrowserTask::PENDING)
                ->orderBy('id')->lockForUpdate()->first();

            $task?->update(['status' => BrowserTask::RUNNING, 'claimed_at' => now()]);

            return $task;
        });
    }

    private function outcome(BrowserTask $task): array
    {
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
            throw new BrowserException('Navigateur du VPS injoignable : tâche annulée, rien n’a été fait.');
        }

        BrowserTask::whereKey($task->id)->where('status', BrowserTask::RUNNING)
            ->update(['status' => BrowserTask::UNCERTAIN, 'error' => 'Délai dépassé', 'finished_at' => now()]);

        throw new BrowserUncertainException('Résultat inconnu : vérifier sur le réseau avant de relancer.');
    }
}
