<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Persona;
use App\Services\AiAssistService;
use App\Services\ThreadContentGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GenerateApiController extends Controller
{
    /**
     * POST /api/generate — Générer un contenu IA (preview, sans créer de post).
     *
     * {
     *   "instructions": "Un tweet sur le SEO local",
     *   "account_id": 3,          // optionnel, pour la persona et la plateforme
     *   "persona_id": 1,          // optionnel, override persona du compte
     *   "platforms": ["twitter"]   // optionnel, pour du contenu multi-plateforme
     * }
     */
    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'instructions' => 'required|string|max:5000',
            'account_id' => 'nullable|integer|exists:social_accounts,id',
            'persona_id' => 'nullable|integer|exists:personas,id',
            'platforms' => 'nullable|array',
            'platforms.*' => 'string|in:facebook,instagram,threads,twitter,telegram,youtube,bluesky,reddit,linkedin,pinterest',
        ]);

        $user = $request->user();
        $account = null;
        $persona = null;

        if (! empty($validated['account_id'])) {
            $account = $user->activeSocialAccounts()
                ->with(['platform', 'persona'])
                ->find($validated['account_id']);

            if (! $account) {
                return response()->json(['error' => 'Compte non trouvé ou inactif.'], 403);
            }
        }

        if (! empty($validated['persona_id'])) {
            $persona = Persona::find($validated['persona_id']);
        }
        $persona = $persona ?? $account?->persona;

        if (! $persona) {
            return response()->json(['error' => 'Persona requise (via account_id ou persona_id).'], 422);
        }

        $aiService = app(AiAssistService::class);

        // Multi-plateforme
        if (! empty($validated['platforms'])) {
            $result = $aiService->generateForPlatforms(
                $validated['instructions'],
                $validated['platforms'],
                $persona,
                $account
            );

            if (! $result) {
                return response()->json(['error' => 'Échec de la génération IA.'], 500);
            }

            return response()->json(['generated' => $result]);
        }

        // Mono
        $result = $aiService->generate($validated['instructions'], $persona, $account);

        if (! $result) {
            return response()->json(['error' => 'Échec de la génération IA.'], 500);
        }

        return response()->json(['generated' => $result]);
    }

    /**
     * POST /api/generate-thread — Générer un thread IA (preview).
     *
     * Depuis une URL :
     * { "source_url": "https://...", "account_id": 3 }
     *
     * Depuis des instructions :
     * { "instructions": "Thread sur le SEO technique", "account_id": 3 }
     */
    public function generateThread(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_url' => 'nullable|url|max:2048',
            'instructions' => 'nullable|string|max:5000',
            'account_id' => 'nullable|integer|exists:social_accounts,id',
            'persona_id' => 'nullable|integer|exists:personas,id',
            'platforms' => 'nullable|array',
            'platforms.*' => 'string|in:facebook,instagram,threads,twitter,telegram,youtube,bluesky',
        ]);

        if (empty($validated['source_url']) && empty($validated['instructions'])) {
            return response()->json(['error' => 'source_url ou instructions requis.'], 422);
        }

        $user = $request->user();
        $account = null;
        $persona = null;

        if (! empty($validated['account_id'])) {
            $account = $user->activeSocialAccounts()
                ->with(['platform', 'persona'])
                ->find($validated['account_id']);
        }

        if (! empty($validated['persona_id'])) {
            $persona = Persona::find($validated['persona_id']);
        }
        $persona = $persona ?? $account?->persona;

        if (! $persona) {
            return response()->json(['error' => 'Persona requise (via account_id ou persona_id).'], 422);
        }

        $platformSlugs = $validated['platforms'] ?? ['twitter', 'threads'];
        $threadService = app(ThreadContentGenerationService::class);

        if (! empty($validated['source_url'])) {
            $result = $threadService->generate(
                $validated['source_url'],
                $persona,
                $platformSlugs,
                null,
                $validated['instructions'] ?? null
            );
        } else {
            $result = $threadService->generateFromInstructions(
                $validated['instructions'],
                $persona,
                $platformSlugs
            );
        }

        if (! $result) {
            return response()->json(['error' => 'Échec de la génération du thread.'], 500);
        }

        return response()->json(['generated' => $result]);
    }
}
