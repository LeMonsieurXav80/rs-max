<?php

namespace App\Services\Llm;

use App\Models\FreeLlmModel;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Healthcheck deterministe pour les modeles LLM gratuits :
 * envoie un ping minimal (texte ou texte+image 1px) et stocke
 * la latence + le code de statut sur la table free_llm_models.
 *
 * Utilise par :
 *   - php artisan free-llms:test (scheduler quotidien)
 *   - bouton "Tester les modeles" sur la page Settings
 */
class FreeLlmTestService
{
    /**
     * PNG 1x1 transparent base64 — payload vision minimal (< 100B).
     * Suffit a verifier que l'endpoint accepte une image, sans gaspiller de tokens.
     */
    private const TEST_IMAGE_DATA_URL = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private const TIMEOUT_SECONDS = 20;

    /**
     * Teste tous les modeles disponibles et met a jour leurs colonnes last_test_*.
     *
     * @return array{tested:int, ok:int, failed:int}
     */
    public function testAll(): array
    {
        $models = FreeLlmModel::available()->get();
        $ok = 0;
        $failed = 0;

        foreach ($models as $model) {
            $result = $this->testModel($model);
            if ($result['status'] === FreeLlmModel::TEST_STATUS_OK) {
                $ok++;
            } else {
                $failed++;
            }
        }

        return ['tested' => $models->count(), 'ok' => $ok, 'failed' => $failed];
    }

    /**
     * Teste un modele individuel et persiste le resultat.
     * Pour les modeles vision, on envoie un payload texte+image ;
     * sinon, juste un ping texte.
     *
     * @return array{status:string, error:?string, latency_ms:int}
     */
    public function testModel(FreeLlmModel $model): array
    {
        $start = microtime(true);

        try {
            if ($model->supports_vision) {
                $result = $this->probeVision($model);
            } else {
                $result = $this->probeText($model);
            }
        } catch (Throwable $e) {
            $result = ['status' => FreeLlmModel::TEST_STATUS_ERROR, 'error' => $e->getMessage()];
        }

        $latency = (int) round((microtime(true) - $start) * 1000);

        $model->update([
            'last_test_status' => $result['status'],
            'last_test_error' => $result['error'] ? mb_substr($result['error'], 0, 500) : null,
            'last_test_latency_ms' => $latency,
            'last_tested_at' => Carbon::now(),
        ]);

        return [...$result, 'latency_ms' => $latency];
    }

    /**
     * @return array{status:string, error:?string}
     */
    private function probeText(FreeLlmModel $model): array
    {
        $messages = [
            ['role' => 'user', 'content' => 'Reply with the single word: OK'],
        ];

        return $this->callAndClassify($model, $messages);
    }

    /**
     * @return array{status:string, error:?string}
     */
    private function probeVision(FreeLlmModel $model): array
    {
        $messages = [
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'Reply with the single word: OK'],
                ['type' => 'image_url', 'image_url' => ['url' => self::TEST_IMAGE_DATA_URL]],
            ]],
        ];

        return $this->callAndClassify($model, $messages);
    }

    /**
     * Effectue l'appel HTTP et classe la reponse en code de statut metier.
     *
     * @return array{status:string, error:?string}
     */
    private function callAndClassify(FreeLlmModel $model, array $messages): array
    {
        $provider = $model->provider;
        $modelId = $model->model_id;

        return match ($provider) {
            'google_ai' => $this->callGoogleAi($modelId, $messages),
            default => $this->callOpenAiCompatible($provider, $modelId, $messages),
        };
    }

    /**
     * @return array{status:string, error:?string}
     */
    private function callOpenAiCompatible(string $provider, string $modelId, array $messages): array
    {
        [$baseUrl, $apiKey] = match ($provider) {
            'groq' => ['https://api.groq.com/openai/v1', Setting::getEncrypted('groq_api_key')],
            'openrouter' => ['https://openrouter.ai/api/v1', Setting::getEncrypted('openrouter_api_key')],
            'mistral' => ['https://api.mistral.ai/v1', Setting::getEncrypted('mistral_api_key')],
            'together' => ['https://api.together.xyz/v1', Setting::getEncrypted('together_api_key')],
            default => [null, null],
        };

        if (! $baseUrl || ! $apiKey) {
            return ['status' => FreeLlmModel::TEST_STATUS_AUTH, 'error' => 'API key missing for provider '.$provider];
        }

        try {
            $resp = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(self::TIMEOUT_SECONDS)
                ->post("{$baseUrl}/chat/completions", [
                    'model' => $modelId,
                    'messages' => $messages,
                    'temperature' => 0,
                    'max_tokens' => 10,
                ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return ['status' => FreeLlmModel::TEST_STATUS_TIMEOUT, 'error' => $e->getMessage()];
        }

        if ($resp->successful()) {
            $text = trim((string) $resp->json('choices.0.message.content', ''));
            $refusal = $resp->json('choices.0.message.refusal');

            if ($refusal) {
                return ['status' => FreeLlmModel::TEST_STATUS_REFUSED, 'error' => (string) $refusal];
            }

            return $text !== ''
                ? ['status' => FreeLlmModel::TEST_STATUS_OK, 'error' => null]
                : ['status' => FreeLlmModel::TEST_STATUS_ERROR, 'error' => 'Empty completion content'];
        }

        return $this->classifyHttpError($resp->status(), $resp->body());
    }

    /**
     * @return array{status:string, error:?string}
     */
    private function callGoogleAi(string $modelId, array $messages): array
    {
        $apiKey = Setting::getEncrypted('google_ai_api_key');
        if (! $apiKey) {
            return ['status' => FreeLlmModel::TEST_STATUS_AUTH, 'error' => 'Google AI API key missing'];
        }

        $parts = [];
        foreach ($messages[0]['content'] ?? [] as $block) {
            if (is_string($block)) {
                $parts[] = ['text' => $block];

                continue;
            }
            if ($block['type'] === 'text') {
                $parts[] = ['text' => $block['text']];
            } elseif ($block['type'] === 'image_url') {
                $url = $block['image_url']['url'] ?? '';
                if (str_starts_with($url, 'data:')) {
                    [$mimePart, $b64] = explode(',', $url, 2);
                    $mime = preg_match('/data:([^;]+);base64/', $mimePart, $m) ? $m[1] : 'image/png';
                    $parts[] = ['inline_data' => ['mime_type' => $mime, 'data' => $b64]];
                }
            }
        }
        // Cas message texte simple (non vision) — content est une string
        if ($parts === [] && is_string($messages[0]['content'] ?? null)) {
            $parts[] = ['text' => $messages[0]['content']];
        }

        try {
            $resp = Http::timeout(self::TIMEOUT_SECONDS)
                ->post("https://generativelanguage.googleapis.com/v1beta/models/{$modelId}:generateContent?key={$apiKey}", [
                    'contents' => [['role' => 'user', 'parts' => $parts]],
                    'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 10],
                ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return ['status' => FreeLlmModel::TEST_STATUS_TIMEOUT, 'error' => $e->getMessage()];
        }

        if ($resp->successful()) {
            $text = trim((string) $resp->json('candidates.0.content.parts.0.text', ''));

            return $text !== ''
                ? ['status' => FreeLlmModel::TEST_STATUS_OK, 'error' => null]
                : ['status' => FreeLlmModel::TEST_STATUS_ERROR, 'error' => 'Empty completion content'];
        }

        return $this->classifyHttpError($resp->status(), $resp->body());
    }

    /**
     * Classe une reponse HTTP non-200 en statut metier exploitable par l'UI.
     *
     * @return array{status:string, error:?string}
     */
    private function classifyHttpError(int $status, string $body): array
    {
        $bodyLow = strtolower($body);
        $excerpt = mb_substr($body, 0, 400);

        if ($status === 401 || $status === 403) {
            return ['status' => FreeLlmModel::TEST_STATUS_AUTH, 'error' => "HTTP {$status}: ".$excerpt];
        }

        if ($status === 429 || str_contains($bodyLow, 'quota') || str_contains($bodyLow, 'rate limit') || str_contains($bodyLow, 'limit: 0')) {
            return ['status' => FreeLlmModel::TEST_STATUS_QUOTA, 'error' => "HTTP {$status}: ".$excerpt];
        }

        Log::debug('FreeLlmTestService: unclassified HTTP error', [
            'status' => $status,
            'body' => $excerpt,
        ]);

        return ['status' => FreeLlmModel::TEST_STATUS_ERROR, 'error' => "HTTP {$status}: ".$excerpt];
    }
}
