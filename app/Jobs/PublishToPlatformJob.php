<?php

namespace App\Jobs;

use App\Models\MediaFile;
use App\Models\Post;
use App\Models\PostLog;
use App\Models\PostPlatform;
use App\Services\Adapters\BlueskyAdapter;
use App\Services\Adapters\FacebookAdapter;
use App\Services\Adapters\InstagramAdapter;
use App\Services\Adapters\LinkedInAdapter;
use App\Services\Adapters\PlatformAdapterInterface;
use App\Services\Adapters\RedditAdapter;
use App\Services\Adapters\TelegramAdapter;
use App\Services\Adapters\ThreadsAdapter;
use App\Services\Adapters\TwitterAdapter;
use App\Services\Adapters\YouTubeAdapter;
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
use Illuminate\Support\Facades\URL;

class PublishToPlatformJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
        $adapter = $this->getAdapter($platform->slug);
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
        $media = $this->resolveMediaUrls($post->media);

        // Publish
        $result = $adapter->publish($account, $content, $media);

        if ($result['success']) {
            $externalId = $result['external_id'] ?? null;
            $platformUrl = $externalId ? PostUrlBuilder::build($account, $externalId) : null;

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

    private function getAdapter(string $slug): ?PlatformAdapterInterface
    {
        return match ($slug) {
            'telegram' => new TelegramAdapter,
            'facebook' => new FacebookAdapter,
            'instagram' => new InstagramAdapter,
            'threads' => new ThreadsAdapter,
            'twitter' => new TwitterAdapter,
            'youtube' => new YouTubeAdapter,
            'bluesky' => new BlueskyAdapter,
            'reddit' => new RedditAdapter,
            'linkedin' => new LinkedInAdapter,
            default => null,
        };
    }

    /**
     * Convert local /media/... paths to absolute signed URLs for external API access,
     * and guarantee every item has a mimetype (enrich from DB or guess from extension).
     * Older posts can have media items without mimetype — adapters then crash on
     * $item['mimetype']. Normalising here keeps the adapter code simple.
     */
    private function resolveMediaUrls(?array $media): ?array
    {
        if (empty($media)) {
            return $media;
        }

        return array_map(function ($item) {
            $url = $item['url'] ?? '';

            if (str_starts_with($url, '/media/')) {
                $filename = basename($url);
                $item['local_path'] = storage_path("app/private/media/{$filename}");

                if (empty($item['mimetype']) || empty($item['size'])) {
                    $mediaFile = MediaFile::where('filename', $filename)->first();
                    if ($mediaFile) {
                        $item['mimetype'] = $item['mimetype'] ?? $mediaFile->mime_type;
                        $item['size'] = $item['size'] ?? $mediaFile->size;
                        $item['title'] = $item['title'] ?? $mediaFile->original_name;
                    }
                }

                if (empty($item['mimetype'])) {
                    $item['mimetype'] = $this->guessMimetypeFromFilename($filename);
                }

                $item['url'] = URL::temporarySignedRoute(
                    'media.show',
                    now()->addHours(4),
                    ['filename' => $filename]
                );
            }

            if (empty($item['mimetype'])) {
                $item['mimetype'] = ($item['type'] ?? 'image') === 'video' ? 'video/mp4' : 'image/jpeg';
            }

            return $item;
        }, $media);
    }

    private function guessMimetypeFromFilename(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'mp4', 'm4v' => 'video/mp4',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            default => 'application/octet-stream',
        };
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
