<?php

namespace App\Jobs;

use App\Models\Post;
use App\Models\PostLog;
use App\Models\PostPlatform;
use App\Services\Adapters\AdapterFactory;
use App\Services\Adapters\ResharingAdapterInterface;
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

class ResharePlatformJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public int $postPlatformId,
        public string $mode,
        public ?string $sourceExternalId,
        public ?string $sourceUrl,
    ) {}

    public function handle(PublishingService $publishingService): void
    {
        $postPlatform = PostPlatform::with('socialAccount.platform', 'post.user')->find($this->postPlatformId);

        if (! $postPlatform || $postPlatform->status === 'published') {
            return;
        }

        $account = $postPlatform->socialAccount;
        $post = $postPlatform->post;
        $platform = $account?->platform;

        if (! $account || ! $post || ! $platform) {
            $this->markFailed($postPlatform, 'Données manquantes (compte, post ou plateforme).');

            return;
        }

        $adapter = AdapterFactory::make($platform->slug);
        if (! $adapter) {
            $this->markFailed($postPlatform, "Aucun adapter pour la plateforme : {$platform->slug}");

            return;
        }

        PostLog::create([
            'post_platform_id' => $postPlatform->id,
            'action' => 'submitted',
            'details' => ['platform' => $platform->slug, 'account' => $account->name, 'reshare_mode' => $this->mode],
        ]);

        $result = match ($this->mode) {
            'link' => $adapter->publish(
                $account,
                $publishingService->getContentForAccount($post, $account),
                $this->resolveMediaUrls($post->media),
            ),
            'native_repost' => $adapter instanceof ResharingAdapterInterface
                ? $adapter->nativeRepost($account, $this->sourceExternalId)
                : ['success' => false, 'external_id' => null, 'error' => "Adapter {$platform->slug} ne supporte pas le repost natif."],
            'native_quote' => $adapter instanceof ResharingAdapterInterface
                ? $adapter->nativeQuote(
                    $account,
                    $this->buildQuoteText($post, $account, $publishingService),
                    $this->sourceExternalId,
                    $this->resolveMediaUrls($post->media),
                )
                : ['success' => false, 'external_id' => null, 'error' => "Adapter {$platform->slug} ne supporte pas le quote natif."],
            default => ['success' => false, 'external_id' => null, 'error' => "Mode inconnu : {$this->mode}"],
        };

        if ($result['success'] ?? false) {
            $externalId = $result['external_id'] ?? null;
            $postPlatform->update([
                'status' => 'published',
                'external_id' => $externalId,
                'platform_url' => $externalId ? PostUrlBuilder::build($account, $externalId) : null,
                'published_at' => now(),
            ]);

            PostLog::create([
                'post_platform_id' => $postPlatform->id,
                'action' => 'published',
                'details' => [
                    'reshare_mode' => $this->mode,
                    'external_id' => $externalId,
                    'platform' => $platform->slug,
                    'account' => $account->name,
                ],
            ]);

            $this->updatePostStatus($post);
        } else {
            $this->markFailed($postPlatform, $result['error'] ?? 'Erreur inconnue');
        }
    }

    /**
     * Pour le quote natif : on utilise le pipeline standard mais sans append du link_url (le quote affiche déjà le post source).
     */
    private function buildQuoteText(Post $post, $account, PublishingService $publishingService): string
    {
        $original = $post->link_url;
        $post->link_url = null; // évite l'append automatique
        $content = $publishingService->getContentForAccount($post, $account);
        $post->link_url = $original;

        return $content;
    }

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
                $item['url'] = URL::temporarySignedRoute(
                    'media.show',
                    now()->addHours(4),
                    ['filename' => $filename]
                );
            }

            return $item;
        }, $media);
    }

    private function markFailed(PostPlatform $postPlatform, string $error): void
    {
        $postPlatform->update([
            'status' => 'failed',
            'error_message' => $error,
        ]);

        PostLog::create([
            'post_platform_id' => $postPlatform->id,
            'action' => 'failed',
            'details' => [
                'reshare_mode' => $this->mode,
                'error' => $error,
                'platform' => $postPlatform->socialAccount?->platform?->slug ?? 'unknown',
            ],
        ]);

        if ($postPlatform->post) {
            $this->updatePostStatus($postPlatform->post);
        }

        Log::error("ResharePlatformJob failed: {$error}", [
            'post_platform_id' => $postPlatform->id,
            'mode' => $this->mode,
        ]);

        TelegramNotificationService::notifyPublishError(
            $postPlatform->socialAccount?->platform?->slug ?? 'unknown',
            $postPlatform->socialAccount?->name ?? 'unknown',
            "[reshare:{$this->mode}] {$error}",
            $postPlatform->post_id,
        );
    }

    private function updatePostStatus(Post $post): void
    {
        $post->refresh();
        $statuses = $post->postPlatforms->pluck('status');

        if ($statuses->every(fn ($s) => $s === 'published')) {
            $post->update(['status' => 'published', 'published_at' => now()]);
        } elseif ($statuses->every(fn ($s) => in_array($s, ['published', 'failed']))) {
            $hasPublished = $statuses->contains('published');
            $post->update([
                'status' => $hasPublished ? 'published' : 'failed',
                'published_at' => $hasPublished ? now() : null,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        $postPlatform = PostPlatform::find($this->postPlatformId);
        if ($postPlatform) {
            $this->markFailed($postPlatform, 'Job exception: ' . $exception->getMessage());
        }
    }
}
