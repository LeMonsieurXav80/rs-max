<?php

namespace App\Services\Rss;

use App\Models\Persona;
use App\Models\RssItem;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\WpItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ContentGenerationService
{
    private ArticleFetchService $articleFetcher;

    public function __construct(?ArticleFetchService $articleFetcher = null)
    {
        $this->articleFetcher = $articleFetcher ?? new ArticleFetchService;
    }

    /**
     * Generate a social media post from an RSS item using a persona.
     */
    public function generate(RssItem|WpItem $item, Persona $persona, SocialAccount $account): ?string
    {
        $apiKey = Setting::getEncrypted('openai_api_key');
        if (! $apiKey) {
            Log::warning('ContentGenerationService: No OpenAI API key configured');

            return null;
        }

        // 1. Try to fetch the full article content (+ title for sitemap items)
        $pageMeta = $this->articleFetcher->fetchPageMeta($item->url);
        $articleContent = $pageMeta['content'];

        // Update item title if it was derived from URL (sitemap) and we got a real title
        if ($pageMeta['title'] && ! $item->content && ! $item->summary) {
            $item->update(['title' => $pageMeta['title']]);
        }

        // 2. Fallback to RSS content/summary
        if (! $articleContent) {
            $articleContent = $item->content ?: $item->summary;
        }

        if (! $articleContent) {
            $articleContent = $item->title;
        }

        // 3. Determine language (always French by default, account can override)
        $language = 'fr';
        $accountLanguages = $account->languages ?? [];
        if (! empty($accountLanguages)) {
            $language = $accountLanguages[0];
        }

        $languageLabel = match ($language) {
            'fr' => 'français',
            'en' => 'anglais',
            default => $language,
        };

        // 4. Build the user prompt
        $userPrompt = "Voici un article à transformer en publication pour les réseaux sociaux.\n\n";
        $userPrompt .= "Titre : {$item->title}\n";
        if ($item->published_at) {
            $userPrompt .= "Date de publication : {$item->published_at->translatedFormat('j F Y')}\n";
        }
        $userPrompt .= "URL : {$item->url}\n\n";
        $userPrompt .= "Contenu de l'article :\n{$articleContent}\n\n";
        $userPrompt .= "Génère une publication en {$languageLabel} pour le compte \"{$account->name}\" sur {$account->platform->name}.\n";

        // Platform-specific formatting rules
        $platformRules = $this->getPlatformRules($account->platform->slug);
        if ($platformRules) {
            $userPrompt .= "\nRègles de formatage pour {$account->platform->name} :\n{$platformRules}\n";
        }

        $charLimit = (int) Setting::get(
            "platform_char_limit_{$account->platform->slug}",
            $this->getDefaultCharLimit($account->platform->slug)
        );
        if ($charLimit > 0) {
            $userPrompt .= "\nLe contenu ne doit pas dépasser {$charLimit} caractères (limite {$account->platform->name}).\n";
        }

        // Instagram links are not clickable, so don't ask to include the URL
        if ($account->platform->slug !== 'instagram') {
            $userPrompt .= "\nInclus le lien de l'article dans la publication : {$item->url}";
        }

        // 5. Call OpenAI API
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
            ]);

            if ($response->successful()) {
                $content = trim($response->json('choices.0.message.content', ''));
                Log::info('ContentGenerationService: Generated content', [
                    'item' => $item->title,
                    'account' => $account->name,
                    'length' => mb_strlen($content),
                ]);

                return $content;
            }

            Log::error('ContentGenerationService: API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('ContentGenerationService: Exception', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function getPlatformRules(string $slug): ?string
    {
        // Global rules applied to ALL platforms
        $globalRules = [
            '- N\'utilise JAMAIS de hashtags (#). Les hashtags sont gérés séparément.',
            '- N\'utilise JAMAIS de liens en format markdown [texte](url). Écris toujours les URLs en clair directement dans le texte.',
        ];

        $platformRules = match ($slug) {
            'twitter' => [
                '- Le ton doit être concis et percutant.',
            ],
            'facebook' => [
                '- Tu peux utiliser des emojis avec modération.',
                '- Le ton peut être plus détaillé et engageant.',
            ],
            'instagram' => [
                '- N\'inclus PAS de lien dans le texte (les liens ne sont pas cliquables sur Instagram).',
                '- Utilise des emojis pour rendre le contenu visuel.',
            ],
            'telegram' => [
                '- Tu peux utiliser le formatage Markdown (gras, italique) mais PAS pour les liens.',
                '- Le ton peut être informatif et direct.',
            ],
            'threads' => [
                '- Le ton doit être conversationnel.',
            ],
            default => [],
        };

        $allRules = array_merge($globalRules, $platformRules);

        return implode("\n", $allRules);
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
