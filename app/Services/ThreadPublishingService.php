<?php

namespace App\Services;

use App\Models\MediaFile;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\Thread;
use App\Models\ThreadSegment;
use App\Models\ThreadSegmentPlatform;
use App\Services\Adapters\BlueskyAdapter;
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
    private const LANGUAGE_FLAGS = [
        'fr' => "\u{1F1EB}\u{1F1F7}",
        'en' => "\u{1F1EC}\u{1F1E7}",
        'pt' => "\u{1F1F5}\u{1F1F9}",
        'es' => "\u{1F1EA}\u{1F1F8}",
        'de' => "\u{1F1E9}\u{1F1EA}",
        'it' => "\u{1F1EE}\u{1F1F9}",
    ];

    public function __construct(
        private TranslationService $translationService,
    ) {}

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

            $content = $this->getSegmentContentForAccount($segment, $account);
            $media = $this->resolveMediaUrls($segment->media);

            // Threads API: video replies break the thread chain, so strip videos from replies.
            if ($account->platform->slug === 'threads' && $previousExternalId !== null && ! empty($media)) {
                $media = array_values(array_filter($media, fn ($m) => ! str_starts_with($m['mimetype'] ?? '', 'video/')));
                $media = ! empty($media) ? $media : null;
            }

            // Inject the first post's URL into the last segment.
            if ($segment->position === $lastPosition && $firstExternalId) {
                $firstPostUrl = $firstPostPermalink
                    ?? $this->buildPostUrl($account, $firstExternalId);
                if ($firstPostUrl) {
                    $content .= "\n\n".$firstPostUrl;
                }
            }

            $segmentPlatform->update(['status' => 'publishing']);

            // Segment de boost : on resolve l'URL du fil source SPECIFIQUE a la plateforme
            // cible et on l'append au contenu. Pour X / Bluesky on tente en plus un quote natif.
            $options = null;
            if ($segment->is_boost && $segment->boost_source_thread_id) {
                $boostSource = app(ThreadBoostService::class)
                    ->findSourceForPlatform($segment->boost_source_thread_id, $account->platform_id);
                $platformUrl = ! empty($boostSource['url']) ? $boostSource['url'] : $segment->boost_source_url;
                if ($platformUrl) {
                    $content = rtrim($content)."\n\n".$platformUrl;
                }
                if ($boostSource && $adapter instanceof \App\Services\Adapters\ResharingAdapterInterface) {
                    $options = ['quote_to_id' => $boostSource['external_id']];
                }
            }

            try {
                if ($previousExternalId === null) {
                    // First segment: standard publish.
                    $result = $adapter->publish($account, $content, $media);
                } else {
                    // Subsequent segments: reply to previous (+ quote si boost).
                    $result = $adapter->publishReply($account, $content, $previousExternalId, $media, $options);
                }

                if ($result['success']) {
                    $segmentPlatform->update([
                        'status' => 'published',
                        'external_id' => $result['external_id'],
                        'platform_url' => PostUrlBuilder::build($account, $result['external_id']),
                        'published_at' => now(),
                    ]);
                    $previousExternalId = $result['external_id'];
                    if ($firstExternalId === null) {
                        $firstExternalId = $result['external_id'];
                        $firstPostPermalink = $result['permalink'] ?? null;
                    }
                    app(MediaPublicationTracker::class)->track(
                        media: $segment->media,
                        threadSegmentId: $segment->id,
                        socialAccountId: $account->id,
                        externalUrl: $result['permalink'] ?? null,
                        context: $account->platform->slug.':thread',
                    );
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
            } elseif ($account->platform->slug === 'bluesky') {
                sleep(1);
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

        // Compile all segments, grouped by language.
        $compiledContent = $this->compileSegmentsForAccount($segments, $account, $thread);

        // Collect all media from all segments.
        $allMedia = [];
        foreach ($segments as $segment) {
            if (! empty($segment->media)) {
                $allMedia = array_merge($allMedia, $segment->media);
            }
        }

        // Instagram limite un carrousel a 10 medias.
        if ($account->platform->slug === 'instagram' && count($allMedia) > 10) {
            $allMedia = array_slice($allMedia, 0, 10);
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
                    'platform_url' => $result['success'] && ! empty($result['external_id'])
                        ? PostUrlBuilder::build($account, $result['external_id'])
                        : null,
                    'published_at' => $result['success'] ? now() : null,
                    'error_message' => $result['error'] ?? null,
                ]);

            foreach ($segments->skip(1) as $segment) {
                $segment->segmentPlatforms()
                    ->where('social_account_id', $account->id)
                    ->update(['status' => $result['success'] ? 'skipped' : 'failed']);
            }

            if ($result['success']) {
                app(MediaPublicationTracker::class)->track(
                    media: $allMedia ?: null,
                    threadSegmentId: $firstSegment->id,
                    socialAccountId: $account->id,
                    externalUrl: $result['permalink'] ?? null,
                    context: $account->platform->slug.':compiled',
                );
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
            $results = $this->publishThreadMode($thread, $account);
        } else {
            $results = $this->publishCompiledMode($thread, $account);
        }

        $this->updateThreadStatus($thread);

        return $results;
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

    /**
     * Attache un compte a un thread existant : determine le publish_mode selon
     * la plateforme, cree le pivot en pending, et cree un thread_segment_platform
     * par segment existant (pending aussi). Les publications deja faites sur
     * d'autres comptes ne sont pas touchees — le thread reste unifie pour les stats.
     */
    public function addAccount(Thread $thread, SocialAccount $account, string $publishMode): void
    {
        $thread->socialAccounts()->attach($account->id, [
            'platform_id' => $account->platform_id,
            'publish_mode' => $publishMode,
            'status' => 'pending',
        ]);

        foreach ($thread->segments as $segment) {
            ThreadSegmentPlatform::create([
                'thread_segment_id' => $segment->id,
                'social_account_id' => $account->id,
                'platform_id' => $account->platform_id,
                'status' => 'pending',
            ]);
        }
    }

    /**
     * Detache completement un compte du thread : supprime les thread_segment_platform
     * associes puis detache le pivot. Les posts deja publies sur la plateforme ne
     * sont PAS supprimes (a faire manuellement par l'utilisateur).
     */
    public function removeAccount(Thread $thread, SocialAccount $account): void
    {
        foreach ($thread->segments as $segment) {
            $segment->segmentPlatforms()
                ->where('social_account_id', $account->id)
                ->delete();
        }

        $thread->socialAccounts()->detach($account->id);

        // Recalcule le statut global. Si tous les pivots restants sont en attente
        // (ou s'il n'y a plus de compte), le thread retombe en draft.
        $thread->refresh();
        $pivotStatuses = $thread->socialAccounts->pluck('pivot.status');

        if ($pivotStatuses->isEmpty() || $pivotStatuses->every(fn ($s) => $s === 'pending')) {
            $thread->update(['status' => 'draft', 'published_at' => null]);
        } else {
            $this->updateThreadStatus($thread);
        }
    }

    // -------------------------------------------------------------------------
    //  Translation
    // -------------------------------------------------------------------------

    private function getSegmentContentForAccount(ThreadSegment $segment, SocialAccount $account): string
    {
        $languages = $account->languages ?? ['fr'];
        $platformSlug = $account->platform->slug;
        $baseContent = $segment->getContentForPlatform($platformSlug);
        $parts = [];

        foreach ($languages as $lang) {
            if ($lang === 'fr') {
                $text = $baseContent;
            } else {
                $text = $this->getSegmentTranslation($segment, $lang, $platformSlug);
            }

            if ($text) {
                $parts[] = ['lang' => $lang, 'text' => $text];
            }
        }

        $multiLang = count($parts) > 1;
        $sections = array_map(function ($part) use ($multiLang) {
            $flag = self::LANGUAGE_FLAGS[$part['lang']] ?? '';

            return $multiLang && $flag ? "{$flag} {$part['text']}" : $part['text'];
        }, $parts);

        return implode("\n\n", $sections);
    }

    /**
     * Compile all segments grouped by language for compiled mode (Facebook, Telegram, Instagram).
     * Result: 🇫🇷 Seg1 FR\n\nSeg2 FR\n\n🇬🇧 Seg1 EN\n\nSeg2 EN
     *
     * Pour Instagram : si le thread a un instagram_compiled_content (genere par IA
     * ou edite manuellement), on l'utilise comme source au lieu de concatener les
     * segments — la concatenation brute depasse souvent les 2200 caracteres.
     */
    private function compileSegmentsForAccount($segments, SocialAccount $account, ?Thread $thread = null): string
    {
        $languages = $account->languages ?? ['fr'];
        $platformSlug = $account->platform->slug;
        $multiLang = count($languages) > 1;

        $igCompiled = ($platformSlug === 'instagram' && $thread)
            ? ($thread->instagram_compiled_content ?? [])
            : [];

        $languageBlocks = [];

        foreach ($languages as $lang) {
            // Pour Instagram, on privilegie le texte compile (FR ou langue cible) si dispo.
            if ($platformSlug === 'instagram' && ! empty($igCompiled[$lang])) {
                $block = $igCompiled[$lang];

                if ($multiLang) {
                    $flag = self::LANGUAGE_FLAGS[$lang] ?? '';
                    if ($flag) {
                        $block = "{$flag} {$block}";
                    }
                }

                $languageBlocks[] = $block;

                continue;
            }

            $segmentTexts = [];

            foreach ($segments as $segment) {
                if ($lang === 'fr') {
                    $text = $segment->getContentForPlatform($platformSlug);
                } else {
                    $text = $this->getSegmentTranslation($segment, $lang, $platformSlug);
                }

                if ($text) {
                    // Pour un segment de boost, append l'URL specifique a la plateforme cible.
                    if ($segment->is_boost && $segment->boost_source_thread_id) {
                        $boostSource = app(ThreadBoostService::class)
                            ->findSourceForPlatform($segment->boost_source_thread_id, $account->platform_id);
                        $platformUrl = ! empty($boostSource['url']) ? $boostSource['url'] : $segment->boost_source_url;
                        if ($platformUrl) {
                            $text = rtrim($text)."\n\n".$platformUrl;
                        }
                    }
                    $segmentTexts[] = $text;
                }
            }

            if (! empty($segmentTexts)) {
                $block = implode("\n\n", $segmentTexts);

                if ($multiLang) {
                    $flag = self::LANGUAGE_FLAGS[$lang] ?? '';
                    if ($flag) {
                        $block = "{$flag} {$block}";
                    }
                }

                $languageBlocks[] = $block;
            }
        }

        return implode("\n\n", $languageBlocks);
    }

    private function getSegmentTranslation(ThreadSegment $segment, string $lang, string $platformSlug): ?string
    {
        $translations = $segment->translations ?? [];
        $cacheKey = "{$platformSlug}_{$lang}";

        if (! empty($translations[$cacheKey])) {
            return $translations[$cacheKey];
        }

        $sourceText = $segment->getContentForPlatform($platformSlug);
        if (empty($sourceText)) {
            return null;
        }

        $apiKey = Setting::getEncrypted('openai_api_key') ?: config('services.openai.api_key');
        if (! $apiKey) {
            return null;
        }

        $translated = $this->translationService->translate($sourceText, 'fr', $lang, $apiKey);

        if ($translated) {
            $translations[$cacheKey] = $translated;
            $segment->update(['translations' => $translations]);
        }

        return $translated;
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

    private function buildPostUrl(SocialAccount $account, string $externalId): ?string
    {
        return PostUrlBuilder::build($account, $externalId);
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
