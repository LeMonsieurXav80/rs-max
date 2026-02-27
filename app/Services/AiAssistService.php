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
