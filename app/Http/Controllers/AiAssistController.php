<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Services\AiAssistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiAssistController extends Controller
{
    public function generate(Request $request): JsonResponse
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $validated = $request->validate([
            'content' => 'nullable|string|max:10000',
            'account_id' => 'required|exists:social_accounts,id',
        ]);

        $account = SocialAccount::with(['platform', 'persona'])->findOrFail($validated['account_id']);
        $persona = $account->persona;

        if (! $persona) {
            return response()->json([
                'error' => 'Aucune persona configurée pour ce compte. Allez dans Comptes > Modifier pour associer une persona.',
            ], 422);
        }

        $service = new AiAssistService;
        $result = $service->generate($validated['content'] ?? '', $persona, $account);

        if (! $result) {
            return response()->json([
                'error' => 'Impossible de générer le contenu. Vérifiez la clé API OpenAI dans les paramètres.',
            ], 422);
        }

        return response()->json(['content' => $result]);
    }
}
