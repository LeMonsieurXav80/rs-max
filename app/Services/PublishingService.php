<?php

namespace App\Services;

use App\Models\Post;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Jobs\PublishToPlatformJob;
use Illuminate\Support\Facades\Log;

class PublishingService
{
    public function __construct(
        private TranslationService $translationService,
    ) {}

    /**
     * Dispatch publishing jobs for all platforms of a post.
     */
    public function publish(Post $post): void
    {
        // Update post status
        $post->update(['status' => 'publishing']);

        // Dispatch a job for each platform (only active accounts)
        $postPlatforms = $post->postPlatforms()
            ->with('socialAccount.platform')
            ->where('status', 'pending')
            ->whereHas('socialAccount', fn ($q) => $q->where('is_active', true))
            ->get();

        foreach ($postPlatforms as $postPlatform) {
            $postPlatform->update(['status' => 'publishing']);
            PublishToPlatformJob::dispatch($postPlatform);
        }

        if ($postPlatforms->isEmpty()) {
            Log::warning("PublishingService: Post #{$post->id} has no pending platforms");
            $post->update(['status' => 'failed']);
        }
    }

    /**
     * Get the right content for a social account based on its languages setting.
     * Translates on-the-fly and caches in post->translations.
     */
    private const LANGUAGE_FLAGS = [
        'fr' => '🇫🇷',
        'en' => '🇬🇧',
        'pt' => '🇵🇹',
        'es' => '🇪🇸',
        'de' => '🇩🇪',
        'it' => '🇮🇹',
    ];

    public function getContentForAccount(Post $post, SocialAccount $account): string
    {
        $languages = $account->languages ?? ['fr'];
        $parts = [];

        foreach ($languages as $lang) {
            if ($lang === 'fr') {
                $text = $post->content_fr;
            } else {
                $text = $this->getTranslation($post, $lang);
            }

            if ($text) {
                $parts[] = ['lang' => $lang, 'text' => $text];
            }
        }

        // Add flag prefixes only when multiple languages
        $multiLang = count($parts) > 1;
        $sections = array_map(function ($part) use ($multiLang) {
            $flag = self::LANGUAGE_FLAGS[$part['lang']] ?? '';
            return $multiLang && $flag ? "{$flag} {$part['text']}" : $part['text'];
        }, $parts);

        $content = implode("\n\n", $sections);

        // Append hashtags
        if ($post->hashtags) {
            $content .= "\n\n" . $post->hashtags;
        }

        // Append branding if enabled
        if ($account->show_branding && $account->branding) {
            $content .= "\n\n" . $account->branding;
        }

        return $content;
    }

    /**
     * Get a translation for a specific language, from cache or by translating.
     */
    private function getTranslation(Post $post, string $lang): ?string
    {
        $translations = $post->translations ?? [];

        // Check cached translation
        if (! empty($translations[$lang])) {
            return $translations[$lang];
        }

        // Backward compat: check content_en for English
        if ($lang === 'en' && ! empty($post->content_en)) {
            return $post->content_en;
        }

        // Auto-translate if enabled
        if (! $post->auto_translate || empty($post->content_fr)) {
            return null;
        }

        $apiKey = $this->getOpenaiApiKey();
        if (! $apiKey) {
            return null;
        }

        $translated = $this->translationService->translate($post->content_fr, 'fr', $lang, $apiKey);

        if ($translated) {
            // Cache in translations JSON
            $translations[$lang] = $translated;
            $post->update(['translations' => $translations]);
        }

        return $translated;
    }

    /**
     * Get the OpenAI API key from settings or env.
     */
    private function getOpenaiApiKey(): ?string
    {
        return Setting::getEncrypted('openai_api_key') ?: config('services.openai.api_key');
    }
}
