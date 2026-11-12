<?php

namespace App\Services\Browser;

use App\Models\AccountActionEvent;
use App\Models\BrowserTask;
use App\Models\SocialAccount;
use App\Services\Agent\AccountActionBudget;
use App\Services\Agent\AdaptiveAccountLimits;
use App\Services\Agent\CommunityManagerWindow;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
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

        $task = DB::transaction(function () use ($account, $kind, $payload) {
            $key = in_array($kind, ['x.post', 'x.like', 'x.repost', 'tiktok.publish'], true) ? ($payload['publication_key'] ?? null) : null;
            if ($key !== null) {
                // Verrou du compte, même quand la ligne de tâche n'existe pas encore.
                $lockedAccount = SocialAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
                $existing = BrowserTask::where('social_account_id', $account->id)->where('idempotency_key', $key)->first();
                if ($existing) {
                    if ($existing->kind !== $kind || $this->targetIdentity($existing->payload) !== $this->targetIdentity($payload) || ($existing->payload['text'] ?? '') !== ($payload['text'] ?? '') || $this->mediaIdentity($existing->payload['media'] ?? []) !== $this->mediaIdentity($payload['media'] ?? [])) {
                        throw new BrowserException('Cette livraison possède déjà une intention différente : vérifier avant toute nouvelle publication.');
                    }

                    if (! $this->canRetryPausedTikTokPublication($existing, $lockedAccount)) {
                        return $existing;
                    }

                    // Conserver la tâche annulée et son journal. La nouvelle demande
                    // réutilise la clé sous le même verrou et repasse par les contrôles.
                    $existing->update(['idempotency_key' => null]);
                }
            }
            if ($kind === 'x.like' && isset($payload['target']['id'])) {
                if ($key === null) {
                    SocialAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
                }
                if (app(\App\Services\Agent\XEngagementService::class)->recordedLike($account, (string) $payload['target']['id'])) {
                    throw new BrowserException('J’aime déjà réservé ou enregistré dans RS-Max.');
                }
            }

            return BrowserTask::create([
                'social_account_id' => $account->id, 'idempotency_key' => $key,
                'profile' => $account->browser_profile, 'kind' => $kind, 'payload' => $payload,
            ]);
        });

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

    private function canRetryPausedTikTokPublication(BrowserTask $task, SocialAccount $account): bool
    {
        if ($task->kind !== 'tiktok.publish' || $task->status !== BrowserTask::CANCELLED
            || ! in_array($task->error, [AccountActionBudget::ADAPTIVE_PAUSE, AccountActionBudget::PLATFORM_PAUSE], true)
            || $task->claimed_at !== null || $task->result !== null) {
            return false;
        }

        return app(AdaptiveAccountLimits::class)->cooldownUntil($account) === null
            && ! AccountActionEvent::where('browser_task_id', $task->id)->exists();
    }

    private function targetIdentity(array $payload): array
    {
        parse_str(parse_url($payload['url'] ?? '', PHP_URL_QUERY) ?? '', $query);

        return [array_intersect_key($payload['target'] ?? [], array_flip(['id', 'author', 'text'])),
            $query['in_reply_to'] ?? null, $query['quote_tweet_id'] ?? null,
            isset($payload['media_type']) ? [$payload['username'] ?? null, $payload['media_type'], $payload['visibility'] ?? null] : null];
    }

    /** Les URLs signées changent, pas l'identité du média livré. */
    private function mediaIdentity(array $media): array
    {
        return array_map(fn ($m) => [parse_url($m['url'] ?? '', PHP_URL_HOST), parse_url($m['url'] ?? '', PHP_URL_PATH), $m['mimetype'] ?? null, $m['sha256'] ?? null], $media);
    }

    private function claimLock(string $profile, int $seconds): Lock
    {
        return Cache::lock('browser-claim:'.$profile, $seconds);
    }

    /** Empêche de nouvelles prises pendant une intervention sur des profils libres. */
    public function withIdleProfiles(array $profiles, int $lease, Closure $operation): bool
    {
        $locks = [];
        try {
            foreach (array_unique($profiles) as $profile) {
                $lock = $this->claimLock($profile, $lease);
                if (! $lock->get()) {
                    return false;
                }
                $locks[] = $lock;
            }

            if (BrowserTask::whereIn('profile', $profiles)->whereIn('status', [BrowserTask::PENDING, BrowserTask::RUNNING])->exists()) {
                return false;
            }

            $operation();

            return true;
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }

    /** Une seule tâche à la fois par profil : deux onglets se voleraient la session. */
    public function claim(string $profile): ?BrowserTask
    {
        $claimed = $this->claimLock($profile, 10)->get(fn () => DB::transaction(function () use ($profile) {
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
                    try {
                        app(\App\Services\Threads\ThreadsAgent::class)->claim($task);
                    } catch (\Throwable $e) {
                        throw new BrowserException($e->getMessage());
                    }
                    app(\App\Services\Agent\XEngagementService::class)->claim($task);
                    try {
                        app(\App\Services\Agent\TikTokPhotoPublisher::class)->claim($task);
                    } catch (\Throwable $e) {
                        throw new BrowserException($e->getMessage());
                    }
                    app(\App\Services\Agent\AccountActionBudget::class)->forTask($task);
                } catch (BrowserException $e) {
                    $task->update(['status' => BrowserTask::CANCELLED, 'finished_at' => now(), 'error' => $e->getMessage()]);
                    app(\App\Services\Agent\XEngagementService::class)->finish($task);
                    app(\App\Services\Agent\TikTokPhotoPublisher::class)->finish($task);

                    continue;
                }
                $task->update(['status' => BrowserTask::RUNNING, 'claimed_at' => now()]);

                return $task;
            }

            return null;
        }));

        return $claimed instanceof BrowserTask ? $claimed : null;
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
            app(\App\Services\Agent\AutomationJournal::class)->task($task->fresh());
            throw new BrowserException('Navigateur du NAS injoignable : tâche annulée, rien n’a été fait.');
        }

        BrowserTask::whereKey($task->id)->where('status', BrowserTask::RUNNING)
            ->update(['status' => BrowserTask::UNCERTAIN, 'error' => 'Délai dépassé', 'finished_at' => now()]);

        app(\App\Services\Agent\AccountActionBudget::class)->finish($task->fresh());

        throw new BrowserUncertainException('Résultat inconnu : vérifier sur le réseau avant de relancer.');
    }
}
