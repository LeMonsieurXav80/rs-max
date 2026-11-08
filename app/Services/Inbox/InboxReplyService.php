<?php

namespace App\Services\Inbox;

use App\Jobs\LikeInboxItem;
use App\Models\InboxItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class InboxReplyService
{
    public function submit(InboxItem $item, string $text): array
    {
        if (((request()->attributes->get('community_manager') && in_array($item->platform->slug, \App\Services\Agent\ApiInboxActions::PLATFORMS, true)) || $item->platform->slug === 'tiktok' || ($item->platform->slug === 'twitter' && $item->socialAccount->usesBrowser())) && ! ($item->replied_at || $item->reply_external_id || $item->status === 'replied')) {
            $autonomous = (bool) request()->attributes->get('community_manager');
            $at = now();
            if ($autonomous) {
                $next = app(\App\Services\Agent\AccountActionBudget::class)->usage($item->socialAccount)['next_allowed_at'];
                if ($next) {
                    $at = \Illuminate\Support\Carbon::parse($next);
                }
            }
            $result = $this->schedule($item, $text, $at);
            if ($autonomous && ($result['success'] ?? false) && ! ($result['already_scheduled'] ?? false)) {
                \App\Jobs\SendAutonomousInboxReply::dispatch($item->id)->delay($at)->afterCommit();
                $result['queued'] = true;
                $result['validation_required'] = false;
            }

            return $result;
        }

        return $this->send($item, $text);
    }

    /** Shared by the API, web inbox and scheduler so two callers cannot send together. */
    public function send(InboxItem $item, string $text, bool $scheduled = false): array
    {
        return $this->locked($item, function () use ($item, $text, $scheduled) {
            if ($item->replied_at || $item->reply_external_id || $item->status === 'replied') {
                return $item->reply_content === $text
                    ? ['success' => true, 'external_id' => $item->reply_external_id, 'already_replied' => true]
                    : $this->conflict('Ce message a déjà reçu une réponse.');
            }

            $account = $item->socialAccount()->with('platform')->first();
            $tiktok = $account->platform->slug === 'tiktok';
            $apiAutonomous = filled($item->reply_activity_window) && in_array($account->platform->slug, \App\Services\Agent\ApiInboxActions::PLATFORMS, true);
            if ($apiAutonomous) {
                if (! app(\App\Services\Agent\CommunityManagerWindow::class)->isOpen($item->reply_activity_window)) {
                    $item->update(['reply_scheduled_at' => null, 'reply_content' => null, 'reply_activity_window' => null]);

                    return $this->conflict('Plage autonome terminée, aucun envoi.');
                }
                try {
                    app(\App\Services\Agent\ApiInboxActions::class)->assertEnabled($account, 'reply');
                } catch (\Throwable $e) {
                    $item->update(['reply_scheduled_at' => null]);

                    return $this->conflict($e->getMessage());
                }
            }

            if ($scheduled) {
                if (! $item->reply_scheduled_at || $item->reply_scheduled_at->isFuture() || $item->reply_attempts >= 3) {
                    return $this->conflict('Cette réponse ne doit pas être envoyée maintenant.');
                }
                // Reload the persisted text: the scheduler may have selected it before an edit.
                $text = $item->reply_content;
                if (($tiktok || $apiAutonomous) && $item->reply_attempts > 0) {
                    $item->update(['status' => 'reply_failed', 'reply_scheduled_at' => null, 'reply_attempts' => 3]);

                    return $this->conflict('Tentative TikTok interrompue. Vérifiez le fil avant toute relance.');
                }
                $item->increment('reply_attempts');
            } elseif ($item->reply_scheduled_at) {
                return $this->conflict('Une réponse est déjà programmée pour ce message.');
            }

            if ($account->usesBrowser() && $account->platform->slug === 'twitter' && ! $scheduled) {
                try {
                    \Illuminate\Support\Facades\DB::transaction(function () use ($account, $item) {
                        \App\Models\SocialAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
                        abort_if($item->reply_attempts > 0, 409, 'Un envoi X a déjà été tenté : vérifier sans renvoyer.');
                        app(\App\Services\Agent\XEngagementService::class)->assertReplyAvailable($account, (string) $item->external_id, ignoreInbox: $item->id);
                        $item->increment('reply_attempts');
                        if (request()->attributes->get('community_manager')) {
                            $item->update(['reply_activity_window' => app(\App\Services\Agent\CommunityManagerWindow::class)->specification()]);
                        }
                    });
                } catch (\Throwable $e) {
                    return $this->conflict($e->getMessage());
                }
            }
            $service = app(InboxSyncService::class)->getServiceForAccount($account);

            $event = null;
            if (! $account->usesBrowser()) {
                try {
                    $event = app(\App\Services\Agent\AccountActionBudget::class)->reserve($account, $apiAutonomous ? $account->platform->slug.'.reply' : 'inbox.reply', 'api', (string) $item->id);
                    $journal = app(\App\Services\Agent\AutomationJournal::class);
                    \App\Models\BotActionLog::where('account_action_event_id', $event->id)->update(['target_text' => $journal->text($text, 2000), 'target_uri' => $journal->url($item->post_url ?? ''), 'target_author' => $journal->text($item->author_name, 255)]);
                } catch (\App\Services\Browser\BrowserException $e) {
                    // Aucun appel réseau. Ne pas épuiser les essais du planificateur.
                    if ($scheduled) {
                        $item->decrement('reply_attempts');
                    }

                    return ['success' => false, 'status_code' => 429, 'error' => $e->getMessage()];
                }
            }
            try {
                $result = $service
                    ? $service->sendReply($account, $item, $text)
                    : ['success' => false, 'error' => 'Service non disponible'];
            } catch (\Throwable $e) {
                Log::error('InboxReplyService: send failed', ['item_id' => $item->id, 'error' => $e->getMessage()]);
                $result = ['success' => false, 'uncertain' => $apiAutonomous, 'error' => 'Envoi non confirmé. Vérifiez le réseau avant de réessayer.'];
            }

            $event?->update(['status' => $result['success'] ? ($apiAutonomous && filled($result['external_id'] ?? null) ? 'confirmed' : 'completed') : (($result['uncertain'] ?? false) ? 'uncertain' : 'failed'),
                'error' => isset($result['error']) ? mb_substr($result['error'], 0, 1000) : null]);
            if ($result['success']) {
                $item->update([
                    'status' => 'replied',
                    'reply_content' => $text,
                    'reply_draft' => null,
                    'reply_external_id' => $result['external_id'] ?? null,
                    'replied_at' => now(),
                    'reply_scheduled_at' => null,
                ]);

                // Après une réponse confirmée, le like suit avec la cadence du compte.
                if ($tiktok || ($account->platform->slug === 'twitter' && $account->usesBrowser()) || ($apiAutonomous && $account->platform->slug === 'facebook' && app(\App\Services\Agent\AgentCatalog::class)->forAccount($account)->get('comment.like')['enabled'])) {
                    $next = app(\App\Services\Agent\AccountActionBudget::class)->usage($account)['next_allowed_at'];
                    LikeInboxItem::dispatch($item->id)->delay($next ? \Illuminate\Support\Carbon::parse($next)->addSeconds(2) : now());
                }
            } else {
                Log::warning('InboxReplyService: reply failed', ['item_id' => $item->id, 'error' => $result['error'] ?? 'unknown']);
                if (($result['uncertain'] ?? false) || ($scheduled && ($tiktok || $apiAutonomous))) {
                    // La réponse a pu partir : ni l'humain ni la planification ne la renvoient.
                    $item->update(['status' => 'reply_failed', 'reply_content' => $text, 'reply_scheduled_at' => null, 'reply_attempts' => 3]);
                    if ($tiktok && ($result['uncertain'] ?? false)) {
                        \App\Jobs\VerifyTikTokInboxReply::dispatch($item->id)->delay(now()->addMinutes(5))->afterCommit();
                    }
                } elseif ($scheduled && $item->reply_attempts >= 3) {
                    $item->update(['status' => 'reply_failed', 'reply_scheduled_at' => null]);
                }
            }

            if (($result['uncertain'] ?? false) === true) {
                $result['status_code'] = 409;
            }

            return $result;
        });
    }

    public function schedule(InboxItem $item, string $text, CarbonInterface $at): array
    {
        return $this->locked($item, function () use ($item, $text, $at) {
            if ($item->replied_at || $item->reply_external_id || $item->status === 'replied') {
                return $this->conflict('Ce message a déjà reçu une réponse.');
            }
            if ($item->reply_scheduled_at) {
                return $item->reply_content === $text
                    ? ['success' => true, 'scheduled_at' => $item->reply_scheduled_at->toIso8601String(), 'already_scheduled' => true]
                    : $this->conflict('Une réponse est déjà programmée pour ce message.');
            }
            if (($item->platform->slug === 'tiktok' || ($item->platform->slug === 'twitter' && $item->socialAccount->usesBrowser())) && ($item->reply_attempts > 0 || ! in_array($item->status, ['unread', 'read'], true))) {
                return $this->conflict('Ce commentaire ne peut plus être programmé. Vérifiez son état sur le réseau.');
            }

            $item->update([
                'reply_content' => $text,
                'reply_draft' => null,
                'reply_scheduled_at' => $at,
                'reply_attempts' => 0,
                'reply_activity_window' => request()->attributes->get('community_manager') ? app(\App\Services\Agent\CommunityManagerWindow::class)->specification() : null,
                'status' => $item->status === 'reply_failed' ? 'read' : $item->status,
            ]);

            return ['success' => true, 'scheduled_at' => $at->toIso8601String()];
        });
    }

    private function locked(InboxItem $item, callable $callback): array
    {
        $lock = Cache::lock('inbox-reply:'.$item->id, $item->platform->slug === 'tiktok' ? 390 : 120);
        if (! $lock->get()) {
            return $this->conflict('Une réponse à ce message est déjà en cours.');
        }

        try {
            $item->refresh();

            return $callback();
        } finally {
            $lock->release();
        }
    }

    private function conflict(string $error): array
    {
        return ['success' => false, 'error' => $error, 'status_code' => 409];
    }
}
