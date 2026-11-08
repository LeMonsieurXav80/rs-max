<?php

namespace App\Services\Browser;

use App\Models\BrowserTask;
use App\Models\SocialAccount;
use App\Services\Agent\CommunityManagerWindow;
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
            $key = in_array($kind, ['x.post', 'x.like', 'x.repost'], true) ? ($payload['publication_key'] ?? null) : null;
            if ($key !== null) {
                // Verrou du compte, même quand la ligne de tâche n'existe pas encore.
                SocialAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
                $existing = BrowserTask::where('social_account_id', $account->id)->where('idempotency_key', $key)->first();
                if ($existing) {
                    if ($existing->kind !== $kind || $this->targetIdentity($existing->payload) !== $this->targetIdentity($payload) || ($existing->payload['text'] ?? '') !== ($payload['text'] ?? '') || $this->mediaIdentity($existing->payload['media'] ?? []) !== $this->mediaIdentity($payload['media'] ?? [])) {
                        throw new BrowserException('Cette livraison possède déjà une intention différente : vérifier avant toute nouvelle publication.');
                    }

                    return $existing;
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

    private function targetIdentity(array $payload): array
    {
        parse_str(parse_url($payload['url'] ?? '', PHP_URL_QUERY) ?? '', $query);

        return [array_intersect_key($payload['target'] ?? [], array_flip(['id', 'author', 'text'])),
            $query['in_reply_to'] ?? null, $query['quote_tweet_id'] ?? null];
    }

    /** Les URLs signées changent, pas l'identité du média livré. */
    private function mediaIdentity(array $media): array
    {
        return array_map(fn ($m) => [parse_url($m['url'] ?? '', PHP_URL_HOST), parse_url($m['url'] ?? '', PHP_URL_PATH), $m['mimetype'] ?? null], $media);
    }

    /** Une seule tâche à la fois par profil : deux onglets se voleraient la session. */
    public function claim(string $profile): ?BrowserTask
    {
        $claimed = Cache::lock('browser-claim:'.$profile, 10)->get(fn () => DB::transaction(function () use ($profile) {
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
