<?php

namespace App\Http\Controllers;

use App\Models\BotActionLog;
use App\Models\SocialAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BotHubController extends Controller
{
    public function index(): View
    {
        // Auto-purge logs > 7 jours (geste leger qu'on garde de l'ancien BotController)
        BotActionLog::where('created_at', '<', now()->subDays(7))->delete();

        $blueskyAccounts = SocialAccount::whereHas('platform', fn ($q) => $q->where('slug', 'bluesky'))
            ->with('platform')
            ->get();

        $facebookAccounts = SocialAccount::whereHas('platform', fn ($q) => $q->where('slug', 'facebook'))
            ->with('platform')
            ->get();

        // KPIs du jour (uniquement actions reussies)
        $todayStats = BotActionLog::where('success', true)
            ->whereDate('created_at', today())
            ->selectRaw('action_type, count(*) as total')
            ->groupBy('action_type')
            ->pluck('total', 'action_type');

        $platformCards = [
            [
                'slug' => 'bluesky',
                'name' => 'Bluesky',
                'route' => 'bot.bluesky.index',
                'accounts_count' => $blueskyAccounts->count(),
                'available' => true,
                'tabs' => ['Likes', 'Commentaires', 'Follow', 'Prospection'],
            ],
            [
                'slug' => 'facebook',
                'name' => 'Facebook',
                'route' => 'bot.facebook.index',
                'accounts_count' => $facebookAccounts->count(),
                'available' => true,
                'tabs' => ['Likes commentaires recus'],
            ],
        ];

        return view('bot.hub', compact('platformCards', 'todayStats'));
    }

    public function logs(Request $request): View
    {
        $filterAccount = $request->input('account');
        $filterType = $request->input('type');

        $query = BotActionLog::with('socialAccount.platform')
            ->where('created_at', '>=', now()->subDays(7))
            ->orderByDesc('created_at');

        if ($filterAccount) {
            $query->where('social_account_id', $filterAccount);
        }
        if ($filterType) {
            $query->where('action_type', $filterType);
        }

        $logs = $query->limit(500)->get();

        $allBotAccounts = SocialAccount::whereHas('platform', fn ($q) => $q->whereIn('slug', ['bluesky', 'facebook']))
            ->with('platform')
            ->get();

        $actionTypes = BotActionLog::where('created_at', '>=', now()->subDays(7))
            ->select('action_type')
            ->distinct()
            ->orderBy('action_type')
            ->pluck('action_type');

        return view('bot.logs', compact('logs', 'allBotAccounts', 'actionTypes', 'filterAccount', 'filterType'));
    }

    public function clearLogs(): RedirectResponse
    {
        BotActionLog::truncate();

        return back()->with('success', 'Historique des actions vide.');
    }
}
