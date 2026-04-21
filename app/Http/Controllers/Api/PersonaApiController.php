<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Persona;
use App\Models\SocialAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonaApiController extends Controller
{
    /**
     * GET /api/personas — Liste des personas (détails complets).
     */
    public function index(): JsonResponse
    {
        $personas = Persona::orderBy('name')->get()->map(fn (Persona $p) => $this->formatPersona($p));

        return response()->json(['personas' => $personas]);
    }

    /**
     * GET /api/personas/{id} — Détail d'une persona.
     */
    public function show(Persona $persona): JsonResponse
    {
        return response()->json(['persona' => $this->formatPersona($persona)]);
    }

    /**
     * POST /api/personas — Créer une persona.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'system_prompt' => 'required|string|max:10000',
            'tone' => 'nullable|string|max:100',
            'language' => 'nullable|string|max:10',
            'is_active' => 'nullable|boolean',
        ]);

        $persona = Persona::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'system_prompt' => $validated['system_prompt'],
            'tone' => $validated['tone'] ?? null,
            'language' => $validated['language'] ?? 'fr',
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json(['persona' => $this->formatPersona($persona)], 201);
    }

    /**
     * PUT /api/personas/{id} — Modifier une persona.
     */
    public function update(Request $request, Persona $persona): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'system_prompt' => 'sometimes|string|max:10000',
            'tone' => 'nullable|string|max:100',
            'language' => 'nullable|string|max:10',
            'is_active' => 'nullable|boolean',
        ]);

        $persona->update($validated);

        return response()->json(['persona' => $this->formatPersona($persona)]);
    }

    /**
     * DELETE /api/personas/{id} — Supprimer une persona.
     */
    public function destroy(Persona $persona): JsonResponse
    {
        // Vérifier qu'aucun compte ne l'utilise
        $accountCount = SocialAccount::where('persona_id', $persona->id)->count();
        if ($accountCount > 0) {
            return response()->json([
                'error' => "Cette persona est utilisée par {$accountCount} compte(s). Réassignez-les d'abord.",
            ], 422);
        }

        $persona->delete();

        return response()->json(['success' => true, 'message' => 'Persona supprimée.']);
    }

    private function formatPersona(Persona $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'system_prompt' => $p->system_prompt,
            'tone' => $p->tone,
            'language' => $p->language,
            'is_active' => $p->is_active,
            'created_at' => $p->created_at->toIso8601String(),
            'updated_at' => $p->updated_at->toIso8601String(),
        ];
    }
}
