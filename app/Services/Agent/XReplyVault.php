<?php

namespace App\Services\Agent;

use App\Models\Setting;
use App\Models\SocialAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class XReplyVault
{
    /** Le Vault apporte des références, jamais un avis : la réponse publique n’en dit pas plus qu’elles. */
    public const GUARD = 'Les sources vault sont des références vérifiées du compte. Ne cite que ce qu’elles disent, sans extrapoler. Aucun conseil médical, diagnostic, dosage ou promesse de résultat, aucune information interne (prix non publics, stocks, clients, chiffres, contrats, données personnelles). En cas de doute, ne pas utiliser la source.';

    public function brandsFor(SocialAccount $account): array
    {
        return $this->profile($account)['brands'] ?? [];
    }

    private function profile(SocialAccount $account): array
    {
        $value = json_decode(Setting::get("x_reply_vault_account_{$account->id}", '') ?? '', true);

        return is_array($value) ? $value : [];
    }

    public function search(SocialAccount $account, string $target, ?string $origin = null): ?array
    {
        $profile = $this->profile($account);
        $corpus = $profile['corpus'] ?? '';
        if (($profile['persona_id'] ?? null) !== $account->persona_id || ! preg_match('/^[a-z][a-z0-9_-]{0,30}$/', $corpus)) {
            return null;
        }

        try {
            $response = Http::withOptions(['curl' => [CURLOPT_UNIX_SOCKET_PATH => "/run/rsmax-vault/{$corpus}.sock"]])
                ->connectTimeout(2)->timeout(12)->acceptJson()
                ->post('http://localhost/lookup', ['target' => $target, 'origin' => $origin]);
        } catch (ConnectionException) {
            abort(503, 'Le Vault est momentanément inaccessible.');
        }
        $result = $response->json();
        abort_unless($response->successful() && is_array($result['sources'] ?? null) && is_string($result['revision'] ?? null),
            503, 'Le Vault est momentanément inaccessible.');

        return $result;
    }
}
