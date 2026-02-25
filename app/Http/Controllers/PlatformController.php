<?php

namespace App\Http\Controllers;

use App\Models\Platform;
use App\Models\SocialAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

class PlatformController extends Controller
{
    /**
     * Facebook / Instagram configuration page.
     */
    public function facebook(Request $request): View
    {
        $accounts = $this->accountsForSlugs($request, ['facebook', 'instagram']);

        $fbAccounts = $accounts->where(fn ($a) => $a->platform->slug === 'facebook')->values();
        $igAccounts = $accounts->where(fn ($a) => $a->platform->slug === 'instagram')->values();

        return view('platforms.facebook', compact('fbAccounts', 'igAccounts'));
    }

    /**
     * Threads configuration page.
     */
    public function threads(Request $request): View
    {
        $accounts = $this->accountsForSlugs($request, ['threads']);

        return view('platforms.threads', compact('accounts'));
    }

    /**
     * Telegram configuration page.
     * Accounts grouped by bot_token, with bot records separated from channels.
     */
    public function telegram(Request $request): View
    {
        $accounts = $this->accountsForSlugs($request, ['telegram']);

        $grouped = $accounts->groupBy(fn ($a) => $a->credentials['bot_token'] ?? 'unknown');

        $bots = $grouped->map(function ($group, $token) {
            $botRecord = $group->first(fn ($a) => ($a->credentials['type'] ?? null) === 'bot');
            $channels = $group->filter(fn ($a) => ($a->credentials['type'] ?? null) !== 'bot')->values();

            return (object) [
                'record' => $botRecord,
                'channels' => $channels,
                'name' => $botRecord?->credentials['bot_name']
                    ?? $channels->first()?->credentials['bot_name']
                    ?? null,
                'username' => $botRecord?->credentials['bot_username']
                    ?? $channels->first()?->credentials['bot_username']
                    ?? null,
                'token' => $token,
            ];
        });

        return view('platforms.telegram', compact('bots'));
    }

    /**
     * Twitter / X configuration page.
     * Accounts grouped by app (api_key in credentials).
     */
    public function twitter(Request $request): View
    {
        $accounts = $this->accountsForSlugs($request, ['twitter']);

        $apps = $accounts->groupBy(fn ($a) => $a->credentials['api_key'] ?? 'unknown');

        return view('platforms.twitter', compact('apps'));
    }

    /**
     * Register a Telegram bot (validate + save).
     */
    public function registerTelegramBot(Request $request): RedirectResponse
    {
        $request->validate([
            'bot_token' => 'required|string|max:255',
        ]);

        $token = $request->input('bot_token');

        $response = Http::get("https://api.telegram.org/bot{$token}/getMe");

        if ($response->failed() || ! $response->json('ok')) {
            return back()
                ->with('error', 'Token de bot invalide : '.($response->json('description') ?? 'Erreur inconnue'))
                ->withInput();
        }

        $result = $response->json('result');
        $botId = (string) $result['id'];
        $botName = $result['first_name'] ?? 'Bot';
        $botUsername = $result['username'] ?? null;

        $platform = Platform::where('slug', 'telegram')->firstOrFail();
        $user = $request->user();

        $account = SocialAccount::where('platform_id', $platform->id)
            ->where('platform_account_id', "bot_{$botId}")
            ->first();

        $credentials = [
            'bot_token' => $token,
            'bot_name' => $botName,
            'bot_username' => $botUsername,
            'type' => 'bot',
        ];

        if ($account) {
            $account->update(['name' => $botName, 'credentials' => $credentials]);
        } else {
            $account = SocialAccount::create([
                'platform_id' => $platform->id,
                'platform_account_id' => "bot_{$botId}",
                'name' => $botName,
                'credentials' => $credentials,
                'language' => $user->default_language ?? 'fr',
                'is_active' => true,
            ]);
        }

        if (! $account->users()->where('user_id', $user->id)->exists()) {
            $account->users()->attach($user->id);
        }

        // Also update bot_name on any existing channels with this token
        SocialAccount::where('platform_id', $platform->id)
            ->where('id', '!=', $account->id)
            ->get()
            ->filter(fn ($a) => ($a->credentials['bot_token'] ?? null) === $token)
            ->each(function ($a) use ($botName, $botUsername) {
                $creds = $a->credentials;
                $creds['bot_name'] = $botName;
                $creds['bot_username'] = $botUsername;
                $a->update(['credentials' => $creds]);
            });

        return redirect()->route('platforms.telegram')
            ->with('success', "Bot \"{$botName}\" enregistré avec succès.");
    }

    /**
     * Validate a Telegram bot token via getMe (AJAX).
     * Also updates bot_name/bot_username on existing accounts.
     */
    public function validateTelegramBot(Request $request): JsonResponse
    {
        $request->validate([
            'bot_token' => 'required|string|max:255',
        ]);

        $token = $request->input('bot_token');

        $response = Http::get("https://api.telegram.org/bot{$token}/getMe");

        if ($response->successful() && $response->json('ok')) {
            $result = $response->json('result');
            $botName = $result['first_name'] ?? 'Bot';
            $botUsername = $result['username'] ?? null;

            // Update bot_name/bot_username on all existing accounts with this token
            $platform = Platform::where('slug', 'telegram')->first();
            if ($platform) {
                SocialAccount::where('platform_id', $platform->id)
                    ->get()
                    ->filter(fn ($a) => ($a->credentials['bot_token'] ?? null) === $token)
                    ->each(function ($a) use ($botName, $botUsername) {
                        $creds = $a->credentials;
                        $creds['bot_name'] = $botName;
                        $creds['bot_username'] = $botUsername;
                        $a->update(['credentials' => $creds]);
                    });
            }

            return response()->json([
                'success' => true,
                'bot_name' => $botName,
                'bot_username' => $botUsername,
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => $response->json('description', 'Token invalide.'),
        ], 422);
    }

    /**
     * Add a Telegram channel after validating bot (getMe) and channel (getChat).
     */
    public function addTelegramChannel(Request $request): RedirectResponse
    {
        $request->validate([
            'bot_token' => 'required|string|max:255',
            'channel_id' => 'required|string|max:255',
        ]);

        $token = $request->input('bot_token');
        $channelId = $request->input('channel_id');

        // Validate bot token
        $botResponse = Http::get("https://api.telegram.org/bot{$token}/getMe");
        if ($botResponse->failed() || ! $botResponse->json('ok')) {
            return back()
                ->with('error', 'Token de bot invalide.')
                ->withInput();
        }

        $botResult = $botResponse->json('result');
        $botName = $botResult['first_name'] ?? 'Bot';
        $botUsername = $botResult['username'] ?? null;

        // Validate channel via getChat
        $response = Http::get("https://api.telegram.org/bot{$token}/getChat", [
            'chat_id' => $channelId,
        ]);

        if ($response->failed() || ! $response->json('ok')) {
            return back()
                ->with('error', 'Canal introuvable : '.($response->json('description') ?? 'Erreur inconnue'))
                ->withInput();
        }

        $chat = $response->json('result');
        $chatId = (string) $chat['id'];
        $chatTitle = $chat['title'] ?? $chat['username'] ?? $channelId;

        $telegramPlatform = Platform::where('slug', 'telegram')->firstOrFail();
        $user = $request->user();

        // Create or update social account
        $account = SocialAccount::where('platform_id', $telegramPlatform->id)
            ->where('platform_account_id', $chatId)
            ->first();

        $credentials = [
            'bot_token' => $token,
            'chat_id' => $chatId,
            'bot_name' => $botName,
            'bot_username' => $botUsername,
        ];

        if ($account) {
            $account->update([
                'name' => $chatTitle,
                'credentials' => $credentials,
            ]);
        } else {
            $account = SocialAccount::create([
                'platform_id' => $telegramPlatform->id,
                'platform_account_id' => $chatId,
                'name' => $chatTitle,
                'credentials' => $credentials,
                'language' => $user->default_language ?? 'fr',
                'is_active' => true,
            ]);
        }

        // Link to current user
        if (! $account->users()->where('user_id', $user->id)->exists()) {
            $account->users()->attach($user->id);
        }

        return redirect()->route('platforms.telegram')
            ->with('success', "Canal Telegram \"{$chatTitle}\" connecté avec succès.");
    }

    /**
     * Add a Twitter/X account with API credentials.
     */
    public function addTwitterAccount(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'api_key' => 'required|string|max:500',
            'api_secret' => 'required|string|max:500',
            'access_token' => 'required|string|max:500',
            'access_token_secret' => 'required|string|max:500',
        ]);

        $twitterPlatform = Platform::where('slug', 'twitter')->firstOrFail();
        $user = $request->user();

        $credentials = [
            'api_key' => $validated['api_key'],
            'api_secret' => $validated['api_secret'],
            'access_token' => $validated['access_token'],
            'access_token_secret' => $validated['access_token_secret'],
        ];

        $account = SocialAccount::create([
            'platform_id' => $twitterPlatform->id,
            'platform_account_id' => null,
            'name' => $validated['name'],
            'credentials' => $credentials,
            'language' => $user->default_language ?? 'fr',
            'is_active' => true,
        ]);

        if (! $account->users()->where('user_id', $user->id)->exists()) {
            $account->users()->attach($user->id);
        }

        return redirect()->route('platforms.twitter')
            ->with('success', "Compte Twitter \"{$validated['name']}\" ajouté avec succès.");
    }

    /**
     * Validate a Twitter account's credentials via OAuth 1.0a (AJAX).
     * Calls GET /2/users/me to verify and retrieve username + profile picture.
     */
    public function validateTwitterAccount(Request $request): JsonResponse
    {
        $request->validate([
            'account_id' => 'required|integer',
        ]);

        $account = SocialAccount::findOrFail($request->input('account_id'));
        $user = $request->user();

        if (! $user->is_admin && ! $account->users()->where('user_id', $user->id)->exists()) {
            return response()->json(['success' => false, 'error' => 'Non autorisé.'], 403);
        }

        $creds = $account->credentials;
        $apiKey = $creds['api_key'] ?? null;
        $apiSecret = $creds['api_secret'] ?? null;
        $accessToken = $creds['access_token'] ?? null;
        $accessTokenSecret = $creds['access_token_secret'] ?? null;

        if (! $apiKey || ! $apiSecret || ! $accessToken || ! $accessTokenSecret) {
            return response()->json(['success' => false, 'error' => 'Credentials incomplets.'], 422);
        }

        $url = 'https://api.twitter.com/2/users/me';
        $params = ['user.fields' => 'profile_image_url,username,name'];
        $fullUrl = $url.'?'.http_build_query($params);

        $oauthHeader = $this->buildOAuth1Header('GET', $url, $params, $apiKey, $apiSecret, $accessToken, $accessTokenSecret);

        $response = Http::withHeaders([
            'Authorization' => $oauthHeader,
        ])->get($fullUrl);

        if ($response->successful() && $response->json('data')) {
            $data = $response->json('data');
            $twitterUsername = $data['username'] ?? null;
            $twitterName = $data['name'] ?? $account->name;
            $profilePic = $data['profile_image_url'] ?? null;

            // Get original size profile picture (remove _normal suffix)
            if ($profilePic) {
                $profilePic = str_replace('_normal', '', $profilePic);
            }

            $account->update([
                'platform_account_id' => $data['id'] ?? $account->platform_account_id,
                'name' => $twitterName,
                'profile_picture_url' => $profilePic,
            ]);

            return response()->json([
                'success' => true,
                'username' => $twitterUsername,
                'name' => $twitterName,
                'profile_picture_url' => $profilePic,
            ]);
        }

        $errorMsg = $response->json('detail')
            ?? $response->json('errors.0.message')
            ?? $response->json('title')
            ?? 'Credentials invalides (HTTP '.$response->status().')';

        return response()->json(['success' => false, 'error' => $errorMsg], 422);
    }

    /**
     * Build OAuth 1.0a Authorization header (HMAC-SHA1).
     */
    private function buildOAuth1Header(string $method, string $url, array $extraParams, string $consumerKey, string $consumerSecret, string $token, string $tokenSecret): string
    {
        $oauthParams = [
            'oauth_consumer_key' => $consumerKey,
            'oauth_nonce' => bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => (string) time(),
            'oauth_token' => $token,
            'oauth_version' => '1.0',
        ];

        // Merge OAuth params + query params for signature base
        $allParams = array_merge($oauthParams, $extraParams);
        ksort($allParams);

        $paramString = http_build_query($allParams, '', '&', PHP_QUERY_RFC3986);
        $baseString = strtoupper($method).'&'.rawurlencode($url).'&'.rawurlencode($paramString);
        $signingKey = rawurlencode($consumerSecret).'&'.rawurlencode($tokenSecret);

        $signature = base64_encode(hash_hmac('sha1', $baseString, $signingKey, true));
        $oauthParams['oauth_signature'] = $signature;

        $headerParts = [];
        foreach ($oauthParams as $k => $v) {
            $headerParts[] = rawurlencode($k).'="'.rawurlencode($v).'"';
        }

        return 'OAuth '.implode(', ', $headerParts);
    }

    /**
     * Delete a Telegram bot and all its channels.
     */
    public function destroyTelegramBot(Request $request): RedirectResponse
    {
        $request->validate([
            'bot_token' => 'required|string|max:255',
        ]);

        $token = $request->input('bot_token');
        $user = $request->user();
        $platform = Platform::where('slug', 'telegram')->firstOrFail();

        $accounts = SocialAccount::where('platform_id', $platform->id)
            ->get()
            ->filter(fn ($a) => ($a->credentials['bot_token'] ?? null) === $token);

        if (! $user->is_admin) {
            $accounts = $accounts->filter(fn ($a) => $a->users()->where('user_id', $user->id)->exists());
        }

        $count = $accounts->count();
        $accounts->each(function ($a) {
            $a->users()->detach();
            $a->delete();
        });

        return redirect()->route('platforms.telegram')
            ->with('success', "Bot et ses {$count} compte(s) supprimés avec succès.");
    }

    /**
     * Delete a single social account (channel, Twitter account, FB/IG account).
     */
    public function destroyAccount(Request $request, SocialAccount $account): RedirectResponse
    {
        $user = $request->user();

        if (! $user->is_admin && ! $account->users()->where('user_id', $user->id)->exists()) {
            abort(403);
        }

        $slug = $account->platform->slug;
        $name = $account->name;

        $account->users()->detach();
        $account->delete();

        $route = match ($slug) {
            'threads' => 'platforms.threads',
            'telegram' => 'platforms.telegram',
            'twitter' => 'platforms.twitter',
            default => 'platforms.facebook',
        };

        return redirect()->route($route)
            ->with('success', "\"{$name}\" supprimé avec succès.");
    }

    /**
     * Load accounts for the given platform slugs.
     */
    private function accountsForSlugs(Request $request, array $slugs)
    {
        $user = $request->user();
        $platformIds = Platform::whereIn('slug', $slugs)->pluck('id');

        if ($user->is_admin) {
            return SocialAccount::with('platform')
                ->whereIn('platform_id', $platformIds)
                ->orderBy('name')
                ->get();
        }

        return $user->socialAccounts()
            ->with('platform')
            ->whereIn('platform_id', $platformIds)
            ->orderBy('name')
            ->get();
    }
}
