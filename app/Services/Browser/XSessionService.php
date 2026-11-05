<?php

namespace App\Services\Browser;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Log;

/**
 * Session X du navigateur du VPS. Une copie chiffrée des cookies reste en base pour
 * reconstruire la session si le profil Camofox est perdu, sans repasser par l'humain.
 */
class XSessionService
{
    public const CONNECTED = 'connected';

    public const EXPIRED = 'expired';

    private const REQUIRED_COOKIES = ['auth_token', 'ct0'];

    public function __construct(private CamofoxClient $client) {}

    public static function userId(SocialAccount $account): string
    {
        return 'rsmax-x-'.$account->id;
    }

    /** Vrai seulement si les cookies sont bien ceux de ce compte : `twid` porte son identifiant. */
    public function belongsTo(array $cookies, SocialAccount $account): bool
    {
        $names = collect($cookies)->pluck('value', 'name');

        return collect(self::REQUIRED_COOKIES)->every(fn ($name) => filled($names->get($name)))
            && self::accountIdFrom($cookies) === (string) $account->platform_account_id;
    }

    public static function accountIdFrom(array $cookies): ?string
    {
        $twid = urldecode((string) collect($cookies)->firstWhere('name', 'twid')['value'] ?? '');

        return preg_match('/^u=(\d+)$/', trim($twid, '"'), $m) ? $m[1] : null;
    }

    public function store(SocialAccount $account, array $cookies): bool
    {
        $account->update(['browser_session' => $cookies, 'browser_session_received_at' => now()]);
        $this->client->importCookies(self::userId($account), self::forPlaywright($cookies));

        return $this->verify($account);
    }

    /** Contrôle la session, et la restaure depuis la copie chiffrée si elle a disparu. */
    public function check(SocialAccount $account): bool
    {
        if ($this->verify($account)) {
            return true;
        }

        if (empty($account->browser_session)) {
            return false;
        }

        $this->client->importCookies(self::userId($account), self::forPlaywright($account->browser_session));

        return $this->verify($account);
    }

    /** Ouvre un onglet connecté, ou échoue explicitement : rien ne doit se saisir sur la page de login. */
    public function open(SocialAccount $account, string $url): XPage
    {
        $userId = self::userId($account);
        $page = new XPage($this->client, $userId, $this->client->open($userId, $url));

        return $page;
    }

    public function verify(SocialAccount $account): bool
    {
        $page = $this->open($account, 'https://x.com/home');

        try {
            $username = $page->identity();
        } finally {
            $page->close();
        }

        $account->update([
            'browser_session_status' => $username ? self::CONNECTED : self::EXPIRED,
            'browser_session_checked_at' => now(),
            'browser_username' => $username ?? $account->browser_username,
        ]);

        if (! $username) {
            Log::warning('Session X expirée sur le navigateur du VPS', ['account_id' => $account->id]);
        }

        return $username !== null;
    }

    /** @return array<int, array<string, mixed>> */
    private static function forPlaywright(array $cookies): array
    {
        return array_map(fn (array $c) => array_filter([
            'name' => $c['name'],
            'value' => $c['value'],
            'domain' => $c['domain'],
            'path' => $c['path'] ?? '/',
            'expires' => isset($c['expirationDate']) ? (int) $c['expirationDate'] : null,
            'httpOnly' => $c['httpOnly'] ?? null,
            'secure' => $c['secure'] ?? null,
            'sameSite' => match ($c['sameSite'] ?? null) {
                'strict' => 'Strict',
                'lax' => 'Lax',
                'no_restriction' => 'None',
                default => null,
            },
        ], fn ($v) => $v !== null), $cookies);
    }
}
