<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Jobs\PrepareAgentProposals;
use App\Models\AgentProposal;
use App\Models\SocialAccount;
use App\Services\Agent\AgentCatalog;
use App\Services\Agent\AgentProposalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Valider est un geste humain : ces routes n'existent qu'en session web, jamais par jeton. */
class AgentValidationController extends Controller
{
    public function index(AgentCatalog $catalog): View
    {
        $pending = AgentProposal::with('socialAccount.platform')->where('status', AgentProposal::PENDING)->orderBy('social_account_id')->orderBy('id')->get();
        $recent = AgentProposal::with('socialAccount.platform')->where('status', '!=', AgentProposal::PENDING)->orderByDesc('updated_at')->limit(20)->get();
        $accounts = SocialAccount::with(['platform', 'persona'])->whereHas('agentActions', fn ($q) => $q->where('action', 'reply')->where('enabled', true))->orderBy('name')->get()
            ->filter(fn (SocialAccount $a) => isset($catalog->platforms()[$a->platform->slug]));

        return view('agent.validations', compact('pending', 'recent', 'accounts'));
    }

    public function prepare(SocialAccount $account): RedirectResponse
    {
        PrepareAgentProposals::dispatch($account->id, 10);

        return back()->with('success', 'Préparation lancée pour '.$account->name.'. Recharge la page dans une minute.');
    }

    public function approve(Request $request, AgentProposal $proposal, AgentProposalService $proposals): RedirectResponse
    {
        $text = trim($request->validate(['reply_text' => 'required|string|max:5000'])['reply_text']);

        return back()->with('success', $proposals->approve($proposal, $text, $request->user()) ? 'Réponse validée : envoi en cours.' : 'Cette proposition a déjà été traitée.');
    }

    public function reject(Request $request, AgentProposal $proposal, AgentProposalService $proposals): RedirectResponse
    {
        return back()->with('success', $proposals->reject($proposal, $request->user()) ? 'Réponse refusée.' : 'Cette proposition a déjà été traitée.');
    }
}
