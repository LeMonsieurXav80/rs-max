<?php

namespace App\Services\Inbox;

use App\Jobs\LikeInboxItem;
use App\Models\BrowserTask;
use App\Models\InboxItem;
use App\Services\Agent\AccountActionBudget;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Relecture d'une réponse incertaine : aucun second clic d'envoi. */
class TikTokReplyReconciler
{
    public const MAX_VERIFICATIONS = 3;

    private function original(InboxItem $item): BrowserTask
    {
        abort_unless($item->socialAccount->usesBrowser() && $item->platform->slug === 'tiktok' && filled($item->reply_content), 422);
        $task = BrowserTask::where('social_account_id', $item->social_account_id)->where('kind', 'tiktok.reply')->whereIn('status', [BrowserTask::UNCERTAIN, BrowserTask::DONE])
            ->where('payload->username', $item->socialAccount->browser_username)->where('payload->text', $item->reply_content)
            ->where('payload->target->author', $item->author_username)->where('payload->target->content', $item->content)
            ->where('payload->target->caption', $item->post_content)->latest('id')->first();
        abort_unless($task && ($task->status === BrowserTask::UNCERTAIN || (($task->result['confirmed'] ?? false) === true && ($task->result['proof'] ?? null) === 'after_reload')) && $task->claimed_at && $task->finished_at && ($task->result['send_clicked'] ?? false) === true, 409, 'Aucune tentative vérifiable correspondant au commentaire exact.');

        return $task;
    }

    /** Code 2 au reçu d’envoi : sur 6 jours (environ 30 cas), TikTok n’a jamais publié la réponse. */
    public function withheld(InboxItem $item): bool
    {
        return (int) ($this->original($item)->result['submission']['comment_status'] ?? 0) === 2;
    }

    public function verify(InboxItem $item): array
    {
        $task = $this->original($item);
        // Chaque relecture occupe le profil Companion partagé plus d’une minute.
        abort_if(BrowserTask::where('social_account_id', $item->social_account_id)->where('kind', 'tiktok.verify')
            ->where('payload->source_task_id', $task->id)->count() >= self::MAX_VERIFICATIONS, 409, 'Relectures épuisées pour cette tentative : aucun renvoi.');
        $nonce = (string) Str::uuid();
        $payload = array_merge($task->payload, ['action' => 'tiktok.reply', 'source_task_id' => $task->id, 'verification_nonce' => $nonce, 'submission' => $task->result['submission'] ?? null]);
        $result = app(BrowserTaskQueue::class)->run($item->socialAccount, 'tiktok.verify', $payload, 180);
        $verification = BrowserTask::where('social_account_id', $item->social_account_id)->where('kind', 'tiktok.verify')->where('payload->verification_nonce', $nonce)->firstOrFail();

        return ['verification_task_id' => $verification->id, 'result' => $result, 'sent' => false];
    }

    public function reconcile(InboxItem $item, int $verificationId, bool $dry = true): array
    {
        return DB::transaction(function () use ($item, $verificationId, $dry) {
            $item = InboxItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($item->replied_at) {
                return ['success' => true, 'already_replied' => true];
            }
            $task = $this->original($item);
            $verification = BrowserTask::where('social_account_id', $item->social_account_id)->where('kind', 'tiktok.verify')->where('status', BrowserTask::DONE)->find($verificationId);
            abort_unless($verification && $verification->claimed_at && $verification->claimed_at->greaterThan($task->finished_at)
                && ($verification->result['confirmed'] ?? false) === true && ($verification->result['proof'] ?? null) === 'after_reload'
                && ($verification->result['verified_kind'] ?? null) === 'tiktok.reply', 409, 'Une relecture confirmée après la tentative est requise.');
            if (($verification->result['verification_source'] ?? null) === 'anonymous_browser') {
                abort_unless(($verification->result['visibility'] ?? null) === 'public'
                    && filled($task->result['submission']['comment_id'] ?? null)
                    && ($verification->result['comment_id'] ?? null) === $task->result['submission']['comment_id']
                    && ($verification->payload['submission'] ?? null) === $task->result['submission'], 409, 'Le reçu public ne correspond pas à la tentative.');
            }
            foreach (['url', 'username', 'text', 'target'] as $key) {
                abort_unless(($verification->payload[$key] ?? null) === ($task->payload[$key] ?? null), 409, 'La preuve concerne un autre commentaire, texte ou compte.');
            }
            $proof = ['confirmed' => true, 'proof' => 'after_reload', 'verification_task_id' => $verification->id,
                'original_status' => $task->status, 'original_error' => $task->error, 'observed_at' => $verification->finished_at?->toIso8601String()];
            foreach (['visibility', 'verification_source', 'comment_id'] as $key) {
                if (isset($verification->result[$key])) {
                    $proof[$key] = $verification->result[$key];
                }
            }
            if ($dry) {
                return ['dry_run' => true, 'success' => true, 'proof' => $proof];
            }
            $task->update(['status' => BrowserTask::DONE, 'result' => array_merge($task->result, $proof), 'error' => null]);
            app(AccountActionBudget::class)->finish($task);
            $item->update(['status' => 'replied', 'replied_at' => now(), 'reply_draft' => null, 'reply_scheduled_at' => null,
                'reply_external_id' => ($proof['verification_source'] ?? null) === 'anonymous_browser' ? ($proof['comment_id'] ?? null) : $item->reply_external_id]);
            if ($draftId = $task->result['draft_id'] ?? null) {
                DB::table('extension_tiktok_comments')->where('id', $draftId)
                    ->whereIn('profile_id', DB::table('extension_tiktok_profiles')->where('social_account_id', $item->social_account_id)->select('id'))
                    ->where('reply_text', $item->reply_content)->where('author', $item->author_username)->where('content', $item->content)->where('video_context', $item->post_content)
                    ->whereIn('status', ['uncertain', 'publishing'])->update(['status' => 'published', 'proof' => ($proof['visibility'] ?? null) === 'public' ? 'Réponse exacte vérifiée sans connexion au compte' : 'Texte exact et auteur vérifiés après rechargement de Studio', 'updated_at' => now()]);
            }
            $next = app(AccountActionBudget::class)->usage($item->socialAccount)['next_allowed_at'];
            LikeInboxItem::dispatch($item->id)->delay($next ? Carbon::parse($next)->addSeconds(2) : now())->afterCommit();

            return ['dry_run' => false, 'success' => true, 'proof' => $proof];
        });
    }
}
