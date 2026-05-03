<?php

namespace App\Http\Controllers\Bot;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\SocialAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class FacebookBotController extends Controller
{
    public function index(): View
    {
        $accounts = SocialAccount::whereHas('platform', fn ($q) => $q->where('slug', 'facebook'))
            ->with('platform')
            ->get();

        $settings = [];
        foreach ($accounts as $acc) {
            $settings[$acc->id] = [
                'frequency' => Setting::get("bot_freq_facebook_{$acc->id}", 'every_30_min'),
                'active' => Setting::get("bot_active_facebook_{$acc->id}") === '1',
            ];
        }

        return view('bot.facebook.index', compact('accounts', 'settings'));
    }

    public function updateFrequency(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => 'required|integer|exists:social_accounts,id',
            'frequency' => 'required|string|in:disabled,every_15_min,every_30_min,hourly,every_2_hours,every_6_hours,every_12_hours,daily',
        ]);

        Setting::set("bot_freq_facebook_{$validated['account_id']}", $validated['frequency']);

        return response()->json(['saved' => true]);
    }

    public function run(Request $request): JsonResponse
    {
        $accountId = $request->input('social_account_id');
        SocialAccount::findOrFail($accountId);

        Setting::set("bot_active_facebook_{$accountId}", '1');
        Artisan::queue('bot:run', ['--platform' => 'facebook', '--account' => $accountId]);

        return response()->json(['activated' => true]);
    }

    public function stop(Request $request): JsonResponse
    {
        $accountId = $request->input('account_id');

        Setting::set("bot_active_facebook_{$accountId}", '0');
        Cache::put("bot_stop_facebook_{$accountId}", true, 300);

        return response()->json(['stopped' => true]);
    }

    public function status(Request $request): JsonResponse
    {
        $accountId = $request->input('account_id');

        return response()->json([
            'active' => Setting::get("bot_active_facebook_{$accountId}") === '1',
            'running' => Cache::has("bot_running_facebook_{$accountId}"),
        ]);
    }
}
