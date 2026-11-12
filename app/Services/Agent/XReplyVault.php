<?php

namespace App\Services\Agent;

use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\VaultSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class XReplyVault
{
    /** Le Vault apporte des références, jamais un avis : la réponse publique n’en dit pas plus qu’elles. */
    public const GUARD = 'Les sources vault sont des références vérifiées du compte. Ne cite que ce qu’elles disent, sans extrapoler. Aucun conseil médical, diagnostic, dosage ou promesse de résultat, aucune information interne (prix non publics, stocks, clients, chiffres, contrats, données personnelles). Une source apporte une idée ou un fait : ne prétends pas avoir lu, vécu ou écrit ce qu’elle contient, et ne parle jamais au nom de son auteur. En cas de doute, ne pas utiliser la source.';

    public function brandsFor(SocialAccount $account): array
    {
        return collect($this->sources($account))->flatMap(fn ($source) => $source['brands'] ?? [])->unique()->values()->all();
    }

    /**
     * Sources actives du compte, ou l’ancien réglage `x_reply_vault_account_{id}` lié au persona (DIX, avant migration).
     *
     * @return list<array{corpus: string, format: string, folders: list<string>, excluded: list<string>, brands: list<string>}>
     */
    private function sources(SocialAccount $account): array
    {
        $sources = $account->vaultSources()->where('is_active', true)->get()
            ->map(fn (VaultSource $source) => ['corpus' => $source->corpus, 'format' => $source->format,
                'folders' => array_values(array_filter($source->folders ?? [], fn ($folder) => ! VaultSource::forbidden($folder))),
                'excluded' => array_values($source->excluded_folders ?? []), 'brands' => $source->brands ?? []])
            ->filter(fn ($source) => $source['folders'] !== [])->values()->all();
        if ($sources !== []) {
            return $sources;
        }
        $legacy = json_decode(Setting::get("x_reply_vault_account_{$account->id}", '') ?? '', true);
        if (! is_array($legacy) || ($legacy['persona_id'] ?? null) !== $account->persona_id) {
            return [];
        }

        return [['corpus' => $legacy['corpus'] ?? '', 'format' => 'pubmed', 'folders' => ['Recherches'], 'excluded' => [], 'brands' => $legacy['brands'] ?? []]];
    }

    public function search(SocialAccount $account, string $target, ?string $origin = null): ?array
    {
        $sources = array_filter($this->sources($account), fn ($source) => preg_match('/^[a-z][a-z0-9_-]{0,30}$/', $source['corpus']));
        if ($sources === []) {
            return null;
        }
        $revisions = [];
        $found = [];
        foreach ($sources as $source) {
            $result = $this->lookup($source, $target, $origin);
            $revisions[$source['corpus']] = $result['revision'];
            array_push($found, ...array_slice($result['sources'], 0, 3));
        }

        return ['revision' => implode(',', array_unique($revisions)), 'sources' => array_slice($found, 0, 4)];
    }

    /** Pour tester une source avant de la rattacher : mêmes filtres que pour un compte. */
    public function lookupSource(VaultSource $source, string $target, ?string $origin = null): array
    {
        $folders = array_values(array_filter($source->folders ?? [], fn ($folder) => ! VaultSource::forbidden($folder)));
        abort_if($folders === [], 422, 'Aucun dossier autorisé dans cette source.');

        return $this->lookup(['corpus' => $source->corpus, 'format' => $source->format, 'folders' => $folders,
            'excluded' => array_values($source->excluded_folders ?? [])], $target, $origin);
    }

    private function lookup(array $source, string $target, ?string $origin): array
    {
        try {
            $response = Http::withOptions(['curl' => [CURLOPT_UNIX_SOCKET_PATH => "/run/rsmax-vault/{$source['corpus']}.sock"]])
                ->connectTimeout(2)->timeout(12)->acceptJson()
                ->post('http://localhost/lookup', ['target' => $target, 'origin' => $origin, 'format' => $source['format'],
                    'folders' => $source['folders'], 'excluded' => $source['excluded']]);
        } catch (ConnectionException) {
            abort(503, 'Le Vault est momentanément inaccessible.');
        }
        $result = $response->json();
        abort_unless($response->successful() && is_array($result['sources'] ?? null) && is_string($result['revision'] ?? null),
            503, 'Le Vault est momentanément inaccessible.');

        return $result;
    }
}
