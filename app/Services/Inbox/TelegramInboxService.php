<?php

namespace App\Services\Inbox;

use App\Models\InboxItem;
use App\Models\Setting;
use App\Models\SocialAccount;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramInboxService implements PlatformInboxInterface
{
    public function fetchInbox(SocialAccount $account, ?Carbon $since = null): Collection
    {
        $items = collect();
        $credentials = $account->credentials;
        $botToken = $credentials['bot_token'];
        $baseUrl = "https://api.telegram.org/bot{$botToken}";

        // Skip bot records (they don't have a chat_id for receiving messages)
        if (($credentials['type'] ?? '') === 'bot') {
            return $items;
        }

        try {
            $lastUpdateId = (int) Setting::get("telegram_last_update_{$account->id}", 0);

            $params = ['allowed_updates' => json_encode(['message'])];
            if ($lastUpdateId > 0) {
                $params['offset'] = $lastUpdateId + 1;
            }

            $response = Http::get("{$baseUrl}/getUpdates", $params);

            if (! $response->successful() || ! ($response->json('ok'))) {
                Log::warning('TelegramInboxService: getUpdates failed', [
                    'status' => $response->status(),
                ]);

                return $items;
            }

            $updates = $response->json('result', []);
            $maxUpdateId = $lastUpdateId;

            foreach ($updates as $update) {
                $updateId = $update['update_id'];
                $maxUpdateId = max($maxUpdateId, $updateId);

                $message = $update['message'] ?? null;
                if (! $message || empty($message['text'])) {
                    continue;
                }

                $from = $message['from'] ?? [];

                // Skip messages from bots
                if ($from['is_bot'] ?? false) {
                    continue;
                }

                $postedAt = isset($message['date']) ? Carbon::createFromTimestamp($message['date']) : null;

                $firstName = $from['first_name'] ?? '';
                $lastName = $from['last_name'] ?? '';
                $authorName = trim("{$firstName} {$lastName}");

                $items->push([
                    'type' => 'dm',
                    'external_id' => (string) $message['message_id'],
                    'external_post_id' => (string) ($message['chat']['id'] ?? ''),
                    'author_name' => $authorName ?: null,
                    'author_username' => $from['username'] ?? null,
                    'author_external_id' => isset($from['id']) ? (string) $from['id'] : null,
                    'content' => $message['text'],
                    'posted_at' => $postedAt,
                ]);
            }

            // Persist last update ID
            if ($maxUpdateId > $lastUpdateId) {
                Setting::set("telegram_last_update_{$account->id}", $maxUpdateId);
            }
        } catch (\Throwable $e) {
            Log::error('TelegramInboxService: fetchInbox failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $items;
    }

    public function sendReply(SocialAccount $account, InboxItem $item, string $replyText): array
    {
        try {
            $botToken = $account->credentials['bot_token'];
            $chatId = $item->external_post_id; // chat_id stored as external_post_id
            $baseUrl = "https://api.telegram.org/bot{$botToken}";

            $response = Http::post("{$baseUrl}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $replyText,
                'reply_to_message_id' => $item->external_id,
                'parse_mode' => 'HTML',
            ]);

            $body = $response->json();

            if ($response->successful() && ($body['ok'] ?? false)) {
                $messageId = (string) ($body['result']['message_id'] ?? '');

                return ['success' => true, 'external_id' => $messageId, 'error' => null];
            }

            $error = $body['description'] ?? 'Failed to send message';

            return ['success' => false, 'external_id' => null, 'error' => $error];
        } catch (\Throwable $e) {
            Log::error('TelegramInboxService: sendReply failed', [
                'account_id' => $account->id,
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'external_id' => null, 'error' => $e->getMessage()];
        }
    }
}
