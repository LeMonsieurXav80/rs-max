<?php

namespace App\Services\Inbox;

use App\Models\BrowserTask;
use App\Models\InboxItem;
use App\Models\SocialAccount;
use App\Services\Agent\AccountActionBudget;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Support\Facades\DB;

/**
 * Réponses et likes TikTok d'un compte sur téléphone, faits en UN passage dans « Pas de réponse » de TikTok Studio.
 *
 * Une tâche par commentaire obligeait le téléphone à chercher chacun en faisant défiler la liste par OCR : jusqu'à
 * 7 min par like pour finir « introuvable » (16/11/2026). Les tâches `tiktok.reply` et `tiktok.like` sont donc mises
 * de côté sur un profil que personne ne prend (`<profil>:lot`), puis regroupées dans une tâche
 * `tiktok.inbox.sweep` : le téléphone lit la liste une fois, de haut en bas, et agit sur chaque carte reconnue.
 * Chaque tâche regroupée garde ses règles (prise par `BrowserTaskQueue::start`, conclue par `PhoneInboxReplyResult`).
 */
class PhoneInboxSweep
{
    public const KIND = 'tiktok.inbox.sweep';

    /** Au-delà, le passage dépasserait 20 min et la tâche deviendrait orpheline (30 min). */
    public const MAX_ITEMS = 40;

    /** Laisse un cycle Hermès finir sa salve avant de regrouper. */
    public const SETTLE_SECONDS = 120;

    public static function parkedProfile(SocialAccount $account): string
    {
        return $account->browser_profile.':lot';
    }

    /** Regroupe les tâches mises de côté du compte ; null s'il n'y a rien à faire ou qu'un passage est déjà en cours. */
    public function bundle(SocialAccount $account, BrowserTaskQueue $queue, bool $settle = true): ?BrowserTask
    {
        return DB::transaction(function () use ($account, $queue, $settle) {
            $parked = BrowserTask::where('profile', self::parkedProfile($account))->where('status', BrowserTask::PENDING)
                ->orderBy('id')->lockForUpdate()->get();
            if ($parked->isEmpty() || ($settle && $parked->max('created_at')->gt(now()->subSeconds(self::SETTLE_SECONDS)))) {
                return null;
            }
            if (BrowserTask::where('social_account_id', $account->id)->where('kind', self::KIND)
                ->whereIn('status', [BrowserTask::PENDING, BrowserTask::RUNNING])->exists()) {
                return null;
            }
            $items = [];
            foreach ($parked->take(self::MAX_ITEMS) as $task) {
                // Échéance, plage de journée, plafonds : mêmes contrôles qu'une prise par le worker. Le délai minimal
                // entre gestes est tenu par le téléphone (`min_gap`) : vérifié ici, il annulait tout sauf le premier.
                $task->payload = $task->payload + ['sweep_paced' => true];
                if (! $queue->start($task)) {
                    continue;
                }
                $items[] = ['task_id' => $task->id, 'kind' => $task->kind, 'target' => $task->payload['target'] ?? [],
                    'text' => $task->kind === 'tiktok.reply' ? (string) ($task->payload['text'] ?? '') : null,
                    // Like d'un commentaire déjà répondu : cherché dans « Avec réponse » seulement. Sans cela le
                    // téléphone lisait d'abord tout « Pas de réponse » (143 cartes, 7 min, le 16/11/2026).
                    'answered' => $task->kind === 'tiktok.like' && InboxItem::whereKey($task->payload['inbox_item_id'] ?? 0)->whereNotNull('replied_at')->exists()];
            }
            if ($items === []) {
                return null;
            }

            return $queue->enqueue($account, self::KIND, ['username' => $account->browser_username, 'items' => $items,
                'min_gap' => max(0, (int) $account->agent_min_interval_seconds),
                'deadline' => now()->addMinutes(30)->toIso8601String()]);
        });
    }

    /** Reçus intermédiaires du téléphone : `sending` avant chaque geste, puis le statut final de la carte. */
    public function checkpoint(BrowserTask $sweep, array $result): void
    {
        DB::transaction(function () use ($sweep, $result) {
            $sweep = BrowserTask::whereKey($sweep->id)->lockForUpdate()->firstOrFail();
            abort_unless($sweep->kind === self::KIND && $sweep->status === BrowserTask::RUNNING, 409);
            $sweep->update(['result' => ['items' => $this->merge($sweep, $result['items'] ?? [])]]);
        });
    }

    /** Résultat final : reçus déjà transmis conservés, jamais un geste effacé par un rapport plus pauvre. */
    public function finalResult(BrowserTask $sweep, array $result): array
    {
        return ['items' => $this->merge($sweep, $result['items'] ?? [])] + $result;
    }

    private function merge(BrowserTask $sweep, mixed $reported): array
    {
        $known = collect($sweep->payload['items'] ?? [])->pluck('task_id')->map(fn ($id) => (string) $id)->all();
        $items = $sweep->result['items'] ?? [];
        foreach (is_array($reported) ? $reported : [] as $id => $entry) {
            $id = (string) $id;
            $status = is_array($entry) ? ($entry['status'] ?? null) : null;
            if (! in_array($id, $known, true) || ! in_array($status, ['sending', 'done', 'failed', 'uncertain', 'missing'], true)) {
                continue;
            }
            $previous = $items[$id]['status'] ?? null;
            if ($previous !== null && $previous !== 'sending') {
                continue;  // statut final déjà reçu
            }
            if ($previous === 'sending' && in_array($status, ['failed', 'missing'], true)) {
                $status = 'uncertain';  // le geste a pu partir : jamais « rien fait »
            }
            $items[$id] = ['status' => $status, 'result' => is_array($entry['result'] ?? null) ? $entry['result'] : [],
                'error' => is_string($entry['error'] ?? null) ? mb_substr($entry['error'], 0, 500) : null];
        }

        return $items;
    }

    /** Conclut chaque tâche regroupée dès que son statut final est connu, et toutes à la fin du passage. */
    public function apply(BrowserTask $sweep): void
    {
        $terminal = in_array($sweep->status, [BrowserTask::DONE, BrowserTask::FAILED, BrowserTask::UNCERTAIN, BrowserTask::CANCELLED], true);
        $reported = $sweep->result['items'] ?? [];
        foreach ($sweep->payload['items'] ?? [] as $item) {
            $entry = $reported[(string) $item['task_id']] ?? null;
            $status = $entry['status'] ?? null;
            $update = match (true) {
                in_array($status, ['done', 'failed', 'uncertain'], true) => ['status' => $status, 'result' => $entry['result'] ?: null,
                    'error' => $entry['error'] ?? null],
                ! $terminal => null,
                $status === 'sending' => ['status' => BrowserTask::UNCERTAIN, 'error' => 'Passage interrompu pendant le geste : jamais relancé.'],
                $status === 'missing' && $sweep->status === BrowserTask::DONE => ['status' => BrowserTask::FAILED,
                    'error' => $entry['error'] ?? 'Commentaire introuvable dans « Pas de réponse » de TikTok Studio : aucun geste.'],
                default => ['status' => BrowserTask::CANCELLED, 'error' => 'Non atteint pendant le passage du téléphone : aucun geste.'],
            };
            if ($update === null) {
                continue;
            }
            $task = BrowserTask::whereKey($item['task_id'])->where('status', BrowserTask::RUNNING)->first();
            if ($task) {
                $task->update($update + ['finished_at' => now()]);
                app(AccountActionBudget::class)->finish($task);
            }
        }
    }
}
