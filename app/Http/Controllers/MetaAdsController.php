<?php

namespace App\Http\Controllers;

use App\Models\MetaAdsActionLog;
use App\Models\MetaAdsBoost;
use App\Models\MetaAudience;
use App\Models\PostPlatform;
use App\Services\Meta\MetaAdsGuard;
use App\Services\Meta\MetaAdsService;
use App\Services\Meta\MetaBoostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Pilotage des campagnes Meta depuis l'interface.
 *
 * Même moteur et mêmes garde-fous que l'API (`MetaAdsGuard`, `MetaBoostService`) :
 * un écran qui contournerait les plafonds serait une porte dérobée. La seule
 * différence est le rendu — et le fait qu'ici, un humain regarde.
 */
class MetaAdsController extends Controller
{
    public function __construct(
        private readonly MetaAdsService $ads,
        private readonly MetaAdsGuard $guard,
        private readonly MetaBoostService $boosts,
    ) {}

    /**
     * Toutes les campagnes du compte, avec leurs performances.
     */
    public function index(Request $request): View
    {
        $campaigns = [];
        $error = null;
        $insights = [];

        if ($this->ads->isConfigured()) {
            $result = $this->ads->campaigns($request->boolean('active'));
            $campaigns = $result['campaigns'];
            $error = $result['error'];

            $rows = $this->ads->insights('campaign', $request->integer('days') ?: null);
            // Indexé par campagne : la vue en a besoin ligne par ligne, pas en vrac.
            foreach ($rows['rows'] as $row) {
                $insights[$row['campaign_id'] ?? ''] = $row;
            }
        }

        return view('ads.index', [
            'campaigns' => $campaigns,
            'insights' => $insights,
            'error' => $error,
            'account' => $this->ads->isConfigured() ? $this->ads->accountInfo() : null,
            'writeEnabled' => (bool) config('meta_ads.write_enabled'),
            'days' => $request->integer('days') ?: (int) config('meta_ads.default_insights_days'),
            'recentBoosts' => MetaAdsBoost::with('postPlatform.platform')->latest()->limit(10)->get(),
            'logs' => MetaAdsActionLog::with('user:id,name')->latest()->limit(15)->get(),
        ]);
    }

    /**
     * Une campagne : ses ad sets, son ciblage, ses chiffres.
     */
    public function campaign(Request $request, string $campaign): View
    {
        $object = $this->ads->object($campaign);
        $adSets = $this->ads->adSets($campaign);
        $insights = $this->ads->insights('adset', $request->integer('days') ?: null, $campaign);

        return view('ads.campaign', [
            'campaign' => $object['object'],
            'error' => $object['error'],
            'adSets' => $adSets['adsets'],
            'insights' => collect($insights['rows'])->keyBy('adset_id'),
            'days' => $insights['days'],
            'audiences' => MetaAudience::orderByDesc('is_default')->orderBy('name')->get(),
            'writeEnabled' => (bool) config('meta_ads.write_enabled'),
            'boost' => MetaAdsBoost::with('postPlatform.post')->where('campaign_id', $campaign)->latest()->first(),
        ]);
    }

    /**
     * Formulaire de sponsorisation d'une publication déjà publiée.
     */
    public function boostForm(PostPlatform $postPlatform): View
    {
        $postPlatform->load(['platform', 'socialAccount', 'post']);

        return view('ads.boost', [
            'pp' => $postPlatform,
            'reference' => $this->boosts->reference($postPlatform),
            'publishable' => $postPlatform->status === 'published' && $postPlatform->external_id,
            'objectives' => MetaAdsService::objectives(boostableOnly: true),
            'audiences' => MetaAudience::orderByDesc('is_default')->orderBy('name')->get(),
            'account' => $this->ads->isConfigured() ? $this->ads->accountInfo() : null,
            'writeEnabled' => (bool) config('meta_ads.write_enabled'),
            'existing' => MetaAdsBoost::where('post_platform_id', $postPlatform->id)->latest()->get(),
        ]);
    }

    /**
     * Simule ou crée la campagne.
     *
     * `dry_run` reste vrai par défaut, y compris ici : le bouton « Simuler »
     * est celui qui est mis en avant, et Meta valide alors la publication et
     * les droits pour de vrai, sans rien créer.
     */
    public function boost(Request $request, PostPlatform $postPlatform): RedirectResponse|JsonResponse
    {
        if ($refusal = $this->guard->write($request->user())) {
            return $this->refuse($request, $refusal);
        }

        $validated = $request->validate([
            'name' => 'nullable|string|max:120',
            'objective' => 'required|string|max:64',
            'optimization_goal' => 'required|string|max:64',
            'billing_event' => 'nullable|string|max:64',
            'special_ad_categories' => 'nullable|array',
            'special_ad_categories.*' => ['string', Rule::in(array_keys(config('meta_ads.special_ad_categories')))],
            'audience_id' => 'nullable|integer|exists:meta_audiences,id',
            'budget' => 'required|numeric|min:1',
            'budget_type' => ['required', Rule::in(['lifetime', 'daily'])],
            'days' => 'required|integer|min:1|max:'.(int) config('meta_ads.max_days'),
            'start_date' => 'nullable|date',
            'bid_strategy' => ['nullable', Rule::in(array_keys(config('meta_ads.bid_strategies')))],
            'bid_amount' => 'nullable|numeric|min:0.01',
            'dry_run' => 'nullable|boolean',
            'force' => 'nullable|boolean',
        ]);

        $postPlatform->load(['platform', 'socialAccount', 'post']);

        if ($postPlatform->status !== 'published' || ! $postPlatform->external_id) {
            return $this->refuse($request, [
                'status' => 422,
                'error' => 'Cette diffusion n\'est pas publiée : il n\'y a pas de publication à sponsoriser.',
            ]);
        }

        $spec = $this->boosts->spec($postPlatform, $validated);

        if (isset($spec['error'])) {
            return $this->refuse($request, ['status' => 422, 'error' => $spec['error']]);
        }

        $refusal = $this->guard->budget(
            $this->boosts->dailyEquivalent($spec),
            null,
            false,
            $request->boolean('force'),
        );

        if ($refusal) {
            return $this->refuse($request, $refusal);
        }

        $dryRun = ! $request->has('dry_run') || $request->boolean('dry_run');
        $plan = $this->boosts->plan($postPlatform, $spec);
        $warnings = $this->boosts->warnings($spec);

        if ($dryRun) {
            $check = $this->boosts->validate($spec);
            $estimate = $this->boosts->estimate($spec);

            return response()->json([
                'dry_run' => true,
                'promotable' => $check['success'],
                'error' => $check['error'],
                'plan' => $plan,
                'warnings' => $warnings,
                'audience_estimate' => ['lower' => $estimate['lower'], 'upper' => $estimate['upper']],
            ]);
        }

        $result = $this->boosts->create($spec, $postPlatform, $request->user());

        if (! $result['success']) {
            return $this->refuse($request, ['status' => 502, 'error' => $result['error']]);
        }

        return $request->expectsJson()
            ? response()->json([
                'created' => true,
                'redirect' => route('ads.campaign', $result['ids']['campaign_id']),
                'ids' => $result['ids'],
            ], 201)
            : redirect()->route('ads.campaign', $result['ids']['campaign_id'])->with('status', 'boost-created');
    }

    /**
     * Met en pause ou lance une campagne / un ad set.
     *
     * C'est ce geste — pas la création — qui commence à dépenser.
     */
    public function updateStatus(Request $request, string $object): RedirectResponse
    {
        if ($refusal = $this->guard->write($request->user())) {
            return back()->with('error', $refusal['error']);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(config('meta_ads.allowed_statuses'))],
            'object_type' => ['nullable', Rule::in(['campaign', 'adset'])],
        ]);

        $current = $this->ads->object($object);
        $result = $this->ads->updateStatus($object, $validated['status']);

        MetaAdsActionLog::create([
            'user_id' => $request->user()->id,
            'object_type' => $validated['object_type'] ?? 'campaign',
            'object_id' => $object,
            'object_name' => $current['object']['name'] ?? null,
            'action' => 'status',
            'previous' => ['status' => $current['object']['status'] ?? null],
            'requested' => ['status' => $validated['status']],
            'dry_run' => false,
            'success' => $result['success'],
            'error' => $result['error'],
        ]);

        // Le boost suit le statut de sa campagne, sinon la liste des
        // sponsorisations dirait « en pause » sur une campagne qui diffuse.
        MetaAdsBoost::where('campaign_id', $object)->orWhere('adset_id', $object)
            ->update(['status' => $validated['status'] === 'ACTIVE' ? 'active' : 'paused']);

        return $result['success']
            ? back()->with('status', $validated['status'] === 'ACTIVE' ? 'campaign-activated' : 'campaign-paused')
            : back()->with('error', $result['error']);
    }

    /**
     * Change le budget — via le même plafond que l'API.
     */
    public function updateBudget(Request $request, string $object): RedirectResponse
    {
        if ($refusal = $this->guard->write($request->user())) {
            return back()->with('error', $refusal['error']);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
            'lifetime' => 'nullable|boolean',
            'force' => 'nullable|boolean',
            'object_type' => ['nullable', Rule::in(['campaign', 'adset'])],
        ]);

        $lifetime = $request->boolean('lifetime');
        $current = $this->ads->object($object);
        $field = $lifetime ? 'lifetime_budget' : 'daily_budget';

        $refusal = $this->guard->budget(
            (float) $validated['amount'],
            $current['object'][$field] ?? null,
            $lifetime,
            $request->boolean('force'),
        );

        if ($refusal) {
            return back()->with('error', $refusal['error'].' '.($refusal['hint'] ?? ''));
        }

        $result = $this->ads->updateBudget($object, (float) $validated['amount'], $lifetime);

        MetaAdsActionLog::create([
            'user_id' => $request->user()->id,
            'object_type' => $validated['object_type'] ?? 'adset',
            'object_id' => $object,
            'object_name' => $current['object']['name'] ?? null,
            'action' => 'budget',
            'previous' => [$field => $current['object'][$field] ?? null],
            'requested' => [$field => (float) $validated['amount']],
            'dry_run' => false,
            'success' => $result['success'],
            'error' => $result['error'],
        ]);

        return $result['success']
            ? back()->with('status', 'budget-updated')
            : back()->with('error', $result['error']);
    }

    /**
     * Change le ciblage et/ou les dates d'un ad set déjà créé.
     *
     * C'est ce qui permet de corriger une campagne au lieu d'en relancer une :
     * une audience qui ne marche pas se remplace, elle ne se jette pas.
     */
    public function updateAdSet(Request $request, string $object): RedirectResponse
    {
        if ($refusal = $this->guard->write($request->user())) {
            return back()->with('error', $refusal['error']);
        }

        $validated = $request->validate([
            'audience_id' => 'nullable|integer|exists:meta_audiences,id',
            'start_time' => 'nullable|date',
            'end_time' => 'nullable|date|after:start_time',
        ]);

        $fields = array_filter([
            'start_time' => $validated['start_time'] ?? null,
            'end_time' => $validated['end_time'] ?? null,
        ]);

        if (! empty($validated['audience_id'])) {
            $fields['targeting'] = MetaAudience::findOrFail($validated['audience_id'])->toTargetingSpec();
        }

        if ($fields === []) {
            return back()->with('error', 'Rien à modifier.');
        }

        $current = $this->ads->object($object);
        $result = $this->ads->updateAdSet($object, $fields);

        MetaAdsActionLog::create([
            'user_id' => $request->user()->id,
            'object_type' => 'adset',
            'object_id' => $object,
            'object_name' => $current['object']['name'] ?? null,
            'action' => 'adset',
            'previous' => ['name' => $current['object']['name'] ?? null],
            'requested' => $fields,
            'dry_run' => false,
            'success' => $result['success'],
            'error' => $result['error'],
        ]);

        return $result['success']
            ? back()->with('status', 'adset-updated')
            : back()->with('error', $result['error']);
    }

    /**
     * @param  array<string,mixed>  $refusal
     */
    private function refuse(Request $request, array $refusal): RedirectResponse|JsonResponse
    {
        return $request->expectsJson()
            ? response()->json(collect($refusal)->except('status')->all(), $refusal['status'])
            : back()->with('error', $refusal['error']);
    }
}
