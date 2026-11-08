<?php

namespace App\Services\Browser;

use App\Models\BrowserTask;
use Illuminate\Support\Facades\DB;

/** Reçu conservé avant la longue vérification ; il ne vaut jamais preuve de publication. */
class TikTokReplyReceipt
{
    public function checkpoint(BrowserTask $task, array $result): void
    {
        DB::transaction(function () use ($task, $result) {
            $task = BrowserTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            abort_unless($task->kind === 'tiktok.reply' && $task->status === BrowserTask::RUNNING, 409);
            $safe = [];
            foreach (['send_clicked', 'initial_visible'] as $key) {
                if (is_bool($result[$key] ?? null)) {
                    abort_if($key === 'send_clicked' && ($task->result[$key] ?? null) === true && $result[$key] === false, 409, 'Un clic déjà réservé ne peut pas être annulé.');
                    $safe[$key] = $result[$key];
                }
            }
            if (in_array($result['input_mode'] ?? null, ['native', 'dom'], true)) {
                $safe['input_mode'] = $result['input_mode'];
            }
            if (is_int($result['draft_id'] ?? null) && $result['draft_id'] > 0) {
                $safe['draft_id'] = $result['draft_id'];
            }
            $submission = $result['submission'] ?? null;
            if (is_array($submission) && ($submission['text_matched'] ?? null) === true) {
                $receipt = ['text_matched' => true];
                foreach (['comment_id', 'parent_id', 'post_id'] as $key) {
                    abort_unless(is_string($submission[$key] ?? null) && preg_match('/^[0-9]{1,30}$/', $submission[$key]), 422);
                    $receipt[$key] = $submission[$key];
                }
                foreach (['http_status', 'status_code', 'comment_status'] as $key) {
                    if (is_int($submission[$key] ?? null)) {
                        $receipt[$key] = $submission[$key];
                    }
                }
                if (in_array($submission['post_type'] ?? null, ['video', 'photo'], true)) {
                    $receipt['post_type'] = $submission['post_type'];
                }
                $existing = $task->result['submission'] ?? null;
                ksort($receipt);
                if ($existing) {
                    ksort($existing);
                }
                abort_if($existing && $existing !== $receipt, 409, 'Un autre reçu existe déjà pour cette tentative.');
                $safe['submission'] = $receipt;
            }
            $task->update(['result' => array_replace($task->result ?? [], $safe)]);
        });
    }
}
