<?php

namespace App\Services\Inbox;

use App\Models\InboxItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class InboxReplyService
{
    /** Shared by the API, web inbox and scheduler so two callers cannot send together. */
    public function send(InboxItem $item, string $text, bool $scheduled = false): array
    {
        return $this->locked($item, function () use ($item, $text, $scheduled) {
            if ($item->replied_at || $item->reply_external_id || $item->status === 'replied') {
                return $item->reply_content === $text
                    ? ['success' => true, 'external_id' => $item->reply_external_id, 'already_replied' => true]
                    : $this->conflict('Ce message a déjà reçu une réponse.');
            }

            if ($scheduled) {
                if (! $item->reply_scheduled_at || $item->reply_scheduled_at->isFuture() || $item->reply_attempts >= 3) {
                    return $this->conflict('Cette réponse ne doit pas être envoyée maintenant.');
                }
                // Reload the persisted text: the scheduler may have selected it before an edit.
                $text = $item->reply_content;
                $item->increment('reply_attempts');
            } elseif ($item->reply_scheduled_at) {
                return $this->conflict('Une réponse est déjà programmée pour ce message.');
            }

            $account = $item->socialAccount()->with('platform')->first();
            $service = app(InboxSyncService::class)->getServiceForPlatform($account->platform->slug);

            try {
                $result = $service
                    ? $service->sendReply($account, $item, $text)
                    : ['success' => false, 'error' => 'Service non disponible'];
            } catch (\Throwable $e) {
                Log::error('InboxReplyService: send failed', ['item_id' => $item->id, 'error' => $e->getMessage()]);
                $result = ['success' => false, 'error' => 'Envoi non confirmé. Vérifiez le réseau avant de réessayer.'];
            }

            if ($result['success']) {
                $item->update([
                    'status' => 'replied',
                    'reply_content' => $text,
                    'reply_external_id' => $result['external_id'] ?? null,
                    'replied_at' => now(),
                    'reply_scheduled_at' => null,
                ]);
            } else {
                Log::warning('InboxReplyService: reply failed', ['item_id' => $item->id, 'error' => $result['error'] ?? 'unknown']);
                if ($scheduled && $item->reply_attempts >= 3) {
                    $item->update(['status' => 'reply_failed', 'reply_scheduled_at' => null]);
                }
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

            $item->update([
                'reply_content' => $text,
                'reply_scheduled_at' => $at,
                'reply_attempts' => 0,
                'status' => $item->status === 'reply_failed' ? 'read' : $item->status,
            ]);

            return ['success' => true, 'scheduled_at' => $at->toIso8601String()];
        });
    }

    private function locked(InboxItem $item, callable $callback): array
    {
        $lock = Cache::lock('inbox-reply:'.$item->id, 120);
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
