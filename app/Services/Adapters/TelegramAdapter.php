<?php

namespace App\Services\Adapters;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramAdapter implements PlatformAdapterInterface
{
    /**
     * Publish content to Telegram via the Bot API.
     *
     * @param  SocialAccount  $account  Credentials: bot_token, chat_id.
     * @param  string  $content  The message text.
     * @param  array|null  $media  Optional media items (each with url, mimetype, size, title).
     * @return array{success: bool, external_id: string|null, error: string|null}
     */
    public function publish(SocialAccount $account, string $content, ?array $media = null, ?array $options = null): array
    {
        try {
            $credentials = $account->credentials;
            $token = $credentials['bot_token'];
            $chatId = $credentials['chat_id'];

            // Allow the Post's telegram_channel field to override the chat_id.
            $post = $account->relationLoaded('postPlatforms')
                ? optional($account->postPlatforms->first())->post
                : null;

            if ($post && ! empty($post->telegram_channel)) {
                $chatId = $post->telegram_channel;
            }

            $baseUrl = "https://api.telegram.org/bot{$token}";

            // No media -- plain text message.
            if (empty($media)) {
                return $this->sendMessage($baseUrl, $chatId, $content);
            }

            // Single media item.
            if (count($media) === 1) {
                $item = $media[0];

                if ($this->isVideo($item['mimetype'])) {
                    return $this->sendVideo($baseUrl, $chatId, $item['url'], $content);
                }

                return $this->sendPhoto($baseUrl, $chatId, $item['url'], $content);
            }

            // Multiple media items -- use sendMediaGroup.
            return $this->sendMediaGroup($baseUrl, $chatId, $media, $content);

        } catch (\Throwable $e) {
            Log::error('TelegramAdapter: publish failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'external_id' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send a plain text message.
     */
    private function sendMessage(string $baseUrl, string $chatId, string $text): array
    {
        $response = Http::post("{$baseUrl}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ]);

        return $this->parseResponse($response);
    }

    /**
     * Send a single photo with an optional caption.
     */
    private function sendPhoto(string $baseUrl, string $chatId, string $photoUrl, string $caption): array
    {
        $response = Http::post("{$baseUrl}/sendPhoto", [
            'chat_id' => $chatId,
            'photo' => $photoUrl,
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ]);

        return $this->parseResponse($response);
    }

    /**
     * Send a single video with an optional caption.
     */
    private function sendVideo(string $baseUrl, string $chatId, string $videoUrl, string $caption): array
    {
        $response = Http::post("{$baseUrl}/sendVideo", [
            'chat_id' => $chatId,
            'video' => $videoUrl,
            'caption' => $caption,
            'parse_mode' => 'HTML',
            'supports_streaming' => true,
        ]);

        return $this->parseResponse($response);
    }

    /**
     * Send a group of media items (photos/videos).
     */
    private function sendMediaGroup(string $baseUrl, string $chatId, array $media, string $caption): array
    {
        $inputMedia = [];

        foreach ($media as $index => $item) {
            $type = $this->isVideo($item['mimetype']) ? 'video' : 'photo';

            $entry = [
                'type' => $type,
                'media' => $item['url'],
            ];

            // Attach the caption only to the first item in the group.
            if ($index === 0) {
                $entry['caption'] = $caption;
                $entry['parse_mode'] = 'HTML';
            }

            $inputMedia[] = $entry;
        }

        $response = Http::post("{$baseUrl}/sendMediaGroup", [
            'chat_id' => $chatId,
            'media' => json_encode($inputMedia),
        ]);

        return $this->parseResponse($response);
    }

    /**
     * Parse a Telegram Bot API response into the standard return format.
     */
    private function parseResponse(\Illuminate\Http\Client\Response $response): array
    {
        $body = $response->json();

        if ($response->successful() && ($body['ok'] ?? false)) {
            // sendMediaGroup returns an array of messages; use the first message_id.
            $result = $body['result'];
            $messageId = is_array($result) && isset($result[0])
                ? (string) $result[0]['message_id']
                : (string) ($result['message_id'] ?? '');

            return [
                'success' => true,
                'external_id' => $messageId,
                'error' => null,
            ];
        }

        $error = $body['description'] ?? 'Unknown Telegram API error';

        Log::error('TelegramAdapter: API error', [
            'status' => $response->status(),
            'error' => $error,
        ]);

        return [
            'success' => false,
            'external_id' => null,
            'error' => $error,
        ];
    }

    /**
     * Determine whether a MIME type represents a video.
     */
    private function isVideo(string $mimetype): bool
    {
        return str_starts_with($mimetype, 'video/');
    }
}
