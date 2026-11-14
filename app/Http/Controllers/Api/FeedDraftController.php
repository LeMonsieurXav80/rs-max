<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Services\Agent\CommunityManagerWindow;
use App\Services\Agent\Feed\FeedDraftService;
use Illuminate\Http\Request;

/** Lots de commentaires du fil « Pour vous » rédigés par Hermès. Voir docs/flux-engagement-scripte.md. */
class FeedDraftController extends Controller
{
    private function account(Request $request, int $id): SocialAccount
    {
        abort_unless($request->user()->isManager(), 403);
        $account = $request->user()->connectedSocialAccounts()->with(['platform', 'persona'])->findOrFail($id);
        abort_unless(($account->platform->slug === 'twitter' && $account->usesBrowser())
            || ($account->platform->slug === 'threads' && filled($account->browser_profile)), 422, 'Compte X en mode navigateur ou Threads avec Companion requis.');

        return $account;
    }

    public function index(Request $request, int $id, FeedDraftService $drafts)
    {
        abort_unless(app(CommunityManagerWindow::class)->isOpen(), 423, 'Community Manager travaille de 06 h à 22 h à Lisbonne.');

        return response()->json($drafts->batch($this->account($request, $id)))->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, int $id, FeedDraftService $drafts)
    {
        $account = $this->account($request, $id);
        $data = $request->validate(['drafts' => 'required|array|min:1|max:'.FeedDraftService::BATCH,
            'drafts.*.id' => 'required|integer|distinct', 'drafts.*.text' => 'nullable|string|max:5000',
            'drafts.*.skip' => 'nullable|string|max:300']);
        $results = $drafts->submit($account, $data['drafts']);

        return response()->json(['results' => $results,
            'note' => 'Les commentaires acceptés partent au prochain passage du script, avec preuve après rechargement : rien à vérifier.']);
    }
}
