<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LocationController extends Controller
{
    private const API_BASE = 'https://graph.facebook.com/v21.0';

    /**
     * Search for places via Facebook Pages Search API.
     * Requires "Page Public Metadata Access" feature enabled in Meta App dashboard.
     *
     * GET /api/locations/search?q=Paris
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'required|string|min:2|max:255',
        ]);

        $query = $request->input('q');
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::warning('LocationController: No access token available for places search');

            return response()->json([], 200);
        }

        try {
            $response = Http::get(self::API_BASE . '/pages/search', [
                'q' => $query,
                'fields' => 'id,name,location,category',
                'limit' => 10,
                'access_token' => $accessToken,
            ]);

            if ($response->failed()) {
                Log::error('LocationController: Pages search failed', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                ]);

                return response()->json([], 200);
            }

            $data = $response->json('data', []);

            // Filter to only pages that have a location (= places)
            $results = collect($data)
                ->filter(fn ($page) => ! empty($page['location']))
                ->map(function ($page) {
                    $location = $page['location'] ?? [];

                    return [
                        'id' => $page['id'],
                        'name' => $page['name'],
                        'city' => $location['city'] ?? null,
                        'country' => $location['country'] ?? null,
                        'street' => $location['street'] ?? null,
                        'category' => $page['category'] ?? null,
                    ];
                })
                ->values()
                ->all();

            return response()->json($results);
        } catch (\Throwable $e) {
            Log::error('LocationController: Pages search exception', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([], 200);
        }
    }

    /**
     * Get an access token for the search.
     * Prefers a user access token (required for /pages/search with pages_read_engagement).
     */
    private function getAccessToken(): ?string
    {
        // /pages/search requires a USER access token with pages_read_engagement
        $fbAccount = SocialAccount::whereHas('platform', fn ($q) => $q->where('slug', 'facebook'))
            ->first();

        if ($fbAccount) {
            $creds = $fbAccount->credentials;
            if (! empty($creds['user_access_token'])) {
                return $creds['user_access_token'];
            }
            // Fallback to page token (less likely to work for /pages/search)
            if (! empty($creds['access_token'])) {
                return $creds['access_token'];
            }
        }

        // Fallback to app token
        $appId = config('services.facebook.client_id');
        $appSecret = config('services.facebook.client_secret');

        if ($appId && $appSecret) {
            return "{$appId}|{$appSecret}";
        }

        return null;
    }
}
