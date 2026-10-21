<?php

namespace App\Services\WordPress;

use App\Models\Persona;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\WpItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Transforme un article WordPress en publication, via la persona du compte.
 *
 * Vivait dans `App\Services\Rss` et servait les trois sources (RSS, WordPress,
 * YouTube). Le retrait de RSS et de YouTube l'a emporte avec eux, alors que
 * `WordPressSiteController` s'en sert toujours : il est donc rapatrie ici,
 * reduit au seul chemin article. Les branches YouTube (duree, vues, tournure
 * « cette video n'est plus toute fraiche ») sont parties avec la source.
 */
class ContentGenerationService
{
    private ArticleFetchService $articleFetcher;

    public function __construct(?ArticleFetchService $articleFetcher = null)
    {
        $this->articleFetcher = $articleFetcher ?? new ArticleFetchService;
    }

    /**
     * Generate a social media post from a WordPress item using a persona.
     */
    public function generate(WpItem $item, Persona $persona, SocialAccount $account): ?string
    {
        $apiKey = Setting::getEncrypted('openai_api_key');
        if (! $apiKey) {
            Log::warning('ContentGenerationService: No OpenAI API key configured');

            return null;
        }

        // 1. Fetch the full article content
        $pageMeta = $this->articleFetcher->fetchPageMeta($item->url);
        $articleContent = $pageMeta['content'];

        // Update item title if it was derived from URL (sitemap) and we got a real title
        if ($pageMeta['title'] && ! $item->content && ! $item->summary) {
            $item->update(['title' => $pageMeta['title']]);
        }

        // Fallback to the item's own content/summary
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

        // Add age-aware phrasing for articles older than 6 months
        if ($item->published_at && $item->published_at->diffInMonths(now()) >= 6) {
            $agePhrase = $this->getArticleAgePhrase($item->published_at, $language);
            if ($language === 'fr') {
                $userPrompt .= "IMPORTANT : L'article n'est PAS récent. N'utilise PAS d'expressions comme « nouvel article », « vient de paraître » ou « tout juste publié ». Intègre naturellement cette formulation dans ta publication : « {$agePhrase} ». Tu peux l'adapter légèrement au contexte, mais garde l'idée.\n\n";
            } else {
                $userPrompt .= "IMPORTANT: This article is NOT recent. Do NOT use expressions like \"new article\", \"just published\" or \"fresh off the press\". Naturally incorporate this phrasing into your post: \"{$agePhrase}\". You can adapt it slightly to the context, but keep the idea.\n\n";
            }
        }
        $userPrompt .= "Génère une publication en {$languageLabel} pour le compte \"{$account->name}\" sur {$account->platform->name}.\n";

        // Platform-specific formatting rules
        $platformRules = $this->getPlatformRules($account->platform->slug);
        if ($platformRules) {
            $userPrompt .= "\nRègles de formatage pour {$account->platform->name} :\n{$platformRules}\n";
        }

        $charLimit = $account->charLimit($this->getDefaultCharLimit($account->platform->slug));

        // Instagram links are not clickable, so don't ask to include the URL
        if ($account->platform->slug !== 'instagram') {
            $linkUrl = $item->url;

            if ($charLimit > 0) {
                if ($account->platform->slug === 'bluesky') {
                    // Bluesky: URLs count toward the 300-grapheme limit — give AI the exact text budget
                    $urlLength = mb_strlen($linkUrl) + 2; // +2 for "\n\n" separator
                    $textBudget = max(50, $charLimit - $urlLength);
                    $userPrompt .= "\nIMPORTANT — limite Bluesky : {$charLimit} graphèmes AU TOTAL (URL incluse). L'URL fait {$urlLength} caractères. Le texte seul ne doit donc PAS dépasser {$textBudget} caractères.\n";
                } else {
                    $userPrompt .= "\nLe contenu ne doit pas dépasser {$charLimit} caractères (limite {$account->platform->name}).\n";
                }
            }

            $userPrompt .= "\nInclus le lien dans la publication : {$linkUrl}";
        } elseif ($charLimit > 0) {
            $userPrompt .= "\nLe contenu ne doit pas dépasser {$charLimit} caractères (limite {$account->platform->name}).\n";
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
            'bluesky' => 300,
            default => 0,
        };
    }

    private function getArticleAgePhrase(\Carbon\Carbon $publishedAt, string $language = 'fr'): string
    {
        $diffMonths = $publishedAt->diffInMonths(now());

        if ($language === 'en') {
            $month = $publishedAt->format('F');
            $year = $publishedAt->format('Y');
            $monthYear = "{$month} {$year}";

            if ($diffMonths >= 24) {
                $age = (int) floor($diffMonths / 12).' years';
            } elseif ($diffMonths >= 12) {
                $age = 'a year';
            } else {
                $age = $diffMonths.' months';
            }

            $templates = [
                'This article is from {month_year}.',
                'We published this article {age} ago...',
                'It was back in {month_year}.',
                'An article from {month_year} on this topic.',
                'Published {age} ago.',
                'We covered this back in {month_year}.',
                'This goes back to {month_year}.',
                '{age} ago already.',
                'In {month_year}, we talked about this.',
                'A {year} article worth reading again.',
                'Released in {month_year}.',
                'From {month_year}, but still relevant.',
                'We wrote about this in {month_year}.',
                'A quick look back at {month_year}.',
                '{age} ago, we were talking about this.',
                '{month_year}, already.',
                'This article is {age} old.',
                'From {month_year}.',
                'Written {age} ago.',
                'We told you about this in {month_year}.',
            ];

            $template = $templates[random_int(0, count($templates) - 1)];

            return str_replace(
                ['{age}', '{month}', '{year}', '{month_year}'],
                [$age, $month, $year, $monthYear],
                $template
            );
        }

        $mois = $publishedAt->translatedFormat('F');
        $année = $publishedAt->format('Y');
        $mois_année = "{$mois} {$année}";

        if ($diffMonths >= 24) {
            $age = (int) floor($diffMonths / 12).' ans';
        } elseif ($diffMonths >= 12) {
            $age = 'un an';
        } else {
            $age = $diffMonths.' mois';
        }

        $templates = [
            'Cet article date de {mois_année}.',
            'Il y a {age}, on publiait cet article...',
            "C'était en {mois_année}.",
            'Un article de {mois_année} sur le sujet.',
            'Publié il y a {age}.',
            'On avait abordé ça en {mois_année}.',
            'Ça remonte à {mois_année}.',
            'Il y a {age} déjà.',
            'En {mois_année}, on en parlait.',
            'Un article de {année} à relire.',
            'Sorti en {mois_année}.',
            "Ça date de {mois_année}, mais c'est toujours valable.",
            'On avait écrit ça en {mois_année}.',
            'Petit retour en {mois_année}.',
            'Il y a {age}, on vous parlait de ça.',
            '{mois_année}, déjà.',
            'Cet article a {age}.',
            'De {mois_année}.',
            'Écrit il y a {age}.',
            'On vous en parlait en {mois_année}.',
        ];

        $template = $templates[random_int(0, count($templates) - 1)];

        return str_replace(
            ['{age}', '{mois}', '{année}', '{mois_année}'],
            [$age, $mois, $année, $mois_année],
            $template
        );
    }
}
