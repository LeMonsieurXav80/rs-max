<?php

namespace App\Jobs;

use App\Models\Post;
use App\Models\PostLog;
use App\Models\PostPlatform;
use App\Services\Adapters\FacebookAdapter;
use App\Services\Adapters\InstagramAdapter;
use App\Services\Adapters\PlatformAdapterInterface;
use App\Services\Adapters\TelegramAdapter;
use App\Services\Adapters\TwitterAdapter;
use App\Services\PublishingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class PublishToPlatformJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $backoff = 30;

    public function __construct(
        public PostPlatform $postPlatform,
    ) {}

    public function handle(PublishingService $publishingService): void
    {
        $postPlatform = $this->postPlatform->load('socialAccount.platform', 'post.user');
        $account = $postPlatform->socialAccount;
        $post = $postPlatform->post;
        $platform = $account->platform;

        if (!$account || !$post || !$platform) {
            $this->markFailed('Missing account, post, or platform data');
            return;
        }

        // Get the right adapter
        $adapter = $this->getAdapter($platform->slug);
        if (!$adapter) {
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
        $media = $this->resolveMediaUrls($post->media);

        // Publish
        $result = $adapter->publish($account, $content, $media);

        if ($result['success']) {
            $postPlatform->update([
                'status' => 'published',
                'external_id' => $result['external_id'] ?? null,
                'published_at' => now(),
            ]);

            PostLog::create([
                'post_platform_id' => $postPlatform->id,
                'action' => 'published',
                'details' => ['external_id' => $result['external_id'] ?? null],
            ]);

            // Check if all platforms are done -> update post status
            $this->updatePostStatus($post);
        } else {
            $this->markFailed($result['error'] ?? 'Unknown error');
        }
    }

    private function getAdapter(string $slug): ?PlatformAdapterInterface
    {
        return match ($slug) {
            'telegram' => new TelegramAdapter(),
            'facebook' => new FacebookAdapter(),
            'instagram' => new InstagramAdapter(),
            'twitter' => new TwitterAdapter(),
            default => null,
        };
    }

    /**
     * Convert local /media/... paths to absolute signed URLs for external API access.
     */
    private function resolveMediaUrls(?array $media): ?array
    {
        if (empty($media)) {
            return $media;
        }

        return array_map(function ($item) {
            $url = $item['url'] ?? '';

            // Private media: generate a temporary signed URL (valid 4 hours)
            if (str_starts_with($url, '/media/')) {
                $filename = basename($url);
                $item['url'] = URL::temporarySignedRoute(
                    'media.show',
                    now()->addHours(4),
                    ['filename' => $filename]
                );
            }

            return $item;
        }, $media);
    }

    private function markFailed(string $error): void
    {
        $this->postPlatform->update([
            'status' => 'failed',
            'error_message' => $error,
        ]);

        PostLog::create([
            'post_platform_id' => $this->postPlatform->id,
            'action' => 'failed',
            'details' => ['error' => $error],
        ]);

        // Update parent post status
        if ($this->postPlatform->post) {
            $this->updatePostStatus($this->postPlatform->post);
        }

        Log::error("PublishToPlatformJob failed: {$error}", [
            'post_platform_id' => $this->postPlatform->id,
        ]);
    }

    private function updatePostStatus(Post $post): void
    {
        $post->refresh();
        $statuses = $post->postPlatforms->pluck('status');

        if ($statuses->every(fn($s) => $s === 'published')) {
            $post->update(['status' => 'published', 'published_at' => now()]);
        } elseif ($statuses->every(fn($s) => in_array($s, ['published', 'failed']))) {
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
        $this->markFailed('Job exception: ' . $exception->getMessage());
    }
}
