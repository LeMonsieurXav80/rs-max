<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramNotificationService
{
    /** Texte brut : noms de compte et erreurs ne deviennent jamais du Markdown. */
    public static function sendConfiguredText(string $text): array
    {
        $token = Setting::getEncrypted('notify_telegram_bot_token');
        $chat = Setting::get('notify_telegram_chat_id');
        if (! $token || ! $chat) {
            return ['status' => 'not_configured', 'sent' => false];
        }
        try {
            $response = Http::timeout(20)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chat, 'text' => $text, 'disable_web_page_preview' => true,
            ]);
            $sent = $response->successful() && $response->json('ok') === true;

            return ['status' => $sent ? 'sent' : 'failed', 'sent' => $sent,
                'message_id' => $sent ? $response->json('result.message_id') : null];
        } catch (\Throwable $e) {
            // Une exception HTTP peut contenir l'URL avec le jeton : ne jamais la journaliser.
            Log::warning('Telegram : résultat non confirmé', ['exception_type' => get_class($e)]);

            return ['status' => 'uncertain', 'sent' => false];
        }
    }

    private static function sessionAlertKey(SocialAccount $account): string
    {
        return 'telegram-session:'.$account->id.':'.hash('sha256', (string) $account->browser_session_received_at);
    }

    public static function clearSessionAlert(SocialAccount $account): void
    {
        Cache::forget(static::sessionAlertKey($account));
    }

    /** Une alerte par épisode, après échec de la restauration, avec les données réelles RS-Max. */
    public static function notifyExpiredSession(SocialAccount $account, string $site): array
    {
        if (! $account->usesBrowser()) {
            return ['status' => 'skipped', 'sent' => false];
        }
        $key = static::sessionAlertKey($account);
        if (! Cache::add($key, true, now()->addDay())) {
            return ['status' => 'duplicate', 'sent' => false];
        }
        $text = "⚠️ Session expirée ou connexion à vérifier sur le NAS\n"
            ."Compte : {$account->name}\nRéseau : {$account->platform->name}\nSite : {$site}\n\n"
            .'La connexion n’est pas confirmée après restauration de la session enregistrée. Reconnectez-vous dans votre navigateur habituel, puis renvoyez la session avec Companion. Les actions non confirmées ne seront pas relancées automatiquement.';
        $result = static::sendConfiguredText($text);
        if (in_array($result['status'], ['not_configured', 'failed'], true)) {
            Cache::forget($key);
        }

        return $result;
    }

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
            ."📱 *Plateforme :* {$platform}\n"
            ."👤 *Compte :* {$account}\n"
            ."❌ *Erreur :* {$error}";

        if ($postLink) {
            $message .= "\n\n🔗 [Voir le post]({$postLink})";
        }

        static::send($botToken, $chatId, $message);
    }

    /**
     * Previent qu'un fil programme ne s'est pas publie en entier.
     *
     * Distincte de notifyPublishError() parce qu'un fil n'echoue pas en bloc : il
     * s'arrete a un segment, les precedents sont bel et bien en ligne. Le message
     * doit donc dire OU ca s'est arrete, sinon on ne sait pas s'il faut reprendre
     * ou tout refaire. Le lien pointe vers le fil, pas vers un post.
     */
    public static function notifyThreadError(int $threadId, string $platform, string $error, ?string $account = null, ?string $progress = null): void
    {
        if (! Setting::get('notify_publish_error')) {
            return;
        }

        $chatId = Setting::get('notify_telegram_chat_id');
        $botToken = Setting::getEncrypted('notify_telegram_bot_token');

        if (! $chatId || ! $botToken) {
            return;
        }

        $message = "⚠️ *Fil incomplet*\n\n"
            ."📱 *Plateforme :* {$platform}\n";

        if ($account) {
            $message .= "👤 *Compte :* {$account}\n";
        }

        if ($progress) {
            $message .= "📊 *Arret :* {$progress}\n";
        }

        $message .= "❌ *Erreur :* {$error}";

        $appUrl = rtrim((string) config('app.url'), '/');
        $message .= "\n\n🔗 [Voir le fil]({$appUrl}/threads/{$threadId})";

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
