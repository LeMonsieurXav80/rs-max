<?php

namespace App\Http\Controllers;

use App\Models\MetaAudience;
use App\Services\Meta\MetaAdsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Audiences réutilisables, côté interface.
 *
 * Meta enterre le ciblage dans l'ad set : il meurt avec lui. On garde donc la
 * définition ici, et on la déroule en `targeting` à chaque campagne — c'est ce
 * qui permet de dire « la même audience que la dernière fois » sans la refaire
 * de mémoire.
 *
 * Enregistrer une audience ne dépense rien : ce chemin n'est pas soumis à
 * `META_ADS_WRITE_ENABLED`, contrairement à tout ce qui crée une campagne.
 */
class MetaAudienceController extends Controller
{
    public function __construct(private readonly MetaAdsService $ads) {}

    public function index(): View
    {
        return view('ads.audiences.index', [
            'audiences' => MetaAudience::with('user:id,name')
                ->orderByDesc('is_default')->orderBy('name')->get(),
            'configured' => $this->ads->isConfigured(),
        ]);
    }

    public function create(): View
    {
        return view('ads.audiences.form', [
            'audience' => new MetaAudience(['spec' => config('meta_ads.default_targeting')]),
            'customAudiences' => $this->customAudienceOptions(),
        ]);
    }

    public function edit(MetaAudience $audience): View
    {
        return view('ads.audiences.form', [
            'audience' => $audience,
            'customAudiences' => $this->customAudienceOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $audience = MetaAudience::create($this->validated($request) + ['user_id' => $request->user()->id]);

        if ($request->boolean('is_default')) {
            $audience->markAsDefault();
        }

        $this->refreshEstimate($audience);

        return redirect()->route('ads.audiences.index')->with('status', 'audience-created');
    }

    public function update(Request $request, MetaAudience $audience): RedirectResponse
    {
        $audience->update($this->validated($request));

        if ($request->boolean('is_default')) {
            $audience->markAsDefault();
        }

        $this->refreshEstimate($audience);

        return redirect()->route('ads.audiences.index')->with('status', 'audience-updated');
    }

    /**
     * Supprimer une audience ne touche à aucune campagne en cours : son ciblage
     * a été copié dans l'ad set à la création.
     */
    public function destroy(MetaAudience $audience): RedirectResponse
    {
        $audience->delete();

        return redirect()->route('ads.audiences.index')->with('status', 'audience-deleted');
    }

    /**
     * Recherche de ciblage pour le formulaire (session web, pas de jeton).
     *
     * Même source que l'API : les identifiants viennent de Meta, jamais d'une
     * saisie libre — un centre d'intérêt inventé donne une campagne qui ne
     * touche personne, sans la moindre erreur pour le dire.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|string|in:interest,behavior,geo,locale',
            'q' => 'nullable|string|max:100',
        ]);

        $result = $this->ads->searchTargeting($validated['type'], $validated['q'] ?? '');

        return response()->json([
            'results' => $result['results'],
            'error' => $result['error'],
        ], $result['success'] ? 200 : 502);
    }

    /**
     * Estimation de portée, appelée en direct depuis le formulaire.
     */
    public function estimate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'spec' => 'required|array',
            'optimization_goal' => 'nullable|string|max:64',
        ]);

        $targeting = (new MetaAudience(['spec' => $validated['spec']]))->toTargetingSpec();
        $result = $this->ads->reachEstimate($targeting, $validated['optimization_goal'] ?? 'POST_ENGAGEMENT');

        return response()->json([
            'lower' => $result['lower'],
            'upper' => $result['upper'],
            'error' => $result['error'],
        ], $result['success'] ? 200 : 502);
    }

    /**
     * @return array<string,mixed>
     */
    private function validated(Request $request): array
    {
        $placements = config('meta_ads.placements');

        // Les listes riches (intérêts, lieux, langues…) arrivent en JSON : Alpine
        // les tient en mémoire et un champ caché les transporte. Il faut les
        // décoder AVANT de valider, sinon `array` échoue sur une chaîne.
        $request->merge(['spec' => $this->decodeSpec((array) $request->input('spec', []))]);

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:1000',
            'spec' => 'required|array',
            'spec.countries' => 'nullable|array',
            'spec.excluded_countries' => 'nullable|array',
            'spec.cities' => 'nullable|array',
            'spec.regions' => 'nullable|array',
            'spec.age_min' => 'nullable|integer|min:13|max:65',
            'spec.age_max' => 'nullable|integer|min:13|max:65|gte:spec.age_min',
            'spec.genders' => 'nullable|array',
            'spec.locales' => 'nullable|array',
            'spec.interests' => 'nullable|array',
            'spec.behaviors' => 'nullable|array',
            'spec.excluded_interests' => 'nullable|array',
            'spec.custom_audiences' => 'nullable|array',
            'spec.excluded_custom_audiences' => 'nullable|array',
            'spec.publisher_platforms' => 'nullable|array',
            'spec.publisher_platforms.*' => 'string|in:'.implode(',', array_keys($placements['publisher_platforms'])),
            'spec.facebook_positions' => 'nullable|array',
            'spec.instagram_positions' => 'nullable|array',
            'spec.device_platforms' => 'nullable|array',
            'spec.advantage_audience' => 'nullable|boolean',
        ]);

        return $data;
    }

    /**
     * @param  array<string,mixed>  $spec
     * @return array<string,mixed>
     */
    private function decodeSpec(array $spec): array
    {
        foreach (['interests', 'behaviors', 'excluded_interests', 'cities', 'regions', 'locales', 'custom_audiences', 'excluded_custom_audiences'] as $key) {
            if (is_string($spec[$key] ?? null)) {
                $spec[$key] = json_decode($spec[$key], true) ?: [];
            }
        }

        return $spec;
    }

    /**
     * Best-effort : une audience s'enregistre même sans compte publicitaire
     * configuré. L'estimation est un confort, pas la donnée.
     */
    private function refreshEstimate(MetaAudience $audience): void
    {
        if (! $this->ads->isConfigured()) {
            return;
        }

        $estimate = $this->ads->reachEstimate($audience->toTargetingSpec());

        if ($estimate['success'] && $estimate['upper'] !== null) {
            $audience->forceFill(['estimated_reach' => $estimate['upper'], 'estimated_at' => now()])->save();
        }
    }

    /**
     * Audiences personnalisées du compte — RS-Max n'en crée pas (données
     * personnelles), il sait les réutiliser.
     *
     * @return array<int,array<string,mixed>>
     */
    private function customAudienceOptions(): array
    {
        return $this->ads->isConfigured() ? $this->ads->customAudiences()['audiences'] : [];
    }
}
