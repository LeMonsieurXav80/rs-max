<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MetaAdsActionLog;
use App\Services\Meta\MetaAdsService;
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
 * Création et suppression de campagnes sont hors périmètre à dessein.
 */
class MetaAdsApiController extends Controller
{
    public function __construct(private readonly MetaAdsService $ads) {}

    // ── Lecture ──────────────────────────────────────────────

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

    // ── Garde-fous ───────────────────────────────────────────

    /**
     * L'écriture est-elle ouverte, et par la bonne personne ?
     */
    private function guardWrite(Request $request): ?JsonResponse
    {
        if (! $request->user()->isManager()) {
            return response()->json(['error' => 'Réservé aux managers.'], 403);
        }

        if (! config('meta_ads.write_enabled')) {
            return response()->json([
                'error' => 'Le pilotage en écriture des campagnes est désactivé.',
                'how_to_enable' => 'Passer META_ADS_WRITE_ENABLED=true — geste délibéré, ces appels dépensent de l\'argent réel.',
            ], 403);
        }

        if (! $this->ads->isConfigured()) {
            return response()->json(['error' => 'Compte publicitaire Meta non configuré (voir /settings).'], 422);
        }

        return null;
    }

    /**
     * Un budget peut-il passer de $current à $amount ?
     *
     * Deux barrières distinctes : le saut relatif (une erreur de raisonnement
     * ou d'unité se voit comme une hausse énorme) et le plafond absolu, qui
     * lui n'est jamais contournable — sinon ce n'est pas un plafond.
     */
    private function guardBudget(float $amount, ?float $current, bool $lifetime, bool $force): ?JsonResponse
    {
        $maxDaily = $this->ads->maxDailyBudget();

        if (! $lifetime && $amount > $maxDaily) {
            return response()->json([
                'error' => "Budget quotidien demandé ({$amount}) au-dessus du plafond absolu ({$maxDaily}).",
                'hint' => 'Ce plafond n\'est pas contournable par `force`. Il se règle dans /settings, onglet Statistiques.',
            ], 422);
        }

        if ($current === null || $current <= 0 || $force) {
            return null;
        }

        $maxIncrease = $this->ads->maxBudgetIncreasePct();
        $increase = ($amount - $current) / $current * 100;

        if ($increase > $maxIncrease) {
            return response()->json([
                'error' => sprintf(
                    'Hausse de %.1f%% (%s → %s) au-dessus du maximum de %.0f%% en une fois.',
                    $increase,
                    $current,
                    $amount,
                    $maxIncrease,
                ),
                'hint' => 'Renvoyer avec `force: true` si la hausse est voulue, ou procéder par paliers.',
            ], 422);
        }

        return null;
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
