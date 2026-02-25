<?php

namespace App\Http\Controllers;

use App\Models\Hashtag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HashtagController extends Controller
{
    /**
     * Récupérer les hashtags les plus utilisés par l'utilisateur connecté
     */
    public function index(Request $request): JsonResponse
    {
        $hashtags = Hashtag::where('user_id', $request->user()->id)
            ->orderByDesc('usage_count')
            ->orderByDesc('last_used_at')
            ->limit(20)
            ->get(['tag', 'usage_count'])
            ->map(fn($h) => $h->tag);

        return response()->json($hashtags);
    }
}
