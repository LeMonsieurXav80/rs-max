<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LocationController extends Controller
{
    private const API_BASE = 'https://graph.facebook.com/v21.0';

    /**
     * Search for places via Facebook Places API.
     *
     * GET /api/locations/search?q=Paris&lat=48.8566&lng=2.3522
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'required|string|min:2|max:255',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
        ]);

        $query = $request->input('q');
        $lat = $request->input('lat');
        $lng = $request->input('lng');

        // Use app token (client_id|client_secret) for Places search
        $appId = config('services.facebook.client_id');
        $appSecret = config('services.facebook.client_secret');
        $accessToken = "{$appId}|{$appSecret}";

        $params = [
            'type' => 'place',
            'q' => $query,
            'fields' => 'id,name,location',
            'limit' => 10,
            'access_token' => $accessToken,
        ];

        if ($lat && $lng) {
            $params['center'] = "{$lat},{$lng}";
            $params['distance'] = 50000; // 50km radius
        }

        try {
            $response = Http::get(self::API_BASE . '/search', $params);

            if ($response->failed()) {
                Log::error('LocationController: Places search failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return response()->json([], 200);
            }

            $data = $response->json('data', []);

            $results = collect($data)->map(function ($place) {
                $location = $place['location'] ?? [];

                return [
                    'id' => $place['id'],
                    'name' => $place['name'],
                    'city' => $location['city'] ?? null,
                    'country' => $location['country'] ?? null,
                    'street' => $location['street'] ?? null,
                ];
            })->values()->all();

            return response()->json($results);
        } catch (\Throwable $e) {
            Log::error('LocationController: Places search exception', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([], 200);
        }
    }
}
