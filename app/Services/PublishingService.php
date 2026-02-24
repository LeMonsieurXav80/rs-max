<?php

namespace App\Services;

use App\Models\Post;
use App\Models\PostPlatform;
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
        // Auto-translate if needed
        if ($post->auto_translate && empty($post->content_en) && !empty($post->content_fr)) {
            $apiKey = $post->user?->openai_api_key ?: config('services.openai.api_key');
            $translated = $this->translationService->translate($post->content_fr, 'fr', 'en', $apiKey);
            if ($translated) {
                $post->update(['content_en' => $translated]);
            }
        }

        // Update post status
        $post->update(['status' => 'publishing']);

        // Dispatch a job for each platform
        $postPlatforms = $post->postPlatforms()->with('socialAccount.platform')->where('status', 'pending')->get();

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
     * Get the right content for a social account based on its language setting.
     */
    public function getContentForAccount(Post $post, SocialAccount $account): string
    {
        $language = $account->language ?? 'fr';

        $content = match ($language) {
            'en' => $post->content_en ?: $post->content_fr,
            'both' => $post->content_fr . ($post->content_en ? "\n\n---\n\n" . $post->content_en : ''),
            default => $post->content_fr,
        };

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
}
