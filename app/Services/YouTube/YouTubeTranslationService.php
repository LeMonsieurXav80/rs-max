<?php

namespace App\Services\YouTube;

use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\YtTranslation;
use App\Services\YouTubeTokenHelper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YouTubeTranslationService
{
    private const API_BASE = 'https://www.googleapis.com/youtube/v3';

    /**
     * List videos from a connected YouTube channel.
     */
    public function listVideos(SocialAccount $account, int $maxResults = 50): array
    {
        $accessToken = YouTubeTokenHelper::getFreshAccessToken($account);
        if (! $accessToken) {
            return ['success' => false, 'error' => 'Token YouTube invalide ou expiré.'];
        }

        $channelId = $account->platform_account_id;

        // Get uploads playlist
        $response = Http::timeout(15)->withToken($accessToken)->get(self::API_BASE . '/channels', [
            'part' => 'contentDetails',
            'id' => $channelId,
        ]);

        if (! $response->successful()) {
            return ['success' => false, 'error' => 'Impossible de récupérer la chaîne YouTube.'];
        }

        $uploadsPlaylistId = $response->json('items.0.contentDetails.relatedPlaylists.uploads');
        if (! $uploadsPlaylistId) {
            return ['success' => false, 'error' => 'Playlist uploads introuvable.'];
        }

        // Fetch video IDs from playlist
        $videoIds = [];
        $pageToken = null;
        $fetched = 0;

        do {
            $params = [
                'part' => 'contentDetails',
                'playlistId' => $uploadsPlaylistId,
                'maxResults' => min(50, $maxResults - $fetched),
            ];
            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }

            $response = Http::timeout(30)->withToken($accessToken)->get(self::API_BASE . '/playlistItems', $params);
            if (! $response->successful()) {
                break;
            }

            $data = $response->json();
            foreach ($data['items'] ?? [] as $item) {
                $videoIds[] = $item['contentDetails']['videoId'];
                $fetched++;
            }

            $pageToken = $data['nextPageToken'] ?? null;
        } while ($pageToken && $fetched < $maxResults);

        if (empty($videoIds)) {
            return ['success' => true, 'videos' => []];
        }

        // Fetch video details in batches
        $videos = [];
        foreach (array_chunk($videoIds, 50) as $batch) {
            $response = Http::timeout(30)->withToken($accessToken)->get(self::API_BASE . '/videos', [
                'part' => 'snippet,contentDetails,statistics,localizations',
                'id' => implode(',', $batch),
            ]);

            if (! $response->successful()) {
                continue;
            }

            foreach ($response->json('items', []) as $video) {
                $snippet = $video['snippet'] ?? [];
                $stats = $video['statistics'] ?? [];
                $localizations = $video['localizations'] ?? [];

                $videos[] = [
                    'id' => $video['id'],
                    'title' => $snippet['title'] ?? '',
                    'description' => $snippet['description'] ?? '',
                    'thumbnail' => $snippet['thumbnails']['medium']['url']
                        ?? $snippet['thumbnails']['default']['url'] ?? null,
                    'published_at' => $snippet['publishedAt'] ?? null,
                    'duration' => $video['contentDetails']['duration'] ?? 'PT0S',
                    'view_count' => $stats['viewCount'] ?? 0,
                    'default_language' => $snippet['defaultLanguage'] ?? $snippet['defaultAudioLanguage'] ?? null,
                    'existing_localizations' => array_keys($localizations),
                    'has_captions' => $video['contentDetails']['caption'] ?? 'false',
                ];
            }
        }

        return ['success' => true, 'videos' => $videos];
    }

    /**
     * Get video details including existing localizations.
     */
    public function getVideoDetails(SocialAccount $account, string $videoId): ?array
    {
        $accessToken = YouTubeTokenHelper::getFreshAccessToken($account);
        if (! $accessToken) {
            return null;
        }

        $response = Http::timeout(15)->withToken($accessToken)->get(self::API_BASE . '/videos', [
            'part' => 'snippet,localizations',
            'id' => $videoId,
        ]);

        if (! $response->successful() || empty($response->json('items'))) {
            return null;
        }

        $video = $response->json('items.0');

        return [
            'id' => $video['id'],
            'title' => $video['snippet']['title'] ?? '',
            'description' => $video['snippet']['description'] ?? '',
            'default_language' => $video['snippet']['defaultLanguage']
                ?? $video['snippet']['defaultAudioLanguage'] ?? null,
            'localizations' => $video['localizations'] ?? [],
        ];
    }

    /**
     * List available caption tracks for a video.
     * Uses YouTube Data API (costs 50 units per call).
     */
    public function listCaptions(SocialAccount $account, string $videoId): array
    {
        $accessToken = YouTubeTokenHelper::getFreshAccessToken($account);
        if (! $accessToken) {
            return [];
        }

        $response = Http::timeout(15)->withToken($accessToken)->get(self::API_BASE . '/captions', [
            'part' => 'snippet',
            'videoId' => $videoId,
        ]);

        if (! $response->successful()) {
            return [];
        }

        $captions = [];
        foreach ($response->json('items', []) as $item) {
            $snippet = $item['snippet'] ?? [];
            $captions[] = [
                'id' => $item['id'],
                'language' => $snippet['language'] ?? '',
                'name' => $snippet['name'] ?? '',
                'is_auto' => ($snippet['trackKind'] ?? '') === 'ASR',
                'is_draft' => $snippet['isDraft'] ?? false,
            ];
        }

        return $captions;
    }

    /**
     * Fetch subtitle content via Innertube API (free, no quota cost).
     */
    public function fetchSubtitles(string $videoId, string $language = 'en'): ?string
    {
        // Step 1: Get player response with caption tracks via Innertube
        $response = Http::timeout(15)->post('https://www.youtube.com/youtubei/v1/player', [
            'context' => [
                'client' => [
                    'clientName' => 'WEB',
                    'clientVersion' => '2.20240101.00.00',
                    'hl' => $language,
                ],
            ],
            'videoId' => $videoId,
        ]);

        if (! $response->successful()) {
            return null;
        }

        $captionTracks = $response->json('captions.playerCaptionsTracklistRenderer.captionTracks', []);
        if (empty($captionTracks)) {
            return null;
        }

        // Find the matching language track
        $trackUrl = null;
        foreach ($captionTracks as $track) {
            $langCode = $track['languageCode'] ?? '';
            if ($langCode === $language || str_starts_with($langCode, $language)) {
                $trackUrl = $track['baseUrl'] ?? null;
                break;
            }
        }

        // Fallback: take the first track if exact match not found
        if (! $trackUrl && ! empty($captionTracks)) {
            $trackUrl = $captionTracks[0]['baseUrl'] ?? null;
        }

        if (! $trackUrl) {
            return null;
        }

        // Step 2: Fetch the actual subtitles (SRT format)
        $subtitleResponse = Http::timeout(15)->get($trackUrl . '&fmt=srv3');
        if (! $subtitleResponse->successful()) {
            return null;
        }

        return $this->parseSrv3ToSrt($subtitleResponse->body());
    }

    /**
     * Translate text using OpenAI.
     */
    public function translateText(string $text, string $from, string $to, string $type = 'title'): ?string
    {
        $apiKey = Setting::getEncrypted('openai_api_key') ?: config('services.openai.api_key');
        if (! $apiKey) {
            return null;
        }

        $systemPrompt = match ($type) {
            'title' => "You are a professional translator. Translate the YouTube video title from {$from} to {$to}. Keep it concise and engaging. Only output the translation.",
            'description' => "You are a professional translator. Translate the YouTube video description from {$from} to {$to}. Preserve all links, timestamps, and formatting. Only output the translation.",
            'subtitles' => "You are a professional subtitle translator. Translate the subtitles from {$from} to {$to}. Preserve all SRT timing codes exactly. Only translate the text lines. Keep it natural and readable.",
            default => "You are a professional translator. Translate from {$from} to {$to}. Only output the translation.",
        };

        $maxTokens = match ($type) {
            'subtitles' => 8000,
            'description' => 4000,
            default => 500,
        };

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
            ])->timeout(120)->post('https://api.openai.com/v1/chat/completions', [
                'model' => Setting::get('ai_model_translation', 'gpt-4o-mini'),
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $text],
                ],
                'temperature' => 0.3,
                'max_tokens' => $maxTokens,
            ]);

            if ($response->successful()) {
                return trim($response->json('choices.0.message.content', ''));
            }

            Log::error('YouTubeTranslationService: Translation API error', [
                'status' => $response->status(),
                'type' => $type,
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('YouTubeTranslationService: Translation failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Upload title/description localization to YouTube via the localizations API.
     * This is very cheap (50 units for videos.update).
     */
    public function uploadLocalization(
        SocialAccount $account,
        string $videoId,
        string $language,
        string $title,
        string $description
    ): array {
        $accessToken = YouTubeTokenHelper::getFreshAccessToken($account);
        if (! $accessToken) {
            return ['success' => false, 'error' => 'Token expiré'];
        }

        // First get existing video data to preserve localizations
        $video = $this->getVideoDetails($account, $videoId);
        if (! $video) {
            return ['success' => false, 'error' => 'Vidéo introuvable'];
        }

        $localizations = $video['localizations'];
        $localizations[$language] = [
            'title' => $title,
            'description' => $description,
        ];

        // Ensure defaultLanguage is set (required for localizations)
        $defaultLang = $video['default_language'] ?? 'en';

        $response = Http::withToken($accessToken)
            ->timeout(15)
            ->put(self::API_BASE . '/videos?part=localizations,snippet', [
                'id' => $videoId,
                'snippet' => [
                    'title' => $video['title'],
                    'description' => $video['description'],
                    'categoryId' => '22', // People & Blogs (required field)
                    'defaultLanguage' => $defaultLang,
                ],
                'localizations' => $localizations,
            ]);

        if ($response->successful()) {
            return ['success' => true];
        }

        return [
            'success' => false,
            'error' => $response->json('error.message', 'Erreur YouTube API'),
        ];
    }

    /**
     * Upload translated captions to YouTube.
     * EXPENSIVE: 400 units per caption insert.
     */
    public function uploadCaption(
        SocialAccount $account,
        string $videoId,
        string $language,
        string $srtContent,
        ?string $name = null
    ): array {
        $accessToken = YouTubeTokenHelper::getFreshAccessToken($account);
        if (! $accessToken) {
            return ['success' => false, 'error' => 'Token expiré'];
        }

        $captionName = $name ?? self::LANGUAGE_NAMES[$language] ?? $language;

        // Multipart upload: metadata + caption file
        $metadata = json_encode([
            'snippet' => [
                'videoId' => $videoId,
                'language' => $language,
                'name' => $captionName,
                'isDraft' => false,
            ],
        ]);

        $boundary = 'yt_caption_' . uniqid();
        $body = "--{$boundary}\r\n"
            . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
            . $metadata . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n"
            . $srtContent . "\r\n"
            . "--{$boundary}--";

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$accessToken}",
            'Content-Type' => "multipart/related; boundary={$boundary}",
        ])->timeout(30)->withBody($body, "multipart/related; boundary={$boundary}")
            ->post(self::API_BASE . '/captions?part=snippet');

        if ($response->successful()) {
            return [
                'success' => true,
                'caption_id' => $response->json('id'),
            ];
        }

        return [
            'success' => false,
            'error' => $response->json('error.message', 'Erreur upload caption'),
        ];
    }

    /**
     * Get existing translation status for videos.
     */
    public function getTranslationStatus(int $accountId, array $videoIds): array
    {
        return YtTranslation::where('social_account_id', $accountId)
            ->whereIn('video_id', $videoIds)
            ->get()
            ->groupBy('video_id')
            ->map(function ($translations) {
                return $translations->groupBy('type')->map(function ($items) {
                    return $items->mapWithKeys(function ($t) {
                        return [$t->language => $t->status];
                    });
                });
            })
            ->toArray();
    }

    /**
     * Convert YouTube srv3 XML subtitles to SRT format.
     */
    private function parseSrv3ToSrt(string $xml): string
    {
        $srt = '';
        $index = 1;

        try {
            $doc = new \SimpleXMLElement($xml);
            foreach ($doc->body->p as $p) {
                $start = (int) $p['t']; // milliseconds
                $dur = (int) $p['d'];
                $end = $start + $dur;
                $text = strip_tags((string) $p);

                if (empty(trim($text))) {
                    continue;
                }

                $srt .= $index . "\n";
                $srt .= $this->msToSrtTime($start) . ' --> ' . $this->msToSrtTime($end) . "\n";
                $srt .= $text . "\n\n";
                $index++;
            }
        } catch (\Exception $e) {
            Log::warning('parseSrv3ToSrt: Failed to parse XML', ['error' => $e->getMessage()]);
        }

        return $srt;
    }

    private function msToSrtTime(int $ms): string
    {
        $h = intdiv($ms, 3600000);
        $m = intdiv($ms % 3600000, 60000);
        $s = intdiv($ms % 60000, 1000);
        $millis = $ms % 1000;

        return sprintf('%02d:%02d:%02d,%03d', $h, $m, $s, $millis);
    }

    public const LANGUAGE_NAMES = [
        'fr' => 'Français',
        'en' => 'English',
        'es' => 'Español',
        'de' => 'Deutsch',
        'it' => 'Italiano',
        'pt' => 'Português',
        'pt-BR' => 'Português (Brasil)',
        'ja' => '日本語',
        'ko' => '한국어',
        'zh-CN' => '中文 (简体)',
        'zh-TW' => '中文 (繁體)',
        'ar' => 'العربية',
        'ru' => 'Русский',
        'pl' => 'Polski',
        'nl' => 'Nederlands',
        'sv' => 'Svenska',
        'tr' => 'Türkçe',
        'da' => 'Dansk',
        'no' => 'Norsk',
        'cs' => 'Čeština',
        'hr' => 'Hrvatski',
        'sl' => 'Slovenščina',
        'el' => 'Ελληνικά',
        'ka' => 'ქართული',
    ];

    public const AVAILABLE_LANGUAGES = [
        'fr', 'en', 'es', 'de', 'it', 'pt', 'pt-BR',
        'ja', 'ko', 'zh-CN', 'zh-TW', 'ar', 'ru',
        'pl', 'nl', 'sv', 'tr', 'da', 'no',
        'cs', 'hr', 'sl', 'el', 'ka',
    ];
}
