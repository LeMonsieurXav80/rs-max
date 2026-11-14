<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Services\Agent\AccountActionBudget;
use App\Services\Agent\AccountStrategy;
use App\Services\Agent\AgentCatalog;
use App\Services\Inbox\ReplyLanguage;
use Illuminate\Http\Request;

class AccountStrategyController extends Controller
{
    private function account(Request $request, int $id): SocialAccount
    {
        abort_unless($request->user()->isManager(), 403);

        return $request->user()->connectedSocialAccounts()->with('platform')->findOrFail($id);
    }

    public function show(Request $request, int $id)
    {
        $a = $this->account($request, $id);

        return response()->json(['account_id' => $a->id, 'platform' => $a->platform->slug, 'strategy' => $a->agent_strategy ?? (object) [], 'reply_language' => ReplyLanguage::code($a),
            'feed_engagement' => \App\Services\Agent\Feed\FeedEngagementSettings::preview($a),
            'cadence' => app(AccountActionBudget::class)->usage($a),
            'hourly_history' => app(AccountActionBudget::class)->history($a),
            'actions' => app(AgentCatalog::class)->forAccount($a)->map(fn ($s) => array_intersect_key($s, array_flip(['label', 'kind', 'channel', 'status', 'enabled', 'daily_limit', 'left'])))]);
    }

    public function update(Request $request, int $id, AccountStrategy $strategy)
    {
        $a = $this->account($request, $id);
        $strategy->save($request, $a);

        return $this->show($request, $id);
    }
}
