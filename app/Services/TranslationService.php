<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TranslationService
{
    public function translate(string $text, string $from = 'fr', string $to = 'en', ?string $apiKey = null): ?string
    {
        $key = $apiKey ?: config('services.openai.api_key');
        if (!$key) {
            Log::warning('TranslationService: No OpenAI API key configured');
            return null;
        }

        $prompt = match("{$from}->{$to}") {
            'fr->en' => "Reinterprete this French text to English. Keep the same tone, style and meaning. Do not add any explanation, just provide the English version. Keep emojis and formatting. Text:\n\n{$text}",
            'en->fr' => "Reinterprete ce texte anglais en francais. Garde le meme ton, style et sens. Ne fournis que la version francaise. Garde les emojis et le formatage. Texte:\n\n{$text}",
            default => "Translate this text from {$from} to {$to}. Keep the same tone, style and meaning. Only provide the translation:\n\n{$text}",
        };

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$key}",
                'Content-Type' => 'application/json',
            ])->timeout(30)->post('https://api.openai.com/v1/chat/completions', [
                'model' => Setting::get('ai_model_translation', 'gpt-4o-mini'),
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a professional translator. Only output the translation, nothing else.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.3,
                'max_tokens' => 2000,
            ]);

            if ($response->successful()) {
                return trim($response->json('choices.0.message.content', ''));
            }

            Log::error('TranslationService: API error', ['status' => $response->status(), 'body' => $response->body()]);
            return null;
        } catch (\Exception $e) {
            Log::error('TranslationService: Exception', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
