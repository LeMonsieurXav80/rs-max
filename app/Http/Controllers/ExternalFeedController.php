<?php

namespace App\Http\Controllers;

use App\Models\ExternalPost;
use App\Models\SocialAccount;
use App\Services\Import\ImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Flux des publications faites nativement sur les reseaux, hors RS-Max.
 *
 * L'ecran sert a les reperer et a les cocher ; l'adoption (fusion des cartes
 * cochees en une publication RS-Max) est traitee separement.
 */
class ExternalFeedController extends Controller
{
    /**
     * Plateformes pour lesquelles un service d'import existe.
     *
     * @see \App\Services\Import\ImportService::getServiceForPlatform()
     */
    private const IMPORTABLE_PLATFORMS = [
        'facebook', 'instagram', 'twitter', 'youtube', 'threads', 'bluesky',
    ];

    /**
     * Nombre de publications remontees par colonne. Le tableau se parcourt a
     * l'oeil, pas a la pagination : la profondeur se regle avec la periode.
     */
    private const PER_COLUMN = 60;

    public function index(Request $request): View
    {
        $accounts = $this->visibleAccounts($request);
        $accountIds = $accounts->pluck('id');

        $selectedAccounts = array_values(array_intersect(
            array_map('intval', (array) $request->input('accounts', [])),
            $accountIds->all()
        ));

        // Sans choix explicite, on part des comptes par defaut de l'utilisateur.
        if (empty($selectedAccounts)) {
            $selectedAccounts = array_values(array_intersect(
                array_map('intval', (array) ($request->user()->default_accounts ?? [])),
                $accountIds->all()
            ));
        }

        $activeAccounts = ! empty($selectedAccounts)
            ? $accounts->whereIn('id', $selectedAccounts)
            : $accounts;

        $period = $request->input('period', '30');
        $search = trim((string) $request->input('search', ''));
        $showIgnored = $request->boolean('ignored');

        // Une colonne par reseau, alimentee par les comptes retenus de ce reseau.
        $columns = $activeAccounts
            ->groupBy(fn (SocialAccount $a) => $a->platform->slug)
            ->map(function ($platformAccounts) use ($period, $search, $showIgnored) {
                $first = $platformAccounts->first();

                $query = ExternalPost::with(['platform', 'socialAccount'])
                    ->whereIn('social_account_id', $platformAccounts->pluck('id'))
                    ->whereNull('adopted_post_id')
                    ->notPublishedByRsMax();

                $query->when($showIgnored, fn ($q) => $q->whereNotNull('ignored_at'))
                    ->when(! $showIgnored, fn ($q) => $q->whereNull('ignored_at'));

                if ($period !== 'all') {
                    $query->where('published_at', '>=', now()->subDays((int) $period));
                }

                if ($search !== '') {
                    $query->where('content', 'like', '%'.$search.'%');
                }

                return [
                    'platform' => $first->platform,
                    'accounts' => $platformAccounts->values(),
                    'importable' => in_array($first->platform->slug, self::IMPORTABLE_PLATFORMS, true),
                    'posts' => $query->orderByDesc('published_at')->limit(self::PER_COLUMN)->get(),
                ];
            })
            ->sortBy(fn ($column) => $column['platform']->name)
            ->values();

        // Compteur d'attente, hors filtres, pour signaler le travail restant.
        $pendingCount = ExternalPost::whereIn('social_account_id', $accountIds)
            ->adoptable()
            ->count();

        $groups = $request->user()->accountGroups()->with('socialAccounts')->get();

        return view('external.index', compact(
            'accounts',
            'groups',
            'columns',
            'selectedAccounts',
            'period',
            'search',
            'showIgnored',
            'pendingCount',
        ));
    }

    /**
     * Ecarte des cartes du flux sans les supprimer : les stats en dependent.
     */
    public function ignore(Request $request): RedirectResponse
    {
        $ids = $this->authorizedIds($request);

        ExternalPost::whereIn('id', $ids)->update(['ignored_at' => now()]);

        return back()->with('success', count($ids).' publication(s) ecartee(s) du flux.');
    }

    public function restore(Request $request): RedirectResponse
    {
        $ids = $this->authorizedIds($request);

        ExternalPost::whereIn('id', $ids)->update(['ignored_at' => null]);

        return back()->with('success', count($ids).' publication(s) remise(s) dans le flux.');
    }

    /**
     * Relance l'import sur les comptes visibles qui savent le faire.
     */
    public function refresh(Request $request, ImportService $importService): JsonResponse
    {
        $limit = min(max((int) $request->input('limit', 50), 1), 200);

        $results = [];
        $total = 0;

        foreach ($this->visibleAccounts($request) as $account) {
            if (! in_array($account->platform->slug, self::IMPORTABLE_PLATFORMS, true)) {
                continue;
            }

            if (! $importService->canImport($account)['allowed']) {
                continue;
            }

            $result = $importService->import($account, $limit);
            $total += $result['imported'];

            $results[] = [
                'account' => $account->name,
                'platform' => $account->platform->slug,
                'imported' => $result['imported'],
                'error' => $result['error'],
            ];
        }

        return response()->json([
            'success' => true,
            'imported' => $total,
            'results' => $results,
        ]);
    }

    /**
     * Comptes sociaux que l'utilisateur a le droit de voir.
     */
    private function visibleAccounts(Request $request)
    {
        $user = $request->user();

        $query = $user->isAdmin()
            ? SocialAccount::query()
            : $user->activeSocialAccounts();

        return $query->with('platform')->orderBy('name')->get();
    }

    /**
     * Restreint les ids postes a ceux des comptes visibles : sans ca, un id
     * devine permettrait d'agir sur le flux d'un autre utilisateur.
     *
     * @return array<int, int>
     */
    private function authorizedIds(Request $request): array
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $accountIds = $this->visibleAccounts($request)->pluck('id');

        return ExternalPost::whereIn('id', $validated['ids'])
            ->whereIn('social_account_id', $accountIds)
            ->pluck('id')
            ->all();
    }
}
