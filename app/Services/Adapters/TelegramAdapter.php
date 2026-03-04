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
     * @param  array|null  $media  Optional media items (each with url, mimetype, size, title, local_path).
     * @return array{success: bool, external_id: string|null, error: string|null}
     */
    public function publish(SocialAccount $account, string $content, ?array $media = null, ?array $options = null): array
    {
        try {
            $credentials = $account->credentials;
            $token = $credentials['bot_token'];
            $chatId = $credentials['chat_id'];
            $baseUrl = "https://api.telegram.org/bot{$token}";

            // No media -- plain text message.
            if (empty($media)) {
                return $this->sendMessage($baseUrl, $chatId, $content);
            }

            // Telegram caption limit is 1024 characters.
            // If text is too long, send text first then media separately.
            if (mb_strlen($content) > 1024) {
                $textResult = $this->sendMessage($baseUrl, $chatId, $content);
                if (! $textResult['success']) {
                    return $textResult;
                }

                // Try to send media separately; if it fails, still return success
                // since the text (main content) was already delivered.
                $mediaResult = null;
                if (count($media) === 1) {
                    $item = $media[0];
                    $mediaResult = $this->isVideo($item['mimetype'], $item['url'])
                        ? $this->sendVideo($baseUrl, $chatId, $item, '')
                        : $this->sendPhoto($baseUrl, $chatId, $item, '');
                } else {
                    $mediaResult = $this->sendMediaGroup($baseUrl, $chatId, $media, '');
                }

                if (! $mediaResult['success']) {
                    Log::warning('TelegramAdapter: text sent but media failed', [
                        'error' => $mediaResult['error'],
                    ]);
                }

                return $textResult;
            }

            // Single media item.
            if (count($media) === 1) {
                $item = $media[0];

                if ($this->isVideo($item['mimetype'], $item['url'])) {
                    return $this->sendVideo($baseUrl, $chatId, $item, $content);
                }

                return $this->sendPhoto($baseUrl, $chatId, $item, $content);
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
    private function sendPhoto(string $baseUrl, string $chatId, array $item, string $caption): array
    {
        $localPath = $item['local_path'] ?? null;

        if ($localPath && file_exists($localPath)) {
            $response = Http::attach('photo', fopen($localPath, 'r'), basename($localPath))
                ->post("{$baseUrl}/sendPhoto", [
                    'chat_id' => $chatId,
                    'caption' => $caption,
                    'parse_mode' => 'HTML',
                ]);
        } else {
            $response = Http::post("{$baseUrl}/sendPhoto", [
                'chat_id' => $chatId,
                'photo' => $item['url'],
                'caption' => $caption,
                'parse_mode' => 'HTML',
            ]);
        }

        return $this->parseResponse($response);
    }

    /**
     * Send a single video with an optional caption.
     */
    private function sendVideo(string $baseUrl, string $chatId, array $item, string $caption): array
    {
        $localPath = $item['local_path'] ?? null;
        $dimensions = $localPath ? $this->getVideoDimensions($localPath) : null;

        $params = [
            'chat_id' => $chatId,
            'caption' => $caption,
            'parse_mode' => 'HTML',
            'supports_streaming' => true,
        ];

        if ($dimensions) {
            $params['width'] = $dimensions['width'];
            $params['height'] = $dimensions['height'];
        }

        if ($localPath && file_exists($localPath)) {
            $response = Http::attach('video', fopen($localPath, 'r'), basename($localPath))
                ->post("{$baseUrl}/sendVideo", $params);
        } else {
            $params['video'] = $item['url'];
            $response = Http::post("{$baseUrl}/sendVideo", $params);
        }

        return $this->parseResponse($response);
    }

    /**
     * Send a group of media items (photos/videos).
     */
    private function sendMediaGroup(string $baseUrl, string $chatId, array $media, string $caption): array
    {
        $inputMedia = [];
        $hasLocalFiles = false;
        $attachments = [];

        foreach ($media as $index => $item) {
            $type = $this->isVideo($item['mimetype'], $item['url'] ?? '') ? 'video' : 'photo';
            $localPath = $item['local_path'] ?? null;

            $entry = ['type' => $type];

            if ($type === 'video' && $localPath) {
                $dimensions = $this->getVideoDimensions($localPath);
                if ($dimensions) {
                    $entry['width'] = $dimensions['width'];
                    $entry['height'] = $dimensions['height'];
                }
            }

            if ($localPath && file_exists($localPath)) {
                $attachName = "file_{$index}";
                $entry['media'] = "attach://{$attachName}";
                $attachments[$attachName] = [$localPath, basename($localPath)];
                $hasLocalFiles = true;
            } else {
                $entry['media'] = $item['url'];
            }

            // Attach the caption only to the first item in the group.
            if ($index === 0) {
                $entry['caption'] = $caption;
                $entry['parse_mode'] = 'HTML';
            }

            $inputMedia[] = $entry;
        }

        if ($hasLocalFiles) {
            $request = Http::asMultipart();
            foreach ($attachments as $name => [$path, $filename]) {
                $request = $request->attach($name, fopen($path, 'r'), $filename);
            }
            $response = $request->post("{$baseUrl}/sendMediaGroup", [
                ['name' => 'chat_id', 'contents' => $chatId],
                ['name' => 'media', 'contents' => json_encode($inputMedia)],
            ]);
        } else {
            $response = Http::post("{$baseUrl}/sendMediaGroup", [
                'chat_id' => $chatId,
                'media' => json_encode($inputMedia),
            ]);
        }

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
     * Extract video width/height via ffprobe.
     */
    private function getVideoDimensions(string $path): ?array
    {
        if (! file_exists($path)) {
            return null;
        }

        $ffprobe = trim(shell_exec('which ffprobe 2>/dev/null') ?: '');
        if (! $ffprobe) {
            return null;
        }

        $output = shell_exec(sprintf(
            '%s -v quiet -select_streams v:0 -show_entries stream=width,height -of json %s 2>/dev/null',
            escapeshellarg($ffprobe),
            escapeshellarg($path)
        ));

        if (! $output) {
            return null;
        }

        $data = json_decode($output, true);
        $stream = $data['streams'][0] ?? null;

        if (! $stream || empty($stream['width']) || empty($stream['height'])) {
            return null;
        }

        return ['width' => (int) $stream['width'], 'height' => (int) $stream['height']];
    }

    /**
     * Determine whether a MIME type (or URL extension) represents a video.
     */
    private function isVideo(string $mimetype, string $url = ''): bool
    {
        if (str_starts_with($mimetype, 'video/')) {
            return true;
        }

        // Fallback: check file extension in URL
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));

        return in_array($ext, ['mp4', 'mov', 'avi', 'webm', 'mkv']);
    }
}
