<?php

namespace App\Services\Inbox;

use App\Models\InboxItem;
use App\Models\Setting;
use App\Models\SocialAccount;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TelegramInboxService implements PlatformInboxInterface
{
    public function fetchInbox(SocialAccount $account, ?Carbon $since = null): Collection
    {
        $items = collect();
        $credentials = $account->credentials;
        $botToken = $credentials['bot_token'];
        $baseUrl = "https://api.telegram.org/bot{$botToken}";

        // Only bot accounts can call getUpdates to receive DMs
        if (($credentials['type'] ?? '') !== 'bot') {
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
                if (! $message) {
                    continue;
                }

                $hasText = ! empty($message['text']) || ! empty($message['caption']);
                $hasMedia = isset($message['animation']) || isset($message['sticker']) || isset($message['photo']);

                if (! $hasText && ! $hasMedia) {
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

                // Detect media type
                $mediaType = null;
                $mediaFileId = null;
                if (isset($message['animation'])) {
                    $mediaType = 'gif';
                    $mediaFileId = $message['animation']['file_id'];
                } elseif (isset($message['sticker'])) {
                    $mediaType = 'sticker';
                    $mediaFileId = $message['sticker']['file_id'];
                } elseif (isset($message['photo'])) {
                    $mediaType = 'image';
                    // Use the largest photo size (last in array)
                    $mediaFileId = end($message['photo'])['file_id'] ?? null;
                }

                // Download media file locally (avoids exposing bot token in URL)
                $mediaUrl = null;
                if ($mediaFileId) {
                    $mediaUrl = $this->downloadTelegramFile($baseUrl, $botToken, $mediaFileId);
                }

                $chatId = (string) ($message['chat']['id'] ?? '');

                $items->push([
                    'type' => 'dm',
                    'external_id' => "{$chatId}_{$message['message_id']}",
                    'external_post_id' => $chatId,
                    'conversation_key' => "dm:{$chatId}",
                    'author_name' => $authorName ?: null,
                    'author_username' => $from['username'] ?? null,
                    'author_external_id' => isset($from['id']) ? (string) $from['id'] : null,
                    'content' => $message['text'] ?? $message['caption'] ?? null,
                    'media_url' => $mediaUrl,
                    'media_type' => $mediaType,
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

            // external_id format: "{chatId}_{messageId}"
            $messageId = str_contains($item->external_id, '_')
                ? explode('_', $item->external_id, 2)[1]
                : $item->external_id;

            $response = Http::post("{$baseUrl}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $replyText,
                'reply_to_message_id' => $messageId,
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

    private function downloadTelegramFile(string $baseUrl, string $botToken, string $fileId): ?string
    {
        try {
            $fileResponse = Http::get("{$baseUrl}/getFile", ['file_id' => $fileId]);
            if (! $fileResponse->successful()) {
                return null;
            }

            $filePath = $fileResponse->json('result.file_path');
            if (! $filePath) {
                return null;
            }

            $remoteUrl = "https://api.telegram.org/file/bot{$botToken}/{$filePath}";
            $fileContent = Http::get($remoteUrl)->body();

            $extension = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'bin';
            $localName = 'tg_' . Str::random(16) . '.' . $extension;

            Storage::disk('local')->put("media/{$localName}", $fileContent);

            return url("/media/{$localName}");
        } catch (\Throwable $e) {
            Log::warning('TelegramInboxService: file download failed', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
