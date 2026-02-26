<?php

namespace App\Http\Controllers;

use App\Models\Platform;
use App\Models\SocialAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

class YouTubeOAuthController extends Controller
{
    private const API_BASE = 'https://www.googleapis.com/youtube/v3';
    private const OAUTH_BASE = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * Step 1: Redirect to Google OAuth dialog for YouTube access.
     */
    public function redirect(): RedirectResponse
    {
        $state = Str::random(40);
        session(['youtube_oauth_state' => $state]);

        $params = http_build_query([
            'client_id' => config('services.youtube.client_id'),
            'redirect_uri' => config('services.youtube.redirect'),
            'scope' => implode(' ', [
                'https://www.googleapis.com/auth/youtube.upload',
                'https://www.googleapis.com/auth/youtube.readonly',
            ]),
            'response_type' => 'code',
            'state' => $state,
            'access_type' => 'offline', // Important: get refresh_token
            'prompt' => 'consent', // Force consent to always get refresh_token
        ]);

        return redirect(self::OAUTH_BASE . '?' . $params);
    }

    /**
     * Step 2: Handle OAuth callback from Google.
     * Exchange code for access_token and refresh_token, fetch channel info.
     */
    public function callback(Request $request): RedirectResponse
    {
        // Verify state
        if ($request->input('state') !== session('youtube_oauth_state')) {
            return redirect()->route('accounts.index')
                ->with('error', 'État OAuth invalide. Veuillez réessayer.');
        }

        session()->forget('youtube_oauth_state');

        // Check for errors
        if ($request->has('error')) {
            return redirect()->route('accounts.index')
                ->with('error', 'Autorisation YouTube refusée : ' . $request->input('error', 'Erreur inconnue'));
        }

        $code = $request->input('code');
        if (! $code) {
            return redirect()->route('accounts.index')
                ->with('error', 'Code d\'autorisation manquant.');
        }

        try {
            // Exchange code for tokens
            $tokens = $this->exchangeCodeForTokens($code);
            if (! $tokens) {
                return redirect()->route('accounts.index')
                    ->with('error', 'Impossible d\'obtenir les tokens YouTube.');
            }

            // Fetch channel information
            $channel = $this->fetchChannelInfo($tokens['access_token']);
            if (! $channel) {
                return redirect()->route('accounts.index')
                    ->with('error', 'Impossible de récupérer les informations de la chaîne YouTube.');
            }

            // Store channel data with tokens
            session([
                'youtube_oauth_channel' => [
                    'channel_id' => $channel['id'],
                    'channel_name' => $channel['title'],
                    'channel_thumbnail' => $channel['thumbnail'],
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'],
                    'expires_in' => $tokens['expires_in'],
                ],
            ]);

            // Redirect to selection page
            return redirect()->route('youtube.select');
        } catch (\Exception $e) {
            Log::error('YouTube OAuth callback error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->route('accounts.index')
                ->with('error', 'Erreur lors de la connexion YouTube : ' . $e->getMessage());
        }
    }

    /**
     * Step 3: Display channel selection page.
     */
    public function select(): View
    {
        $channel = session('youtube_oauth_channel');

        if (! $channel) {
            return redirect()->route('accounts.index')
                ->with('error', 'Session YouTube expirée. Veuillez réessayer.');
        }

        return view('platforms.youtube-select', ['channel' => $channel]);
    }

    /**
     * Step 4: Store the selected YouTube channel.
     */
    public function store(Request $request): RedirectResponse
    {
        $channelData = session('youtube_oauth_channel');

        if (! $channelData) {
            return redirect()->route('accounts.index')
                ->with('error', 'Session YouTube expirée. Veuillez réessayer.');
        }

        $platform = Platform::where('slug', 'youtube')->first();
        if (! $platform) {
            return redirect()->route('accounts.index')
                ->with('error', 'Plateforme YouTube non configurée.');
        }

        $user = $request->user();

        // Check if channel already exists
        $existingAccount = SocialAccount::where('platform_id', $platform->id)
            ->where('platform_account_id', $channelData['channel_id'])
            ->first();

        if ($existingAccount) {
            // Update tokens
            $existingAccount->update([
                'credentials' => [
                    'channel_id' => $channelData['channel_id'],
                    'access_token' => $channelData['access_token'],
                    'refresh_token' => $channelData['refresh_token'],
                    'expires_in' => $channelData['expires_in'],
                ],
                'profile_picture_url' => $channelData['channel_thumbnail'],
            ]);

            // Attach to user if not already attached
            if (! $existingAccount->users()->where('user_id', $user->id)->exists()) {
                $existingAccount->users()->attach($user->id);
            }

            session()->forget('youtube_oauth_channel');

            return redirect()->route('accounts.index')
                ->with('success', "Chaîne YouTube '{$channelData['channel_name']}' mise à jour avec succès!");
        }

        // Create new account
        $account = SocialAccount::create([
            'platform_id' => $platform->id,
            'platform_account_id' => $channelData['channel_id'],
            'name' => $channelData['channel_name'],
            'credentials' => [
                'channel_id' => $channelData['channel_id'],
                'access_token' => $channelData['access_token'],
                'refresh_token' => $channelData['refresh_token'],
                'expires_in' => $channelData['expires_in'],
            ],
            'profile_picture_url' => $channelData['channel_thumbnail'],
            'is_active' => true,
        ]);

        // Attach to user
        $account->users()->attach($user->id);

        session()->forget('youtube_oauth_channel');

        return redirect()->route('accounts.index')
            ->with('success', "Chaîne YouTube '{$channelData['channel_name']}' connectée avec succès!");
    }

    /**
     * Exchange authorization code for access_token and refresh_token.
     */
    private function exchangeCodeForTokens(string $code): ?array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => config('services.youtube.client_id'),
            'client_secret' => config('services.youtube.client_secret'),
            'redirect_uri' => config('services.youtube.redirect'),
            'grant_type' => 'authorization_code',
        ]);

        if (! $response->successful()) {
            Log::error('YouTube token exchange failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $data = $response->json();

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_in' => $data['expires_in'] ?? 3600,
        ];
    }

    /**
     * Fetch YouTube channel information.
     */
    private function fetchChannelInfo(string $accessToken): ?array
    {
        $response = Http::withToken($accessToken)->get(self::API_BASE . '/channels', [
            'part' => 'snippet,contentDetails,statistics',
            'mine' => 'true',
        ]);

        if (! $response->successful()) {
            Log::error('YouTube channel fetch failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $data = $response->json();

        if (empty($data['items'])) {
            return null;
        }

        $channel = $data['items'][0];

        return [
            'id' => $channel['id'],
            'title' => $channel['snippet']['title'],
            'description' => $channel['snippet']['description'] ?? '',
            'thumbnail' => $channel['snippet']['thumbnails']['default']['url'] ?? null,
            'subscriber_count' => $channel['statistics']['subscriberCount'] ?? 0,
        ];
    }
}
