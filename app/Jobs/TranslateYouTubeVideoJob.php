<?php

namespace App\Jobs;

use App\Models\SocialAccount;
use App\Models\YtTranslation;
use App\Services\YouTube\YouTubeTranslationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TranslateYouTubeVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    public function __construct(
        public SocialAccount $account,
        public string $videoId,
        public string $language,
        public array $types, // ['title', 'description', 'subtitles']
        public string $sourceLanguage = 'en',
    ) {}

    public function handle(YouTubeTranslationService $service): void
    {
        $wantsTitle = in_array('title', $this->types);
        $wantsDescription = in_array('description', $this->types);
        $wantsSubtitles = in_array('subtitles', $this->types);

        // Mark all as translating
        foreach ($this->types as $type) {
            YtTranslation::updateOrCreate(
                [
                    'social_account_id' => $this->account->id,
                    'video_id' => $this->videoId,
                    'language' => $this->language,
                    'type' => $type,
                ],
                ['status' => 'translating', 'error_message' => null]
            );
        }

        // Fetch video details for title/description
        if ($wantsTitle || $wantsDescription) {
            $video = $service->getVideoDetails($this->account, $this->videoId);
            if (! $video) {
                $this->markFailed(['title', 'description'], 'Impossible de récupérer les détails de la vidéo');
                return;
            }

            if ($wantsTitle) {
                $this->translateAndStore($service, 'title', $video['title']);
            }

            if ($wantsDescription) {
                $this->translateAndStore($service, 'description', $video['description']);
            }

            // Upload localizations (title + description together)
            $this->uploadLocalizations($service, $video);
        }

        // Subtitles
        if ($wantsSubtitles) {
            $this->translateSubtitles($service);
        }
    }

    private function translateAndStore(YouTubeTranslationService $service, string $type, string $originalText): void
    {
        $record = YtTranslation::where([
            'social_account_id' => $this->account->id,
            'video_id' => $this->videoId,
            'language' => $this->language,
            'type' => $type,
        ])->first();

        if (! $record) {
            return;
        }

        $record->update(['original_text' => $originalText]);

        if (empty(trim($originalText))) {
            $record->update(['status' => 'translated', 'translated_text' => '']);
            return;
        }

        $translated = $service->translateText($originalText, $this->sourceLanguage, $this->language, $type);

        if ($translated) {
            $record->update(['status' => 'translated', 'translated_text' => $translated]);
        } else {
            $record->update(['status' => 'failed', 'error_message' => 'Échec de la traduction']);
        }
    }

    private function uploadLocalizations(YouTubeTranslationService $service, array $video): void
    {
        $title = YtTranslation::where([
            'social_account_id' => $this->account->id,
            'video_id' => $this->videoId,
            'language' => $this->language,
            'type' => 'title',
        ])->first();

        $description = YtTranslation::where([
            'social_account_id' => $this->account->id,
            'video_id' => $this->videoId,
            'language' => $this->language,
            'type' => 'description',
        ])->first();

        $translatedTitle = $title?->translated_text ?? $video['title'];
        $translatedDesc = $description?->translated_text ?? $video['description'];

        if ($title?->status !== 'translated' && $description?->status !== 'translated') {
            return; // Nothing to upload
        }

        $result = $service->uploadLocalization(
            $this->account,
            $this->videoId,
            $this->language,
            $translatedTitle,
            $translatedDesc
        );

        $now = now();
        if ($result['success']) {
            $title?->update(['status' => 'uploaded', 'uploaded_at' => $now]);
            $description?->update(['status' => 'uploaded', 'uploaded_at' => $now]);
        } else {
            $error = $result['error'] ?? 'Upload failed';
            $title?->update(['status' => 'failed', 'error_message' => $error]);
            $description?->update(['status' => 'failed', 'error_message' => $error]);
        }
    }

    private function translateSubtitles(YouTubeTranslationService $service): void
    {
        $record = YtTranslation::where([
            'social_account_id' => $this->account->id,
            'video_id' => $this->videoId,
            'language' => $this->language,
            'type' => 'subtitles',
        ])->first();

        if (! $record) {
            return;
        }

        // Fetch original subtitles
        $srt = $service->fetchSubtitles($this->videoId, $this->sourceLanguage);
        if (! $srt) {
            $record->update(['status' => 'failed', 'error_message' => 'Aucun sous-titre disponible']);
            return;
        }

        $record->update(['original_text' => $srt]);

        // Translate in chunks (SRT can be very long)
        $translated = $this->translateSrtInChunks($service, $srt);

        if (! $translated) {
            $record->update(['status' => 'failed', 'error_message' => 'Échec de la traduction des sous-titres']);
            return;
        }

        $record->update(['status' => 'translated', 'translated_text' => $translated]);

        // Upload caption
        $result = $service->uploadCaption(
            $this->account,
            $this->videoId,
            $this->language,
            $translated
        );

        if ($result['success']) {
            $record->update(['status' => 'uploaded', 'uploaded_at' => now()]);
        } else {
            $record->update(['status' => 'failed', 'error_message' => $result['error'] ?? 'Upload caption failed']);
        }
    }

    /**
     * Translate SRT in chunks to avoid token limits.
     */
    private function translateSrtInChunks(YouTubeTranslationService $service, string $srt): ?string
    {
        $blocks = preg_split('/\n\n+/', trim($srt));
        $chunks = [];
        $current = '';

        foreach ($blocks as $block) {
            if (strlen($current) + strlen($block) > 4000) {
                $chunks[] = $current;
                $current = $block;
            } else {
                $current .= ($current ? "\n\n" : '') . $block;
            }
        }
        if ($current) {
            $chunks[] = $current;
        }

        $translated = '';
        foreach ($chunks as $chunk) {
            $result = $service->translateText($chunk, $this->sourceLanguage, $this->language, 'subtitles');
            if (! $result) {
                return null;
            }
            $translated .= ($translated ? "\n\n" : '') . $result;
        }

        return $translated;
    }

    private function markFailed(array $types, string $error): void
    {
        foreach ($types as $type) {
            YtTranslation::where([
                'social_account_id' => $this->account->id,
                'video_id' => $this->videoId,
                'language' => $this->language,
                'type' => $type,
            ])->update(['status' => 'failed', 'error_message' => $error]);
        }

        Log::error('TranslateYouTubeVideoJob failed', [
            'video_id' => $this->videoId,
            'language' => $this->language,
            'error' => $error,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $this->markFailed($this->types, 'Job exception: ' . $exception->getMessage());
    }
}
