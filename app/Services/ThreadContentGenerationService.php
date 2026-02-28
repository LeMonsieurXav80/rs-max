<?php

namespace App\Services;

use App\Models\Persona;
use App\Models\Setting;
use App\Services\Rss\ArticleFetchService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ThreadContentGenerationService
{
    private ArticleFetchService $articleFetcher;

    public function __construct(?ArticleFetchService $articleFetcher = null)
    {
        $this->articleFetcher = $articleFetcher ?? new ArticleFetchService;
    }

    /**
     * Generate a structured thread from a source URL.
     *
     * Returns an array with:
     *   - 'segments' => [ ['position' => 1, 'content_fr' => '...', 'platform_contents' => ['twitter' => '...', 'threads' => '...']], ... ]
     *   - 'compiled' => ['facebook' => '...', 'telegram' => '...']
     *   - 'title' => '...'
     */
    public function generate(string $sourceUrl, Persona $persona, array $platformSlugs): ?array
    {
        $apiKey = Setting::getEncrypted('openai_api_key');
        if (! $apiKey) {
            Log::warning('ThreadContentGenerationService: No OpenAI API key configured');

            return null;
        }

        // 1. Fetch article content.
        $pageMeta = $this->articleFetcher->fetchPageMeta($sourceUrl);
        $articleContent = $pageMeta['content'] ?: $pageMeta['title'] ?? '';

        if (! $articleContent) {
            Log::warning('ThreadContentGenerationService: No content found', ['url' => $sourceUrl]);

            return null;
        }

        $articleTitle = $pageMeta['title'] ?? '';

        // 2. Determine platform limits.
        $charLimits = [];
        foreach ($platformSlugs as $slug) {
            $limit = $this->getCharLimit($slug);
            $charLimits[$slug] = $limit;
        }

        // 3. Build the prompt.
        $hasThreadPlatforms = ! empty(array_intersect($platformSlugs, ['twitter', 'threads']));
        $hasCompiledPlatforms = ! empty(array_intersect($platformSlugs, ['facebook', 'telegram']));

        $userPrompt = "Transforme cet article en un fil de discussion (thread) pour les réseaux sociaux.\n\n";
        $userPrompt .= "Titre : {$articleTitle}\n";
        $userPrompt .= "URL source : {$sourceUrl}\n\n";
        $userPrompt .= "Contenu de l'article :\n{$articleContent}\n\n";

        $userPrompt .= "=== RÈGLES ===\n";
        $userPrompt .= "- Découpe le contenu en 3 à 10 segments logiques.\n";
        $userPrompt .= "- Le premier segment doit être accrocheur et donner envie de lire la suite.\n";
        $userPrompt .= "- Chaque segment doit être compréhensible seul mais s'inscrire dans la continuité.\n";
        $userPrompt .= "- N'utilise PAS de hashtags (#).\n";
        $userPrompt .= "- N'utilise PAS de liens en format markdown [texte](url). Écris les URLs en clair.\n";
        $userPrompt .= "- Inclus le lien source dans le dernier segment.\n";
        $userPrompt .= "- N'utilise PAS de numérotation (1/, 2/, etc.) ni d'indicateurs comme [Thread] ou [Fil].\n\n";

        $userPrompt .= "=== LIMITES DE CARACTÈRES PAR SEGMENT ===\n";
        foreach ($charLimits as $slug => $limit) {
            $userPrompt .= "- {$slug} : maximum {$limit} caractères par segment\n";
        }
        $userPrompt .= "\n";

        if ($hasThreadPlatforms) {
            $userPrompt .= "=== RÈGLES PAR PLATEFORME (SEGMENTS) ===\n";
            if (in_array('twitter', $platformSlugs)) {
                $userPrompt .= "- twitter : ton concis et percutant, max 280 caractères.\n";
            }
            if (in_array('threads', $platformSlugs)) {
                $userPrompt .= "- threads : ton conversationnel, max 500 caractères.\n";
            }
            $userPrompt .= "\n";
        }

        // Build JSON structure instruction.
        $userPrompt .= "=== FORMAT DE RÉPONSE ===\n";
        $userPrompt .= "Réponds UNIQUEMENT en JSON valide avec cette structure exacte :\n";
        $userPrompt .= "{\n";
        $userPrompt .= "  \"title\": \"Titre court du fil (pour usage interne)\",\n";
        $userPrompt .= "  \"segments\": [\n";
        $userPrompt .= "    {\n";
        $userPrompt .= "      \"position\": 1,\n";
        $userPrompt .= "      \"content_fr\": \"Contenu principal en français\"";

        if ($hasThreadPlatforms) {
            foreach (['twitter', 'threads'] as $slug) {
                if (in_array($slug, $platformSlugs)) {
                    $userPrompt .= ",\n      \"{$slug}\": \"Version adaptée pour {$slug}\"";
                }
            }
        }

        $userPrompt .= "\n    }\n  ]";

        if ($hasCompiledPlatforms) {
            $userPrompt .= ",\n  \"compiled\": {";
            $compiledParts = [];
            if (in_array('facebook', $platformSlugs)) {
                $compiledParts[] = "\n    \"facebook\": \"Texte long complet pour Facebook (tous segments réunis en un seul texte fluide)\"";
            }
            if (in_array('telegram', $platformSlugs)) {
                $compiledParts[] = "\n    \"telegram\": \"Texte long complet pour Telegram (tous segments réunis, formatage Markdown autorisé)\"";
            }
            $userPrompt .= implode(',', $compiledParts);
            $userPrompt .= "\n  }";
        }

        $userPrompt .= "\n}\n";

        // 4. Call OpenAI API.
        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(120)->post('https://api.openai.com/v1/chat/completions', [
                'model' => Setting::get('ai_model_rss', 'gpt-4o-mini'),
                'messages' => [
                    ['role' => 'system', 'content' => $persona->system_prompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.7,
                'max_tokens' => 8000,
                'response_format' => ['type' => 'json_object'],
            ]);

            if (! $response->successful()) {
                Log::error('ThreadContentGenerationService: API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $rawContent = $response->json('choices.0.message.content', '');
            $parsed = json_decode($rawContent, true);

            if (! $parsed || empty($parsed['segments'])) {
                Log::error('ThreadContentGenerationService: Invalid JSON response', [
                    'raw' => $rawContent,
                ]);

                return null;
            }

            // Normalize the response.
            return $this->normalizeResponse($parsed, $platformSlugs);

        } catch (\Exception $e) {
            Log::error('ThreadContentGenerationService: Exception', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Regenerate a single segment.
     */
    public function regenerateSegment(string $sourceUrl, Persona $persona, int $position, int $totalSegments, string $previousContent, string $nextContent, array $platformSlugs): ?array
    {
        $apiKey = Setting::getEncrypted('openai_api_key');
        if (! $apiKey) {
            return null;
        }

        $charLimits = [];
        foreach ($platformSlugs as $slug) {
            $charLimits[$slug] = $this->getCharLimit($slug);
        }

        $userPrompt = "Régénère le segment {$position} d'un fil de discussion ({$totalSegments} segments au total).\n\n";
        $userPrompt .= "URL source : {$sourceUrl}\n\n";

        if ($previousContent) {
            $userPrompt .= "Segment précédent ({$position} - 1) :\n{$previousContent}\n\n";
        }
        if ($nextContent) {
            $userPrompt .= "Segment suivant ({$position} + 1) :\n{$nextContent}\n\n";
        }

        $userPrompt .= "Limites par plateforme :\n";
        foreach ($charLimits as $slug => $limit) {
            $userPrompt .= "- {$slug} : max {$limit} caractères\n";
        }

        $userPrompt .= "\nRéponds en JSON : {\"content_fr\": \"...\"";
        foreach (['twitter', 'threads'] as $slug) {
            if (in_array($slug, $platformSlugs)) {
                $userPrompt .= ", \"{$slug}\": \"...\"";
            }
        }
        $userPrompt .= "}\n";

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(60)->post('https://api.openai.com/v1/chat/completions', [
                'model' => Setting::get('ai_model_rss', 'gpt-4o-mini'),
                'messages' => [
                    ['role' => 'system', 'content' => $persona->system_prompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.7,
                'max_tokens' => 2000,
                'response_format' => ['type' => 'json_object'],
            ]);

            if ($response->successful()) {
                return json_decode($response->json('choices.0.message.content', ''), true);
            }

            return null;
        } catch (\Exception $e) {
            Log::error('ThreadContentGenerationService: regenerateSegment error', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function normalizeResponse(array $parsed, array $platformSlugs): array
    {
        $segments = [];

        foreach ($parsed['segments'] as $i => $segment) {
            $normalized = [
                'position' => $segment['position'] ?? ($i + 1),
                'content_fr' => $segment['content_fr'] ?? '',
                'platform_contents' => [],
            ];

            foreach (['twitter', 'threads'] as $slug) {
                if (in_array($slug, $platformSlugs) && ! empty($segment[$slug])) {
                    $normalized['platform_contents'][$slug] = $segment[$slug];
                }
            }

            $segments[] = $normalized;
        }

        return [
            'title' => $parsed['title'] ?? '',
            'segments' => $segments,
            'compiled' => $parsed['compiled'] ?? [],
        ];
    }

    private function getCharLimit(string $slug): int
    {
        return (int) Setting::get(
            "platform_char_limit_{$slug}",
            match ($slug) {
                'twitter' => 280,
                'facebook' => 63206,
                'instagram' => 2200,
                'threads' => 500,
                'youtube' => 5000,
                'telegram' => 4096,
                default => 0,
            }
        );
    }
}
