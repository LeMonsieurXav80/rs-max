<?php

namespace App\Services\Threads;

use App\Models\SocialAccount;
use App\Services\Browser\BrowserTaskQueue;
use App\Services\TelegramNotificationService;
use Illuminate\Support\Facades\Http;

/** La preuve est le profil réellement connecté sur le NAS, recoupé avec l'API du compte. */
class ThreadsSessionService
{
    public function store(SocialAccount $account, string $username, array $cookies): bool
    {
        $r = Http::timeout(20)->withToken($account->credentials['access_token'])->get('https://graph.threads.net/v1.0/me', ['fields' => 'id,username']);
        abort_unless($r->successful() && (string) $r->json('id') === (string) $account->platform_account_id && strtolower($r->json('username', '')) === strtolower($username), 409, 'Identité Threads différente de celle du compte API.');
        $profile = $account->browser_profile;
        if (! $profile && $account->persona_id) {
            $profiles = SocialAccount::where('persona_id', $account->persona_id)->whereHas('users', fn ($q) => $q->whereIn('users.id', $account->users()->pluck('users.id')))->where('driver', 'browser')->whereNotNull('browser_profile')->pluck('browser_profile')->filter()->unique();
            abort_unless($profiles->count() === 1, 422, 'Renseigner le profil NAS de ce compte Threads.');
            $profile = $profiles->first();
        }
        abort_unless($profile, 422, 'Profil NAS requis.');
        $account->update(['browser_profile' => $profile, 'browser_session' => $cookies, 'browser_username' => strtolower($username), 'browser_session_received_at' => now(), 'browser_session_status' => 'pending']);
        app(BrowserTaskQueue::class)->run($account, 'threads.session.apply', ['url' => 'https://www.threads.com/', 'username' => strtolower($username)], 90);

        return $this->verify($account);
    }

    public function verify(SocialAccount $account): bool
    {
        $r = app(BrowserTaskQueue::class)->run($account, 'threads.whoami', ['url' => 'https://www.threads.com/', 'username' => $account->browser_username], 90);
        $connected = filled($account->browser_username) && ($r['username'] ?? null) === $account->browser_username;
        $account->update(['browser_session_status' => $connected ? 'connected' : 'expired', 'browser_session_checked_at' => now()]);
        if ($connected) {
            TelegramNotificationService::clearSessionAlert($account);
        } else {
            TelegramNotificationService::notifyExpiredSession($account, 'https://www.threads.com/');
        }

        return $connected;
    }
}
