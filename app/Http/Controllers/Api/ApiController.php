<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiController extends Controller
{
    /**
     * GET /api/me — Infos utilisateur courant.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
        ]);
    }

    /**
     * GET /api/accounts — Liste des comptes sociaux actifs de l'utilisateur.
     */
    public function accounts(Request $request): JsonResponse
    {
        $user = $request->user();

        $accounts = $user->activeSocialAccounts()
            ->with('platform')
            ->orderBy('name')
            ->get()
            ->map(fn (SocialAccount $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'platform' => $a->platform->slug,
                'platform_name' => $a->platform->name,
                // Identifiant côté plateforme (Facebook = page_id, Telegram = chat_id…).
                // Exposé pour que l'extension Chrome rattache toute seule la page
                // consultée au bon compte, sans que l'utilisateur ait à le choisir.
                'platform_account_id' => $a->platform_account_id,
                'languages' => $a->languages ?? ['fr'],
                'followers_count' => $a->followers_count,
                'browser_followers_observation' => $a->browser_followers_observation,
            ]);

        return response()->json(['accounts' => $accounts]);
    }
}
