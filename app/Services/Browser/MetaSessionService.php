<?php

namespace App\Services\Browser;

use App\Models\SocialAccount;

/** Sessions des profils Facebook et Instagram, communes au Mac et aux workers distants. */
class MetaSessionService
{
    public const PLATFORMS = ['facebook', 'instagram'];

    public static function identity(array $cookies, string $platform): ?string
    {
        if (! in_array($platform, self::PLATFORMS, true)) {
            return null;
        }
        $values = [];
        foreach ($cookies as $cookie) {
            if (! preg_match('/^\.?([a-z0-9-]+\.)*'.preg_quote($platform, '/').'\.com$/i', $cookie['domain'] ?? '')) {
                return null;
            }
            $name = $cookie['name'] ?? '';
            $value = (string) ($cookie['value'] ?? '');
            if (isset($values[$name]) && $values[$name] !== $value) {
                return null;
            }
            $values[$name] = $value;
        }
        $id = $platform === 'facebook' ? ($values['i_user'] ?? $values['c_user'] ?? '') : ($values['ds_user_id'] ?? '');
        $authenticated = $platform === 'facebook' ? filled($values['xs'] ?? null) && filled($values['c_user'] ?? null) : filled($values['sessionid'] ?? null);

        return $authenticated && preg_match('/^\d{1,30}$/', $id) ? $id : null;
    }

    public function store(SocialAccount $account, array $cookies): bool
    {
        $platform = $account->platform->slug;
        abort_unless($account->usesBrowser() && filled($account->browser_profile), 422, 'Choisissez Companion et son profil distant dans la fiche du compte.');
        abort_unless(self::identity($cookies, $platform) === (string) $account->platform_account_id, 409, 'La session ne correspond pas au profil demandé.');
        $account->update(['browser_session' => $cookies, 'browser_session_status' => 'pending', 'browser_session_received_at' => now()]);
        $result = app(BrowserTaskQueue::class)->run($account, $platform.'.session.apply', ['url' => self::home($platform)], 90);

        return $this->record($account, $result);
    }

    public function check(SocialAccount $account): bool
    {
        $platform = $account->platform->slug;
        abort_unless(in_array($platform, self::PLATFORMS, true), 422);

        return $this->record($account, app(BrowserTaskQueue::class)->run($account, $platform.'.whoami', ['url' => self::home($platform)], 90));
    }

    private function record(SocialAccount $account, array $result): bool
    {
        $connected = (string) ($result['account_id'] ?? '') === (string) $account->platform_account_id;
        if ($account->platform->slug === 'instagram' && filled($account->browser_username)) {
            $connected = $connected && strtolower((string) ($result['username'] ?? '')) === strtolower($account->browser_username);
        }
        $account->update(['browser_session_status' => $connected ? 'connected' : 'expired', 'browser_session_checked_at' => now(),
            'browser_username' => $connected && filled($result['username'] ?? null) ? $result['username'] : $account->browser_username]);

        return $connected;
    }

    public static function home(string $platform): string
    {
        abort_unless(in_array($platform, self::PLATFORMS, true), 422);

        return 'https://www.'.$platform.'.com/';
    }
}
