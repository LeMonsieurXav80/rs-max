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
     * Prompt par défaut : génération de texte de publication depuis une photo.
     * Variables substituées au runtime : {contexte_utilisateur}, {langue},
     * {liste_plateformes}, {liste_plateformes_json}.
     */
    public const DEFAULT_PROMPT_PUBLICATION_FROM_PHOTO = <<<'TXT'
Observe attentivement les images/photos ci-jointes et génère un texte de publication pour les réseaux sociaux.

Pour écrire un texte pertinent, décris mentalement ce que tu vois :
- Le style et l'ambiance générale de la photo
- Le décor, la lumière, les couleurs dominantes
- La tenue, le style vestimentaire, les accessoires
- La pose, l'attitude, l'énergie qui se dégage
- L'atmosphère et le mood de l'ensemble

CONVENTION DE NOMMAGE DES PERSONNES :
- Si une femme adulte est visible sur la photo, désigne-la par "Caroline".
- Si un homme adulte est visible, désigne-le par "Xavier".
- Évite les formulations génériques type "une femme", "un homme", "une personne", "elle", "il" en début de phrase. Utilise leur prénom.
- Si plusieurs personnes du même genre, utilise "Caroline" (resp. "Xavier") une seule fois et reste cohérent.
- Si la photo ne montre clairement personne ou seulement des inconnus de fond, ignore cette règle (parle du décor, de l'objet ou de l'action).

{contexte_utilisateur}

En t'appuyant sur ces éléments visuels concrets, génère un texte engageant en {langue} adapté à chaque plateforme : {liste_plateformes}.
Le texte doit faire référence à des détails spécifiques de l'image (couleurs, tenue, pose, lieu, ambiance), pas à des concepts abstraits.
Suis le style et le ton de la persona.
Adapte le ton et la longueur à chaque plateforme. Respecte les limites de caractères.
N'inclus PAS de hashtags dans le texte, ils sont gérés séparément.

Réponds UNIQUEMENT en JSON valide avec les clés suivantes : {liste_plateformes_json}. Chaque valeur est le texte pour cette plateforme. Pas de markdown, pas d'explication, juste le JSON.
TXT;

    /**
     * Prompt par défaut : extraction de métadonnées structurées depuis une photo (catalogue média).
     * Variables substituées : {contexte}, {personnes_attendues}.
     */
    public const DEFAULT_PROMPT_METADATA_EXTRACTION = <<<'TXT'
Tu es un expert en analyse d'image. Tu extrais les informations structurées d'une photo pour alimenter un catalogue média.

Contexte de la session : {contexte}
Personnes potentiellement présentes : {personnes_attendues}

Réponds UNIQUEMENT en JSON valide :
{
  "description_fr": "Une phrase descriptive de la photo en français.",
  "thematic_tags": ["tag1", "tag2", "..."],
  "people_ids": ["caroline", "xavier"],
  "person_count": 0,
  "city": null,
  "region": null,
  "country": null,
  "brands": [],
  "event": null,
  "taken_at": null
}

UTILISATION DU CONTEXTE :
Le "Contexte de la session" ci-dessus n'est PAS décoratif. Tu DOIS l'exploiter activement :
- Pour orienter le choix des **thematic_tags** : si le contexte est "voyage Portugal 2024", privilégie des tags géographiques/thématiques (algarve, plage portugaise, ocean atlantique) plutôt que génériques (mer, sable). Si le contexte mentionne un événement, une activité ou un lieu, intègre-le dans les tags quand c'est visuellement cohérent.
- Pour enrichir la **description_fr** : injecte les éléments du contexte qui correspondent à ce que tu vois (lieu, période, ambiance). Exemple : contexte "Voyage Portugal 2004" + plage visible → "Caroline sur une plage de l'Algarve, parasols en paille au coucher de soleil." plutôt que "Une plage avec des parasols."
- Si le contexte mentionne un lieu identifiable (ville, région, pays) ET que la photo est cohérente avec, **renseigne aussi** les champs city/region/country en conséquence.
- Ne recopie jamais le contexte mot pour mot ; il sert d'amorce, pas de copier-coller. Si la photo contredit visiblement le contexte (ex: contexte "Portugal" mais photo de neige en montagne), ignore le contexte.

RÈGLES :
- description_fr : 1-2 phrases factuelles décrivant la scène, pas de copywriting. **Sert de contexte à une IA pour la rédaction de publications, doit être informative.**
  Convention de nommage : si une femme adulte est visible, désigne-la par "Caroline" ; si un homme adulte est visible, désigne-le par "Xavier". Évite "une femme", "un homme", "une personne", "elle", "il" en début de phrase. Si la photo ne montre clairement personne ou seulement des inconnus de fond, ignore cette règle.
- thematic_tags : MAXIMUM 10, en français minuscules sans accents sur concepts simples, pas de doublons singulier/pluriel, pas de générique ("photo", "image"). Privilégie ce qui rend la photo unique. **Sert aussi de contexte pour la génération de contenu.**
- people_ids : ids normalisés. **Heuristique automatique** : si un homme adulte est visible → ajoute "xavier". Si une femme adulte est visible → ajoute "caroline". Si plusieurs personnes du même genre, garde "xavier" (resp. "caroline") une seule fois. Si la photo ne montre clairement personne ou seulement des inconnus, laisse le tableau vide.
- city/region/country : null si non identifiable visuellement (pas deviner). Cf. règle d'utilisation du contexte ci-dessus si le contexte aide à confirmer.
- brands : tableau de marques visibles (logos, packaging). Vide si aucune.
- event : null si pas d'évènement contextuel évident. Si le contexte mentionne explicitement un événement ("Voyage Portugal 2024", "Mariage Léa", "Festival X"), utilise cette valeur.
- taken_at : null (l'EXIF est traité ailleurs).

Réponds en JSON pur, sans ```json``` ni explications.
TXT;

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
                'model' => Setting::get('ai_model_text', 'gpt-4o-mini'),
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
                'bluesky' => 'Bluesky',
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
            .implode(', ', array_map(fn ($s) => "\"{$s}\"", $platformSlugs))
            .'. Chaque valeur est le texte pour cette plateforme. Pas de markdown, pas d\'explication, juste le JSON.';

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(90)->post('https://api.openai.com/v1/chat/completions', [
                'model' => Setting::get('ai_model_text', 'gpt-4o-mini'),
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
    public function generateFromMediaForPlatforms(array $imageDataUrls, array $platformSlugs, Persona $persona, ?SocialAccount $account = null, string $content = ''): null|array|string
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
                'bluesky' => 'Bluesky',
                default => ucfirst($slug),
            };
            $platformDescriptions[] = "{$label} (max {$charLimit} caractères)";
        }

        $platformList = implode(', ', $platformDescriptions);
        $platformsJson = implode(', ', array_map(fn ($s) => "\"{$s}\"", $platformSlugs));

        $contextBlock = trim($content) !== ''
            ? "CONTEXTE FOURNI PAR L'UTILISATEUR :\n{$content}\n\nUtilise ce contexte pour orienter et enrichir ton texte en combinaison avec les détails visuels."
            : '';

        // Prompt configurable via Settings → Contenu IA (fallback sur le défaut hardcodé).
        $template = Setting::get('ai_prompt_publication_from_photo', self::DEFAULT_PROMPT_PUBLICATION_FROM_PHOTO);
        $userPrompt = strtr($template, [
            '{contexte_utilisateur}' => $contextBlock,
            '{langue}' => $languageLabel,
            '{liste_plateformes}' => $platformList,
            '{liste_plateformes_json}' => $platformsJson,
        ]);

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

        // System prompt with professional framing to avoid content refusals
        $systemPrompt = 'Tu es un assistant de gestion de réseaux sociaux professionnel. '
            .'Tu travailles pour un(e) créateur/créatrice de contenu qui te fournit ses propres photos pour publication sur ses comptes. '
            ."Ton rôle est de rédiger des légendes/descriptions engageantes pour accompagner ces photos.\n\n"
            .$persona->system_prompt;

        // Try with configured model, then fallback models if refused
        $primaryModel = Setting::get('ai_model_vision', 'gpt-4o');
        $modelsToTry = array_unique([$primaryModel, 'gpt-4.1', 'gpt-4.1-mini', 'gpt-4o-mini']);

        try {
            foreach ($modelsToTry as $model) {
                $response = Http::withHeaders([
                    'Authorization' => "Bearer {$apiKey}",
                    'Content-Type' => 'application/json',
                ])->timeout(120)->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $contentBlocks],
                    ],
                    'temperature' => 0.7,
                    'max_tokens' => 4000,
                    'response_format' => ['type' => 'json_object'],
                ]);

                if ($response->successful()) {
                    $refusal = $response->json('choices.0.message.refusal');

                    if ($refusal) {
                        Log::warning('AiAssistService: Vision model refused', [
                            'model' => $model,
                            'refusal' => $refusal,
                        ]);

                        continue; // Try next model
                    }

                    $raw = trim($response->json('choices.0.message.content', ''));
                    $parsed = json_decode($raw, true);

                    if (is_array($parsed)) {
                        $result = array_intersect_key($parsed, array_flip($platformSlugs));
                        Log::info('AiAssistService: Media-based content generated', [
                            'persona' => $persona->name,
                            'model' => $model,
                            'platforms' => $platformSlugs,
                            'image_count' => count($imageDataUrls),
                            'lengths' => array_map('mb_strlen', $result),
                        ]);

                        return $result;
                    }

                    Log::error('AiAssistService: Failed to parse Vision JSON response', [
                        'model' => $model,
                        'raw' => $raw,
                    ]);

                    return null;
                }

                Log::warning('AiAssistService: Vision API error, trying next model', [
                    'model' => $model,
                    'status' => $response->status(),
                ]);
            }

            Log::error('AiAssistService: All Vision models failed or refused');

            return 'refused';

        } catch (\Exception $e) {
            Log::error('AiAssistService: Vision exception', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Analyse une image avec Vision API et retourne les métadonnées structurées
     * pour le catalogue média (description_fr, thematic_tags, people_ids,
     * person_count, city, region, country, brands, event, taken_at).
     *
     * Renvoie un tableau parsable, ou null en cas d'échec/refus, ou la string 'refused'
     * si tous les modèles ont refusé.
     *
     * @param  string  $imageDataUrl  Data URL "data:image/jpeg;base64,..." de l'image à analyser
     * @param  string|null  $context  Contexte libre (ex: nom/chemin du dossier rattaché — sert d'orientation)
     * @param  array  $expectedPeople  Ids de personnes attendues (ex: ["caroline", "xavier"])
     */
    public function extractMetadataFromImage(
        string $imageDataUrl,
        ?string $context = null,
        array $expectedPeople = [],
    ): null|array|string {
        $apiKey = Setting::getEncrypted('openai_api_key');
        if (! $apiKey) {
            Log::warning('AiAssistService: No OpenAI API key configured');

            return null;
        }

        $template = Setting::get('ai_prompt_metadata_extraction', self::DEFAULT_PROMPT_METADATA_EXTRACTION);
        $userPrompt = strtr($template, [
            '{contexte}' => $context !== null && trim($context) !== '' ? trim($context) : 'aucun',
            '{personnes_attendues}' => empty($expectedPeople) ? 'aucune' : implode(', ', $expectedPeople),
        ]);

        $contentBlocks = [
            ['type' => 'text', 'text' => $userPrompt],
            ['type' => 'image_url', 'image_url' => ['url' => $imageDataUrl, 'detail' => 'auto']],
        ];

        $systemPrompt = "Tu es un assistant d'analyse d'image pour un catalogue média. "
            .'Tu observes une photo et extrais des métadonnées factuelles (lieu, marques, ambiance, tags). '
            .'Tu réponds toujours en JSON valide selon le schéma demandé.';

        $primaryModel = Setting::get('ai_model_vision', 'gpt-4o');
        $modelsToTry = array_unique([$primaryModel, 'gpt-4.1', 'gpt-4.1-mini', 'gpt-4o-mini']);

        try {
            foreach ($modelsToTry as $model) {
                $response = Http::withHeaders([
                    'Authorization' => "Bearer {$apiKey}",
                    'Content-Type' => 'application/json',
                ])->timeout(120)->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $contentBlocks],
                    ],
                    'temperature' => 0.3, // plus déterministe que pour la rédaction
                    'max_tokens' => 1500,
                    'response_format' => ['type' => 'json_object'],
                ]);

                if ($response->successful()) {
                    $refusal = $response->json('choices.0.message.refusal');
                    if ($refusal) {
                        Log::warning('AiAssistService: Metadata extraction refused', [
                            'model' => $model,
                            'refusal' => $refusal,
                        ]);

                        continue;
                    }

                    $raw = trim($response->json('choices.0.message.content', ''));
                    $parsed = json_decode($raw, true);
                    if (! is_array($parsed)) {
                        Log::error('AiAssistService: Failed to parse metadata JSON', [
                            'model' => $model,
                            'raw' => $raw,
                        ]);

                        return null;
                    }

                    Log::info('AiAssistService: Metadata extracted', [
                        'model' => $model,
                        'context' => $context,
                        'tags_count' => count($parsed['thematic_tags'] ?? []),
                        'has_location' => ! empty($parsed['city']) || ! empty($parsed['country']),
                    ]);

                    return $this->normalizeMetadataResponse($parsed, $expectedPeople);
                }

                Log::warning('AiAssistService: Metadata API error, trying next model', [
                    'model' => $model,
                    'status' => $response->status(),
                ]);
            }

            Log::error('AiAssistService: All Vision models failed for metadata extraction');

            return 'refused';
        } catch (\Exception $e) {
            Log::error('AiAssistService: Metadata extraction exception', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Garde uniquement les clés attendues du schéma + valide les types.
     * Sans cette normalisation, le LLM peut retourner des valeurs hors-schéma qu'on
     * n'a pas envie de propager au modèle MediaFile.
     */
    private function normalizeMetadataResponse(array $raw, array $expectedPeople): array
    {
        $stringOrNull = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;

        $tags = is_array($raw['thematic_tags'] ?? null)
            ? array_values(array_filter(array_map(fn ($t) => is_string($t) ? mb_strtolower(trim($t)) : null, $raw['thematic_tags']), fn ($t) => $t !== null && $t !== ''))
            : [];

        $peopleIds = is_array($raw['people_ids'] ?? null)
            ? array_values(array_filter(array_map(fn ($p) => is_string($p) ? mb_strtolower(trim($p)) : null, $raw['people_ids']), fn ($p) => $p !== null && $p !== ''))
            : [];
        // Whitelist anti-hallucination. Si le caller n'a pas précisé une liste explicite,
        // on tombe sur la heuristique par défaut (xavier/caroline) cohérente avec le prompt.
        $allowed = array_map('mb_strtolower', ! empty($expectedPeople) ? $expectedPeople : ['xavier', 'caroline']);
        $peopleIds = array_values(array_unique(array_intersect($peopleIds, $allowed)));

        $brands = is_array($raw['brands'] ?? null)
            ? array_values(array_filter(array_map(fn ($b) => is_string($b) ? trim($b) : null, $raw['brands']), fn ($b) => $b !== null && $b !== ''))
            : [];

        return [
            'description_fr' => $stringOrNull($raw['description_fr'] ?? null),
            'thematic_tags' => $tags,
            'people_ids' => $peopleIds,
            'person_count' => is_int($raw['person_count'] ?? null) ? $raw['person_count'] : null,
            'city' => $stringOrNull($raw['city'] ?? null),
            'region' => $stringOrNull($raw['region'] ?? null),
            'country' => $stringOrNull($raw['country'] ?? null),
            'brands' => $brands,
            'event' => $stringOrNull($raw['event'] ?? null),
            'taken_at' => $stringOrNull($raw['taken_at'] ?? null),
        ];
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

    /**
     * Genere un commentaire bot adapte au type de post (article / texte / image).
     * Utilise le persona + son contexte bot specifique au type detecte.
     * Choisit automatiquement un modele vision si $imageUrl fourni.
     *
     * @param  Persona  $persona  Le persona du compte social (doit avoir au moins un bot_comment_context_* rempli)
     * @param  string  $postKind  'article' | 'text' | 'image'
     * @param  string  $postContent  Texte du post a commenter
     * @param  string|null  $imageUrl  URL d'une image visible dans le post (declenche vision)
     * @param  string|null  $authorName  Nom de l'auteur (pour personnalisation)
     */
    public function generateBotComment(
        Persona $persona,
        string $postKind,
        string $postContent,
        ?string $imageUrl = null,
        ?string $authorName = null,
    ): ?string {
        $context = match ($postKind) {
            'article' => $persona->bot_comment_context_article,
            'image' => $persona->bot_comment_context_image,
            default => $persona->bot_comment_context_text,
        };

        if (! $context || ! trim((string) $context)) {
            $context = 'Reponds de maniere authentique et engageante au post.';
        }

        $maxLength = (int) ($persona->bot_comment_max_length ?: 280);

        $systemPrompt = trim($persona->system_prompt)."\n\n".trim($context)
            ."\n\nContraintes : reponse en {$maxLength} caracteres maximum, pas de hashtags, ton naturel, pas de formule de politesse generique.";

        $authorBlock = $authorName ? "Auteur : {$authorName}\n" : '';
        $userText = "{$authorBlock}Type de post : {$postKind}\n\nContenu du post :\n{$postContent}";

        $needVision = $postKind === 'image' && ! empty($imageUrl);

        if ($needVision) {
            $userContent = [
                ['type' => 'text', 'text' => $userText],
                ['type' => 'image_url', 'image_url' => ['url' => $imageUrl]],
            ];
        } else {
            $userContent = $userText;
        }

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userContent],
        ];

        $reply = $this->callLlm($messages, null, $needVision, [
            'temperature' => 0.8,
            'max_tokens' => 600,
        ]);

        if (! $reply) {
            return null;
        }

        // Garde-fou : si le LLM depasse, on tronque proprement.
        if (mb_strlen($reply) > $maxLength) {
            $reply = mb_substr($reply, 0, $maxLength - 1).'…';
        }

        return $reply;
    }

    /**
     * Appel LLM multi-provider (Groq / OpenRouter / Google AI / Mistral / Together / OpenAI).
     * Selection :
     *   1. $modelOverride si fourni (format "provider/model_id")
     *   2. Sinon : free_llms_default_vision_model si $needVision, sinon free_llms_default_text_model
     *   3. Fallback : OpenAI (modeles ai_model_text / ai_model_vision)
     *
     * Retourne le contenu textuel de la reponse (str) ou null en cas d'echec.
     */
    private function callLlm(array $messages, ?string $modelOverride, bool $needVision, array $opts = []): ?string
    {
        $qualified = $modelOverride;

        if (! $qualified) {
            $settingKey = $needVision ? 'free_llms_default_vision_model' : 'free_llms_default_text_model';
            $qualified = Setting::get($settingKey) ?: null;
        }

        if ($qualified && str_contains($qualified, '/')) {
            [$provider, $modelId] = explode('/', $qualified, 2);
        } else {
            $provider = 'openai';
            $modelId = $qualified ?: Setting::get($needVision ? 'ai_model_vision' : 'ai_model_text', $needVision ? 'gpt-4o' : 'gpt-4o-mini');
        }

        $temperature = $opts['temperature'] ?? 0.7;
        $maxTokens = $opts['max_tokens'] ?? 1000;

        return match ($provider) {
            'google_ai' => $this->callGoogleAi($messages, $modelId, $temperature, $maxTokens),
            default => $this->callOpenAiCompatible($provider, $modelId, $messages, $temperature, $maxTokens),
        };
    }

    /**
     * Appelle un endpoint compatible OpenAI Chat Completions
     * (OpenAI / Groq / OpenRouter / Mistral / Together).
     */
    private function callOpenAiCompatible(string $provider, string $modelId, array $messages, float $temperature, int $maxTokens): ?string
    {
        [$baseUrl, $apiKey] = match ($provider) {
            'groq' => ['https://api.groq.com/openai/v1', Setting::getEncrypted('groq_api_key')],
            'openrouter' => ['https://openrouter.ai/api/v1', Setting::getEncrypted('openrouter_api_key')],
            'mistral' => ['https://api.mistral.ai/v1', Setting::getEncrypted('mistral_api_key')],
            'together' => ['https://api.together.xyz/v1', Setting::getEncrypted('together_api_key')],
            default => ['https://api.openai.com/v1', Setting::getEncrypted('openai_api_key')],
        };

        if (! $apiKey) {
            Log::warning("AiAssistService: No API key for provider {$provider}");

            return null;
        }

        try {
            $resp = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(60)->post("{$baseUrl}/chat/completions", [
                'model' => $modelId,
                'messages' => $messages,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
            ]);

            if (! $resp->successful()) {
                Log::error('AiAssistService: LLM API error', [
                    'provider' => $provider,
                    'model' => $modelId,
                    'status' => $resp->status(),
                    'body' => mb_substr($resp->body(), 0, 500),
                ]);

                return null;
            }

            $content = trim($resp->json('choices.0.message.content', ''));

            return $content !== '' ? $content : null;
        } catch (\Exception $e) {
            Log::error('AiAssistService: LLM call exception', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Adapter Google AI (format generateContent different d'OpenAI Chat).
     */
    private function callGoogleAi(array $messages, string $modelId, float $temperature, int $maxTokens): ?string
    {
        $apiKey = Setting::getEncrypted('google_ai_api_key');
        if (! $apiKey) {
            Log::warning('AiAssistService: No Google AI API key');

            return null;
        }

        $systemInstruction = null;
        $contents = [];

        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $systemInstruction = is_string($msg['content']) ? $msg['content'] : '';

                continue;
            }

            $parts = [];
            if (is_string($msg['content'])) {
                $parts[] = ['text' => $msg['content']];
            } else {
                foreach ($msg['content'] as $block) {
                    if ($block['type'] === 'text') {
                        $parts[] = ['text' => $block['text']];
                    } elseif ($block['type'] === 'image_url') {
                        $url = $block['image_url']['url'] ?? '';
                        if (str_starts_with($url, 'data:')) {
                            // data URL → inline_data
                            [$mimePart, $b64] = explode(',', $url, 2);
                            $mime = preg_match('/data:([^;]+);base64/', $mimePart, $m) ? $m[1] : 'image/jpeg';
                            $parts[] = ['inline_data' => ['mime_type' => $mime, 'data' => $b64]];
                        } else {
                            // URL distante → fetch + base64 (Google AI ne supporte pas les URL directes hors File API)
                            try {
                                $bin = Http::timeout(15)->get($url);
                                if ($bin->successful()) {
                                    $mime = $bin->header('Content-Type') ?: 'image/jpeg';
                                    $parts[] = ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bin->body())]];
                                }
                            } catch (\Exception $e) {
                                Log::warning('AiAssistService: Failed to fetch image for Google AI', ['url' => $url]);
                            }
                        }
                    }
                }
            }

            $contents[] = [
                'role' => $msg['role'] === 'assistant' ? 'model' : 'user',
                'parts' => $parts,
            ];
        }

        try {
            $payload = [
                'contents' => $contents,
                'generationConfig' => [
                    'temperature' => $temperature,
                    'maxOutputTokens' => $maxTokens,
                ],
            ];
            if ($systemInstruction) {
                $payload['systemInstruction'] = ['parts' => [['text' => $systemInstruction]]];
            }

            $resp = Http::timeout(60)
                ->post("https://generativelanguage.googleapis.com/v1beta/models/{$modelId}:generateContent?key={$apiKey}", $payload);

            if (! $resp->successful()) {
                Log::error('AiAssistService: Google AI error', [
                    'model' => $modelId,
                    'status' => $resp->status(),
                    'body' => mb_substr($resp->body(), 0, 500),
                ]);

                return null;
            }

            $text = $resp->json('candidates.0.content.parts.0.text', '');

            return $text !== '' ? trim($text) : null;
        } catch (\Exception $e) {
            Log::error('AiAssistService: Google AI exception', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
