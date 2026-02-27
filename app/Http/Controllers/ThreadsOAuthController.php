<?php

namespace App\Http\Controllers;

use App\Models\Platform;
use App\Models\SocialAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ThreadsOAuthController extends Controller
{
    private const API_BASE = 'https://graph.threads.net/v1.0';

    /**
     * Step 1: Redirect to Threads OAuth authorization.
     */
    public function redirect(): RedirectResponse
    {
        $state = Str::random(40);
        session(['threads_oauth_state' => $state]);

        $params = http_build_query([
            'client_id' => config('services.threads.client_id'),
            'redirect_uri' => route('threads.callback'),
            'scope' => 'threads_basic,threads_content_publish,threads_location_tagging,threads_manage_insights,threads_manage_replies,threads_read_replies',
            'response_type' => 'code',
            'state' => $state,
        ]);

        return redirect("https://threads.net/oauth/authorize?{$params}");
    }

    /**
     * Step 2: Handle the callback from Threads.
     * Exchange code → short-lived → long-lived → fetch profile → create account.
     */
    public function callback(Request $request): RedirectResponse
    {
        // Verify state
        if ($request->input('state') !== session('threads_oauth_state')) {
            return redirect()->route('platforms.threads')
                ->with('error', 'État OAuth invalide. Veuillez réessayer.');
        }

        session()->forget('threads_oauth_state');

        // Check for errors
        if ($request->has('error')) {
            return redirect()->route('platforms.threads')
                ->with('error', 'Autorisation Threads refusée : ' . $request->input('error_description', 'Erreur inconnue'));
        }

        $code = $request->input('code');
        if (! $code) {
            return redirect()->route('platforms.threads')
                ->with('error', 'Code d\'autorisation manquant.');
        }

        try {
            // Exchange code for short-lived token
            $tokenData = $this->exchangeCodeForToken($code);
            if (! $tokenData) {
                return redirect()->route('platforms.threads')
                    ->with('error', 'Impossible d\'obtenir le token Threads.');
            }

            $shortLivedToken = $tokenData['access_token'];
            $userId = (string) $tokenData['user_id'];

            // Exchange for long-lived token (60 days)
            $longLivedToken = $this->exchangeForLongLivedToken($shortLivedToken);
            if (! $longLivedToken) {
                $longLivedToken = $shortLivedToken; // Fallback to short-lived
            }

            // Fetch user profile
            $profile = $this->fetchProfile($longLivedToken);
            $username = $profile['username'] ?? 'Threads User';
            $profilePicture = $profile['threads_profile_picture_url'] ?? null;

            // Create or update social account
            $threadsPlatform = Platform::where('slug', 'threads')->firstOrFail();
            $user = $request->user();

            $account = SocialAccount::where('platform_id', $threadsPlatform->id)
                ->where('platform_account_id', $userId)
                ->first();

            if ($account) {
                $account->update([
                    'name' => $username,
                    'profile_picture_url' => $profilePicture ?? $account->profile_picture_url,
                    'credentials' => [
                        'user_id' => $userId,
                        'access_token' => $longLivedToken,
                    ],
                ]);
            } else {
                $account = SocialAccount::create([
                    'platform_id' => $threadsPlatform->id,
                    'platform_account_id' => $userId,
                    'name' => $username,
                    'profile_picture_url' => $profilePicture,
                    'credentials' => [
                        'user_id' => $userId,
                        'access_token' => $longLivedToken,
                    ],
                    'languages' => [$user->default_language ?? 'fr'],
                ]);
            }

            // Link to current user
            if (! $account->users()->where('user_id', $user->id)->exists()) {
                $account->users()->attach($user->id, ['is_active' => true]);
            }

            session()->forget('threads_oauth_state');

            return redirect()->route('platforms.threads')
                ->with('success', "Compte Threads \"{$username}\" connecté avec succès.");
        } catch (\Throwable $e) {
            Log::error('Threads OAuth error', ['message' => $e->getMessage()]);

            return redirect()->route('platforms.threads')
                ->with('error', 'Erreur lors de la connexion Threads : ' . $e->getMessage());
        }
    }

    // ─── API helpers ──────────────────────────────────────────

    private function exchangeCodeForToken(string $code): ?array
    {
        $response = Http::asForm()->post('https://graph.threads.net/oauth/access_token', [
            'client_id' => config('services.threads.client_id'),
            'client_secret' => config('services.threads.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => route('threads.callback'),
        ]);

        if ($response->failed()) {
            Log::error('Threads token exchange failed', ['body' => $response->body()]);
            return null;
        }

        $data = $response->json();
        if (empty($data['access_token']) || empty($data['user_id'])) {
            Log::error('Threads token exchange: missing fields', ['data' => $data]);
            return null;
        }

        return $data;
    }

    private function exchangeForLongLivedToken(string $shortLivedToken): ?string
    {
        $response = Http::get('https://graph.threads.net/access_token', [
            'grant_type' => 'th_exchange_token',
            'client_secret' => config('services.threads.client_secret'),
            'access_token' => $shortLivedToken,
        ]);

        if ($response->failed()) {
            Log::error('Threads long-lived token exchange failed', ['body' => $response->body()]);
            return null;
        }

        return $response->json('access_token');
    }

    private function fetchProfile(string $accessToken): array
    {
        $response = Http::get(self::API_BASE . '/me', [
            'fields' => 'id,username,threads_profile_picture_url',
            'access_token' => $accessToken,
        ]);

        if ($response->failed()) {
            Log::error('Threads fetch profile failed', ['body' => $response->body()]);
            return [];
        }

        return $response->json() ?? [];
    }
}
