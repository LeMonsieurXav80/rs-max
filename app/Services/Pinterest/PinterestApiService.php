<?php

namespace App\Services\Pinterest;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PinterestApiService
{
    private const API_BASE = 'https://api.pinterest.com/v5';
    private const OAUTH_URL = 'https://www.pinterest.com/oauth/';
    private const TOKEN_URL = 'https://api.pinterest.com/v5/oauth/token';

    public function getAuthorizationUrl(string $state): string
    {
        $params = http_build_query([
            'client_id' => config('services.pinterest.client_id'),
            'redirect_uri' => config('services.pinterest.redirect'),
            'response_type' => 'code',
            'scope' => 'user_accounts:read,boards:read,pins:read',
            'state' => $state,
        ]);

        return self::OAUTH_URL . '?' . $params;
    }

    public function exchangeCodeForTokens(string $code): ?array
    {
        $response = Http::withBasicAuth(
            config('services.pinterest.client_id'),
            config('services.pinterest.client_secret')
        )->asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('services.pinterest.redirect'),
        ]);

        if (! $response->successful()) {
            Log::error('Pinterest token exchange failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return $response->json();
    }

    public function refreshToken(string $refreshToken): ?array
    {
        $response = Http::withBasicAuth(
            config('services.pinterest.client_id'),
            config('services.pinterest.client_secret')
        )->asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        if (! $response->successful()) {
            Log::error('Pinterest token refresh failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return $response->json();
    }

    public function getUserAccount(string $accessToken): ?array
    {
        $response = Http::withToken($accessToken)
            ->get(self::API_BASE . '/user_account');

        if (! $response->successful()) {
            return null;
        }

        return $response->json();
    }

    public function getBoards(SocialAccount $account): array
    {
        $accessToken = $this->getValidToken($account);
        if (! $accessToken) {
            return [];
        }

        $boards = [];
        $bookmark = null;

        do {
            $params = ['page_size' => 25];
            if ($bookmark) {
                $params['bookmark'] = $bookmark;
            }

            $response = Http::withToken($accessToken)
                ->get(self::API_BASE . '/boards', $params);

            if (! $response->successful()) {
                break;
            }

            $data = $response->json();
            foreach ($data['items'] ?? [] as $board) {
                $boards[] = [
                    'id' => $board['id'],
                    'name' => $board['name'],
                    'description' => $board['description'] ?? '',
                    'pin_count' => $board['pin_count'] ?? 0,
                    'privacy' => $board['privacy'] ?? 'PUBLIC',
                ];
            }

            $bookmark = $data['bookmark'] ?? null;
        } while ($bookmark);

        return $boards;
    }

    public function getBoardPins(SocialAccount $account, string $boardId, int $limit = 25): array
    {
        $accessToken = $this->getValidToken($account);
        if (! $accessToken) {
            return [];
        }

        $response = Http::withToken($accessToken)
            ->get(self::API_BASE . "/boards/{$boardId}/pins", [
                'page_size' => min($limit, 25),
            ]);

        if (! $response->successful()) {
            return [];
        }

        return $response->json('items', []);
    }

    private function getValidToken(SocialAccount $account): ?string
    {
        $credentials = $account->credentials;
        $accessToken = $credentials['access_token'] ?? null;
        $refreshToken = $credentials['refresh_token'] ?? null;

        if (! $accessToken || ! $refreshToken) {
            return null;
        }

        // Try current token first via a lightweight call
        $test = Http::withToken($accessToken)
            ->get(self::API_BASE . '/user_account');

        if ($test->successful()) {
            return $accessToken;
        }

        // Token expired, refresh it
        $tokens = $this->refreshToken($refreshToken);
        if (! $tokens) {
            return null;
        }

        $account->update([
            'credentials' => array_merge($credentials, [
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?? $refreshToken,
            ]),
        ]);

        return $tokens['access_token'];
    }
}
