<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MetaAdsActionLog;
use App\Models\MetaAdsBoost;
use App\Models\MetaAudience;
use App\Models\PostPlatform;
use App\Services\Meta\MetaAdsGuard;
use App\Services\Meta\MetaAdsService;
use App\Services\Meta\MetaBoostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pilotage des campagnes Meta, pensé pour être appelé par une IA.
 *
 * La lecture est ouverte ; l'écriture est bardée de garde-fous parce qu'elle
 * engage de l'argent réel et qu'aucun humain ne relit forcément l'appel :
 *
 *   1. `config('meta_ads.write_enabled')` — interrupteur général, false par défaut ;
 *   2. `dry_run` à **true par défaut** : sans le dire explicitement, on obtient
 *      le plan, pas l'exécution (même convention que /partners/{p}/media/detach) ;
 *   3. plafond de hausse de budget, contournable par `force` ;
 *   4. plafond absolu de budget quotidien, que `force` NE contourne PAS ;
 *   5. journal d'audit de tout, dry-run compris.
 *
 * Ce qui reste hors périmètre à dessein : créer une campagne **à partir de rien**
 * et en supprimer une. Tout ce qui se crée ici part d'une publication RS-Max
 * déjà publiée, en PAUSE, avec un objectif et un ciblage explicites.
 */
class MetaAdsApiController extends Controller
{
    public function __construct(
        private readonly MetaAdsService $ads,
        private readonly MetaAdsGuard $guard,
        private readonly MetaBoostService $boosts,
    ) {}

    // ── Lecture ──────────────────────────────────────────────

    /**
     * Catalogue des objectifs et des réglages disponibles.
     *
     * Un agent qui doit choisir un objectif ne peut pas le deviner : les couples
     * objectif / optimisation valides sont ici, en clair, plutôt que découverts
     * à coups de « Invalid parameter ».
     */
    public function objectives(Request $request): JsonResponse
    {
        return response()->json([
            'objectives' => MetaAdsService::objectives($request->boolean('boostable', true)),
            'billing_events' => config('meta_ads.billing_events'),
            'bid_strategies' => config('meta_ads.bid_strategies'),
            'special_ad_categories' => config('meta_ads.special_ad_categories'),
            'placements' => config('meta_ads.placements'),
            'limits' => [
                'max_daily_budget' => $this->ads->maxDailyBudget(),
                'max_budget_increase_pct' => $this->ads->maxBudgetIncreasePct(),
                'max_days' => (int) config('meta_ads.max_days'),
            ],
        ]);
    }

    /**
     * Recherche une entrée de ciblage chez Meta.
     *
     * Les identifiants de ciblage ne s'inventent pas : un centre d'intérêt
     * fabriqué de toutes pièces donne une campagne qui ne touche personne.
     */
    public function searchTargeting(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['interest', 'behavior', 'geo', 'locale'])],
            'q' => 'nullable|string|max:100',
        ]);

        $result = $this->ads->searchTargeting($validated['type'], $validated['q'] ?? '');

        return $result['success']
            ? response()->json(['results' => $result['results']])
            : response()->json(['error' => $result['error']], 502);
    }

    /**
     * Audiences personnalisées du compte (RS-Max n'en crée pas, il les réutilise).
     */
    public function customAudiences(): JsonResponse
    {
        $result = $this->ads->customAudiences();

        return $result['success']
            ? response()->json(['audiences' => $result['audiences']])
            : response()->json(['error' => $result['error']], 502);
    }

    /**
     * Taille estimée d'un ciblage — à lire avant de dépenser.
     *
     * Une audience trop étroite ne renvoie pas d'erreur : elle ne diffuse pas,
     * et les insights ne diront jamais pourquoi.
     */
    public function estimate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'audience_id' => 'nullable|integer|exists:meta_audiences,id',
            'targeting' => 'nullable|array',
            'optimization_goal' => 'nullable|string|max:64',
        ]);

        $targeting = ! empty($validated['audience_id'])
            ? MetaAudience::findOrFail($validated['audience_id'])->toTargetingSpec()
            : ($validated['targeting'] ?? null);

        if (! $targeting) {
            return response()->json(['error' => 'Fournir `audience_id` ou `targeting`.'], 422);
        }

        $result = $this->ads->reachEstimate($targeting, $validated['optimization_goal'] ?? 'POST_ENGAGEMENT');

        return $result['success']
            ? response()->json(['lower' => $result['lower'], 'upper' => $result['upper'], 'targeting' => $targeting])
            : response()->json(['error' => $result['error']], 502);
    }

    public function campaigns(Request $request): JsonResponse
    {
        $result = $this->ads->campaigns($request->boolean('active'));

        return $result['success']
            ? response()->json(['campaigns' => $result['campaigns']])
            : response()->json(['error' => $result['error']], 502);
    }

    /**
     * Une campagne, ses ad sets et ses performances — de quoi décider en un appel.
     */
    public function campaign(Request $request, string $campaign): JsonResponse
    {
        $object = $this->ads->object($campaign);

        if (! $object['success']) {
            return response()->json(['error' => $object['error']], 502);
        }

        $adSets = $this->ads->adSets($campaign);
        $insights = $this->ads->insights('adset', $request->integer('days') ?: null, $campaign);

        return response()->json([
            'campaign' => $object['object'],
            'adsets' => $adSets['adsets'],
            'insights' => [
                'days' => $insights['days'],
                'rows' => $insights['rows'],
                'error' => $insights['error'],
            ],
        ]);
    }

    public function insights(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'level' => ['nullable', Rule::in(['account', 'campaign', 'adset', 'ad'])],
            'days' => 'nullable|integer|min:1|max:365',
            'object_id' => 'nullable|string|max:64',
        ]);

        $result = $this->ads->insights(
            $validated['level'] ?? 'campaign',
            $validated['days'] ?? null,
            $validated['object_id'] ?? null,
        );

        return $result['success']
            ? response()->json(['days' => $result['days'], 'rows' => $result['rows']])
            : response()->json(['error' => $result['error']], 502);
    }

    /**
     * Journal des gestes déjà passés — indispensable pour qu'une IA sache ce
     * qu'elle a déjà tenté, plutôt que de reproposer la même chose en boucle.
     */
    public function logs(Request $request): JsonResponse
    {
        $logs = MetaAdsActionLog::with('user:id,name')
            ->latest()
            ->limit(min((int) $request->input('limit', 50), 200))
            ->get();

        return response()->json(['logs' => $logs]);
    }

    // ── Écriture ─────────────────────────────────────────────

    public function updateStatus(Request $request, string $object): JsonResponse
    {
        if ($refusal = $this->guardWrite($request)) {
            return $refusal;
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(config('meta_ads.allowed_statuses'))],
            'dry_run' => 'nullable|boolean',
            'object_type' => ['nullable', Rule::in(['campaign', 'adset'])],
        ]);

        $current = $this->ads->object($object);

        if (! $current['success']) {
            return response()->json(['error' => $current['error']], 502);
        }

        $dryRun = $this->dryRun($request);
        $previous = $current['object'];

        // Rien à faire : le dire plutôt que de consommer un appel d'écriture.
        if (($previous['status'] ?? null) === $validated['status']) {
            return response()->json([
                'dry_run' => $dryRun,
                'applied' => false,
                'reason' => 'Le statut est déjà '.$validated['status'].'.',
                'object' => $previous,
            ]);
        }

        return $this->execute(
            request: $request,
            objectType: $validated['object_type'] ?? 'campaign',
            objectId: $object,
            objectName: $previous['name'] ?? null,
            action: 'status',
            previous: ['status' => $previous['status'] ?? null],
            requested: ['status' => $validated['status']],
            dryRun: $dryRun,
            apply: fn () => $this->ads->updateStatus($object, $validated['status']),
        );
    }

    public function updateBudget(Request $request, string $object): JsonResponse
    {
        if ($refusal = $this->guardWrite($request)) {
            return $refusal;
        }

        $validated = $request->validate([
            // Montant dans la devise du compte (25.50), pas en centimes :
            // la conversion est faite par le service, une seule fois.
            'amount' => 'required|numeric|min:1',
            'lifetime' => 'nullable|boolean',
            'dry_run' => 'nullable|boolean',
            'force' => 'nullable|boolean',
            'object_type' => ['nullable', Rule::in(['campaign', 'adset'])],
        ]);

        $current = $this->ads->object($object);

        if (! $current['success']) {
            return response()->json(['error' => $current['error']], 502);
        }

        $previous = $current['object'];
        $lifetime = (bool) ($validated['lifetime'] ?? false);
        $field = $lifetime ? 'lifetime_budget' : 'daily_budget';
        $currentAmount = $previous[$field] ?? null;
        $amount = (float) $validated['amount'];

        if ($refusal = $this->guardBudget($amount, $currentAmount, $lifetime, $request->boolean('force'))) {
            return $refusal;
        }

        return $this->execute(
            request: $request,
            objectType: $validated['object_type'] ?? 'campaign',
            objectId: $object,
            objectName: $previous['name'] ?? null,
            action: 'budget',
            previous: [$field => $currentAmount],
            requested: [$field => $amount],
            dryRun: $this->dryRun($request),
            apply: fn () => $this->ads->updateBudget($object, $amount, $lifetime),
        );
    }

    /**
     * Sponsorise une publication RS-Max deja publiee, sans la republier.
     *
     * L'agent designe un `post_platform_id` — une publication RS-Max sur un
     * compte donne — et non un identifiant Meta brut : c'est tout l'interet de
     * passer par RS-Max, qui connait deja la Page et l'id du post.
     *
     * La structure est creee en PAUSED. L'activation se fait ensuite par
     * POST /api/meta-ads/{adset_id}/status, qui repasse par les garde-fous et
     * le journal : monter la campagne ne coute rien, l'activer depense.
     */
    public function boost(Request $request): JsonResponse
    {
        if ($refusal = $this->guardWrite($request)) {
            return $refusal;
        }

        $validated = $request->validate([
            'post_platform_id' => 'required|integer|exists:post_platform,id',
            'name' => 'nullable|string|max:120',
            // Objectif et optimisation : le couple est vérifié par le service,
            // le catalogue est sur GET /api/meta-ads/objectives.
            'objective' => 'nullable|string|max:64',
            'optimization_goal' => 'nullable|string|max:64',
            'billing_event' => 'nullable|string|max:64',
            'special_ad_categories' => 'nullable|array',
            'special_ad_categories.*' => ['string', Rule::in(array_keys(config('meta_ads.special_ad_categories')))],
            // Ciblage : une audience enregistrée, ou une spec Meta brute.
            'audience_id' => 'nullable|integer|exists:meta_audiences,id',
            'targeting' => 'nullable|array',
            // Budget : total par défaut (`lifetime`), ou quotidien si demandé.
            'budget' => 'required|numeric|min:1',
            'budget_type' => ['nullable', Rule::in(['lifetime', 'daily'])],
            'days' => 'required|integer|min:1|max:'.(int) config('meta_ads.max_days'),
            'start_date' => 'nullable|date',
            'bid_strategy' => ['nullable', Rule::in(array_keys(config('meta_ads.bid_strategies')))],
            'bid_amount' => 'nullable|numeric|min:0.01',
            'with_estimate' => 'nullable|boolean',
            'dry_run' => 'nullable|boolean',
            'force' => 'nullable|boolean',
        ]);

        $pp = PostPlatform::with(['platform', 'socialAccount', 'post'])->find($validated['post_platform_id']);

        if ($pp->status !== 'published' || ! $pp->external_id) {
            return response()->json([
                'error' => 'Cette diffusion n\'est pas publiée : il n\'y a pas de publication à sponsoriser.',
            ], 422);
        }

        $spec = $this->boosts->spec($pp, $validated);

        if (isset($spec['error'])) {
            return response()->json(['error' => $spec['error']], 422);
        }

        // On ramene au quotidien pour confronter au meme plafond que les autres
        // ecritures, sinon « 300 € sur 30 jours » passerait sous un plafond
        // pense par jour.
        $daily = $this->boosts->dailyEquivalent($spec);

        if ($refusal = $this->guardBudget($daily, null, false, $request->boolean('force'))) {
            return $refusal;
        }

        $dryRun = $this->dryRun($request);
        $plan = $this->boosts->plan($pp, $spec);
        $warnings = $this->boosts->warnings($spec);

        // En simulation, on demande a Meta de VALIDER le creatif sans rien creer :
        // ca teste la publication et les droits pour de vrai, la ou une
        // simulation maison se contenterait de dire « ça a l'air bon ».
        if ($dryRun) {
            $check = $this->boosts->validate($spec);
            $estimate = $request->boolean('with_estimate') ? $this->boosts->estimate($spec) : null;

            return response()->json(array_filter([
                'dry_run' => true,
                'applied' => false,
                'promotable' => $check['success'],
                'error' => $check['error'],
                'plan' => $plan,
                'warnings' => $warnings,
                'audience_estimate' => $estimate ? ['lower' => $estimate['lower'], 'upper' => $estimate['upper']] : null,
                'note' => $check['success']
                    ? 'Publication promouvable. Renvoyer avec `dry_run: false` pour créer la campagne (en pause).'
                    : 'Meta refuse de promouvoir cette publication — voir `error`.',
            ], fn ($v) => $v !== null));
        }

        $result = $this->boosts->create($spec, $pp, $request->user());

        if (! $result['success']) {
            return response()->json(['applied' => false, 'error' => $result['error'], 'boost_id' => $result['boost']->id], 502);
        }

        return response()->json([
            'dry_run' => false,
            'applied' => true,
            'boost_id' => $result['boost']->id,
            'ids' => $result['ids'],
            'plan' => $plan,
            'warnings' => $warnings,
            'note' => 'Campagne créée EN PAUSE. Pour lancer la diffusion : '
                .'POST /api/meta-ads/'.$result['ids']['adset_id'].'/status {"status":"ACTIVE","dry_run":false}',
        ], 201);
    }

    /**
     * Change le ciblage et/ou les dates d'un ad set existant.
     *
     * Le budget garde son endpoint dédié : c'est lui qui passe par les plafonds,
     * et le mélanger ici les contournerait.
     */
    public function updateAdSet(Request $request, string $object): JsonResponse
    {
        if ($refusal = $this->guardWrite($request)) {
            return $refusal;
        }

        $validated = $request->validate([
            'audience_id' => 'nullable|integer|exists:meta_audiences,id',
            'targeting' => 'nullable|array',
            'start_time' => 'nullable|date',
            'end_time' => 'nullable|date|after:start_time',
            'optimization_goal' => 'nullable|string|max:64',
            'billing_event' => 'nullable|string|max:64',
            'bid_strategy' => ['nullable', Rule::in(array_keys(config('meta_ads.bid_strategies')))],
            'bid_amount' => 'nullable|numeric|min:0.01',
            'dry_run' => 'nullable|boolean',
        ]);

        $fields = collect($validated)->except(['audience_id', 'dry_run'])->filter()->all();

        if (! empty($validated['audience_id'])) {
            $fields['targeting'] = MetaAudience::findOrFail($validated['audience_id'])->toTargetingSpec();
        }

        if ($fields === []) {
            return response()->json(['error' => 'Rien à modifier.'], 422);
        }

        $current = $this->ads->object($object);

        return $this->execute(
            request: $request,
            objectType: 'adset',
            objectId: $object,
            objectName: $current['object']['name'] ?? null,
            action: 'adset',
            previous: ['name' => $current['object']['name'] ?? null],
            requested: $fields,
            dryRun: $this->dryRun($request),
            apply: fn () => $this->ads->updateAdSet($object, $fields),
        );
    }

    /**
     * Boosts d'une publication, pour ajuster ou arreter ensuite.
     */
    public function boosts(Request $request): JsonResponse
    {
        $boosts = MetaAdsBoost::with('postPlatform.platform')
            ->when($request->filled('post_platform_id'), fn ($q) => $q->where('post_platform_id', $request->integer('post_platform_id')))
            ->latest()
            ->limit(min((int) $request->input('limit', 50), 200))
            ->get();

        return response()->json(['boosts' => $boosts]);
    }

    // ── Garde-fous ───────────────────────────────────────────

    /**
     * Les règles vivent dans `MetaAdsGuard`, partagées avec l'interface web :
     * deux implémentations, ce serait deux occasions d'oublier un plafond.
     */
    private function guardWrite(Request $request): ?JsonResponse
    {
        $refusal = $this->guard->write($request->user());

        return $refusal ? response()->json($this->body($refusal), $refusal['status']) : null;
    }

    private function guardBudget(float $amount, ?float $current, bool $lifetime, bool $force): ?JsonResponse
    {
        $refusal = $this->guard->budget($amount, $current, $lifetime, $force);

        return $refusal ? response()->json($this->body($refusal), $refusal['status']) : null;
    }

    /**
     * @param  array<string,mixed>  $refusal
     * @return array<string,mixed>
     */
    private function body(array $refusal): array
    {
        return collect($refusal)->except('status')->all();
    }

    /**
     * Défaut à true : une écriture non demandée explicitement n'a pas lieu.
     */
    private function dryRun(Request $request): bool
    {
        return $request->has('dry_run') ? $request->boolean('dry_run') : true;
    }

    /**
     * Applique (ou simule) le geste, et le journalise dans tous les cas.
     *
     * @param  array<string,mixed>  $previous
     * @param  array<string,mixed>  $requested
     * @param  callable():array{success:bool,error:?string}  $apply
     */
    private function execute(
        Request $request,
        string $objectType,
        string $objectId,
        ?string $objectName,
        string $action,
        array $previous,
        array $requested,
        bool $dryRun,
        callable $apply,
    ): JsonResponse {
        $outcome = $dryRun ? ['success' => true, 'error' => null] : $apply();

        MetaAdsActionLog::create([
            'user_id' => $request->user()->id,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'object_name' => $objectName,
            'action' => $action,
            'previous' => $previous,
            'requested' => $requested,
            'dry_run' => $dryRun,
            'success' => $outcome['success'],
            'error' => $outcome['error'],
        ]);

        if (! $outcome['success']) {
            return response()->json([
                'dry_run' => $dryRun,
                'applied' => false,
                'error' => $outcome['error'],
            ], 502);
        }

        return response()->json([
            'dry_run' => $dryRun,
            'applied' => ! $dryRun,
            'object_id' => $objectId,
            'object_name' => $objectName,
            'action' => $action,
            'previous' => $previous,
            'requested' => $requested,
            'note' => $dryRun
                ? 'Simulation : rien n\'a été envoyé à Meta. Renvoyer avec `dry_run: false` pour appliquer.'
                : null,
        ]);
    }
}
