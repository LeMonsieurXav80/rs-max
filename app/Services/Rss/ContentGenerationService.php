<?php

namespace App\Services\Rss;

use App\Models\Persona;
use App\Models\RedditItem;
use App\Models\RssItem;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\WpItem;
use App\Models\YtItem;
use App\Services\YouTube\YouTubeFetchService;
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
    public function generate(RssItem|WpItem|YtItem|RedditItem $item, Persona $persona, SocialAccount $account): ?string
    {
        $apiKey = Setting::getEncrypted('openai_api_key');
        if (! $apiKey) {
            Log::warning('ContentGenerationService: No OpenAI API key configured');

            return null;
        }

        // 1. Build article content based on item type
        $articleContent = '';

        if ($item instanceof YtItem) {
            // YouTube: use description as content
            $articleContent = $item->description ?: $item->title;
        } elseif ($item instanceof RedditItem) {
            // Reddit: use selftext for self posts, title for link posts
            $articleContent = $item->selftext ?: $item->title;
        } else {
            // RSS/WordPress: fetch the full article content
            $pageMeta = $this->articleFetcher->fetchPageMeta($item->url);
            $articleContent = $pageMeta['content'];

            // Update item title if it was derived from URL (sitemap) and we got a real title
            if ($pageMeta['title'] && ! $item->content && ! $item->summary) {
                $item->update(['title' => $pageMeta['title']]);
            }

            // Fallback to RSS content/summary
            if (! $articleContent) {
                $articleContent = $item->content ?: $item->summary;
            }

            if (! $articleContent) {
                $articleContent = $item->title;
            }
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
        if ($item instanceof YtItem) {
            $userPrompt = "Voici une vidéo YouTube à transformer en publication pour les réseaux sociaux.\n\n";
            $userPrompt .= "Titre : {$item->title}\n";
            if ($item->duration) {
                $userPrompt .= "Durée : " . YouTubeFetchService::formatDuration($item->duration) . "\n";
            }
            if ($item->view_count) {
                $userPrompt .= "Vues : " . number_format($item->view_count, 0, ',', ' ') . "\n";
            }
            if ($item->like_count) {
                $userPrompt .= "Likes : " . number_format($item->like_count, 0, ',', ' ') . "\n";
            }
            if ($item->published_at) {
                $userPrompt .= "Date de publication : {$item->published_at->translatedFormat('j F Y')}\n";
            }
            $userPrompt .= "URL : {$item->url}\n\n";
            $userPrompt .= "Description de la vidéo :\n{$articleContent}\n\n";

            // Add age-aware phrasing for videos older than 6 months
            if ($item->published_at && $item->published_at->diffInMonths(now()) >= 6) {
                $agePhrase = $this->getVideoAgePhrase($item->published_at, $language);
                if ($language === 'fr') {
                    $userPrompt .= "IMPORTANT : La vidéo n'est PAS récente. N'utilise PAS d'expressions comme « notre dernière vidéo », « nouvelle vidéo » ou « vient de sortir ». Intègre naturellement cette formulation dans ta publication : « {$agePhrase} ». Tu peux l'adapter légèrement au contexte, mais garde l'idée.\n\n";
                } else {
                    $userPrompt .= "IMPORTANT: This video is NOT recent. Do NOT use expressions like \"our latest video\", \"new video\" or \"just released\". Naturally incorporate this phrasing into your post: \"{$agePhrase}\". You can adapt it slightly to the context, but keep the idea.\n\n";
                }
            }
        } elseif ($item instanceof RedditItem) {
            $userPrompt = "Voici un post Reddit à transformer en publication pour les réseaux sociaux.\n\n";
            $userPrompt .= "Titre : {$item->title}\n";
            $userPrompt .= "Score : {$item->score} upvotes\n";
            $userPrompt .= "Commentaires : {$item->num_comments}\n";
            if ($item->author) {
                $userPrompt .= "Auteur : u/{$item->author}\n";
            }
            if ($item->published_at) {
                $userPrompt .= "Date de publication : {$item->published_at->translatedFormat('j F Y')}\n";
            }
            $userPrompt .= "URL : {$item->permalink}\n\n";
            if ($item->is_self && $item->selftext) {
                $userPrompt .= "Contenu du post :\n{$articleContent}\n\n";
            } else {
                $userPrompt .= "Lien partagé : {$item->url}\n\n";
            }
        } else {
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
        }
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

        // Instagram links are not clickable, so don't ask to include the URL
        if ($account->platform->slug !== 'instagram') {
            $linkUrl = ($item instanceof RedditItem) ? $item->permalink : $item->url;

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

    private function getVideoAgePhrase(\Carbon\Carbon $publishedAt, string $language = 'fr'): string
    {
        $diffMonths = $publishedAt->diffInMonths(now());

        if ($language === 'en') {
            $month = $publishedAt->format('F');
            $year = $publishedAt->format('Y');
            $monthYear = "{$month} {$year}";

            if ($diffMonths >= 24) {
                $age = (int) floor($diffMonths / 12) . ' years';
            } elseif ($diffMonths >= 12) {
                $age = 'a year';
            } else {
                $age = $diffMonths . ' months';
            }

            $templates = [
                "We posted this video {age} ago...",
                "Remember this one? It was back in {month_year}.",
                "We stumbled back on this {year} video and it's worth a watch.",
                "This video from {month_year} may be older, but its message still holds up.",
                "Throwback to {month_year} with this video.",
                "{month_year} — time flies, but this video is still relevant.",
                "Quick flashback: this video is from {month_year}.",
                "Bringing this video from {age} ago back into the spotlight.",
                "That was {age} ago. Time flies!",
                "Released in {month_year}, this video deserves to be (re)discovered.",
                "A {year} video we wanted to share again.",
                "Published in {month_year}, still relevant today.",
                "{age} ago, we shared this video. Did you catch it?",
                "Diving back into our {month_year} archives.",
                "Since {month_year}, this video has traveled far.",
                "This video is {age} old and hasn't aged a day.",
                "Back in {month_year}, we shared this video. Here it is again!",
                "A {year} classic worth (re)watching.",
                "It's already been {age} since this video came out.",
                "A look back at {month_year}.",
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
            $age = (int) floor($diffMonths / 12) . ' ans';
        } elseif ($diffMonths >= 12) {
            $age = 'un an';
        } else {
            $age = $diffMonths . ' mois';
        }

        $templates = [
            "Il y a {age}, on publiait cette vidéo...",
            "Vous vous souvenez ? C'était en {mois_année}.",
            "On est retombé sur cette vidéo de {année} et elle vaut le détour.",
            "Cette vidéo de {mois_année} a peut-être un peu vieilli, mais pas son message.",
            "Retour en {mois_année} avec cette vidéo.",
            "{mois_année} — ça ne nous rajeunit pas, mais cette vidéo reste pertinente.",
            "Petit flashback : cette vidéo date de {mois_année}.",
            "On remet en lumière cette vidéo sortie il y a {age}.",
            "C'était il y a {age}. Le temps passe vite !",
            "Sortie en {mois_année}, cette vidéo mérite d'être (re)découverte.",
            "Une vidéo de {année} qu'on avait envie de vous repartager.",
            "Publiée en {mois_année}, toujours d'actualité.",
            "Il y a {age}, on vous proposait cette vidéo. Vous l'aviez vue ?",
            "On replonge dans nos archives de {mois_année}.",
            "Depuis {mois_année}, cette vidéo a bien voyagé.",
            "Cette vidéo a {age} et elle n'a pas pris une ride.",
            "En {mois_année}, on partageait cette vidéo. La revoilà !",
            "Un classique de {année} à (re)voir.",
            "Ça fait déjà {age} que cette vidéo est sortie.",
            "Coup d'œil dans le rétro : {mois_année}.",
        ];

        $template = $templates[random_int(0, count($templates) - 1)];

        return str_replace(
            ['{age}', '{mois}', '{année}', '{mois_année}'],
            [$age, $mois, $année, $mois_année],
            $template
        );
    }

    private function getArticleAgePhrase(\Carbon\Carbon $publishedAt, string $language = 'fr'): string
    {
        $diffMonths = $publishedAt->diffInMonths(now());

        if ($language === 'en') {
            $month = $publishedAt->format('F');
            $year = $publishedAt->format('Y');
            $monthYear = "{$month} {$year}";

            if ($diffMonths >= 24) {
                $age = (int) floor($diffMonths / 12) . ' years';
            } elseif ($diffMonths >= 12) {
                $age = 'a year';
            } else {
                $age = $diffMonths . ' months';
            }

            $templates = [
                "This article is from {month_year}.",
                "We published this article {age} ago...",
                "It was back in {month_year}.",
                "An article from {month_year} on this topic.",
                "Published {age} ago.",
                "We covered this back in {month_year}.",
                "This goes back to {month_year}.",
                "{age} ago already.",
                "In {month_year}, we talked about this.",
                "A {year} article worth reading again.",
                "Released in {month_year}.",
                "From {month_year}, but still relevant.",
                "We wrote about this in {month_year}.",
                "A quick look back at {month_year}.",
                "{age} ago, we were talking about this.",
                "{month_year}, already.",
                "This article is {age} old.",
                "From {month_year}.",
                "Written {age} ago.",
                "We told you about this in {month_year}.",
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
            $age = (int) floor($diffMonths / 12) . ' ans';
        } elseif ($diffMonths >= 12) {
            $age = 'un an';
        } else {
            $age = $diffMonths . ' mois';
        }

        $templates = [
            "Cet article date de {mois_année}.",
            "Il y a {age}, on publiait cet article...",
            "C'était en {mois_année}.",
            "Un article de {mois_année} sur le sujet.",
            "Publié il y a {age}.",
            "On avait abordé ça en {mois_année}.",
            "Ça remonte à {mois_année}.",
            "Il y a {age} déjà.",
            "En {mois_année}, on en parlait.",
            "Un article de {année} à relire.",
            "Sorti en {mois_année}.",
            "Ça date de {mois_année}, mais c'est toujours valable.",
            "On avait écrit ça en {mois_année}.",
            "Petit retour en {mois_année}.",
            "Il y a {age}, on vous parlait de ça.",
            "{mois_année}, déjà.",
            "Cet article a {age}.",
            "De {mois_année}.",
            "Écrit il y a {age}.",
            "On vous en parlait en {mois_année}.",
        ];

        $template = $templates[random_int(0, count($templates) - 1)];

        return str_replace(
            ['{age}', '{mois}', '{année}', '{mois_année}'],
            [$age, $mois, $année, $mois_année],
            $template
        );
    }
}
