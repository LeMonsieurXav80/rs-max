<?php

namespace App\Services\Meta;

use App\Models\MetaAdsBoost;
use App\Models\MetaAudience;
use App\Models\PostPlatform;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Monte une campagne de sponsorisation à partir d'une publication RS-Max.
 *
 * Sépare volontairement trois moments qu'il ne faut pas confondre :
 *   1. `spec()`    — traduire un choix humain (objectif, audience, budget,
 *                    durée) en paramètres Meta valides ;
 *   2. `validate()`— demander à **Meta** si ça passerait, sans rien créer ;
 *   3. `create()`  — créer, en PAUSE, et garder la trace de ce qui a été demandé.
 *
 * L'interface et l'API passent toutes deux par ici : c'est ce qui garantit
 * qu'un boost lancé depuis un écran obéit aux mêmes règles qu'un boost lancé
 * par un agent.
 */
class MetaBoostService
{
    public function __construct(private readonly MetaAdsService $ads) {}

    /**
     * Construit la spec complète envoyée à Meta.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed> la spec, ou `['error' => '…']`
     */
    public function spec(PostPlatform $pp, array $input): array
    {
        $reference = $this->reference($pp);

        if (isset($reference['error'])) {
            return $reference;
        }

        $objective = $input['objective'] ?? 'OUTCOME_ENGAGEMENT';

        if (! array_key_exists($objective, MetaAdsService::objectives())) {
            return ['error' => "Objectif inconnu : {$objective}."];
        }

        $goal = $input['optimization_goal'] ?? config("meta_ads.objectives.{$objective}.default_goal");

        if (! MetaAdsService::goalIsValid($objective, $goal)) {
            return ['error' => "L'optimisation {$goal} n'existe pas pour l'objectif {$objective}."];
        }

        $categories = array_values(array_intersect(
            (array) ($input['special_ad_categories'] ?? []),
            array_keys(config('meta_ads.special_ad_categories')),
        ));

        $audience = ! empty($input['audience_id']) ? MetaAudience::find($input['audience_id']) : null;

        if (! empty($input['audience_id']) && ! $audience) {
            return ['error' => 'Audience introuvable.'];
        }

        $targeting = $audience
            ? $audience->toTargetingSpec($categories !== [])
            : ($input['targeting'] ?? $this->fallbackTargeting($pp));

        $days = (int) $input['days'];
        $start = ! empty($input['start_date']) ? Carbon::parse($input['start_date']) : now();

        // Une campagne programmée dans le passé démarre immédiatement chez Meta
        // sans le dire ; on préfère le corriger visiblement.
        if ($start->isPast()) {
            $start = now()->addMinutes(5);
        }

        $spec = $reference + [
            'name' => $this->name($pp, $input),
            'objective' => $objective,
            'optimization_goal' => $goal,
            'billing_event' => $this->billingEvent($goal, $input['billing_event'] ?? null),
            'special_ad_categories' => $categories,
            'targeting' => $targeting,
            'budget' => (float) $input['budget'],
            'budget_type' => ($input['budget_type'] ?? 'lifetime') === 'daily' ? 'daily' : 'lifetime',
            'days' => $days,
            'start_time' => $start->toIso8601String(),
            'end_time' => $start->copy()->addDays($days)->toIso8601String(),
            'audience_id' => $audience?->id,
        ];

        if (! empty($input['bid_strategy']) && array_key_exists($input['bid_strategy'], config('meta_ads.bid_strategies'))) {
            $spec['bid_strategy'] = $input['bid_strategy'];

            if ($input['bid_strategy'] !== 'LOWEST_COST_WITHOUT_CAP') {
                if (empty($input['bid_amount'])) {
                    return ['error' => 'Cette stratégie d\'enchère exige un montant (`bid_amount`).'];
                }

                $spec['bid_amount'] = (float) $input['bid_amount'];
            }
        }

        if ($promoted = $this->promotedObject($pp, $goal)) {
            $spec['promoted_object'] = $promoted;
        }

        return $spec;
    }

    /**
     * Budget quotidien équivalent — c'est lui qu'on confronte au plafond.
     *
     * Sans ça, « 300 € sur 3 jours » passerait sous un plafond pensé par jour.
     *
     * @param  array<string,mixed>  $spec
     */
    public function dailyEquivalent(array $spec): float
    {
        return $spec['budget_type'] === 'daily'
            ? round((float) $spec['budget'], 2)
            : round((float) $spec['budget'] / max(1, (int) $spec['days']), 2);
    }

    /**
     * Dépense maximale possible sur toute la période — le chiffre qui compte
     * vraiment pour un humain, et que Meta n'affiche jamais en budget quotidien.
     *
     * @param  array<string,mixed>  $spec
     */
    public function totalExposure(array $spec): float
    {
        return $spec['budget_type'] === 'daily'
            ? round((float) $spec['budget'] * (int) $spec['days'], 2)
            : round((float) $spec['budget'], 2);
    }

    /**
     * Demande à Meta de valider le créatif sans rien créer.
     *
     * Une simulation maison se contenterait de dire « ça a l'air bon » ;
     * `validate_only` teste la publication et les droits pour de vrai.
     *
     * @param  array<string,mixed>  $spec
     * @return array{success:bool,error:?string}
     */
    public function validate(array $spec): array
    {
        return $this->ads->validateOnly($this->ads->accountId().'/adcreatives', $this->creativePayload($spec));
    }

    /**
     * Estimation de portée du ciblage retenu.
     *
     * @param  array<string,mixed>  $spec
     * @return array{success:bool,error:?string,lower:?int,upper:?int}
     */
    public function estimate(array $spec): array
    {
        return $this->ads->reachEstimate($spec['targeting'], $spec['optimization_goal']);
    }

    /**
     * Crée la campagne (en PAUSE) et journalise ce qui a été demandé.
     *
     * @param  array<string,mixed>  $spec
     * @return array{success:bool,error:?string,ids:array<string,?string>,boost:MetaAdsBoost}
     */
    public function create(array $spec, PostPlatform $pp, User $user): array
    {
        $result = $this->ads->createBoost($spec);

        $boost = MetaAdsBoost::create([
            'post_platform_id' => $pp->id,
            'user_id' => $user->id,
            'meta_audience_id' => $spec['audience_id'] ?? null,
            'name' => $spec['name'],
            'platform' => $spec['platform'],
            'object_story_id' => $spec['object_story_id'] ?? null,
            'instagram_media_id' => $spec['instagram_media_id'] ?? null,
            'objective' => $spec['objective'],
            'optimization_goal' => $spec['optimization_goal'],
            'billing_event' => $spec['billing_event'],
            'bid_strategy' => $spec['bid_strategy'] ?? null,
            'bid_amount' => $spec['bid_amount'] ?? null,
            'budget' => $spec['budget'],
            'budget_type' => $spec['budget_type'],
            'days' => $spec['days'],
            'starts_at' => $spec['start_time'],
            'ends_at' => $spec['end_time'],
            // Copie figée : l'audience aura pu être modifiée quand on relira
            // cette campagne, et le ciblage réellement diffusé serait perdu.
            'targeting' => $spec['targeting'],
            'status' => $result['success'] ? 'paused' : 'failed',
            'error' => $result['error'],
        ] + $result['ids']);

        return $result + ['boost' => $boost];
    }

    /**
     * Plan lisible — ce qui sera fait, en français, avant de le faire.
     *
     * @param  array<string,mixed>  $spec
     * @return array<string,mixed>
     */
    public function plan(PostPlatform $pp, array $spec): array
    {
        return [
            'post_platform_id' => $pp->id,
            'platform' => $spec['platform'],
            'compte' => $pp->socialAccount?->name,
            'publication' => $pp->platform_url ?? $pp->external_id,
            'reference_meta' => $spec['object_story_id'] ?? $spec['instagram_media_id'] ?? null,
            'nom_campagne' => $spec['name'],
            'objectif' => config("meta_ads.objectives.{$spec['objective']}.label").' ('.$spec['objective'].')',
            'optimisation' => config("meta_ads.objectives.{$spec['objective']}.goals.{$spec['optimization_goal']}")
                .' ('.$spec['optimization_goal'].')',
            'facturation' => $spec['billing_event'],
            'enchere' => $spec['bid_strategy'] ?? 'LOWEST_COST_WITHOUT_CAP',
            'budget' => $spec['budget'],
            'type_budget' => $spec['budget_type'] === 'daily' ? 'quotidien' : 'total',
            'jours' => $spec['days'],
            'debut' => $spec['start_time'],
            'fin' => $spec['end_time'],
            'budget_quotidien_equivalent' => $this->dailyEquivalent($spec),
            'depense_maximale' => $this->totalExposure($spec),
            'audience' => $spec['audience_id']
                ? MetaAudience::find($spec['audience_id'])?->name
                : 'ciblage ad hoc',
            'ciblage' => $spec['targeting'],
            'categories_speciales' => $spec['special_ad_categories'],
            'cree_en' => 'PAUSED',
        ];
    }

    /**
     * Avertissements qui ne bloquent pas mais qu'un humain doit lire.
     *
     * @param  array<string,mixed>  $spec
     * @return array<int,string>
     */
    public function warnings(array $spec): array
    {
        $warnings = [];
        $objective = config("meta_ads.objectives.{$spec['objective']}");

        if (! empty($objective['needs_pixel']) && ! $this->ads->pixelId()) {
            $warnings[] = 'Objectif de conversion sans pixel renseigné (/settings) : la campagne diffusera, '
                .'mais l\'optimisation n\'aura aucun signal à apprendre.';
        }

        if (! empty($objective['needs_link'])) {
            $warnings[] = 'Cet objectif suppose que la publication contient un lien : sans lien, '
                .'Meta n\'a nulle part où envoyer le trafic.';
        }

        if ($spec['special_ad_categories'] !== []) {
            $warnings[] = 'Catégorie publicitaire spéciale déclarée : Meta interdit alors le ciblage par âge, '
                .'genre et centres d\'intérêt — ces critères ont été retirés du ciblage.';
        }

        if ($spec['budget_type'] === 'daily') {
            $warnings[] = sprintf(
                'Budget quotidien : la dépense maximale sur %d jours est de %s, pas de %s.',
                $spec['days'],
                $this->totalExposure($spec),
                $spec['budget'],
            );
        }

        if (($spec['platform'] ?? null) === 'facebook') {
            $warnings[] = 'Facebook exige que la Page soit un actif de l\'utilisateur système et que le jeton '
                .'porte `pages_manage_ads`. Instagram fonctionne sans.';
        }

        return $warnings;
    }

    /**
     * Résout une diffusion RS-Max vers la référence Meta de sa publication.
     *
     * @return array<string,mixed>
     */
    public function reference(PostPlatform $pp): array
    {
        $slug = $pp->platform?->slug;
        $accountId = $pp->socialAccount?->platform_account_id;

        if (! $accountId) {
            return ['error' => 'Le compte social n\'a pas de platform_account_id : impossible de résoudre la publication chez Meta.'];
        }

        return match ($slug) {
            // La Graph API rend souvent un id deja composite ({page}_{post}) ;
            // on ne prefixe que s'il ne l'est pas.
            'facebook' => [
                'platform' => 'facebook',
                'object_story_id' => str_contains($pp->external_id, '_')
                    ? $pp->external_id
                    : $accountId.'_'.$pp->external_id,
            ],
            'instagram' => [
                'platform' => 'instagram',
                'instagram_user_id' => $accountId,
                'instagram_media_id' => $pp->external_id,
            ],
            default => ['error' => "La sponsorisation n'existe que sur Facebook et Instagram (reçu : ".($slug ?? 'inconnu').')'],
        };
    }

    /**
     * @param  array<string,mixed>  $spec
     * @return array<string,mixed>
     */
    public function creativePayload(array $spec): array
    {
        return $spec['platform'] === 'instagram'
            ? [
                'name' => $spec['name'],
                'instagram_user_id' => $spec['instagram_user_id'],
                'source_instagram_media_id' => $spec['instagram_media_id'],
            ]
            : [
                'name' => $spec['name'],
                'object_story_id' => $spec['object_story_id'],
            ];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function name(PostPlatform $pp, array $input): string
    {
        $name = trim((string) ($input['name'] ?? ''));

        return $name !== ''
            ? mb_substr($name, 0, 120)
            : 'RS-Max — '.str($pp->post?->content_preview ?? 'publication')->limit(40);
    }

    private function billingEvent(string $goal, ?string $requested): string
    {
        $allowed = config("meta_ads.billing_events.{$goal}", ['IMPRESSIONS']);

        return $requested && in_array($requested, $allowed, true)
            ? $requested
            : MetaAdsService::defaultBillingEvent($goal);
    }

    /**
     * Objet promu, quand l'optimisation ne veut rien dire sans lui.
     *
     * @return array<string,mixed>|null
     */
    private function promotedObject(PostPlatform $pp, string $goal): ?array
    {
        $pageId = $pp->platform?->slug === 'facebook' ? $pp->socialAccount?->platform_account_id : null;

        return match ($goal) {
            'PAGE_LIKES', 'LEAD_GENERATION' => $pageId ? ['page_id' => $pageId] : null,
            'OFFSITE_CONVERSIONS' => $this->ads->pixelId()
                ? ['pixel_id' => $this->ads->pixelId(), 'custom_event_type' => 'LEAD']
                : null,
            'VALUE' => $this->ads->pixelId()
                ? ['pixel_id' => $this->ads->pixelId(), 'custom_event_type' => 'PURCHASE']
                : null,
            default => null,
        };
    }

    /**
     * Ciblage de repli quand aucune audience n'est choisie : large, mais
     * explicite. Un ciblage vide n'est pas « tout le monde » chez Meta, c'est
     * une erreur de validation.
     *
     * @return array<string,mixed>
     */
    private function fallbackTargeting(PostPlatform $pp): array
    {
        $defaults = config('meta_ads.default_targeting');

        return [
            'geo_locations' => ['countries' => $defaults['countries']],
            'age_min' => $defaults['age_min'],
            'age_max' => $defaults['age_max'],
            'publisher_platforms' => $defaults['publisher_platforms'],
            'targeting_automation' => ['advantage_audience' => 0],
        ];
    }
}
