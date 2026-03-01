<?php

namespace App\Services;

use App\Models\MediaFile;
use App\Models\SocialAccount;
use App\Models\Thread;
use App\Models\ThreadSegment;
use App\Models\ThreadSegmentPlatform;
use App\Services\Adapters\FacebookAdapter;
use App\Services\Adapters\InstagramAdapter;
use App\Services\Adapters\PlatformAdapterInterface;
use App\Services\Adapters\TelegramAdapter;
use App\Services\Adapters\ThreadableAdapterInterface;
use App\Services\Adapters\ThreadsAdapter;
use App\Services\Adapters\TwitterAdapter;
use App\Services\Adapters\YouTubeAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class ThreadPublishingService
{
    /**
     * Publish a thread as a multi-post sequence (Twitter, Threads).
     * Each segment is published as a reply to the previous one.
     */
    public function publishThreadMode(Thread $thread, SocialAccount $account): array
    {
        $segments = $thread->segments()->orderBy('position')->get();
        $adapter = $this->getAdapter($account->platform->slug);

        if (! $adapter || ! $adapter instanceof ThreadableAdapterInterface) {
            return [['success' => false, 'error' => "Adapter does not support threading: {$account->platform->slug}"]];
        }

        $previousExternalId = null;
        $firstExternalId = null;
        $firstPostPermalink = null;
        $results = [];
        $lastPosition = $segments->max('position');

        // Update pivot status.
        $thread->socialAccounts()->updateExistingPivot($account->id, ['status' => 'publishing']);

        foreach ($segments as $segment) {
            $segmentPlatform = $segment->segmentPlatforms()
                ->where('social_account_id', $account->id)
                ->first();

            if (! $segmentPlatform) {
                continue;
            }

            // Resume support: skip already published segments.
            if ($segmentPlatform->status === 'published' && $segmentPlatform->external_id) {
                $previousExternalId = $segmentPlatform->external_id;
                if ($firstExternalId === null) {
                    $firstExternalId = $segmentPlatform->external_id;
                }
                $results[] = ['success' => true, 'external_id' => $previousExternalId, 'skipped' => true];
                continue;
            }

            $content = $segment->getContentForPlatform($account->platform->slug);
            $media = $this->resolveMediaUrls($segment->media);

            // Inject the first post's URL into the last segment.
            if ($segment->position === $lastPosition && $firstExternalId) {
                $firstPostUrl = $firstPostPermalink
                    ?? $this->buildPostUrl($account->platform->slug, $account->platform_account_id, $firstExternalId);
                if ($firstPostUrl) {
                    $content .= "\n\n" . $firstPostUrl;
                }
            }

            $segmentPlatform->update(['status' => 'publishing']);

            try {
                if ($previousExternalId === null) {
                    // First segment: standard publish.
                    $result = $adapter->publish($account, $content, $media);
                } else {
                    // Subsequent segments: reply to previous.
                    $result = $adapter->publishReply($account, $content, $previousExternalId, $media);
                }

                if ($result['success']) {
                    $segmentPlatform->update([
                        'status' => 'published',
                        'external_id' => $result['external_id'],
                        'published_at' => now(),
                    ]);
                    $previousExternalId = $result['external_id'];
                    if ($firstExternalId === null) {
                        $firstExternalId = $result['external_id'];
                        $firstPostPermalink = $result['permalink'] ?? null;
                    }
                    $results[] = $result;
                } else {
                    $segmentPlatform->update([
                        'status' => 'failed',
                        'error_message' => $result['error'],
                    ]);
                    $results[] = $result;

                    // Cannot continue: mark remaining segments as failed.
                    $this->markRemainingSegments($segments, $account, $segment->position, 'failed', 'Previous segment failed');
                    break;
                }
            } catch (\Throwable $e) {
                Log::error('ThreadPublishingService: segment publish error', [
                    'thread_id' => $thread->id,
                    'segment_position' => $segment->position,
                    'error' => $e->getMessage(),
                ]);

                $segmentPlatform->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
                $results[] = ['success' => false, 'error' => $e->getMessage()];

                $this->markRemainingSegments($segments, $account, $segment->position, 'failed', 'Exception in previous segment');
                break;
            }

            // Rate limiting between segments.
            if ($account->platform->slug === 'threads') {
                sleep(35);
            } else {
                sleep(2);
            }
        }

        $this->updatePivotStatus($thread, $account);

        return $results;
    }

    /**
     * Publish a thread as a single compiled post (Facebook, Telegram).
     * All segments are joined into one long text.
     */
    public function publishCompiledMode(Thread $thread, SocialAccount $account): array
    {
        $segments = $thread->segments()->orderBy('position')->get();
        $adapter = $this->getAdapter($account->platform->slug);

        if (! $adapter) {
            return [['success' => false, 'error' => "No adapter for: {$account->platform->slug}"]];
        }

        // Update pivot status.
        $thread->socialAccounts()->updateExistingPivot($account->id, ['status' => 'publishing']);

        // Compile all segments into one text.
        $compiledParts = [];
        foreach ($segments as $segment) {
            $compiledParts[] = $segment->getContentForPlatform($account->platform->slug);
        }

        $compiledContent = implode("\n\n", $compiledParts);

        // Collect all media from all segments.
        $allMedia = [];
        foreach ($segments as $segment) {
            if (! empty($segment->media)) {
                $allMedia = array_merge($allMedia, $segment->media);
            }
        }

        $media = $this->resolveMediaUrls(! empty($allMedia) ? $allMedia : null);
        $result = $adapter->publish($account, $compiledContent, $media);

        // Mark first segment with external_id, rest as skipped.
        $firstSegment = $segments->first();
        if ($firstSegment) {
            $firstSegment->segmentPlatforms()
                ->where('social_account_id', $account->id)
                ->update([
                    'status' => $result['success'] ? 'published' : 'failed',
                    'external_id' => $result['external_id'] ?? null,
                    'published_at' => $result['success'] ? now() : null,
                    'error_message' => $result['error'] ?? null,
                ]);

            foreach ($segments->skip(1) as $segment) {
                $segment->segmentPlatforms()
                    ->where('social_account_id', $account->id)
                    ->update(['status' => $result['success'] ? 'skipped' : 'failed']);
            }
        }

        $this->updatePivotStatus($thread, $account);

        return [$result];
    }

    /**
     * Publish a thread to a single account, auto-detecting the mode.
     */
    public function publishToAccount(Thread $thread, SocialAccount $account): array
    {
        $pivot = $thread->socialAccounts()->where('social_account_id', $account->id)->first();

        if (! $pivot) {
            return [['success' => false, 'error' => 'Account not linked to thread']];
        }

        $publishMode = $pivot->pivot->publish_mode;

        if ($publishMode === 'thread') {
            return $this->publishThreadMode($thread, $account);
        }

        return $this->publishCompiledMode($thread, $account);
    }

    /**
     * Publish a thread to all linked accounts.
     */
    public function publishAll(Thread $thread): array
    {
        $thread->update(['status' => 'publishing']);
        $allResults = [];

        foreach ($thread->socialAccounts as $account) {
            $results = $this->publishToAccount($thread, $account);
            $allResults[$account->name] = $results;
        }

        $this->updateThreadStatus($thread);

        return $allResults;
    }

    /**
     * Reset all segment platforms for a specific account back to pending.
     */
    public function resetAccount(Thread $thread, SocialAccount $account): void
    {
        foreach ($thread->segments as $segment) {
            $segment->segmentPlatforms()
                ->where('social_account_id', $account->id)
                ->update([
                    'status' => 'pending',
                    'external_id' => null,
                    'error_message' => null,
                    'published_at' => null,
                ]);
        }

        $thread->socialAccounts()->updateExistingPivot($account->id, ['status' => 'pending']);
    }

    // -------------------------------------------------------------------------
    //  Helpers
    // -------------------------------------------------------------------------

    private function markRemainingSegments($segments, SocialAccount $account, int $failedPosition, string $status, string $error): void
    {
        foreach ($segments as $segment) {
            if ($segment->position <= $failedPosition) {
                continue;
            }

            $segment->segmentPlatforms()
                ->where('social_account_id', $account->id)
                ->where('status', 'pending')
                ->update([
                    'status' => $status,
                    'error_message' => $error,
                ]);
        }
    }

    private function updatePivotStatus(Thread $thread, SocialAccount $account): void
    {
        $statuses = ThreadSegmentPlatform::query()
            ->whereHas('threadSegment', fn ($q) => $q->where('thread_id', $thread->id))
            ->where('social_account_id', $account->id)
            ->pluck('status');

        $allPublishedOrSkipped = $statuses->every(fn ($s) => in_array($s, ['published', 'skipped']));
        $anyPublished = $statuses->contains('published');
        $anyFailed = $statuses->contains('failed');

        if ($allPublishedOrSkipped) {
            $pivotStatus = 'published';
        } elseif ($anyFailed && $anyPublished) {
            $pivotStatus = 'partial';
        } elseif ($anyFailed) {
            $pivotStatus = 'failed';
        } else {
            $pivotStatus = 'pending';
        }

        $thread->socialAccounts()->updateExistingPivot($account->id, ['status' => $pivotStatus]);
    }

    private function updateThreadStatus(Thread $thread): void
    {
        $thread->refresh();
        $pivotStatuses = $thread->socialAccounts->pluck('pivot.status');

        if ($pivotStatuses->every(fn ($s) => $s === 'published')) {
            $thread->update(['status' => 'published', 'published_at' => now()]);
        } elseif ($pivotStatuses->contains('published') || $pivotStatuses->contains('partial')) {
            $thread->update(['status' => 'partial', 'published_at' => now()]);
        } elseif ($pivotStatuses->every(fn ($s) => $s === 'failed')) {
            $thread->update(['status' => 'failed']);
        }
    }

    private function buildPostUrl(string $platformSlug, string $handle, string $externalId): ?string
    {
        return match ($platformSlug) {
            'twitter' => "https://x.com/{$handle}/status/{$externalId}",
            'threads' => "https://www.threads.net/@{$handle}/post/{$externalId}",
            default => null,
        };
    }

    private function getAdapter(string $slug): ?PlatformAdapterInterface
    {
        return match ($slug) {
            'telegram' => new TelegramAdapter(),
            'facebook' => new FacebookAdapter(),
            'instagram' => new InstagramAdapter(),
            'threads' => new ThreadsAdapter(),
            'twitter' => new TwitterAdapter(),
            'youtube' => new YouTubeAdapter(),
            default => null,
        };
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

                // Enrich with mimetype/size from database.
                $mediaFile = MediaFile::where('filename', $filename)->first();
                if ($mediaFile) {
                    $item['mimetype'] = $mediaFile->mime_type;
                    $item['size'] = $mediaFile->size;
                    $item['title'] = $mediaFile->original_name;
                } else {
                    // Fallback: guess from extension.
                    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    $item['mimetype'] = match ($ext) {
                        'jpg', 'jpeg' => 'image/jpeg',
                        'png' => 'image/png',
                        'gif' => 'image/gif',
                        'webp' => 'image/webp',
                        'mp4' => 'video/mp4',
                        default => 'application/octet-stream',
                    };
                }

                $item['url'] = URL::temporarySignedRoute(
                    'media.show',
                    now()->addHours(4),
                    ['filename' => $filename]
                );
            }

            // Ensure mimetype key always exists for external URLs too.
            if (! isset($item['mimetype'])) {
                $item['mimetype'] = ($item['type'] ?? 'image') === 'video' ? 'video/mp4' : 'image/jpeg';
            }

            return $item;
        }, $media);
    }
}
