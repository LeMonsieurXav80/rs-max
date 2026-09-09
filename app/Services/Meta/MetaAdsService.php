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
     * Pages visibles par CE jeton.
     *
     * Zero Page ne veut pas dire « aucune Page attribuee a l'utilisateur
     * systeme » : le plus souvent le jeton n'a simplement pas `pages_show_list`.
     * **Les scopes sont figes a la generation du jeton** — attribuer un actif
     * ensuite ne les elargit pas, il faut regenerer.
     *
     * Sert au diagnostic du boost Facebook, qui echoue sinon sur un message
     * (« publication indisponible ») qui accuse le mauvais coupable.
     *
     * @return array{success:bool,error:?string,pages:array<int,array<string,mixed>>}
     */
    public function pages(): array
    {
        $result = $this->get('me/accounts', ['fields' => 'id,name']);

        if (! $result['success']) {
            return ['success' => false, 'error' => $result['error'], 'pages' => []];
        }

        return ['success' => true, 'error' => null, 'pages' => $result['data']['data'] ?? []];
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

    // ── Sponsorisation d'une publication existante ───────────

    /**
     * Sponsorise une publication organique DEJA PUBLIEE, sans la republier.
     *
     * Meta n'a pas d'endpoint « booster » : il faut monter les quatre objets
     * (campagne → ad set → creatif → annonce). Le creatif ne porte aucun
     * contenu, seulement une REFERENCE a la publication existante :
     *   - Facebook  : `object_story_id` = {page_id}_{post_id}
     *   - Instagram : `instagram_user_id` + `source_instagram_media_id`
     * C'est ce qui fait qu'on sponsorise le post d'origine — avec ses likes et
     * ses commentaires — au lieu d'en publier un double.
     *
     * Tout est cree en PAUSED : monter la structure ne coute rien, c'est
     * l'activation qui depense. Elle se fait ensuite par updateStatus(), qui
     * passe par les garde-fous et le journal.
     *
     * @param  array{platform:string,object_story_id?:string,instagram_media_id?:string,instagram_user_id?:string,page_id?:string,name:string,budget:float,days:int}  $spec
     * @return array{success:bool,error:?string,ids:array<string,?string>}
     */
    public function createBoost(array $spec): array
    {
        $ids = ['campaign_id' => null, 'adset_id' => null, 'creative_id' => null, 'ad_id' => null];

        $campaign = $this->post($this->accountId().'/campaigns', [
            'name' => $spec['name'],
            'objective' => 'OUTCOME_ENGAGEMENT',
            'status' => 'PAUSED',
            'special_ad_categories' => json_encode([]),
            // Exige par l'API depuis 2026 des lors que le budget est porte par
            // l'ad set et non par la campagne. Son absence renvoie un 4834011.
            'is_adset_budget_sharing_enabled' => 'false',
        ], returnId: true);

        if (! $campaign['success']) {
            return ['success' => false, 'error' => $campaign['error'], 'ids' => $ids];
        }
        $ids['campaign_id'] = $campaign['id'];

        // Budget de DUREE DE VIE, pas quotidien : un boost doit etre borne dans
        // le temps ET dans la depense totale. Un budget quotidien sans date de
        // fin tournerait indefiniment.
        $adset = $this->post($this->accountId().'/adsets', [
            'name' => $spec['name'],
            'campaign_id' => $campaign['id'],
            'lifetime_budget' => (string) (int) round($spec['budget'] * 100),
            'billing_event' => 'IMPRESSIONS',
            'optimization_goal' => 'POST_ENGAGEMENT',
            'start_time' => now()->toIso8601String(),
            'end_time' => now()->addDays($spec['days'])->toIso8601String(),
            'targeting' => json_encode($spec['targeting'] ?? [
                'geo_locations' => ['countries' => ['PT']],
                'publisher_platforms' => ['facebook', 'instagram'],
            ]),
            'status' => 'PAUSED',
        ], returnId: true);

        if (! $adset['success']) {
            return $this->rollbackBoost($ids, $adset['error']);
        }
        $ids['adset_id'] = $adset['id'];

        $creativePayload = $spec['platform'] === 'instagram'
            ? [
                'name' => $spec['name'],
                'instagram_user_id' => $spec['instagram_user_id'],
                'source_instagram_media_id' => $spec['instagram_media_id'],
            ]
            : [
                'name' => $spec['name'],
                'object_story_id' => $spec['object_story_id'],
            ];

        $creative = $this->post($this->accountId().'/adcreatives', $creativePayload, returnId: true);

        if (! $creative['success']) {
            return $this->rollbackBoost($ids, $creative['error']);
        }
        $ids['creative_id'] = $creative['id'];

        $ad = $this->post($this->accountId().'/ads', [
            'name' => $spec['name'],
            'adset_id' => $adset['id'],
            'creative' => json_encode(['creative_id' => $creative['id']]),
            'status' => 'PAUSED',
        ], returnId: true);

        if (! $ad['success']) {
            return $this->rollbackBoost($ids, $ad['error']);
        }
        $ids['ad_id'] = $ad['id'];

        return ['success' => true, 'error' => null, 'ids' => $ids];
    }

    /**
     * Supprime ce qui a ete cree avant l'echec.
     *
     * Sans ca, un boost interrompu au 3e appel laisse une campagne et un ad set
     * orphelins dans le compte, qu'aucun ecran RS-Max ne montre. Supprimer la
     * campagne emporte ses enfants.
     *
     * @param  array<string,?string>  $ids
     * @return array{success:bool,error:?string,ids:array<string,?string>}
     */
    private function rollbackBoost(array $ids, ?string $error): array
    {
        if ($ids['campaign_id']) {
            $deleted = $this->post($ids['campaign_id'], ['status' => 'DELETED']);

            if (! $deleted['success']) {
                Log::error('MetaAdsService: rollback du boost impossible, campagne orpheline', [
                    'campaign_id' => $ids['campaign_id'],
                    'error' => $deleted['error'],
                ]);

                $error .= ' (attention : campagne '.$ids['campaign_id'].' laissée en place, à supprimer à la main)';
            }
        }

        return ['success' => false, 'error' => $error, 'ids' => $ids];
    }

    /**
     * Valide un appel de creation sans rien creer (execution_options).
     *
     * Sert au dry-run : Meta verifie la publication, les droits et le budget,
     * et rend l'erreur exacte — donc un plan qui ment beaucoup moins qu'une
     * simulation faite de notre cote.
     *
     * @param  array<string,mixed>  $payload
     * @return array{success:bool,error:?string}
     */
    public function validateOnly(string $path, array $payload): array
    {
        return $this->post($path, $payload + ['execution_options' => json_encode(['validate_only'])]);
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
    private function post(string $path, array $payload, bool $returnId = false): array
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

            return ['success' => true, 'error' => null] + ($returnId ? ['id' => $response->json('id')] : []);
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
        // error_user_msg est le message lisible (et traduit) de Meta ; `message`
        // se reduit souvent a « Invalid parameter », qui n'aide personne.
        $message = $error['error_user_msg'] ?? $error['message'] ?? 'Erreur inconnue';

        // 190/460 = le token utilisateur est mort avec la session (changement de
        // mot de passe). C'est precisement ce qu'un token systeme evite.
        if (($error['code'] ?? null) === 190) {
            $message .= ' — jeton invalide : utiliser un token « utilisateur système » du portefeuille Business, il survit aux changements de mot de passe.';
        }

        // 1885557 : publication introuvable OU Page inaccessible au JETON. Le
        // second cas est de loin le plus frequent et Meta ne les distingue pas.
        // Piege : attribuer la Page a l'utilisateur systeme ne suffit PAS — un
        // jeton n'exerce que les scopes coches a sa generation. Un jeton
        // `ads_read,ads_management` ne verra jamais une Page, meme attribuee ;
        // il faut le REGENERER avec les scopes Pages.
        if (($error['error_subcode'] ?? null) === 1885557) {
            $message .= ' — le plus souvent le jeton ne porte pas les scopes Pages :'
                .' vérifier `pages_show_list` et `pages_manage_ads` via /debug_token.'
                .' Attribuer la Page à l\'utilisateur système ne suffit pas, il faut REGENERER le jeton'
                .' (les scopes sont figés à sa création). Diagnostic : GET /me/accounts doit renvoyer la Page.';
        }

        return $message;
    }
}
