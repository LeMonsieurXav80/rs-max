<?php

namespace App\Http\Controllers;

use App\Models\BotActionLog;
use App\Models\BotSearchTerm;
use App\Models\BotTargetAccount;
use App\Models\Setting;
use App\Models\SocialAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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

        $targetAccounts = BotTargetAccount::with('socialAccount')
            ->orderByDesc('created_at')
            ->get();

        return view('bot.index', compact(
            'blueskyAccounts',
            'facebookAccounts',
            'searchTerms',
            'targetAccounts',
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

    public function botStatusBatch(Request $request): JsonResponse
    {
        $accounts = $request->input('accounts', []);
        $results = [];

        foreach ($accounts as $entry) {
            $platform = $entry['platform'] ?? '';
            $accountId = $entry['account_id'] ?? '';
            $key = "{$platform}_{$accountId}";

            $results[$key] = [
                'active' => Setting::get("bot_active_{$platform}_{$accountId}") === '1',
                'running' => Cache::has("bot_running_{$platform}_{$accountId}"),
            ];
        }

        return response()->json($results);
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

    public function updateOption(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'feature' => 'required|string|in:like_comments,feed_likes,unfollow',
            'account_id' => 'required|integer|exists:social_accounts,id',
            'enabled' => 'required|boolean',
        ]);

        Setting::set(
            "bot_{$validated['feature']}_bluesky_{$validated['account_id']}",
            $validated['enabled'] ? '1' : '0'
        );

        return response()->json(['saved' => true]);
    }

    public function clearLogs(): RedirectResponse
    {
        BotActionLog::truncate();

        return back()->with('success', 'Historique des actions vidé.');
    }

    public function addTarget(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'social_account_id' => 'required|exists:social_accounts,id',
            'handle' => 'required|string|max:255',
        ]);

        $handle = ltrim(trim($validated['handle']), '@');

        BotTargetAccount::updateOrCreate(
            [
                'social_account_id' => $validated['social_account_id'],
                'handle' => $handle,
            ],
            ['status' => 'pending']
        );

        return back()->with('success', "Compte cible @{$handle} ajouté.");
    }

    public function removeTarget(BotTargetAccount $target): RedirectResponse
    {
        $target->delete();

        return back()->with('success', 'Compte cible supprimé.');
    }

    public function runTarget(BotTargetAccount $target): JsonResponse
    {
        // Reset target so the command picks it up and re-scans posts
        if (in_array($target->status, ['completed', 'paused'])) {
            $update = ['status' => 'pending'];
            if ($target->status === 'completed') {
                // Reset cursor to re-scan posts from scratch (new likers since last run)
                $update['current_post_uri'] = null;
                $update['current_cursor'] = null;
            }
            $target->update($update);
        }

        // Run as detached background process (not in queue) to avoid blocking the queue worker
        $cmd = 'nohup php ' . base_path('artisan') . ' bot:prospect --target=' . (int) $target->id
             . ' >> ' . storage_path('logs/prospect.log') . ' 2>&1 &';
        exec($cmd);

        return response()->json(['started' => true]);
    }

    public function stopTarget(BotTargetAccount $target): JsonResponse
    {
        Cache::put("bot_stop_prospect_{$target->id}", true, 3600);

        return response()->json(['stopped' => true]);
    }

    public function targetStatus(BotTargetAccount $target): JsonResponse
    {
        return response()->json([
            'status' => $target->status,
            'likers_processed' => $target->likers_processed,
            'likes_given' => $target->likes_given,
            'follows_given' => $target->follows_given,
        ]);
    }

    public function apiStatus(SocialAccount $account): JsonResponse
    {
        $credentials = $account->credentials;
        $checks = ['auth' => false, 'api' => false, 'rate_limited' => false, 'error' => null];

        // 1. Test auth (get a valid token)
        $accessJwt = $credentials['access_jwt'] ?? null;
        $refreshJwt = $credentials['refresh_jwt'] ?? null;
        $did = $credentials['did'] ?? null;

        // Try refresh if access token looks expired
        if ($refreshJwt) {
            $response = Http::withToken($refreshJwt)
                ->post('https://bsky.social/xrpc/com.atproto.server.refreshSession');

            if ($response->successful()) {
                $data = $response->json();
                $accessJwt = $data['accessJwt'];
                $did = $data['did'];
                $account->update([
                    'credentials' => array_merge($credentials, [
                        'access_jwt' => $data['accessJwt'],
                        'refresh_jwt' => $data['refreshJwt'],
                        'did' => $data['did'],
                    ]),
                ]);
                $checks['auth'] = true;
            } else {
                // Try full login
                $loginResponse = Http::post('https://bsky.social/xrpc/com.atproto.server.createSession', [
                    'identifier' => $credentials['handle'],
                    'password' => $credentials['app_password'],
                ]);

                if ($loginResponse->successful()) {
                    $data = $loginResponse->json();
                    $accessJwt = $data['accessJwt'];
                    $did = $data['did'];
                    $account->update([
                        'credentials' => array_merge($credentials, [
                            'did' => $data['did'],
                            'access_jwt' => $data['accessJwt'],
                            'refresh_jwt' => $data['refreshJwt'],
                        ]),
                    ]);
                    $checks['auth'] = true;
                } else {
                    $checks['error'] = 'Auth failed: ' . ($loginResponse->json('message') ?? $loginResponse->status());

                    return response()->json($checks);
                }
            }
        }

        // 2. Test public API (fetch own profile)
        if ($did) {
            $profileResponse = Http::get('https://public.api.bsky.app/xrpc/app.bsky.actor.getProfile', [
                'actor' => $did,
            ]);

            if ($profileResponse->successful()) {
                $checks['api'] = true;
            } elseif ($profileResponse->status() === 429) {
                $checks['rate_limited'] = true;
                $checks['error'] = 'Rate limited (429)';
            } else {
                $checks['error'] = 'API error: ' . $profileResponse->status() . ' - ' . ($profileResponse->json('message') ?? '');
            }
        }

        // 3. Test authenticated API (try creating a record check)
        if ($checks['auth'] && $accessJwt) {
            $authTestResponse = Http::withToken($accessJwt)
                ->get('https://bsky.social/xrpc/com.atproto.repo.describeRepo', [
                    'repo' => $did,
                ]);

            if ($authTestResponse->status() === 429) {
                $checks['rate_limited'] = true;
                $checks['error'] = 'Rate limited on PDS (429)';
            } elseif (! $authTestResponse->successful()) {
                $checks['error'] = 'PDS error: ' . $authTestResponse->status() . ' - ' . ($authTestResponse->json('message') ?? '');
            }
        }

        return response()->json($checks);
    }

    public function resetTarget(BotTargetAccount $target): RedirectResponse
    {
        $target->update([
            'status' => 'pending',
            'current_post_uri' => null,
            'current_cursor' => null,
            'likers_processed' => 0,
            'likes_given' => 0,
            'follows_given' => 0,
            'started_at' => null,
            'completed_at' => null,
        ]);

        return back()->with('success', "Compte @{$target->handle} réinitialisé.");
    }
}
