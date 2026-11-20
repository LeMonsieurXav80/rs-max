<?php

namespace App\Services\Agent;

use App\Models\AccountActionEvent;
use App\Models\BrowserTask;
use App\Services\Browser\BrowserException;
use Illuminate\Support\Facades\DB;

/**
 * Session « Pour toi » d'un téléphone (`tiktok.feed.engage`) : likes de commentaires sans rouvrir les vidéos.
 * Chaque like est réservé dans le quota du compte AVANT le geste, sur le reçu du téléphone ; un refus l'arrête.
 * `browser_task_id` est unique sur les événements : ceux de la session n'y sont pas rattachés, la tâche garde
 * leurs identifiants.
 */
class TikTokFeedEngagement
{
    public const KIND = 'tiktok.feed.engage';

    private const FINAL = ['confirmed' => 'confirmed', 'shown' => 'completed', 'uncertain' => 'uncertain'];

    public function checkpoint(BrowserTask $task, array $result): void
    {
        DB::transaction(function () use ($task, $result) {
            $task = BrowserTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            abort_unless($task->kind === self::KIND && $task->status === BrowserTask::RUNNING, 409);
            $likes = $task->result['items'] ?? [];
            foreach ($this->valid($result['items'] ?? []) as $id => $like) {
                if (isset($likes[$id])) {
                    continue;
                }
                abort_if(count($likes) >= (int) ($task->payload['max_likes'] ?? 0), 409, 'Quota de la session atteint : aucun geste.');
                try {
                    $event = app(AccountActionBudget::class)->reserve($task->socialAccount, 'tiktok.comment.like', 'browser',
                        $like['video'].' · '.$like['author'], paced: true);
                } catch (BrowserException $e) {
                    abort(409, $e->getMessage());
                }
                $likes[$id] = ['event_id' => $event->id, 'status' => 'sending'] + $like;
            }
            $task->update(['result' => ['items' => $likes] + ($task->result ?? [])]);
        });
    }

    /** Résultat final : un like réservé n'est jamais effacé, un reçu sans suite devient incertain. */
    public function finalResult(BrowserTask $task, array $result): array
    {
        $likes = $task->result['items'] ?? [];
        foreach ($this->valid($result['items'] ?? []) as $id => $like) {
            if (isset($likes[$id]) && isset(self::FINAL[$like['status']])) {
                $likes[$id]['status'] = $like['status'];
                $likes[$id]['url'] = $like['url'] ?? null;
            }
        }

        return ['items' => $likes] + $result;
    }

    public function finish(BrowserTask $task): void
    {
        $updated = 0;
        foreach ($task->result['items'] ?? [] as $like) {
            $updated += AccountActionEvent::whereKey($like['event_id'] ?? 0)->where('status', 'attempted')->update([
                'status' => self::FINAL[$like['status'] ?? ''] ?? 'uncertain', 'error' => $task->error, 'updated_at' => now(),
            ]);
        }
        if ($updated && $adjustment = app(AdaptiveAccountLimits::class)->evaluate($task->socialAccount)) {
            \App\Jobs\NotifyAccountLimitAdjustment::dispatch($adjustment->id)->afterCommit();
        }
    }

    /** @return array<string, array{video: string, key: string, author: string, status: string}> */
    private function valid(mixed $items): array
    {
        $valid = [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (! is_array($item) || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($item['key'] ?? ''))
                || blank($item['video'] ?? null) || blank($item['author'] ?? null)) {
                continue;
            }
            $valid[hash('sha256', $item['video'].'|'.$item['key'])] = [
                'video' => mb_substr((string) $item['video'], 0, 120), 'key' => $item['key'],
                'author' => mb_substr((string) $item['author'], 0, 120), 'status' => (string) ($item['status'] ?? ''),
                'url' => is_string($item['url'] ?? null) ? mb_substr($item['url'], 0, 300) : null,
            ];
        }

        return $valid;
    }
}
