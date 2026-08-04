<?php

namespace App\Services\Twitter;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Articles X (contenu long format), via les endpoints ouverts le 11 juin 2026 :
 *
 *   POST /2/articles/draft            { title, content_state } -> { data: { id, title } }
 *   POST /2/articles/{id}/publish                              -> { data: { post_id } }
 *
 * Le compte doit être abonné (cf. SocialAccount::hasPaidSubscription()) et
 * l'application doit disposer des scopes tweet.read, tweet.write, users.read.
 *
 * Le corps est au format DraftJS. rs-max manipule du texte plat partout
 * (composer, IA, traduction) : la conversion se fait ici, à la frontière.
 */
class TwitterArticleService
{
    private const DRAFT_URL = 'https://api.x.com/2/articles/draft';

    private const PUBLISH_URL = 'https://api.x.com/2/articles/%s/publish';

    public function __construct(private TwitterOAuth1Signer $signer = new TwitterOAuth1Signer) {}

    /**
     * Crée un brouillon d'article. Renvoie l'id de l'article, ou une erreur.
     *
     * @return array{success: bool, article_id?: string, error?: string, response?: array}
     */
    public function createDraft(SocialAccount $account, string $title, string $body): array
    {
        $header = $this->signer->header($account, 'POST', self::DRAFT_URL);

        if (! $header) {
            return ['success' => false, 'error' => 'Credentials OAuth 1.0a incomplets sur le compte.'];
        }

        $payload = [
            'title' => $title,
            'content_state' => $this->toContentState($body),
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => $header,
                'Content-Type' => 'application/json',
            ])->post(self::DRAFT_URL, $payload);
        } catch (\Throwable $e) {
            Log::warning('TwitterArticleService: exception au brouillon', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }

        $articleId = $response->json('data.id');

        if (! $response->successful() || ! $articleId) {
            return [
                'success' => false,
                'error' => $this->errorMessage($response),
                'response' => $response->json() ?? [],
            ];
        }

        return [
            'success' => true,
            'article_id' => (string) $articleId,
            'response' => $response->json() ?? [],
        ];
    }

    /**
     * Publie un brouillon. Renvoie le post_id du post créé dans la timeline.
     *
     * @return array{success: bool, post_id?: string, error?: string, response?: array}
     */
    public function publishDraft(SocialAccount $account, string $articleId): array
    {
        $url = sprintf(self::PUBLISH_URL, $articleId);
        $header = $this->signer->header($account, 'POST', $url);

        if (! $header) {
            return ['success' => false, 'error' => 'Credentials OAuth 1.0a incomplets sur le compte.'];
        }

        try {
            $response = Http::withHeaders(['Authorization' => $header])->post($url);
        } catch (\Throwable $e) {
            Log::warning('TwitterArticleService: exception à la publication', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }

        $postId = $response->json('data.post_id');

        if (! $response->successful() || ! $postId) {
            return [
                'success' => false,
                'error' => $this->errorMessage($response),
                'response' => $response->json() ?? [],
            ];
        }

        return [
            'success' => true,
            'post_id' => (string) $postId,
            'response' => $response->json() ?? [],
        ];
    }

    /**
     * Texte plat -> content_state DraftJS.
     *
     * Une ligne non vide = un bloc. Un sous-ensemble de Markdown est reconnu
     * parce que c'est ce que produit spontanément la génération IA ; le reste
     * tombe en paragraphe simple.
     *
     * `entities` reste vide : X documente le champ mais pas son format, et
     * inventer une structure de lien ferait échouer l'appel entier. Les URL
     * restent donc en texte dans le corps.
     *
     * @return array{blocks: array<int, array{text: string, type: string}>, entities: array}
     */
    public function toContentState(string $body): array
    {
        $blocks = [];

        foreach (preg_split("/\r\n|\n|\r/", $body) as $line) {
            $line = rtrim($line);

            // Les lignes vides séparent les blocs, elles ne deviennent pas des blocs.
            if (trim($line) === '') {
                continue;
            }

            $blocks[] = $this->toBlock($line);
        }

        // Un article sans bloc serait refusé : on garde au moins un paragraphe vide.
        if ($blocks === []) {
            $blocks[] = ['text' => '', 'type' => 'unstyled'];
        }

        return ['blocks' => $blocks, 'entities' => []];
    }

    /**
     * @return array{text: string, type: string}
     */
    private function toBlock(string $line): array
    {
        $patterns = [
            '/^###\s+(.*)$/u' => 'header-three',
            '/^##\s+(.*)$/u' => 'header-two',
            '/^#\s+(.*)$/u' => 'header-one',
            '/^>\s+(.*)$/u' => 'blockquote',
            '/^[-*]\s+(.*)$/u' => 'unordered-list-item',
            '/^\d+[.)]\s+(.*)$/u' => 'ordered-list-item',
        ];

        foreach ($patterns as $pattern => $type) {
            if (preg_match($pattern, $line, $matches)) {
                return ['text' => trim($matches[1]), 'type' => $type];
            }
        }

        return ['text' => trim($line), 'type' => 'unstyled'];
    }

    private function errorMessage(\Illuminate\Http\Client\Response $response): string
    {
        return $response->json('detail')
            ?? $response->json('errors.0.message')
            ?? $response->json('title')
            ?? 'Erreur HTTP '.$response->status();
    }
}
