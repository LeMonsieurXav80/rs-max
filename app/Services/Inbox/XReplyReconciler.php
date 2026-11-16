<?php

namespace App\Services\Inbox;

use App\Jobs\LikeInboxItem;
use App\Models\BotActionLog;
use App\Models\BrowserTask;
use App\Models\InboxItem;
use App\Services\Agent\AccountActionBudget;
use App\Services\Agent\CommentFreshness;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Résout une incertitude par une NOUVELLE lecture, sans jamais renvoyer la réponse. */
class XReplyReconciler
{
    /**
     * Preuve par la lecture du fil de la mention (`x.agent.read` / `thread.read`, réponses visibles) : une réponse
     * du compte, au texte exact, datée de la tentative incertaine. Rien trouvé ne prouve rien : `found` faux.
     */
    public function reconcileFromThread(InboxItem $item, array $read, bool $dry = true): array
    {
        return DB::transaction(function () use ($item, $read, $dry) {
            $item = InboxItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $account = $item->socialAccount()->with('platform')->firstOrFail();
            abort_unless($account->usesBrowser() && $account->platform->slug === 'twitter', 422);
            if ($item->replied_at || $item->reply_external_id) {
                return ['found' => true, 'already_replied' => true];
            }
            $task = BrowserTask::where('social_account_id', $account->id)->where('idempotency_key', 'inbox-reply:'.$item->id)
                ->where('status', BrowserTask::UNCERTAIN)->lockForUpdate()->first();
            if (! $task?->claimed_at || ! $task->finished_at || (string) ($read['target']['id'] ?? '') !== (string) $item->external_id) {
                return ['found' => false, 'reason' => 'Tentative ou fil de la mention introuvable.'];
            }
            $reply = collect($read['visible_replies'] ?? [])->first(function ($post) use ($account, $task, $item) {
                $postedAt = CommentFreshness::xStatusDate((string) ($post['id'] ?? ''));

                return strtolower($post['author'] ?? '') === strtolower($account->browser_username)
                    && ($post['text'] ?? null) === $item->reply_content && ($task->payload['text'] ?? null) === $item->reply_content
                    && $postedAt?->betweenIncluded($task->claimed_at->copy()->subSeconds(5), $task->finished_at->copy()->addMinute());
            });
            if (! $reply) {
                return ['found' => false, 'reason' => 'Aucune réponse du compte au texte exact parmi les réponses visibles.'];
            }
            $proof = ['id' => (string) $reply['id'], 'confirmed' => true, 'proof' => 'after_reload', 'parent_id' => (string) $item->external_id,
                'reconciled_from' => 'thread.read', 'original_status' => $task->status, 'original_error' => $task->error, 'observed_at' => now()->toIso8601String()];
            if ($dry) {
                return ['found' => true, 'dry_run' => true, 'proof' => $proof];
            }
            $task->update(['status' => BrowserTask::DONE, 'result' => $proof, 'error' => null, 'finished_at' => now()]);
            app(AccountActionBudget::class)->finish($task);
            $item->update(['status' => 'replied', 'reply_external_id' => $proof['id'], 'replied_at' => now(), 'reply_draft' => null, 'reply_scheduled_at' => null]);
            BotActionLog::create(['social_account_id' => $account->id, 'action_type' => 'x.reply.reconciled', 'source' => 'browser',
                'target_uri' => $reply['url'] ?? null, 'success' => true, 'metadata' => ['task_id' => $task->id, 'from' => 'thread.read'], 'performed_at' => now()]);
            $next = app(AccountActionBudget::class)->usage($account)['next_allowed_at'];
            LikeInboxItem::dispatch($item->id)->delay($next ? Carbon::parse($next)->addSeconds(2) : now())->afterCommit();

            return ['found' => true, 'dry_run' => false, 'proof' => $proof];
        });
    }

    public function reconcile(InboxItem $item, string $snapshotId, bool $dry = true): array
    {
        return DB::transaction(function () use ($item, $snapshotId, $dry) {
            $item = InboxItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $account = $item->socialAccount()->with('platform')->firstOrFail();
            abort_unless($account->usesBrowser() && $account->platform->slug === 'twitter', 422);
            if ($item->replied_at || $item->reply_external_id) {
                return ['success' => true, 'already_replied' => true, 'external_id' => $item->reply_external_id];
            }
            $task = BrowserTask::where('social_account_id', $account->id)->where('idempotency_key', 'inbox-reply:'.$item->id)
                ->where('status', BrowserTask::UNCERTAIN)->lockForUpdate()->first();
            $snapshot = Cache::get('x-agent-snapshot:'.$snapshotId);
            abort_unless($task && $task->claimed_at && $task->finished_at && $snapshot && $snapshot['account_id'] === $account->id
                && isset($snapshot['read_at']) && Carbon::parse($snapshot['read_at'])->greaterThan($task->finished_at), 409, 'Une nouvelle lecture après l’envoi incertain est requise.');
            $result = $snapshot['result'];
            $target = $result['target'] ?? [];
            $id = (string) ($target['id'] ?? '');
            abort_unless(preg_match('/^[0-9]{1,19}$/', $id) && ($result['context_complete'] ?? false)
                && ($snapshot['source']['action'] ?? '') === 'thread.read'
                && ($snapshot['source']['target_id'] ?? '') === $id
                && strtolower($target['author'] ?? '') === strtolower($account->browser_username)
                && ($target['text'] ?? '') === ($task->payload['text'] ?? null)
                && $target['text'] === $item->reply_content, 409, 'La lecture ne prouve pas cette réponse exacte du compte.');
            $postedAt = CommentFreshness::xStatusDate($id);
            abort_unless($postedAt?->betweenIncluded($task->claimed_at->copy()->subSeconds(5), $task->finished_at->copy()->addMinute()),
                409, 'La réponse retrouvée ne correspond pas à l’heure de cette tentative.');
            parse_str(parse_url($task->payload['url'], PHP_URL_QUERY) ?? '', $query);
            $chain = collect($result['conversation'] ?? []);
            $targetIndex = $chain->search(fn ($p) => (string) $p['id'] === $id);
            $parent = $targetIndex !== false ? $chain->take($targetIndex)->firstWhere('id', (string) $item->external_id) : null;
            abort_unless($parent && (string) ($query['in_reply_to'] ?? '') === (string) $item->external_id
                && strtolower($parent['author']) === strtolower(ltrim($item->author_username, '@'))
                && $parent['text'] === $item->content, 409, 'Le commentaire parent exact n’est pas prouvé.');
            $proof = ['id' => $id, 'confirmed' => true, 'proof' => 'after_reload', 'parent_id' => (string) $item->external_id,
                'reconciled_snapshot' => $snapshotId, 'original_status' => $task->status, 'original_error' => $task->error,
                'metrics' => $target['stats'] ?? [], 'observed_at' => $snapshot['read_at']];
            if ($dry) {
                return ['dry_run' => true, 'success' => true, 'proof' => $proof];
            }
            $task->update(['status' => BrowserTask::DONE, 'result' => $proof, 'error' => null, 'finished_at' => now()]);
            app(AccountActionBudget::class)->finish($task);
            $item->update(['status' => 'replied', 'reply_external_id' => $id, 'replied_at' => now(), 'reply_draft' => null, 'reply_scheduled_at' => null]);
            BotActionLog::create(['social_account_id' => $account->id, 'action_type' => 'x.reply.reconciled', 'source' => 'browser',
                'target_uri' => $target['url'], 'success' => true, 'metadata' => ['task_id' => $task->id, 'snapshot_id' => $snapshotId], 'performed_at' => now()]);
            $next = app(AccountActionBudget::class)->usage($account)['next_allowed_at'];
            LikeInboxItem::dispatch($item->id)->delay($next ? Carbon::parse($next)->addSeconds(2) : now())->afterCommit();

            return ['dry_run' => false, 'success' => true, 'external_id' => $id, 'proof' => $proof];
        });
    }
}
