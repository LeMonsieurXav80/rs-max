<?php

namespace App\Http\Controllers\Bot;

use App\Http\Controllers\Controller;
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

class BlueskyBotController extends Controller
{
    private const PURPOSES = ['likes', 'comments', 'follow'];

    public function index(): View
    {
        $accounts = SocialAccount::whereHas('platform', fn ($q) => $q->where('slug', 'bluesky'))
            ->with(['platform', 'persona'])
            ->get();

        $termsByAccountAndPurpose = BotSearchTerm::with('socialAccount')
            ->whereIn('social_account_id', $accounts->pluck('id'))
            ->orderBy('term')
            ->get()
            ->groupBy(fn ($t) => $t->social_account_id.'_'.$t->purpose);

        $settings = [];
        foreach ($accounts as $acc) {
            $settings[$acc->id] = [
                'frequency' => Setting::get("bot_freq_bluesky_{$acc->id}", 'every_30_min'),
                'active' => Setting::get("bot_active_bluesky_{$acc->id}") === '1',
                'like_comments' => Setting::get("bot_like_comments_bluesky_{$acc->id}") === '1',
                'feed_likes' => Setting::get("bot_feed_likes_bluesky_{$acc->id}") === '1',
                'feed_likes_max' => (int) Setting::get("bot_feed_likes_max_bluesky_{$acc->id}", 5),
                'unfollow' => Setting::get("bot_unfollow_bluesky_{$acc->id}") === '1',
                'unfollow_max' => (int) Setting::get("bot_unfollow_max_bluesky_{$acc->id}", 10),
                'comments_keyword' => Setting::get("bot_comments_keyword_bluesky_{$acc->id}") === '1',
                'comments_max' => (int) Setting::get("bot_comments_max_per_run_bluesky_{$acc->id}", 3),
                'follow_keyword' => Setting::get("bot_follow_keyword_bluesky_{$acc->id}") === '1',
                'follow_max' => (int) Setting::get("bot_follow_max_bluesky_{$acc->id}", 5),
            ];
        }

        $targetAccounts = BotTargetAccount::with('socialAccount')
            ->whereIn('social_account_id', $accounts->pluck('id'))
            ->orderByDesc('created_at')
            ->get();

        return view('bot.bluesky.index', compact('accounts', 'termsByAccountAndPurpose', 'settings', 'targetAccounts'));
    }

    public function addTerm(Request $request, string $purpose): RedirectResponse
    {
        abort_unless(in_array($purpose, self::PURPOSES, true), 404);

        $validated = $request->validate([
            'social_account_id' => 'required|exists:social_accounts,id',
            'term' => 'required|string|max:100',
            'max_per_run' => 'nullable|integer|min:1|max:50',
            'like_replies' => 'nullable|boolean',
        ]);

        BotSearchTerm::updateOrCreate(
            [
                'social_account_id' => $validated['social_account_id'],
                'term' => mb_strtolower(trim($validated['term'])),
                'purpose' => $purpose,
            ],
            [
                'is_active' => true,
                'max_per_run' => $validated['max_per_run'] ?? null,
                'max_likes_per_run' => $purpose === 'likes' ? ($validated['max_per_run'] ?? 10) : 10,
                'like_replies' => $validated['like_replies'] ?? ($purpose === 'likes'),
            ]
        );

        return back()->with('success', 'Mot-cle ajoute.');
    }

    public function removeTerm(BotSearchTerm $term): RedirectResponse
    {
        $term->delete();

        return back()->with('success', 'Mot-cle supprime.');
    }

    public function toggleTerm(BotSearchTerm $term): JsonResponse
    {
        $term->update(['is_active' => ! $term->is_active]);

        return response()->json(['is_active' => $term->is_active]);
    }

    public function updateOption(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'feature' => 'required|string|in:like_comments,feed_likes,unfollow,comments_keyword,follow_keyword',
            'account_id' => 'required|integer|exists:social_accounts,id',
            'enabled' => 'required|boolean',
        ]);

        Setting::set(
            "bot_{$validated['feature']}_bluesky_{$validated['account_id']}",
            $validated['enabled'] ? '1' : '0'
        );

        return response()->json(['saved' => true]);
    }

    public function updateNumeric(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => 'required|string|in:unfollow_max,comments_max,follow_max,feed_likes_max',
            'account_id' => 'required|integer|exists:social_accounts,id',
            'value' => 'required|integer|min:1|max:100',
        ]);

        $settingKey = match ($validated['key']) {
            'unfollow_max' => "bot_unfollow_max_bluesky_{$validated['account_id']}",
            'comments_max' => "bot_comments_max_per_run_bluesky_{$validated['account_id']}",
            'follow_max' => "bot_follow_max_bluesky_{$validated['account_id']}",
            'feed_likes_max' => "bot_feed_likes_max_bluesky_{$validated['account_id']}",
        };

        Setting::set($settingKey, (string) $validated['value']);

        return response()->json(['saved' => true]);
    }

    public function updateFrequency(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => 'required|integer|exists:social_accounts,id',
            'frequency' => 'required|string|in:disabled,every_15_min,every_30_min,hourly,every_2_hours,every_6_hours,every_12_hours,daily',
        ]);

        Setting::set("bot_freq_bluesky_{$validated['account_id']}", $validated['frequency']);

        return response()->json(['saved' => true]);
    }

    public function run(Request $request): JsonResponse
    {
        $accountId = $request->input('social_account_id');
        SocialAccount::findOrFail($accountId);

        Setting::set("bot_active_bluesky_{$accountId}", '1');
        Artisan::queue('bot:run', ['--platform' => 'bluesky', '--account' => $accountId]);

        return response()->json(['activated' => true]);
    }

    public function stop(Request $request): JsonResponse
    {
        $accountId = $request->input('account_id');

        Setting::set("bot_active_bluesky_{$accountId}", '0');
        Cache::put("bot_stop_bluesky_{$accountId}", true, 300);

        return response()->json(['stopped' => true]);
    }

    public function status(Request $request): JsonResponse
    {
        $accountId = $request->input('account_id');

        return response()->json([
            'active' => Setting::get("bot_active_bluesky_{$accountId}") === '1',
            'running' => Cache::has("bot_running_bluesky_{$accountId}"),
        ]);
    }

    public function statusBatch(Request $request): JsonResponse
    {
        $accounts = $request->input('accounts', []);
        $results = [];

        foreach ($accounts as $accountId) {
            $results[(int) $accountId] = [
                'active' => Setting::get("bot_active_bluesky_{$accountId}") === '1',
                'running' => Cache::has("bot_running_bluesky_{$accountId}"),
            ];
        }

        return response()->json($results);
    }

    // ─── Prospection ────────────────────────────────────────────────

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

        return back()->with('success', "Compte cible @{$handle} ajoute.");
    }

    public function removeTarget(BotTargetAccount $target): RedirectResponse
    {
        $target->delete();

        return back()->with('success', 'Compte cible supprime.');
    }

    public function runTarget(BotTargetAccount $target): JsonResponse
    {
        if (in_array($target->status, ['completed', 'paused'])) {
            $update = ['status' => 'pending'];
            if ($target->status === 'completed') {
                $update['current_post_uri'] = null;
                $update['current_cursor'] = null;
            }
            $target->update($update);
        }

        // Background process detache (pattern reutilise de l'ancien BotController)
        $cmd = 'nohup php '.base_path('artisan').' bot:prospect --target='.(int) $target->id
             .' >> '.storage_path('logs/prospect.log').' 2>&1 &';
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

        return back()->with('success', "Compte @{$target->handle} reinitialise.");
    }

    public function apiStatus(SocialAccount $account): JsonResponse
    {
        $credentials = $account->credentials;
        $checks = ['auth' => false, 'api' => false, 'rate_limited' => false, 'error' => null];

        $accessJwt = $credentials['access_jwt'] ?? null;
        $refreshJwt = $credentials['refresh_jwt'] ?? null;
        $did = $credentials['did'] ?? null;

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
                    $checks['error'] = 'Auth failed: '.($loginResponse->json('message') ?? $loginResponse->status());

                    return response()->json($checks);
                }
            }
        }

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
                $checks['error'] = 'API error: '.$profileResponse->status();
            }
        }

        if ($checks['auth'] && $accessJwt) {
            $authTestResponse = Http::withToken($accessJwt)
                ->get('https://bsky.social/xrpc/com.atproto.repo.describeRepo', [
                    'repo' => $did,
                ]);

            if ($authTestResponse->status() === 429) {
                $checks['rate_limited'] = true;
                $checks['error'] = 'Rate limited on PDS (429)';
            } elseif (! $authTestResponse->successful()) {
                $checks['error'] = 'PDS error: '.$authTestResponse->status();
            }
        }

        return response()->json($checks);
    }
}
