<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Jobs\PrepareInboxDrafts;
use App\Models\SocialAccount;
use App\Services\Agent\AgentCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AgentController extends Controller
{
    public function __construct(private AgentCatalog $catalog) {}

    public function show(string $platform): View
    {
        $definition = $this->catalog->platforms()[$platform] ?? abort(404);
        $accounts = SocialAccount::with('platform')->whereRelation('platform', 'slug', $platform)->orderBy('name')->get()
            ->reject(fn (SocialAccount $a) => str_starts_with((string) $a->platform_account_id, 'bot_') || str_starts_with((string) $a->platform_account_id, 'app_'))
            ->map(fn (SocialAccount $a) => ['account' => $a, 'actions' => $this->catalog->forAccount($a)]);

        return view('agent.platform', compact('platform', 'definition', 'accounts'));
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
