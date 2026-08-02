<?php

namespace App\Services;

use App\Jobs\PublishToPlatformJob;
use App\Models\Post;
use App\Models\Setting;
use App\Models\SocialAccount;
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

        // Dispatch a job for each pending platform
        $postPlatforms = $post->postPlatforms()
            ->with('socialAccount.platform')
            ->where('status', 'pending')
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
        $platformSlug = $account->platform->slug;
        $baseContent = $post->getContentForPlatform($platformSlug);
        $parts = [];

        foreach ($languages as $lang) {
            if ($lang === 'fr') {
                $text = $baseContent;
            } else {
                $text = $this->getTranslation($post, $lang, $platformSlug);
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

        // Fallback: never publish empty content when we have a base text
        if (trim($content) === '' && $baseContent) {
            $content = $baseContent;
        }

        // Append link_url if set and not already present in the content
        if ($post->link_url && ! str_contains($content, $post->link_url)) {
            $content .= "\n\n".$post->link_url;
        }

        // Append hashtags
        if ($post->hashtags) {
            $content .= "\n\n".$post->hashtags;
        }

        // Append branding if enabled
        if ($account->show_branding && $account->branding) {
            $content .= "\n\n".$account->branding;
        }

        return $content;
    }

    /**
     * Get a translation for a specific language, from cache or by translating.
     */
    private function getTranslation(Post $post, string $lang, ?string $platformSlug = null): ?string
    {
        $translations = $post->translations ?? [];
        $cacheKey = $platformSlug ? "{$platformSlug}_{$lang}" : $lang;

        // Check cached translation
        if (! empty($translations[$cacheKey])) {
            return $translations[$cacheKey];
        }

        // Repli sur la traduction generique (clé `en` plutot que `twitter_en`) : c'est la
        // forme ecrite par l'API et par l'UI. On ne l'utilise que si la plateforme n'a pas
        // de contenu dedie, sinon on publierait la traduction du mauvais texte source.
        $hasPlatformOverride = $platformSlug && ! empty(($post->platform_contents ?? [])[$platformSlug]);

        if (! $hasPlatformOverride && ! empty($translations[$lang])) {
            return $translations[$lang];
        }

        // Backward compat: content_en, meme quand une plateforme est ciblee (tant qu'elle
        // n'a pas de contenu dedie) — sinon ce champ n'etait jamais lu a la publication.
        if ($lang === 'en' && ! $hasPlatformOverride && ! empty($post->content_en)) {
            return $post->content_en;
        }

        // Translate automatically (language is set on the account, so always translate)
        $sourceText = $platformSlug ? $post->getContentForPlatform($platformSlug) : $post->content_fr;
        if (empty($sourceText)) {
            return null;
        }

        $apiKey = $this->getOpenaiApiKey();
        if (! $apiKey) {
            return null;
        }

        $translated = $this->translationService->translate($sourceText, 'fr', $lang, $apiKey);

        if ($translated) {
            $translations[$cacheKey] = $translated;
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
