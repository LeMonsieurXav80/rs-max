<?php

namespace App\Services;

use App\Models\Persona;
use App\Models\Setting;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiAssistService
{
    /**
     * Generate or improve content using a persona.
     * If $content is provided, it rewrites/improves it.
     * If $content is empty, it generates from scratch.
     */
    public function generate(string $content, Persona $persona, ?SocialAccount $account = null): ?string
    {
        $apiKey = Setting::getEncrypted('openai_api_key');
        if (! $apiKey) {
            Log::warning('AiAssistService: No OpenAI API key configured');

            return null;
        }

        $language = 'fr';
        if ($account && ! empty($account->languages)) {
            $language = $account->languages[0];
        }

        $languageLabel = match ($language) {
            'fr' => 'français',
            'en' => 'anglais',
            'pt' => 'portugais',
            'es' => 'espagnol',
            'de' => 'allemand',
            'it' => 'italien',
            default => $language,
        };

        if (trim($content)) {
            $userPrompt = "Voici un brouillon/des notes pour une publication sur les réseaux sociaux.\n\n";
            $userPrompt .= "Texte :\n{$content}\n\n";
            $userPrompt .= "Réécris et améliore ce texte en {$languageLabel} en suivant le style et le ton de la persona.";
        } else {
            $userPrompt = "Génère une publication pour les réseaux sociaux en {$languageLabel} en suivant le style et le ton de la persona.";
            if ($account) {
                $userPrompt .= "\nPour le compte \"{$account->name}\" sur {$account->platform->name}.";
            }
        }

        if ($account) {
            $charLimit = (int) Setting::get(
                "platform_char_limit_{$account->platform->slug}",
                $this->getDefaultCharLimit($account->platform->slug)
            );
            if ($charLimit > 0) {
                $userPrompt .= "\n\nLe contenu ne doit pas dépasser {$charLimit} caractères (limite {$account->platform->name}).";
            }
        }

        $userPrompt .= "\nN'inclus PAS de hashtags dans le texte, ils sont gérés séparément.";

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(60)->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'system', 'content' => $persona->system_prompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.7,
                'max_tokens' => 2000,
            ]);

            if ($response->successful()) {
                $result = trim($response->json('choices.0.message.content', ''));
                Log::info('AiAssistService: Content generated', [
                    'persona' => $persona->name,
                    'account' => $account?->name,
                    'length' => mb_strlen($result),
                ]);

                return $result;
            }

            Log::error('AiAssistService: API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('AiAssistService: Exception', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Generate content adapted for multiple platforms in a single API call.
     * Returns an associative array: ['facebook' => '...', 'instagram' => '...', ...]
     */
    public function generateForPlatforms(string $content, array $platformSlugs, Persona $persona, ?SocialAccount $account = null): ?array
    {
        $apiKey = Setting::getEncrypted('openai_api_key');
        if (! $apiKey) {
            Log::warning('AiAssistService: No OpenAI API key configured');

            return null;
        }

        $language = 'fr';
        if ($account && ! empty($account->languages)) {
            $language = $account->languages[0];
        }

        $languageLabel = match ($language) {
            'fr' => 'français',
            'en' => 'anglais',
            'pt' => 'portugais',
            'es' => 'espagnol',
            'de' => 'allemand',
            'it' => 'italien',
            default => $language,
        };

        // Build platform descriptions with character limits
        $platformDescriptions = [];
        foreach ($platformSlugs as $slug) {
            $charLimit = (int) Setting::get(
                "platform_char_limit_{$slug}",
                $this->getDefaultCharLimit($slug)
            );
            $label = match ($slug) {
                'facebook' => 'Facebook',
                'instagram' => 'Instagram',
                'threads' => 'Threads',
                'twitter' => 'Twitter/X',
                'telegram' => 'Telegram',
                'youtube' => 'YouTube',
                default => ucfirst($slug),
            };
            $platformDescriptions[] = "{$label} (max {$charLimit} caractères)";
        }

        $platformList = implode(', ', $platformDescriptions);

        if (trim($content)) {
            $userPrompt = "Voici un brouillon/des notes pour une publication sur les réseaux sociaux.\n\n";
            $userPrompt .= "Texte :\n{$content}\n\n";
            $userPrompt .= "Génère une version adaptée pour chaque plateforme en {$languageLabel} : {$platformList}.\n";
        } else {
            $userPrompt = "Génère une publication pour les réseaux sociaux en {$languageLabel} adaptée à chaque plateforme : {$platformList}.\n";
            if ($account) {
                $userPrompt .= "Pour le compte \"{$account->name}\".\n";
            }
        }

        $userPrompt .= "\nAdapte le ton et la longueur à chaque plateforme. Respecte les limites de caractères.";
        $userPrompt .= "\nN'inclus PAS de hashtags dans le texte, ils sont gérés séparément.";
        $userPrompt .= "\n\nRéponds UNIQUEMENT en JSON valide avec les clés suivantes : "
            . implode(', ', array_map(fn ($s) => "\"{$s}\"", $platformSlugs))
            . '. Chaque valeur est le texte pour cette plateforme. Pas de markdown, pas d\'explication, juste le JSON.';

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(90)->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'system', 'content' => $persona->system_prompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.7,
                'max_tokens' => 4000,
                'response_format' => ['type' => 'json_object'],
            ]);

            if ($response->successful()) {
                $raw = trim($response->json('choices.0.message.content', ''));
                $parsed = json_decode($raw, true);

                if (is_array($parsed)) {
                    $result = array_intersect_key($parsed, array_flip($platformSlugs));
                    Log::info('AiAssistService: Multi-platform content generated', [
                        'persona' => $persona->name,
                        'platforms' => $platformSlugs,
                        'lengths' => array_map('mb_strlen', $result),
                    ]);

                    return $result;
                }

                Log::error('AiAssistService: Failed to parse JSON response', ['raw' => $raw]);

                return null;
            }

            Log::error('AiAssistService: API error (multi-platform)', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('AiAssistService: Multi-platform exception', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Generate platform-specific content by analyzing images/video frames via OpenAI Vision API.
     * $imageDataUrls is an array of base64 data URLs ("data:image/jpeg;base64,...").
     */
    public function generateFromMediaForPlatforms(array $imageDataUrls, array $platformSlugs, Persona $persona, ?SocialAccount $account = null, string $content = ''): ?array
    {
        $apiKey = Setting::getEncrypted('openai_api_key');
        if (! $apiKey) {
            Log::warning('AiAssistService: No OpenAI API key configured');

            return null;
        }

        if (empty($imageDataUrls)) {
            return null;
        }

        $language = 'fr';
        if ($account && ! empty($account->languages)) {
            $language = $account->languages[0];
        }

        $languageLabel = match ($language) {
            'fr' => 'français',
            'en' => 'anglais',
            'pt' => 'portugais',
            'es' => 'espagnol',
            'de' => 'allemand',
            'it' => 'italien',
            default => $language,
        };

        // Build platform descriptions with character limits
        $platformDescriptions = [];
        foreach ($platformSlugs as $slug) {
            $charLimit = (int) Setting::get(
                "platform_char_limit_{$slug}",
                $this->getDefaultCharLimit($slug)
            );
            $label = match ($slug) {
                'facebook' => 'Facebook',
                'instagram' => 'Instagram',
                'threads' => 'Threads',
                'twitter' => 'Twitter/X',
                'telegram' => 'Telegram',
                'youtube' => 'YouTube',
                default => ucfirst($slug),
            };
            $platformDescriptions[] = "{$label} (max {$charLimit} caractères)";
        }

        $platformList = implode(', ', $platformDescriptions);

        $userPrompt = "Analyse en profondeur les images/photos ci-jointes. Ces médias seront publiés sur les réseaux sociaux.\n\n";
        $userPrompt .= "ÉTAPE 1 — ANALYSE VISUELLE DÉTAILLÉE (utilise ces observations pour nourrir ton texte) :\n";
        $userPrompt .= "- Type de photo : selfie, portrait, photo posée, photo spontanée, paysage, produit, food, etc.\n";
        $userPrompt .= "- Personnes : visage visible ou non, expression (sourire, regard, etc.), pose, angle de prise de vue\n";
        $userPrompt .= "- Corps : parties visibles (visage, mains, jambes, pieds, dos, etc.), posture\n";
        $userPrompt .= "- Vêtements/accessoires : type (robe, lingerie, talons, bijoux, etc.), couleurs, matières, style\n";
        $userPrompt .= "- Décor/environnement : intérieur/extérieur, lieu, lumière (naturelle, tamisée, etc.), ambiance\n";
        $userPrompt .= "- Couleurs dominantes, composition, mood général de l'image\n\n";
        if (trim($content)) {
            $userPrompt .= "CONTEXTE FOURNI PAR L'UTILISATEUR :\n";
            $userPrompt .= "{$content}\n\n";
            $userPrompt .= "Utilise ce contexte pour orienter et enrichir ton texte en combinaison avec les détails visuels.\n\n";
        }

        $userPrompt .= "ÉTAPE 2 — GÉNÉRATION DU TEXTE :\n";
        $userPrompt .= "En t'appuyant sur les détails visuels concrets identifiés ci-dessus" . (trim($content) ? ' et le contexte fourni' : '') . ", génère un texte de publication engageant en {$languageLabel} adapté à chaque plateforme : {$platformList}.\n";
        $userPrompt .= "Le texte doit faire référence à des éléments spécifiques et concrets de l'image (couleurs, vêtements, pose, lieu, ambiance), pas à des concepts abstraits.\n";
        $userPrompt .= "Suis le style et le ton de la persona.\n";
        $userPrompt .= "Adapte le ton et la longueur à chaque plateforme. Respecte les limites de caractères.\n";
        $userPrompt .= "N'inclus PAS de hashtags dans le texte, ils sont gérés séparément.\n\n";
        $userPrompt .= 'Réponds UNIQUEMENT en JSON valide avec les clés suivantes : '
            . implode(', ', array_map(fn ($s) => "\"{$s}\"", $platformSlugs))
            . '. Chaque valeur est le texte pour cette plateforme. Pas de markdown, pas d\'explication, juste le JSON.';

        // Build content blocks for Vision API
        $contentBlocks = [
            ['type' => 'text', 'text' => $userPrompt],
        ];

        foreach ($imageDataUrls as $dataUrl) {
            $contentBlocks[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $dataUrl,
                    'detail' => 'auto',
                ],
            ];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(120)->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'system', 'content' => $persona->system_prompt],
                    ['role' => 'user', 'content' => $contentBlocks],
                ],
                'temperature' => 0.7,
                'max_tokens' => 4000,
                'response_format' => ['type' => 'json_object'],
            ]);

            if ($response->successful()) {
                $raw = trim($response->json('choices.0.message.content', ''));
                $parsed = json_decode($raw, true);

                if (is_array($parsed)) {
                    $result = array_intersect_key($parsed, array_flip($platformSlugs));
                    Log::info('AiAssistService: Media-based content generated', [
                        'persona' => $persona->name,
                        'platforms' => $platformSlugs,
                        'image_count' => count($imageDataUrls),
                        'lengths' => array_map('mb_strlen', $result),
                    ]);

                    return $result;
                }

                Log::error('AiAssistService: Failed to parse Vision JSON response', ['raw' => $raw]);

                return null;
            }

            Log::error('AiAssistService: Vision API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('AiAssistService: Vision exception', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function getDefaultCharLimit(string $slug): int
    {
        return match ($slug) {
            'twitter' => 280,
            'facebook' => 63206,
            'instagram' => 2200,
            'threads' => 500,
            'youtube' => 5000,
            'telegram' => 4096,
            default => 0,
        };
    }
}
