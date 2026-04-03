<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramNotificationService
{
    /**
     * Send a publish error notification via Telegram.
     */
    public static function notifyPublishError(string $platform, string $account, string $error, ?int $postId = null): void
    {
        if (! Setting::get('notify_publish_error')) {
            return;
        }

        $chatId = Setting::get('notify_telegram_chat_id');
        $botToken = Setting::getEncrypted('notify_telegram_bot_token');

        if (! $chatId || ! $botToken) {
            return;
        }

        $appUrl = config('app.url');
        $postLink = $postId ? "{$appUrl}/posts/{$postId}" : '';

        $message = "⚠️ *Erreur de publication*\n\n"
            . "📱 *Plateforme :* {$platform}\n"
            . "👤 *Compte :* {$account}\n"
            . "❌ *Erreur :* {$error}";

        if ($postLink) {
            $message .= "\n\n🔗 [Voir le post]({$postLink})";
        }

        static::send($botToken, $chatId, $message);
    }

    /**
     * Send a test notification.
     */
    public static function sendTest(): bool
    {
        $chatId = Setting::get('notify_telegram_chat_id');
        $botToken = Setting::getEncrypted('notify_telegram_bot_token');

        if (! $chatId || ! $botToken) {
            return false;
        }

        return static::send($botToken, $chatId, "✅ *Test RS-Max*\n\nLes notifications fonctionnent correctement.");
    }

    /**
     * Send a Telegram message.
     */
    private static function send(string $botToken, string $chatId, string $text): bool
    {
        try {
            $response = Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'Markdown',
                'disable_web_page_preview' => true,
            ]);

            if (! $response->successful()) {
                Log::warning('TelegramNotificationService: send failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('TelegramNotificationService: exception', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
