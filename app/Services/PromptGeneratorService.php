<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PromptGeneratorService
{
    /**
     * Generate image prompts (Midjourney/DALL-E style) based on criteria.
     *
     * @return array{success: bool, prompts?: array, error?: string}
     */
    public function generateImagePrompts(array $options): array
    {
        $apiKey = Setting::getEncrypted('openai_api_key');
        if (! $apiKey) {
            return ['success' => false, 'error' => 'Clé API OpenAI non configurée.'];
        }

        $systemPrompt = $this->buildImageSystemPrompt($options);
        $count = (int) ($options['count'] ?? 3);

        $userPrompt = "Génère {$count} prompts créatifs et détaillés pour la génération d'images IA (Midjourney / DALL-E).";
        if (! empty($options['description'])) {
            $userPrompt .= "\n\nDescription/thème fourni par l'utilisateur : {$options['description']}";
        }
        $userPrompt .= "\n\nRéponds UNIQUEMENT en JSON valide avec la structure : {\"prompts\": [\"prompt1\", \"prompt2\", ...]}";
        $userPrompt .= "\nChaque prompt doit être en anglais, détaillé (50-150 mots), et prêt à être copié-collé dans un générateur d'images IA.";
        $userPrompt .= "\nInclus des détails techniques : éclairage, angle de caméra, style artistique, ambiance, résolution.";

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(60)->post('https://api.openai.com/v1/chat/completions', [
                'model' => Setting::get('ai_model_text', 'gpt-4o-mini'),
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.9,
                'max_tokens' => 3000,
                'response_format' => ['type' => 'json_object'],
            ]);

            if ($response->successful()) {
                $raw = trim($response->json('choices.0.message.content', ''));
                $parsed = json_decode($raw, true);

                if (is_array($parsed) && isset($parsed['prompts'])) {
                    return ['success' => true, 'prompts' => $parsed['prompts']];
                }

                Log::error('PromptGeneratorService: Failed to parse image prompts JSON', ['raw' => $raw]);

                return ['success' => false, 'error' => 'Erreur de parsing de la réponse IA.'];
            }

            Log::error('PromptGeneratorService: API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return ['success' => false, 'error' => 'Erreur API OpenAI (code ' . $response->status() . ').'];

        } catch (\Exception $e) {
            Log::error('PromptGeneratorService: Exception', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => 'Erreur: ' . $e->getMessage()];
        }
    }

    /**
     * Analyze a photo using GPT-4o Vision to identify elements for video animation.
     *
     * @return array{success: bool, analysis?: string, error?: string}
     */
    public function analyzePhotoForVideo(string $imageDataUrl): array
    {
        $apiKey = Setting::getEncrypted('openai_api_key');
        if (! $apiKey) {
            return ['success' => false, 'error' => 'Clé API OpenAI non configurée.'];
        }

        $systemPrompt = "Tu es un expert en animation vidéo et en direction artistique. "
            . "Ton rôle est d'analyser une image pour identifier les éléments qui pourraient être animés dans une vidéo générée par IA (Runway ML, Pika Labs, Kling, etc.). "
            . "Tu dois être précis et technique dans ton analyse.";

        $userPrompt = "Analyse cette image et identifie :\n"
            . "1. **Éléments mobiles** : personnes, animaux, véhicules, objets qui pourraient bouger\n"
            . "2. **Éléments statiques** : arrière-plan, bâtiments, paysage\n"
            . "3. **Atmosphère** : lumière, couleurs, ambiance, météo\n"
            . "4. **Composition** : premier plan, arrière-plan, profondeur\n"
            . "5. **Potentiel d'animation** : quels mouvements seraient naturels et réalistes\n\n"
            . "Sois concis mais précis. Réponds en français.";

        $contentBlocks = [
            ['type' => 'text', 'text' => $userPrompt],
            [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $imageDataUrl,
                    'detail' => 'low',
                ],
            ],
        ];

        $primaryModel = Setting::get('ai_model_vision', 'gpt-4o');
        $modelsToTry = array_unique([$primaryModel, 'gpt-4.1', 'gpt-4.1-mini', 'gpt-4o-mini']);

        try {
            foreach ($modelsToTry as $model) {
                $response = Http::withHeaders([
                    'Authorization' => "Bearer {$apiKey}",
                    'Content-Type' => 'application/json',
                ])->timeout(60)->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $contentBlocks],
                    ],
                    'temperature' => 0.3,
                    'max_tokens' => 500,
                ]);

                if ($response->successful()) {
                    $refusal = $response->json('choices.0.message.refusal');
                    if ($refusal) {
                        continue;
                    }

                    $analysis = trim($response->json('choices.0.message.content', ''));
                    if ($analysis) {
                        return ['success' => true, 'analysis' => $analysis];
                    }
                }
            }

            return ['success' => false, 'error' => 'Tous les modèles Vision ont échoué.'];

        } catch (\Exception $e) {
            Log::error('PromptGeneratorService: Vision exception', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => 'Erreur: ' . $e->getMessage()];
        }
    }

    /**
     * Generate video animation prompts based on photo analysis.
     *
     * @return array{success: bool, prompts?: array, error?: string}
     */
    public function generateVideoPrompts(string $analysis, array $options): array
    {
        $apiKey = Setting::getEncrypted('openai_api_key');
        if (! $apiKey) {
            return ['success' => false, 'error' => 'Clé API OpenAI non configurée.'];
        }

        $mode = $options['mode'] ?? 'standard'; // standard or advanced
        $movementType = $options['movement_type'] ?? 'subtle';
        $videoStyle = $options['video_style'] ?? 'realistic';
        $count = (int) ($options['count'] ?? 3);

        $systemPrompt = $this->buildVideoSystemPrompt($mode, $movementType, $videoStyle);

        $userPrompt = "Voici l'analyse d'une photo à animer :\n\n{$analysis}\n\n";
        $userPrompt .= "Génère {$count} prompts d'animation vidéo en anglais pour des outils comme Runway ML, Pika Labs ou Kling.\n";

        if (! empty($options['description'])) {
            $userPrompt .= "\nInstructions supplémentaires : {$options['description']}\n";
        }

        $userPrompt .= "\nRéponds UNIQUEMENT en JSON valide avec la structure : {\"prompts\": [\"prompt1\", \"prompt2\", ...]}";
        $userPrompt .= "\nChaque prompt doit être en anglais, prêt à être copié-collé, et décrire précisément le mouvement/animation souhaité.";

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(60)->post('https://api.openai.com/v1/chat/completions', [
                'model' => Setting::get('ai_model_text', 'gpt-4o-mini'),
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.9,
                'max_tokens' => 3000,
                'response_format' => ['type' => 'json_object'],
            ]);

            if ($response->successful()) {
                $raw = trim($response->json('choices.0.message.content', ''));
                $parsed = json_decode($raw, true);

                if (is_array($parsed) && isset($parsed['prompts'])) {
                    return ['success' => true, 'prompts' => $parsed['prompts']];
                }

                return ['success' => false, 'error' => 'Erreur de parsing de la réponse IA.'];
            }

            return ['success' => false, 'error' => 'Erreur API OpenAI (code ' . $response->status() . ').'];

        } catch (\Exception $e) {
            Log::error('PromptGeneratorService: Video prompt exception', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => 'Erreur: ' . $e->getMessage()];
        }
    }

    /**
     * Build the system prompt for image generation.
     */
    private function buildImageSystemPrompt(array $options): string
    {
        $prompt = "Tu es un expert en création de prompts pour les générateurs d'images IA (Midjourney, DALL-E, Stable Diffusion). ";
        $prompt .= "Tu maîtrises parfaitement les techniques de prompting : styles artistiques, éclairages, compositions, paramètres techniques.\n\n";
        $prompt .= "RÈGLES :\n";
        $prompt .= "- Chaque prompt doit être en anglais\n";
        $prompt .= "- Être détaillé et spécifique (50-150 mots)\n";
        $prompt .= "- Inclure des détails techniques : éclairage, angle, style, résolution\n";
        $prompt .= "- Varier les approches entre chaque prompt\n";
        $prompt .= "- Utiliser le vocabulaire standard des générateurs d'images (cinematic lighting, 8k, ultra detailed, etc.)\n\n";

        // Content type
        if (! empty($options['content_type'])) {
            $prompt .= "TYPE DE CONTENU : {$options['content_type']}\n";
        }

        // Season
        if (! empty($options['season'])) {
            $prompt .= "SAISON : {$options['season']}\n";
        }

        // Time of day
        if (! empty($options['time_of_day'])) {
            $prompt .= "MOMENT DE LA JOURNÉE : {$options['time_of_day']}\n";
        }

        // Vehicle/setting
        if (! empty($options['vehicle'])) {
            $prompt .= "VÉHICULE/CADRE : {$options['vehicle']}\n";
        }

        // Photo style
        if (! empty($options['photo_style'])) {
            $prompt .= "STYLE PHOTO : {$options['photo_style']}\n";
        }

        // Shot type
        if (! empty($options['shot_type'])) {
            $prompt .= "TYPE DE PLAN : {$options['shot_type']}\n";
        }

        // Animals
        if (! empty($options['animals'])) {
            $prompt .= "ANIMAUX : {$options['animals']}\n";
        }

        // Safe mode
        if (! empty($options['safe_mode'])) {
            $prompt .= "\nMODE SAFE : Le contenu doit être adapté à tous les publics (pas de contenu suggestif, violent ou controversé).\n";
        }

        return $prompt;
    }

    /**
     * Build the system prompt for video animation.
     */
    private function buildVideoSystemPrompt(string $mode, string $movementType, string $videoStyle): string
    {
        $prompt = "Tu es un expert en animation vidéo IA et en direction de mouvement. ";
        $prompt .= "Tu crées des prompts pour des outils comme Runway ML Gen-3, Pika Labs, Kling, et Luma Dream Machine.\n\n";

        if ($mode === 'advanced') {
            $prompt .= "MODE AVANCÉ : Génère des prompts cinématographiques avec :\n";
            $prompt .= "- Des mouvements de caméra complexes (dolly, crane, tracking shot)\n";
            $prompt .= "- Des transitions d'éclairage et d'atmosphère\n";
            $prompt .= "- Des animations multi-éléments coordonnées\n";
            $prompt .= "- Un storytelling visuel en quelques secondes\n\n";
        } else {
            $prompt .= "MODE STANDARD : Génère des prompts avec des mouvements naturels :\n";
            $prompt .= "- Caméra fixe, mouvements subtils des éléments\n";
            $prompt .= "- Animations réalistes (vent, eau, lumière)\n";
            $prompt .= "- Focus sur un ou deux éléments principaux\n\n";
        }

        $movementLabels = [
            'subtle' => 'Mouvements subtils et naturels (vent, respiration, eau)',
            'moderate' => 'Mouvements modérés (marche, gestes, vagues)',
            'dynamic' => 'Mouvements dynamiques (course, vol, action)',
            'cinematic' => 'Mouvements cinématographiques (slow motion, transitions, dramatic)',
        ];

        $prompt .= "TYPE DE MOUVEMENT : " . ($movementLabels[$movementType] ?? $movementLabels['subtle']) . "\n";

        $styleLabels = [
            'realistic' => 'Réaliste et naturel',
            'cinematic' => 'Cinématographique (film, dramatique)',
            'dreamy' => 'Onirique et éthéré',
            'energetic' => 'Énergique et dynamique',
            'slow_motion' => 'Ralenti artistique',
        ];

        $prompt .= "STYLE VIDÉO : " . ($styleLabels[$videoStyle] ?? $styleLabels['realistic']) . "\n\n";

        $prompt .= "RÈGLES :\n";
        $prompt .= "- Chaque prompt en anglais\n";
        $prompt .= "- Décrire précisément quel élément bouge et comment\n";
        $prompt .= "- Mentionner la direction et la vitesse du mouvement\n";
        $prompt .= "- Garder les éléments statiques immobiles\n";
        $prompt .= "- Prompt concis mais complet (30-80 mots)\n";

        return $prompt;
    }
}
