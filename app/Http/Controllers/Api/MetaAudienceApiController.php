<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MetaAudience;
use App\Services\Meta\MetaAdsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Audiences réutilisables pour le ciblage Meta.
 *
 * Ce ne sont pas des objets Meta : ils vivent chez RS-Max et se déroulent en
 * `targeting` au moment de créer l'ad set. Meta, lui, enterre le ciblage dans
 * l'ad set — refaire la même audience à chaque campagne, c'est refaire les
 * mêmes erreurs.
 *
 * Écriture réservée aux managers, comme tout ce qui touche à la publicité,
 * mais **sans `META_ADS_WRITE_ENABLED`** : enregistrer une audience ne dépense
 * rien. C'est l'utiliser dans une campagne qui coûte, et ce chemin-là est gardé.
 */
class MetaAudienceApiController extends Controller
{
    public function __construct(private readonly MetaAdsService $ads) {}

    public function index(): JsonResponse
    {
        $audiences = MetaAudience::orderByDesc('is_default')->orderBy('name')->get()
            ->map(fn ($a) => $this->format($a));

        return response()->json(['audiences' => $audiences]);
    }

    public function show(MetaAudience $audience): JsonResponse
    {
        return response()->json(['audience' => $this->format($audience, withSpec: true)]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($refusal = $this->guard($request)) {
            return $refusal;
        }

        $audience = MetaAudience::create($this->validated($request) + ['user_id' => $request->user()->id]);

        if ($request->boolean('is_default')) {
            $audience->markAsDefault();
        }

        $this->refreshEstimate($audience);

        return response()->json(['audience' => $this->format($audience, withSpec: true)], 201);
    }

    public function update(Request $request, MetaAudience $audience): JsonResponse
    {
        if ($refusal = $this->guard($request)) {
            return $refusal;
        }

        $audience->update($this->validated($request));

        if ($request->boolean('is_default')) {
            $audience->markAsDefault();
        }

        $this->refreshEstimate($audience);

        return response()->json(['audience' => $this->format($audience->fresh(), withSpec: true)]);
    }

    /**
     * Supprimer une audience ne touche à aucune campagne : le ciblage a été
     * copié dans l'ad set à la création, et dans `meta_ads_boosts.targeting`.
     */
    public function destroy(Request $request, MetaAudience $audience): JsonResponse
    {
        if ($refusal = $this->guard($request)) {
            return $refusal;
        }

        $audience->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Rafraîchit l'ordre de grandeur de la portée.
     *
     * Best-effort : une audience s'enregistre même si le compte publicitaire
     * n'est pas configuré ou si Graph tousse — l'estimation est un confort,
     * pas la donnée.
     */
    private function refreshEstimate(MetaAudience $audience): void
    {
        if (! $this->ads->isConfigured()) {
            return;
        }

        $estimate = $this->ads->reachEstimate($audience->toTargetingSpec());

        if ($estimate['success'] && $estimate['upper'] !== null) {
            $audience->forceFill([
                'estimated_reach' => $estimate['upper'],
                'estimated_at' => now(),
            ])->save();
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function format(MetaAudience $audience, bool $withSpec = false): array
    {
        return array_filter([
            'id' => $audience->id,
            'name' => $audience->name,
            'description' => $audience->description,
            'summary' => $audience->summary(),
            'is_default' => $audience->is_default,
            'estimated_reach' => $audience->estimated_reach,
            'estimated_at' => $audience->estimated_at?->toIso8601String(),
            'spec' => $withSpec ? $audience->spec : null,
            'targeting' => $withSpec ? $audience->toTargetingSpec() : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * @return array<string,mixed>
     */
    private function validated(Request $request): array
    {
        $placements = config('meta_ads.placements');

        return $request->validate([
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:1000',
            'spec' => 'required|array',
            'spec.countries' => 'nullable|array',
            'spec.countries.*' => 'string|size:2',
            'spec.excluded_countries' => 'nullable|array',
            'spec.excluded_countries.*' => 'string|size:2',
            'spec.regions' => 'nullable|array',
            'spec.cities' => 'nullable|array',
            'spec.age_min' => 'nullable|integer|min:13|max:65',
            'spec.age_max' => 'nullable|integer|min:13|max:65|gte:spec.age_min',
            'spec.genders' => 'nullable|array',
            'spec.genders.*' => 'integer|in:1,2',
            'spec.locales' => 'nullable|array',
            'spec.interests' => 'nullable|array',
            'spec.behaviors' => 'nullable|array',
            'spec.excluded_interests' => 'nullable|array',
            'spec.custom_audiences' => 'nullable|array',
            'spec.excluded_custom_audiences' => 'nullable|array',
            'spec.publisher_platforms' => 'nullable|array',
            'spec.publisher_platforms.*' => 'string|in:'.implode(',', array_keys($placements['publisher_platforms'])),
            'spec.facebook_positions' => 'nullable|array',
            'spec.facebook_positions.*' => 'string|in:'.implode(',', array_keys($placements['facebook_positions'])),
            'spec.instagram_positions' => 'nullable|array',
            'spec.instagram_positions.*' => 'string|in:'.implode(',', array_keys($placements['instagram_positions'])),
            'spec.device_platforms' => 'nullable|array',
            'spec.device_platforms.*' => 'string|in:mobile,desktop',
            'spec.advantage_audience' => 'nullable|boolean',
        ]);
    }

    private function guard(Request $request): ?JsonResponse
    {
        return $request->user()?->isManager()
            ? null
            : response()->json(['error' => 'Réservé aux managers.'], 403);
    }
}
