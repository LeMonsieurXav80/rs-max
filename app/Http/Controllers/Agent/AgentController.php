<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Api\TikTokFeedController;
use App\Http\Controllers\Controller;
use App\Jobs\PrepareInboxDrafts;
use App\Models\SocialAccount;
use App\Models\TikTokEngagement;
use App\Services\Agent\AgentCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AgentController extends Controller
{
    public function __construct(private AgentCatalog $catalog) {}

    public function show(Request $request, string $platform): View
    {
        $definition = $this->catalog->platforms()[$platform] ?? abort(404);
        $accounts = SocialAccount::with('platform')->whereRelation('platform', 'slug', $platform)->orderBy('name')->get()
            ->reject(fn (SocialAccount $a) => str_starts_with((string) $a->platform_account_id, 'bot_') || str_starts_with((string) $a->platform_account_id, 'app_'))
            ->map(fn (SocialAccount $a) => ['account' => $a, 'actions' => $this->catalog->forAccount($a)]);

        $engagements = $platform === 'tiktok' ? TikTokEngagement::with('socialAccount')
            ->whereIn('social_account_id', $accounts->pluck('account.id'))->latest()->limit(20)->get() : collect();

        $xEngagements = $platform === 'twitter' ? \App\Models\XEngagement::with('socialAccount')->whereIn('social_account_id', $accounts->pluck('account.id'))->latest()->limit(30)->get() : collect();

        $journal = app(\App\Services\Agent\AutomationJournal::class);
        $logRows = app(\App\Http\Controllers\Api\AutomationLogController::class)->query($request, $platform)->limit(10)->get()->map(fn ($log) => $journal->present($log));

        return view('agent.platform', compact('platform', 'definition', 'accounts', 'engagements', 'xEngagements', 'logRows'));
    }

    public function logs(Request $request, string $platform): View
    {
        $definition = $this->catalog->platforms()[$platform] ?? abort(404);
        $journal = app(\App\Services\Agent\AutomationJournal::class);
        $logs = app(\App\Http\Controllers\Api\AutomationLogController::class)->query($request, $platform)->paginate(50)->withQueryString();
        $logRows = $logs->getCollection()->map(fn ($log) => $journal->present($log));
        $accounts = $request->user()->visibleSocialAccounts()->whereHas('platform', fn ($q) => $q->where('slug', $platform))->orderBy('name')->get();

        return view('agent.logs', compact('platform', 'definition', 'logs', 'logRows', 'accounts'));
    }

    public function sendEngagement(Request $request, TikTokEngagement $engagement, TikTokFeedController $feed): RedirectResponse
    {
        $request->merge(['dry_run' => false]);
        $feed->send($request, $engagement);

        return back()->with('success', 'Action TikTok lancée sur le NAS. Son résultat sera confirmé après rechargement.');
    }

    public function sendXEngagement(Request $request, \App\Models\XEngagement $engagement, \App\Http\Controllers\Api\XAgentController $api, \App\Services\Agent\XEngagementService $service): RedirectResponse
    {
        $request->merge(['dry_run' => false]);
        $response = $api->execute($request, $engagement, $service);
        if ($response->getStatusCode() >= 400) {
            return back()->withErrors(['agent' => $response->getData(true)['message'] ?? 'Action refusée.']);
        }

        return back()->with('success', 'Action X lancée sur le NAS. Attendre sa confirmation après rechargement.');
    }

    public function prepare(SocialAccount $account): RedirectResponse
    {
        PrepareInboxDrafts::dispatch($account->id, 10);

        return back()->with('success', 'Préparation lancée pour '.$account->name.' : les réponses arriveront dans la messagerie, onglet À valider.');
    }

    public function update(Request $request, SocialAccount $account, string $action): RedirectResponse
    {
        $definition = $this->catalog->action($account, $action);
        abort_unless($definition && $definition['status'] === 'ready', 404);
        $data = $request->validate(['enabled' => 'nullable|boolean', 'daily_limit' => 'required|integer|min:0|max:1000']);

        $this->catalog->configure($account, $action, (bool) ($data['enabled'] ?? false), $data['daily_limit']);

        return back()->with('success', 'Action enregistrée.');
    }
}
