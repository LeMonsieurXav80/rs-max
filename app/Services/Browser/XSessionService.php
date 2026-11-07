<?php

namespace App\Services\Browser;

use App\Models\SocialAccount;
use App\Services\TelegramNotificationService;
use Illuminate\Support\Facades\Log;

/**
 * Session X du Chromium du NAS. Une copie chiffrée des cookies reste en base pour
 * la réinstaller si le profil la perd, sans repasser par l'humain.
 */
class XSessionService
{
    public const CONNECTED = 'connected';

    public const EXPIRED = 'expired';

    private const REQUIRED_COOKIES = ['auth_token', 'ct0'];

    private const TASK_TIMEOUT = 60;

    public function __construct(private BrowserTaskQueue $queue) {}

    /** Vrai seulement si les cookies sont bien ceux de ce compte : `twid` porte son identifiant. */
    public function belongsTo(array $cookies, SocialAccount $account): bool
    {
        $names = collect($cookies)->pluck('value', 'name');

        return collect(self::REQUIRED_COOKIES)->every(fn ($name) => filled($names->get($name)))
            && self::accountIdFrom($cookies) === (string) $account->platform_account_id;
    }

    public static function accountIdFrom(array $cookies): ?string
    {
        $twid = urldecode((string) (collect($cookies)->firstWhere('name', 'twid')['value'] ?? ''));

        return preg_match('/^u=(\d+)$/', trim($twid, '"'), $m) ? $m[1] : null;
    }

    public function store(SocialAccount $account, array $cookies): bool
    {
        $account->update(['browser_session' => $cookies, 'browser_session_received_at' => now()]);
        $this->apply($account);

        return $this->notifyResult($account, $this->verify($account));
    }

    /** Contrôle la session, et la réinstalle depuis la copie chiffrée si elle a disparu. */
    public function check(SocialAccount $account): bool
    {
        if ($this->verify($account)) {
            return $this->notifyResult($account, true);
        }

        if (empty($account->browser_session)) {
            return $this->notifyResult($account, false);
        }

        try {
            $this->apply($account);

            return $this->notifyResult($account, $this->verify($account));
        } catch (BrowserException $e) {
            TelegramNotificationService::notifyExpiredSession($account, 'https://x.com/');
            throw $e;
        }
    }

    private function notifyResult(SocialAccount $account, bool $connected): bool
    {
        if ($connected) {
            TelegramNotificationService::clearSessionAlert($account);
        } else {
            TelegramNotificationService::notifyExpiredSession($account, 'https://x.com/');
        }

        return $connected;
    }

    public function verify(SocialAccount $account): bool
    {
        $result = $this->queue->run($account, 'x.whoami', ['url' => 'https://x.com/home'], self::TASK_TIMEOUT);
        $username = $result['username'] ?? null;

        // Le profil d'une marque ne doit pas agir pour une autre.
        $connected = $username !== null && ($account->browser_username === null || $account->browser_username === $username);

        $account->update([
            'browser_session_status' => $connected ? self::CONNECTED : self::EXPIRED,
            'browser_session_checked_at' => now(),
            'browser_username' => $connected ? $username : $account->browser_username,
        ]);

        if (! $connected) {
            Log::warning('Session X absente du navigateur du NAS', ['account_id' => $account->id, 'vu' => $username]);
        }

        return $connected;
    }

    private function apply(SocialAccount $account): void
    {
        $this->queue->run($account, 'session.apply', ['cookies' => $account->browser_session], self::TASK_TIMEOUT);
    }
}
