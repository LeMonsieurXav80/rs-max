<?php

namespace App\Http\Controllers;

use App\Models\BotActionLog;
use App\Models\BotSearchTerm;
use App\Models\Setting;
use App\Models\SocialAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class BotController extends Controller
{
    public function index(Request $request): View
    {
        // Bluesky accounts with their search terms
        $blueskyAccounts = SocialAccount::whereHas('platform', fn ($q) => $q->where('slug', 'bluesky'))
            ->with('platform')
            ->get();

        $facebookAccounts = SocialAccount::whereHas('platform', fn ($q) => $q->where('slug', 'facebook'))
            ->with('platform')
            ->get();

        // Search terms grouped by account
        $searchTerms = BotSearchTerm::with('socialAccount.platform')
            ->orderBy('social_account_id')
            ->orderBy('term')
            ->get();

        // Recent action logs
        $logs = BotActionLog::with('socialAccount.platform')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        // Stats summary
        $todayStats = BotActionLog::where('success', true)
            ->whereDate('created_at', today())
            ->selectRaw('action_type, count(*) as total')
            ->groupBy('action_type')
            ->pluck('total', 'action_type');

        // Bot frequency and active state per account
        $botFrequencies = [];
        $botActiveStates = [];
        foreach ($blueskyAccounts as $acc) {
            $botFrequencies["bluesky_{$acc->id}"] = Setting::get("bot_freq_bluesky_{$acc->id}", 'every_30_min');
            $botActiveStates["bluesky_{$acc->id}"] = Setting::get("bot_active_bluesky_{$acc->id}") === '1';
        }
        foreach ($facebookAccounts as $acc) {
            $botFrequencies["facebook_{$acc->id}"] = Setting::get("bot_freq_facebook_{$acc->id}", 'every_30_min');
            $botActiveStates["facebook_{$acc->id}"] = Setting::get("bot_active_facebook_{$acc->id}") === '1';
        }

        return view('bot.index', compact(
            'blueskyAccounts',
            'facebookAccounts',
            'searchTerms',
            'logs',
            'todayStats',
            'botFrequencies',
            'botActiveStates',
        ));
    }

    public function addTerm(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'social_account_id' => 'required|exists:social_accounts,id',
            'term' => 'required|string|max:100',
            'max_likes_per_run' => 'nullable|integer|min:1|max:50',
            'like_replies' => 'nullable|boolean',
        ]);

        BotSearchTerm::updateOrCreate(
            [
                'social_account_id' => $validated['social_account_id'],
                'term' => mb_strtolower(trim($validated['term'])),
            ],
            [
                'is_active' => true,
                'max_likes_per_run' => $validated['max_likes_per_run'] ?? 10,
                'like_replies' => $validated['like_replies'] ?? true,
            ]
        );

        return back()->with('success', 'Terme de recherche ajouté.');
    }

    public function removeTerm(BotSearchTerm $term): RedirectResponse
    {
        $term->delete();

        return back()->with('success', 'Terme supprimé.');
    }

    public function toggleTerm(BotSearchTerm $term): JsonResponse
    {
        $term->update(['is_active' => ! $term->is_active]);

        return response()->json(['is_active' => $term->is_active]);
    }

    public function runBluesky(Request $request): JsonResponse
    {
        $accountId = $request->input('social_account_id');
        SocialAccount::findOrFail($accountId);

        Setting::set("bot_active_bluesky_{$accountId}", '1');
        Artisan::queue('bot:run', ['--platform' => 'bluesky', '--account' => $accountId]);

        return response()->json(['activated' => true]);
    }

    public function runFacebook(Request $request): JsonResponse
    {
        $accountId = $request->input('social_account_id');
        SocialAccount::findOrFail($accountId);

        Setting::set("bot_active_facebook_{$accountId}", '1');
        Artisan::queue('bot:run', ['--platform' => 'facebook', '--account' => $accountId]);

        return response()->json(['activated' => true]);
    }

    public function botStatus(Request $request): JsonResponse
    {
        $platform = $request->input('platform');
        $accountId = $request->input('account_id');

        $active = Setting::get("bot_active_{$platform}_{$accountId}") === '1';
        $running = Cache::has("bot_running_{$platform}_{$accountId}");

        return response()->json(['active' => $active, 'running' => $running]);
    }

    public function stopBot(Request $request): JsonResponse
    {
        $platform = $request->input('platform');
        $accountId = $request->input('account_id');

        // Deactivate bot persistently
        Setting::set("bot_active_{$platform}_{$accountId}", '0');

        // Also signal current run to stop if running
        Cache::put("bot_stop_{$platform}_{$accountId}", true, 300);

        return response()->json(['stopped' => true]);
    }

    public function updateFrequency(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform' => 'required|string|in:bluesky,facebook',
            'account_id' => 'required|integer|exists:social_accounts,id',
            'frequency' => 'required|string|in:disabled,every_15_min,every_30_min,hourly,every_2_hours,every_6_hours,every_12_hours,daily',
        ]);

        Setting::set("bot_freq_{$validated['platform']}_{$validated['account_id']}", $validated['frequency']);

        return response()->json(['saved' => true]);
    }

    public function clearLogs(): RedirectResponse
    {
        BotActionLog::truncate();

        return back()->with('success', 'Historique des actions vidé.');
    }
}
