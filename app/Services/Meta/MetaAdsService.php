<?php

namespace App\Services\Meta;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Lecture du compte publicitaire Meta — CPM reellement constate.
 *
 * Volontairement HORS du modele `social_accounts` : ce n'est pas un compte
 * social publiable, c'est une source de donnees. Le jeton est un token
 * « utilisateur systeme » du portefeuille Business, stocke chiffre comme une
 * cle API. Aucun flux OAuth, donc :
 *   - rien a re-autoriser quand un mot de passe change (c'est ce qui a tue le
 *     token DIX : code 190 / subcode 460) ;
 *   - pas d'App Review, l'app ne lit que les actifs de son propre portefeuille.
 *
 * Lecture seule : aucune methode n'ecrit chez Meta, `ads_read` suffit.
 */
class MetaAdsService
{
    private const GRAPH_API_VERSION = 'v21.0';

    private const GRAPH_API_BASE = 'https://graph.facebook.com';

    /** Les regies Meta, ramenees aux slugs de nos plateformes. */
    private const PUBLISHER_PLATFORM_MAP = [
        'facebook' => 'facebook',
        'instagram' => 'instagram',
        'threads' => 'threads',
        // messenger et audience_network n'ont pas d'equivalent publiable ici.
    ];

    /** Le CPM constate bouge lentement : inutile d'appeler Graph a chaque page. */
    private const CACHE_TTL_SECONDS = 21600; // 6 h

    public function isConfigured(): bool
    {
        return $this->token() !== null && $this->accountId() !== null;
    }

    /**
     * Plafond absolu du budget quotidien, dans la devise du compte.
     *
     * Réglable depuis /settings : les budgets réels varient d'un compte à
     * l'autre, un plafond en dur serait soit inopérant, soit bloquant.
     * `config/meta_ads.php` ne sert plus que de valeur de départ.
     */
    public function maxDailyBudget(): float
    {
        return $this->numericSetting('meta_ads_max_daily_budget', 'meta_ads.max_daily_budget');
    }

    /**
     * Hausse maximale tolérée en une fois, en % du budget courant.
     */
    public function maxBudgetIncreasePct(): float
    {
        return $this->numericSetting('meta_ads_max_budget_increase_pct', 'meta_ads.max_budget_increase_pct');
    }

    /**
     * Réglage en base s'il est renseigné, sinon la valeur de config.
     *
     * Un champ vidé dans le formulaire doit retomber sur la config, pas valoir
     * zéro — un plafond à zéro bloquerait tout et ressemblerait à une panne.
     */
    private function numericSetting(string $settingKey, string $configKey): float
    {
        $value = Setting::get($settingKey);

        return is_numeric($value) ? (float) $value : (float) config($configKey);
    }

    public function token(): ?string
    {
        return Setting::getEncrypted('meta_ads_token') ?: null;
    }

    /**
     * Identifiant du compte publicitaire, toujours prefixe `act_`.
     */
    public function accountId(): ?string
    {
        $raw = trim((string) Setting::get('meta_ads_account_id', ''));

        if ($raw === '') {
            return null;
        }

        return str_starts_with($raw, 'act_') ? $raw : 'act_'.$raw;
    }

    /**
     * Comptes publicitaires visibles par le jeton — alimente le selecteur
     * des reglages, et sert de test de connexion.
     *
     * @return array{success:bool,error:?string,accounts:array<int,array<string,mixed>>}
     */
    public function adAccounts(): array
    {
        $token = $this->token();

        if (! $token) {
            return ['success' => false, 'error' => 'Aucun jeton enregistré.', 'accounts' => []];
        }

        try {
            $response = Http::timeout(15)->get(self::GRAPH_API_BASE.'/'.self::GRAPH_API_VERSION.'/me/adaccounts', [
                'fields' => 'id,name,account_status,currency',
                'access_token' => $token,
            ]);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'error' => $this->errorMessage($response->json()),
                    'accounts' => [],
                ];
            }

            return [
                'success' => true,
                'error' => null,
                'accounts' => array_map(fn ($a) => [
                    'id' => $a['id'] ?? null,
                    'name' => $a['name'] ?? $a['id'] ?? '?',
                    'currency' => $a['currency'] ?? null,
                    // 1 = actif ; les autres etats sont affiches tels quels.
                    'active' => ($a['account_status'] ?? null) === 1,
                ], $response->json('data', [])),
            ];
        } catch (\Throwable $e) {
            Log::error('MetaAdsService: adAccounts a échoué', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage(), 'accounts' => []];
        }
    }

    /**
     * CPM reellement paye, par regie, sur les N derniers jours.
     *
     * Le CPM est recalcule depuis `spend / impressions` plutot que lu dans le
     * champ `cpm` : sur une ventilation par regie, les deux coincident, mais
     * repartir des montants bruts evite de dependre d'un arrondi cote Meta.
     *
     * @return array{
     *     available:bool,
     *     currency:?string,
     *     days:int,
     *     platforms:array<string,array{cpm:float,spend:float,impressions:int}>,
     *     error:?string
     * }
     */
    public function observedCpm(int $days = 90): array
    {
        if (! $this->isConfigured()) {
            return $this->emptyCpm($days, 'Compte publicitaire non configuré.');
        }

        $key = "meta_ads_cpm:{$this->accountId()}:{$days}";

        return Cache::remember($key, self::CACHE_TTL_SECONDS, fn () => $this->fetchObservedCpm($days));
    }

    public function forgetCache(int $days = 90): void
    {
        Cache::forget("meta_ads_cpm:{$this->accountId()}:{$days}");
    }

    // ── Lecture des campagnes ────────────────────────────────

    /**
     * Campagnes du compte, avec leur budget et leur statut effectif.
     *
     * `effective_status` et non `status` : une campagne ACTIVE dont le compte
     * est en dépassement de plafond ne diffuse pas, et seul le statut effectif
     * le dit. Confondre les deux fait conclure « ça tourne » à tort.
     *
     * @return array{success:bool,error:?string,campaigns:array<int,array<string,mixed>>}
     */
    public function campaigns(bool $activeOnly = false): array
    {
        $params = [
            'fields' => 'id,name,status,effective_status,objective,daily_budget,lifetime_budget,budget_remaining,start_time,stop_time,created_time',
            'limit' => 200,
        ];

        if ($activeOnly) {
            $params['filtering'] = json_encode([[
                'field' => 'effective_status',
                'operator' => 'IN',
                'value' => ['ACTIVE'],
            ]]);
        }

        $result = $this->get($this->accountId().'/campaigns', $params);

        if (! $result['success']) {
            return ['success' => false, 'error' => $result['error'], 'campaigns' => []];
        }

        return [
            'success' => true,
            'error' => null,
            'campaigns' => array_map(fn ($c) => $this->formatObject($c), $result['data']['data'] ?? []),
        ];
    }

    /**
     * Un objet (campagne ou ad set) avec son budget courant.
     *
     * @return array{success:bool,error:?string,object:?array<string,mixed>}
     */
    public function object(string $objectId): array
    {
        $result = $this->get($objectId, [
            'fields' => 'id,name,status,effective_status,daily_budget,lifetime_budget,budget_remaining',
        ]);

        if (! $result['success']) {
            return ['success' => false, 'error' => $result['error'], 'object' => null];
        }

        return ['success' => true, 'error' => null, 'object' => $this->formatObject($result['data'])];
    }

    /**
     * Ad sets d'une campagne.
     *
     * @return array{success:bool,error:?string,adsets:array<int,array<string,mixed>>}
     */
    public function adSets(string $campaignId): array
    {
        $result = $this->get($campaignId.'/adsets', [
            'fields' => 'id,name,status,effective_status,daily_budget,lifetime_budget,optimization_goal,billing_event,targeting',
            'limit' => 200,
        ]);

        if (! $result['success']) {
            return ['success' => false, 'error' => $result['error'], 'adsets' => []];
        }

        return [
            'success' => true,
            'error' => null,
            'adsets' => array_map(fn ($a) => $this->formatObject($a), $result['data']['data'] ?? []),
        ];
    }

    /**
     * Performances, ventilables par campagne / ad set / annonce.
     *
     * @param  string  $level  account|campaign|adset|ad
     * @return array{success:bool,error:?string,days:int,rows:array<int,array<string,mixed>>}
     */
    public function insights(string $level = 'campaign', ?int $days = null, ?string $objectId = null): array
    {
        $days ??= (int) config('meta_ads.default_insights_days');

        $result = $this->get(($objectId ?? $this->accountId()).'/insights', [
            'fields' => 'campaign_id,campaign_name,adset_id,adset_name,spend,impressions,reach,clicks,ctr,cpc,cpm,frequency,actions',
            'level' => $level,
            'time_range' => json_encode([
                'since' => now()->subDays($days)->toDateString(),
                'until' => now()->toDateString(),
            ]),
            'limit' => 500,
        ]);

        if (! $result['success']) {
            return ['success' => false, 'error' => $result['error'], 'days' => $days, 'rows' => []];
        }

        return [
            'success' => true,
            'error' => null,
            'days' => $days,
            'rows' => $result['data']['data'] ?? [],
        ];
    }

    // ── Écriture ─────────────────────────────────────────────

    /**
     * Change le statut d'une campagne ou d'un ad set.
     *
     * @return array{success:bool,error:?string}
     */
    public function updateStatus(string $objectId, string $status): array
    {
        return $this->post($objectId, ['status' => $status]);
    }

    /**
     * Change le budget d'une campagne ou d'un ad set.
     *
     * Meta raisonne en **unités mineures** (centimes) : 2500 = 25,00 €.
     * La conversion se fait ici et nulle part ailleurs — la laisser fuiter
     * jusqu'à l'appelant, c'est programmer un budget x100 un jour.
     *
     * @return array{success:bool,error:?string}
     */
    public function updateBudget(string $objectId, float $amount, bool $lifetime = false): array
    {
        $field = $lifetime ? 'lifetime_budget' : 'daily_budget';

        return $this->post($objectId, [$field => (string) (int) round($amount * 100)]);
    }

    /**
     * Montant Meta (unités mineures) ramené à la devise du compte.
     */
    public function toMajorUnits(mixed $minor): ?float
    {
        return $minor === null || $minor === '' ? null : round(((int) $minor) / 100, 2);
    }

    // ── Transport ────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $params
     * @return array{success:bool,error:?string,data:array<string,mixed>}
     */
    private function get(string $path, array $params): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'error' => 'Compte publicitaire non configuré.', 'data' => []];
        }

        try {
            $response = Http::timeout(30)->get(
                self::GRAPH_API_BASE.'/'.self::GRAPH_API_VERSION.'/'.$path,
                $params + ['access_token' => $this->token()],
            );

            if (! $response->successful()) {
                return ['success' => false, 'error' => $this->errorMessage($response->json()), 'data' => []];
            }

            return ['success' => true, 'error' => null, 'data' => $response->json() ?? []];
        } catch (\Throwable $e) {
            Log::error('MetaAdsService: GET a échoué', ['path' => $path, 'error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage(), 'data' => []];
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{success:bool,error:?string}
     */
    private function post(string $path, array $payload): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'error' => 'Compte publicitaire non configuré.'];
        }

        try {
            $response = Http::timeout(30)->asForm()->post(
                self::GRAPH_API_BASE.'/'.self::GRAPH_API_VERSION.'/'.$path,
                $payload + ['access_token' => $this->token()],
            );

            if (! $response->successful()) {
                return ['success' => false, 'error' => $this->errorMessage($response->json())];
            }

            return ['success' => true, 'error' => null];
        } catch (\Throwable $e) {
            Log::error('MetaAdsService: POST a échoué', ['path' => $path, 'error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Budgets ramenés en devise lisible, le reste inchangé.
     *
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     */
    private function formatObject(array $raw): array
    {
        foreach (['daily_budget', 'lifetime_budget', 'budget_remaining'] as $field) {
            if (array_key_exists($field, $raw)) {
                $raw[$field] = $this->toMajorUnits($raw[$field]);
            }
        }

        return $raw;
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchObservedCpm(int $days): array
    {
        try {
            $response = Http::timeout(30)->get(
                self::GRAPH_API_BASE.'/'.self::GRAPH_API_VERSION.'/'.$this->accountId().'/insights',
                [
                    'fields' => 'spend,impressions,account_currency',
                    'breakdowns' => 'publisher_platform',
                    'level' => 'account',
                    'time_range' => json_encode([
                        'since' => now()->subDays($days)->toDateString(),
                        'until' => now()->toDateString(),
                    ]),
                    'access_token' => $this->token(),
                ],
            );

            if (! $response->successful()) {
                return $this->emptyCpm($days, $this->errorMessage($response->json()));
            }

            $platforms = [];
            $currency = null;

            foreach ($response->json('data', []) as $row) {
                $slug = self::PUBLISHER_PLATFORM_MAP[$row['publisher_platform'] ?? ''] ?? null;
                $impressions = (int) ($row['impressions'] ?? 0);
                $spend = (float) ($row['spend'] ?? 0);

                if (! $slug || $impressions <= 0) {
                    continue;
                }

                $currency ??= $row['account_currency'] ?? null;

                // Une regie peut apparaitre sur plusieurs lignes : on cumule
                // les montants bruts avant de calculer le ratio.
                $platforms[$slug]['spend'] = ($platforms[$slug]['spend'] ?? 0) + $spend;
                $platforms[$slug]['impressions'] = ($platforms[$slug]['impressions'] ?? 0) + $impressions;
            }

            foreach ($platforms as $slug => $totals) {
                $platforms[$slug]['cpm'] = round($totals['spend'] / $totals['impressions'] * 1000, 2);
            }

            return [
                'available' => $platforms !== [],
                'currency' => $currency,
                'days' => $days,
                'platforms' => $platforms,
                'error' => $platforms === [] ? 'Aucune impression sur la période.' : null,
            ];
        } catch (\Throwable $e) {
            Log::error('MetaAdsService: observedCpm a échoué', ['error' => $e->getMessage()]);

            return $this->emptyCpm($days, $e->getMessage());
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyCpm(int $days, ?string $error): array
    {
        return [
            'available' => false,
            'currency' => null,
            'days' => $days,
            'platforms' => [],
            'error' => $error,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $body
     */
    private function errorMessage(?array $body): string
    {
        $error = $body['error'] ?? [];
        $message = $error['message'] ?? 'Erreur inconnue';

        // 190/460 = le token utilisateur est mort avec la session (changement de
        // mot de passe). C'est precisement ce qu'un token systeme evite.
        if (($error['code'] ?? null) === 190) {
            $message .= ' — jeton invalide : utiliser un token « utilisateur système » du portefeuille Business, il survit aux changements de mot de passe.';
        }

        return $message;
    }
}
