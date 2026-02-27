<?php

namespace App\Services;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YouTubeTokenHelper
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * Get a fresh access token for a YouTube account.
     *
     * Always refreshes the token since YouTube tokens expire after 1 hour.
     * Falls back to the stored token if refresh fails.
     */
    public static function getFreshAccessToken(SocialAccount $account): ?string
    {
        $credentials = $account->credentials;
        $refreshToken = $credentials['refresh_token'] ?? null;

        if (! $refreshToken) {
            return $credentials['access_token'] ?? null;
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => config('services.youtube.client_id'),
            'client_secret' => config('services.youtube.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            Log::warning('YouTubeTokenHelper: refresh failed, using existing token', [
                'account_id' => $account->id,
                'status' => $response->status(),
            ]);

            return $credentials['access_token'] ?? null;
        }

        $newAccessToken = $response->json('access_token');

        // Update stored credentials
        $credentials['access_token'] = $newAccessToken;
        $account->update(['credentials' => $credentials]);

        return $newAccessToken;
    }
}
