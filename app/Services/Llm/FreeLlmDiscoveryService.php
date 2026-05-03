<?php

namespace App\Services\Llm;

use App\Models\FreeLlmModel;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class FreeLlmDiscoveryService
{
    public const PROVIDER_OPENROUTER = 'openrouter';

    public const PROVIDER_GROQ = 'groq';

    public const PROVIDER_GOOGLE_AI = 'google_ai';

    public const PROVIDER_MISTRAL = 'mistral';

    public const PROVIDER_TOGETHER = 'together';

    private const STALE_AFTER_DAYS = 7;

    /**
     * Liste statique des modeles vision Groq connus (l'API ne renseigne pas la modalite).
     * Source: https://console.groq.com/docs/models — Llama 4.
     */
    private const GROQ_VISION_MODELS = [
        'meta-llama/llama-4-scout-17b-16e-instruct',
        'meta-llama/llama-4-maverick-17b-128e-instruct',
    ];

    /**
     * Mistral free-tier models (la classe `models` retourne tout le catalogue, on filtre ceux dispo en gratuit).
     */
    private const MISTRAL_FREE_TIER = [
        'mistral-small-latest' => ['vision' => false, 'context' => 128_000],
        'pixtral-12b-2409' => ['vision' => true, 'context' => 128_000],
        'open-mistral-nemo' => ['vision' => false, 'context' => 128_000],
    ];

    /**
     * Together "free" tier — modeles avec :free dans le nom ou tier gratuit (ex Llama 3.3 70B Free).
     */
    private const TOGETHER_FREE_HINTS = ['-free', ':free'];

    public function refresh(): array
    {
        $results = [];

        foreach ($this->providers() as $provider => $callback) {
            try {
                $models = $callback();
                $count = $this->upsertModels($provider, $models);
                $results[$provider] = $count;
            } catch (Throwable $e) {
                Log::warning("FreeLlmDiscovery: provider {$provider} failed", ['err' => $e->getMessage()]);
                $results[$provider] = 0;
            }
        }

        $this->markStaleAsUnavailable();
        Setting::set('free_llms_last_refresh_at', Carbon::now()->toIso8601String());

        return $results;
    }

    private function providers(): array
    {
        return [
            self::PROVIDER_OPENROUTER => fn () => $this->fetchOpenRouter(),
            self::PROVIDER_GROQ => fn () => $this->fetchGroq(),
            self::PROVIDER_GOOGLE_AI => fn () => $this->fetchGoogleAi(),
            self::PROVIDER_MISTRAL => fn () => $this->fetchMistral(),
            self::PROVIDER_TOGETHER => fn () => $this->fetchTogether(),
        ];
    }

    public function fetchOpenRouter(): Collection
    {
        $key = Setting::getEncrypted('openrouter_api_key');
        if (! $key) {
            return collect();
        }

        $resp = Http::withToken($key)
            ->timeout(20)
            ->get('https://openrouter.ai/api/v1/models');

        if (! $resp->successful()) {
            return collect();
        }

        return collect($resp->json('data', []))
            ->filter(function (array $model) {
                $pricing = $model['pricing'] ?? [];
                $prompt = (float) ($pricing['prompt'] ?? 1);
                $completion = (float) ($pricing['completion'] ?? 1);

                return $prompt === 0.0 && $completion === 0.0;
            })
            ->map(function (array $model) {
                $modalities = (array) ($model['architecture']['input_modalities'] ?? $model['architecture']['modality'] ?? []);
                $modalitiesStr = is_array($modalities) ? implode(',', $modalities) : (string) $modalities;
                $supportsVision = str_contains(strtolower($modalitiesStr), 'image');

                return [
                    'model_id' => $model['id'],
                    'display_name' => $model['name'] ?? $model['id'],
                    'supports_text' => true,
                    'supports_vision' => $supportsVision,
                    'context_length' => $model['context_length'] ?? null,
                    'daily_token_limit' => null,
                    'rpm_limit' => null,
                    'metadata' => [
                        'description' => $model['description'] ?? null,
                        'pricing' => $model['pricing'] ?? null,
                    ],
                ];
            })
            ->values();
    }

    public function fetchGroq(): Collection
    {
        $key = Setting::getEncrypted('groq_api_key');
        if (! $key) {
            return collect();
        }

        $resp = Http::withToken($key)
            ->timeout(15)
            ->get('https://api.groq.com/openai/v1/models');

        if (! $resp->successful()) {
            return collect();
        }

        return collect($resp->json('data', []))
            ->filter(fn (array $m) => ($m['active'] ?? true) === true)
            ->map(function (array $m) {
                $id = $m['id'];
                $supportsVision = in_array($id, self::GROQ_VISION_MODELS, true);

                return [
                    'model_id' => $id,
                    'display_name' => $m['id'],
                    'supports_text' => true,
                    'supports_vision' => $supportsVision,
                    'context_length' => $m['context_window'] ?? null,
                    'daily_token_limit' => null,
                    'rpm_limit' => null,
                    'metadata' => [
                        'owned_by' => $m['owned_by'] ?? null,
                    ],
                ];
            })
            ->values();
    }

    public function fetchGoogleAi(): Collection
    {
        $key = Setting::getEncrypted('google_ai_api_key');
        if (! $key) {
            return collect();
        }

        $resp = Http::timeout(20)
            ->get('https://generativelanguage.googleapis.com/v1beta/models', ['key' => $key]);

        if (! $resp->successful()) {
            return collect();
        }

        return collect($resp->json('models', []))
            ->filter(function (array $model) {
                $methods = $model['supportedGenerationMethods'] ?? [];

                return in_array('generateContent', $methods, true);
            })
            ->map(function (array $model) {
                $name = str_replace('models/', '', $model['name']);
                $isFlash = str_contains($name, 'flash');
                $isVision = str_contains($name, 'vision') || str_contains($name, 'gemini-1.5') || str_contains($name, 'gemini-2');

                return [
                    'model_id' => $name,
                    'display_name' => $model['displayName'] ?? $name,
                    'supports_text' => true,
                    'supports_vision' => $isVision,
                    'context_length' => $model['inputTokenLimit'] ?? null,
                    'daily_token_limit' => null,
                    'rpm_limit' => null,
                    'metadata' => [
                        'description' => $model['description'] ?? null,
                        'is_flash_free' => $isFlash,
                    ],
                ];
            })
            ->filter(fn (array $m) => $m['metadata']['is_flash_free'] ?? false)
            ->values();
    }

    public function fetchMistral(): Collection
    {
        $key = Setting::getEncrypted('mistral_api_key');
        if (! $key) {
            return collect();
        }

        $resp = Http::withToken($key)
            ->timeout(15)
            ->get('https://api.mistral.ai/v1/models');

        if (! $resp->successful()) {
            return collect();
        }

        return collect($resp->json('data', []))
            ->filter(fn (array $m) => array_key_exists($m['id'], self::MISTRAL_FREE_TIER))
            ->map(function (array $m) {
                $info = self::MISTRAL_FREE_TIER[$m['id']];

                return [
                    'model_id' => $m['id'],
                    'display_name' => $m['id'],
                    'supports_text' => true,
                    'supports_vision' => $info['vision'],
                    'context_length' => $info['context'],
                    'daily_token_limit' => null,
                    'rpm_limit' => null,
                    'metadata' => [],
                ];
            })
            ->values();
    }

    public function fetchTogether(): Collection
    {
        $key = Setting::getEncrypted('together_api_key');
        if (! $key) {
            return collect();
        }

        $resp = Http::withToken($key)
            ->timeout(20)
            ->get('https://api.together.xyz/v1/models');

        if (! $resp->successful()) {
            return collect();
        }

        $list = $resp->json();
        $models = is_array($list) && array_is_list($list) ? $list : ($list['data'] ?? []);

        return collect($models)
            ->filter(function (array $m) {
                $id = $m['id'] ?? '';
                foreach (self::TOGETHER_FREE_HINTS as $hint) {
                    if (str_contains(strtolower($id), $hint)) {
                        return true;
                    }
                }

                return false;
            })
            ->map(function (array $m) {
                $id = $m['id'];
                $isVision = str_contains(strtolower($id), 'vision') || str_contains(strtolower($id), 'llama-4');

                return [
                    'model_id' => $id,
                    'display_name' => $m['display_name'] ?? $id,
                    'supports_text' => true,
                    'supports_vision' => $isVision,
                    'context_length' => $m['context_length'] ?? null,
                    'daily_token_limit' => null,
                    'rpm_limit' => null,
                    'metadata' => [
                        'organization' => $m['organization'] ?? null,
                        'type' => $m['type'] ?? null,
                    ],
                ];
            })
            ->values();
    }

    public function upsertModels(string $provider, Collection $models): int
    {
        $now = Carbon::now();
        $count = 0;

        foreach ($models as $data) {
            FreeLlmModel::updateOrCreate(
                ['provider' => $provider, 'model_id' => $data['model_id']],
                array_merge($data, [
                    'is_available' => true,
                    'last_seen_at' => $now,
                ])
            );
            $count++;
        }

        return $count;
    }

    private function markStaleAsUnavailable(): void
    {
        FreeLlmModel::where('last_seen_at', '<', Carbon::now()->subDays(self::STALE_AFTER_DAYS))
            ->update(['is_available' => false]);
    }
}
