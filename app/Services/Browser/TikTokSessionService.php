<?php

namespace App\Services\Browser;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Log;

/**
 * Session TikTok du Chromium du VPS. Aucun cookie ne nomme le compte : la preuve est
 * le nom d'utilisateur affiché par TikTok Studio une fois la session installée.
 */
class TikTokSessionService
{
    public const CONNECTED = 'connected';

    public const EXPIRED = 'expired';

    public const STORED = 'stored';

    public const REQUIRED_COOKIE = 'sessionid';

    private const STUDIO_URL = 'https://www.tiktok.com/tiktokstudio';

    private const TASK_TIMEOUT = 75;

    public function __construct(private BrowserTaskQueue $queue) {}

    public function hasSession(array $cookies): bool
    {
        return filled(collect($cookies)->firstWhere('name', self::REQUIRED_COOKIE)['value'] ?? null);
    }

    public function store(SocialAccount $account, array $cookies, string $username): bool
    {
        $account->update(['browser_session' => $cookies, 'browser_session_received_at' => now(),
            'browser_session_status' => self::STORED, 'browser_username' => $username]);
        // La réception reste disponible quand le routage est suspendu. Aucune
        // installation ni visite TikTok ne doit servir à confirmer un simple stockage.
        if (blank($account->browser_profile)) {
            return false;
        }
        $this->queue->run($account, 'session.apply', ['cookies' => $cookies], self::TASK_TIMEOUT);

        return $this->verify($account, $username);
    }

    /** Contrôle la session, et la réinstalle depuis la copie chiffrée si elle a disparu. */
    public function check(SocialAccount $account): bool
    {
        if ($this->verify($account, $account->browser_username)) {
            return true;
        }

        $this->queue->run($account, 'session.apply', ['cookies' => $account->browser_session], self::TASK_TIMEOUT);

        return $this->verify($account, $account->browser_username);
    }

    public function verify(SocialAccount $account, string $username): bool
    {
        $result = $this->queue->run($account, 'tiktok.whoami', ['url' => self::STUDIO_URL], self::TASK_TIMEOUT);
        $connected = ($result['username'] ?? null) === $username;

        $account->update([
            'browser_session_status' => $connected ? self::CONNECTED : self::EXPIRED,
            'browser_session_checked_at' => now(),
            'browser_username' => $connected ? $username : $account->browser_username,
        ]);

        if (! $connected) {
            Log::warning('Session TikTok absente du navigateur du VPS', ['account_id' => $account->id, 'vu' => $result['username'] ?? null]);
        }

        return $connected;
    }
}
