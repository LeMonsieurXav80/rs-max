<?php

namespace App\Services\Inbox;

use App\Jobs\LikeInboxItem;
use App\Models\BotActionLog;
use App\Models\BrowserTask;
use App\Models\InboxItem;
use App\Services\Agent\AccountActionBudget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Résout une incertitude par une NOUVELLE lecture, sans jamais renvoyer la réponse. */
class XReplyReconciler
{
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
            $postedAt = Carbon::createFromTimestampMs(((int) $id >> 22) + 1288834974657, 'UTC');
            abort_unless($postedAt->greaterThanOrEqualTo($task->claimed_at->copy()->subSeconds(5))
                && $postedAt->lessThanOrEqualTo($task->finished_at->copy()->addMinute()), 409, 'La réponse retrouvée ne correspond pas à l’heure de cette tentative.');
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
