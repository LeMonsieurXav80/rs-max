<?php

namespace App\Jobs;

use App\Concerns\ResolvesPublishableMedia;
use App\Models\Post;
use App\Models\PostLog;
use App\Models\PostPlatform;
use App\Services\Adapters\AdapterFactory;
use App\Services\MediaPublicationTracker;
use App\Services\PostUrlBuilder;
use App\Services\PublishingService;
use App\Services\TelegramNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PublishToPlatformJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, ResolvesPublishableMedia, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public PostPlatform $postPlatform,
    ) {}

    public function handle(PublishingService $publishingService): void
    {
        // Idempotency guard: skip if already published (e.g. manual retry after success)
        $this->postPlatform->refresh();
        if ($this->postPlatform->status === 'published') {
            return;
        }

        $postPlatform = $this->postPlatform->load('socialAccount.platform', 'post.user');
        $account = $postPlatform->socialAccount;
        $post = $postPlatform->post;
        $platform = $account->platform;

        if (! $account || ! $post || ! $platform) {
            $this->markFailed('Missing account, post, or platform data');

            return;
        }

        // Get the right adapter
        $adapter = AdapterFactory::forAccount($account);
        if (! $adapter) {
            $this->markFailed("No adapter for platform: {$platform->slug}");

            return;
        }

        // Build content for this account
        $content = $publishingService->getContentForAccount($post, $account);

        // Log the attempt
        PostLog::create([
            'post_platform_id' => $postPlatform->id,
            'action' => 'submitted',
            'details' => ['platform' => $platform->slug, 'account' => $account->name],
        ]);

        // Resolve local media URLs to absolute public URLs
        $media = $this->resolveMediaUrls($post->media, $platform->slug);

        // Publish
        // article_title n'est lu que par TwitterAdapter ; les autres adapters
        // ignorent les options qu'ils ne connaissent pas.
        $result = $adapter->publish($account, $content, $media, [
            'article_title' => $post->article_title,
        ]);

        if ($result['success']) {
            $externalId = $result['external_id'] ?? null;
            // Le permalink de la plateforme prime : PostUrlBuilder forge l'URL Threads
            // depuis l'id numerique, alors que les vraies URLs portent un shortcode.
            $platformUrl = $result['permalink']
                ?? ($externalId ? PostUrlBuilder::build($account, $externalId) : null);

            $postPlatform->update([
                'status' => 'published',
                'external_id' => $externalId,
                'platform_url' => $platformUrl,
                'published_at' => now(),
            ]);

            PostLog::create([
                'post_platform_id' => $postPlatform->id,
                'action' => 'published',
                'details' => [
                    'external_id' => $result['external_id'] ?? null,
                    'platform' => $platform->slug,
                    'account' => $account->name,
                ],
            ]);

            app(MediaPublicationTracker::class)->track(
                media: $post->media,
                postId: $post->id,
                postPlatformId: $postPlatform->id,
                socialAccountId: $account->id,
                externalUrl: $result['permalink'] ?? null,
                context: $platform->slug,
            );

            // Check if all platforms are done -> update post status
            $this->updatePostStatus($post);
        } else {
            $this->markFailed($result['error'] ?? 'Unknown error');
        }
    }

    private function markFailed(string $error): void
    {
        $this->postPlatform->load('socialAccount.platform');
        $account = $this->postPlatform->socialAccount;
        $platform = $account?->platform;

        $this->postPlatform->update([
            'status' => 'failed',
            'error_message' => $error,
        ]);

        PostLog::create([
            'post_platform_id' => $this->postPlatform->id,
            'action' => 'failed',
            'details' => [
                'error' => $error,
                'platform' => $platform?->slug ?? 'unknown',
                'account' => $account?->name ?? 'unknown',
                'account_id' => $account?->id,
                'post_id' => $this->postPlatform->post_id,
            ],
        ]);

        // Update parent post status
        if ($this->postPlatform->post) {
            $this->updatePostStatus($this->postPlatform->post);
        }

        Log::error("PublishToPlatformJob failed: {$error}", [
            'post_platform_id' => $this->postPlatform->id,
            'platform' => $platform?->slug,
            'account' => $account?->name,
        ]);

        // Send Telegram notification
        TelegramNotificationService::notifyPublishError(
            $platform?->slug ?? 'unknown',
            $account?->name ?? 'unknown',
            $error,
            $this->postPlatform->post_id,
        );
    }

    private function updatePostStatus(Post $post): void
    {
        $post->refresh();
        $statuses = $post->postPlatforms->pluck('status');

        if ($statuses->every(fn ($s) => $s === 'published')) {
            $post->update(['status' => 'published', 'published_at' => now()]);
        } elseif ($statuses->every(fn ($s) => in_array($s, ['published', 'failed']))) {
            // All done, but some failed
            $hasPublished = $statuses->contains('published');
            $post->update([
                'status' => $hasPublished ? 'published' : 'failed',
                'published_at' => $hasPublished ? now() : null,
            ]);
        }
        // Otherwise, still publishing (some pending/publishing)
    }

    public function failed(\Throwable $exception): void
    {
        $this->markFailed('Job exception: '.$exception->getMessage());
    }
}
