<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Persona;
use App\Models\Setting;
use App\Services\AiAssistService;
use App\Services\ThreadContentGenerationService;
use App\Services\TranslationService;
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

        // Génère le content_fr (toujours en français — ne pas passer $account
        // pour éviter que languages[0] du compte force une autre langue)
        $contentFr = $aiService->generate($validated['instructions'], $persona);

        if (! $contentFr) {
            return response()->json(['error' => 'Échec de la génération IA.'], 500);
        }

        $result = ['content_fr' => $contentFr];

        // Traduire dans les langues du compte (hors français)
        if ($account) {
            $languages = $account->languages ?? ['fr'];
            $translations = $this->translateToLanguages($contentFr, $languages);
            if (! empty($translations)) {
                $result['translations'] = $translations;
            }
        }

        // Multi-plateforme : génère aussi les versions adaptées par plateforme
        if (! empty($validated['platforms'])) {
            $platformContents = $aiService->generateForPlatforms(
                $contentFr,
                $validated['platforms'],
                $persona,
                $account
            );
            $result['platform_contents'] = $platformContents ?? [];

            // Traduire aussi les platform_contents dans les langues du compte
            if ($account && ! empty($platformContents)) {
                $languages = $account->languages ?? ['fr'];
                $otherLangs = array_filter($languages, fn ($l) => $l !== 'fr');
                if (! empty($otherLangs)) {
                    $platformTranslations = [];
                    $translator = app(TranslationService::class);
                    $apiKey = Setting::getEncrypted('openai_api_key');
                    foreach ($otherLangs as $lang) {
                        foreach ($platformContents as $slug => $text) {
                            $translated = $translator->translate($text, 'fr', $lang, $apiKey);
                            if ($translated) {
                                $platformTranslations["{$slug}_{$lang}"] = $translated;
                            }
                        }
                    }
                    if (! empty($platformTranslations)) {
                        $result['platform_translations'] = $platformTranslations;
                    }
                }
            }
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

        // Traduire les segments dans les langues du compte
        if ($account) {
            $languages = $account->languages ?? ['fr'];
            $otherLangs = array_filter($languages, fn ($l) => $l !== 'fr');
            if (! empty($otherLangs) && ! empty($result['segments'])) {
                $translator = app(TranslationService::class);
                $apiKey = Setting::getEncrypted('openai_api_key');
                foreach ($result['segments'] as &$segment) {
                    $segment['translations'] = [];
                    foreach ($otherLangs as $lang) {
                        $translated = $translator->translate($segment['content_fr'], 'fr', $lang, $apiKey);
                        if ($translated) {
                            $segment['translations'][$lang] = $translated;
                        }
                    }
                }
                unset($segment);
            }
        }

        return response()->json(['generated' => $result]);
    }

    /**
     * Traduit un texte dans toutes les langues du compte (sauf français).
     */
    private function translateToLanguages(string $text, array $languages): array
    {
        $otherLangs = array_filter($languages, fn ($l) => $l !== 'fr');
        if (empty($otherLangs)) {
            return [];
        }

        $translator = app(TranslationService::class);
        $apiKey = Setting::getEncrypted('openai_api_key');
        $translations = [];

        foreach ($otherLangs as $lang) {
            $translated = $translator->translate($text, 'fr', $lang, $apiKey);
            if ($translated) {
                $translations[$lang] = $translated;
            }
        }

        return $translations;
    }
}
