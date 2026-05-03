<?php

namespace App\Http\Controllers;

use App\Models\FreeLlmModel;
use App\Models\Setting;
use App\Services\Llm\FreeLlmDiscoveryService;
use App\Services\TelegramNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

class SettingsController extends Controller
{
    private const SETTINGS_KEYS = [
        'image_max_dimension',
        'image_target_min_kb',
        'image_target_max_kb',
        'image_min_quality',
        'image_max_upload_mb',
        'video_max_upload_mb',
        'video_bitrate_1080p',
        'video_bitrate_720p',
        'video_codec',
        'video_audio_bitrate',
        // Stats sync
        'stats_sync_frequency',
        'stats_facebook_interval',
        'stats_instagram_interval',
        'stats_twitter_interval',
        'stats_youtube_interval',
        'stats_threads_interval',
        'stats_bluesky_interval',
        'stats_facebook_max_days',
        'stats_instagram_max_days',
        'stats_twitter_max_days',
        'stats_youtube_max_days',
        'stats_threads_max_days',
        'stats_bluesky_max_days',
        // Platform character limits
        'platform_char_limit_twitter',
        'platform_char_limit_facebook',
        'platform_char_limit_instagram',
        'platform_char_limit_threads',
        'platform_char_limit_youtube',
        'platform_char_limit_telegram',
        'platform_char_limit_bluesky',
        // AI models
        'ai_model_text',
        'ai_model_vision',
        'ai_model_translation',
        'ai_model_rss',
        // AI vision prompts (éditables — défauts dans DEFAULT_PROMPT_*)
        'ai_prompt_publication_from_photo',
        'ai_prompt_metadata_extraction',
        // Inbox / Messagerie
        'inbox_platform_facebook_enabled',
        'inbox_platform_instagram_enabled',
        'inbox_platform_threads_enabled',
        'inbox_platform_youtube_enabled',
        'inbox_platform_bluesky_enabled',
        'inbox_platform_telegram_enabled',
        'inbox_platform_reddit_enabled',
        'inbox_platform_twitter_enabled',
        'inbox_sync_freq_facebook',
        'inbox_sync_freq_instagram',
        'inbox_sync_freq_threads',
        'inbox_sync_freq_youtube',
        'inbox_sync_freq_bluesky',
        'inbox_sync_freq_telegram',
        'inbox_sync_freq_reddit',
        'inbox_sync_freq_twitter',
        'ai_model_inbox',
        'inbox_use_persona',
        'inbox_reply_prompt',
        // Studio
        'studio_video_crf',
        'studio_audio_bitrate',
        'studio_logo_size',
        'studio_logo_x',
        'studio_logo_y',
        'studio_text_font_size',
        'studio_text_x',
        'studio_text_y',
        // Notifications
        'notify_publish_error',
        'notify_telegram_chat_id',
        // IA Gratuite (cles API + defauts)
        'free_llms_default_text_model',
        'free_llms_default_vision_model',
        'free_llms_last_refresh_at',
    ];

    private const DEFAULTS = [
        'image_max_dimension' => 2048,
        'image_target_min_kb' => 200,
        'image_target_max_kb' => 500,
        'image_min_quality' => 60,
        'image_max_upload_mb' => 10,
        'video_max_upload_mb' => 50,
        'video_bitrate_1080p' => 6000,
        'video_bitrate_720p' => 2500,
        'video_codec' => 'h264',
        'video_audio_bitrate' => 128,
        // Stats sync
        'stats_sync_frequency' => 'hourly',
        'stats_facebook_interval' => 12,
        'stats_instagram_interval' => 12,
        'stats_twitter_interval' => 24,
        'stats_youtube_interval' => 24,
        'stats_threads_interval' => 12,
        'stats_bluesky_interval' => 24,
        'stats_facebook_max_days' => 30,
        'stats_instagram_max_days' => 30,
        'stats_twitter_max_days' => 14,
        'stats_youtube_max_days' => 30,
        'stats_threads_max_days' => 30,
        'stats_bluesky_max_days' => 30,
        // Platform character limits
        'platform_char_limit_twitter' => 280,
        'platform_char_limit_facebook' => 63206,
        'platform_char_limit_instagram' => 2200,
        'platform_char_limit_threads' => 500,
        'platform_char_limit_youtube' => 5000,
        'platform_char_limit_telegram' => 4096,
        'platform_char_limit_bluesky' => 300,
        // AI models
        'ai_model_text' => 'gpt-4o-mini',
        'ai_model_vision' => 'gpt-4o',
        'ai_model_translation' => 'gpt-4o-mini',
        'ai_model_rss' => 'gpt-4o-mini',
        'ai_prompt_publication_from_photo' => \App\Services\AiAssistService::DEFAULT_PROMPT_PUBLICATION_FROM_PHOTO,
        'ai_prompt_metadata_extraction' => \App\Services\AiAssistService::DEFAULT_PROMPT_METADATA_EXTRACTION,
        // Inbox / Messagerie
        'inbox_platform_facebook_enabled' => true,
        'inbox_platform_instagram_enabled' => true,
        'inbox_platform_threads_enabled' => true,
        'inbox_platform_youtube_enabled' => true,
        'inbox_platform_bluesky_enabled' => true,
        'inbox_platform_telegram_enabled' => true,
        'inbox_platform_reddit_enabled' => true,
        'inbox_platform_twitter_enabled' => false,
        'inbox_sync_freq_facebook' => 'every_15_min',
        'inbox_sync_freq_instagram' => 'every_15_min',
        'inbox_sync_freq_threads' => 'every_30_min',
        'inbox_sync_freq_youtube' => 'every_2_hours',
        'inbox_sync_freq_bluesky' => 'every_30_min',
        'inbox_sync_freq_telegram' => 'every_15_min',
        'inbox_sync_freq_reddit' => 'hourly',
        'inbox_sync_freq_twitter' => 'hourly',
        'ai_model_inbox' => 'gpt-4o-mini',
        'inbox_use_persona' => true,
        // Studio
        'studio_video_crf' => 28,
        'studio_audio_bitrate' => 96,
        'studio_logo_size' => 50,
        'studio_logo_x' => 20,
        'studio_logo_y' => 35,
        'studio_text_font_size' => 28,
        'studio_text_x' => 65,
        'studio_text_y' => 35,
        // Notifications
        'notify_publish_error' => false,
        'notify_telegram_chat_id' => '',
        // IA Gratuite
        'free_llms_default_text_model' => '',
        'free_llms_default_vision_model' => '',
        'free_llms_last_refresh_at' => '',
        'inbox_reply_prompt' => "Tu reponds a des commentaires et messages sur les reseaux sociaux. Adapte la longueur et le style de ta reponse au message recu :\n- Emoji seul ou reaction simple (coeur, flamme, applaudissements...) → reponds par 1-2 emojis adaptes, rien d'autre\n- Compliment court (\"bravo\", \"top\", \"j'adore\", \"genial\") → remercie en 2-5 mots max, tu peux ajouter un emoji\n- Question → reponds brievement et precisement, 1-2 phrases max\n- Commentaire developpe ou avis → 1-2 phrases engageantes max\n- Message prive → reponds de maniere naturelle et conversationnelle\n\nRegles absolues :\n- Ne fais JAMAIS une reponse plus longue que le message original\n- Pas de hashtags\n- Pas de formule de politesse generique (\"Merci pour votre commentaire !\")\n- Sois authentique, pas corporate\n- Garde le ton et la personnalite definis dans ton profil",
    ];

    public function index(Request $request): View
    {
        if (! $request->user()->isManager()) {
            abort(403);
        }

        $settings = [];
        foreach (self::SETTINGS_KEYS as $key) {
            $settings[$key] = Setting::get($key, self::DEFAULTS[$key]);
        }

        $hasOpenaiKey = (bool) Setting::getEncrypted('openai_api_key');

        // Fetch available OpenAI models if API key is configured
        $availableModels = [];
        if ($hasOpenaiKey) {
            $availableModels = rescue(function () {
                $apiKey = Setting::getEncrypted('openai_api_key');
                $response = Http::withHeaders([
                    'Authorization' => "Bearer {$apiKey}",
                ])->timeout(10)->get('https://api.openai.com/v1/models');

                if ($response->successful()) {
                    return collect($response->json('data'))
                        ->pluck('id')
                        ->filter(fn ($m) => str_starts_with($m, 'gpt-'))
                        ->reject(fn ($m) => str_contains($m, 'realtime')
                            || str_contains($m, 'audio')
                            || str_contains($m, 'transcribe')
                            || str_contains($m, 'tts')
                            || str_contains($m, 'search')
                            || str_contains($m, 'instruct'))
                        ->sort()
                        ->values()
                        ->toArray();
                }

                return [];
            }, [], false);
        }

        $hasNotifyBotToken = (bool) Setting::getEncrypted('notify_telegram_bot_token');

        $freeLlm = [
            'has_groq_key' => (bool) Setting::getEncrypted('groq_api_key'),
            'has_openrouter_key' => (bool) Setting::getEncrypted('openrouter_api_key'),
            'has_google_ai_key' => (bool) Setting::getEncrypted('google_ai_api_key'),
            'has_mistral_key' => (bool) Setting::getEncrypted('mistral_api_key'),
            'has_together_key' => (bool) Setting::getEncrypted('together_api_key'),
            'models' => FreeLlmModel::available()->orderBy('provider')->orderBy('display_name')->get(),
            'last_refresh_at' => Setting::get('free_llms_last_refresh_at'),
        ];

        return view('settings.index', compact('settings', 'hasOpenaiKey', 'availableModels', 'hasNotifyBotToken', 'freeLlm'));
    }

    public function update(Request $request)
    {
        if (! $request->user()->isManager()) {
            abort(403);
        }

        $validated = $request->validate([
            'openai_api_key' => 'nullable|string|min:10',
            'image_max_dimension' => 'required|integer|min:512|max:4096',
            'image_target_min_kb' => 'required|integer|min:50|max:500',
            'image_target_max_kb' => 'required|integer|min:200|max:2000',
            'image_min_quality' => 'required|integer|min:30|max:90',
            'image_max_upload_mb' => 'required|integer|min:1|max:50',
            'video_max_upload_mb' => 'required|integer|min:10|max:500',
            'video_bitrate_1080p' => 'required|integer|min:1000|max:20000',
            'video_bitrate_720p' => 'required|integer|min:500|max:10000',
            'video_codec' => 'required|in:h264',
            'video_audio_bitrate' => 'required|integer|min:64|max:320',
            // Stats sync
            'stats_sync_frequency' => 'required|in:every_15_min,every_30_min,hourly,every_2_hours,every_6_hours,every_12_hours,daily',
            'stats_facebook_interval' => 'required|integer|min:1|max:168',
            'stats_instagram_interval' => 'required|integer|min:1|max:168',
            'stats_twitter_interval' => 'required|integer|min:1|max:168',
            'stats_youtube_interval' => 'required|integer|min:1|max:168',
            'stats_threads_interval' => 'required|integer|min:1|max:168',
            'stats_bluesky_interval' => 'required|integer|min:1|max:168',
            'stats_facebook_max_days' => 'required|integer|min:1|max:365',
            'stats_instagram_max_days' => 'required|integer|min:1|max:365',
            'stats_twitter_max_days' => 'required|integer|min:1|max:365',
            'stats_youtube_max_days' => 'required|integer|min:1|max:365',
            'stats_threads_max_days' => 'required|integer|min:1|max:365',
            'stats_bluesky_max_days' => 'required|integer|min:1|max:365',
            // Platform character limits
            'platform_char_limit_twitter' => 'required|integer|min:1|max:100000',
            'platform_char_limit_facebook' => 'required|integer|min:1|max:100000',
            'platform_char_limit_instagram' => 'required|integer|min:1|max:100000',
            'platform_char_limit_threads' => 'required|integer|min:1|max:100000',
            'platform_char_limit_youtube' => 'required|integer|min:1|max:100000',
            'platform_char_limit_telegram' => 'required|integer|min:1|max:100000',
            'platform_char_limit_bluesky' => 'required|integer|min:1|max:100000',
            // AI models
            'ai_model_text' => 'required|string|max:50',
            'ai_model_vision' => 'required|string|max:50',
            'ai_model_translation' => 'required|string|max:50',
            'ai_model_rss' => 'required|string|max:50',
            'ai_prompt_publication_from_photo' => 'required|string|max:5000',
            'ai_prompt_metadata_extraction' => 'required|string|max:5000',
            // Inbox / Messagerie
            'inbox_platform_facebook_enabled' => 'nullable',
            'inbox_platform_instagram_enabled' => 'nullable',
            'inbox_platform_threads_enabled' => 'nullable',
            'inbox_platform_youtube_enabled' => 'nullable',
            'inbox_platform_bluesky_enabled' => 'nullable',
            'inbox_platform_telegram_enabled' => 'nullable',
            'inbox_platform_reddit_enabled' => 'nullable',
            'inbox_platform_twitter_enabled' => 'nullable',
            'inbox_sync_freq_facebook' => 'required|in:every_15_min,every_30_min,hourly,every_2_hours,every_6_hours,every_12_hours,daily',
            'inbox_sync_freq_instagram' => 'required|in:every_15_min,every_30_min,hourly,every_2_hours,every_6_hours,every_12_hours,daily',
            'inbox_sync_freq_threads' => 'required|in:every_15_min,every_30_min,hourly,every_2_hours,every_6_hours,every_12_hours,daily',
            'inbox_sync_freq_youtube' => 'required|in:every_15_min,every_30_min,hourly,every_2_hours,every_6_hours,every_12_hours,daily',
            'inbox_sync_freq_bluesky' => 'required|in:every_15_min,every_30_min,hourly,every_2_hours,every_6_hours,every_12_hours,daily',
            'inbox_sync_freq_telegram' => 'required|in:every_15_min,every_30_min,hourly,every_2_hours,every_6_hours,every_12_hours,daily',
            'inbox_sync_freq_reddit' => 'required|in:every_15_min,every_30_min,hourly,every_2_hours,every_6_hours,every_12_hours,daily',
            'inbox_sync_freq_twitter' => 'required|in:every_15_min,every_30_min,hourly,every_2_hours,every_6_hours,every_12_hours,daily',
            'ai_model_inbox' => 'required|string|max:50',
            'inbox_use_persona' => 'nullable',
            'inbox_reply_prompt' => 'nullable|string|max:5000',
            // Notifications
            'notify_publish_error' => 'nullable',
            'notify_telegram_bot_token' => 'nullable|string|max:100',
            'notify_telegram_chat_id' => 'nullable|string|max:50',
            // Services externes (banques d'images)
            'pexels_api_key' => 'nullable|string|min:10|max:100',
            'pixabay_api_key' => 'nullable|string|min:10|max:100',
            'unsplash_access_key' => 'nullable|string|min:10|max:100',
            'stock_photos_auto_fallback' => 'nullable',
            // IA Gratuite (cles API + defauts)
            'groq_api_key' => 'nullable|string|min:10|max:200',
            'openrouter_api_key' => 'nullable|string|min:10|max:200',
            'google_ai_api_key' => 'nullable|string|min:10|max:200',
            'mistral_api_key' => 'nullable|string|min:10|max:200',
            'together_api_key' => 'nullable|string|min:10|max:200',
            'free_llms_default_text_model' => 'nullable|string|max:200',
            'free_llms_default_vision_model' => 'nullable|string|max:200',
        ]);

        // Handle encrypted keys separately
        if ($request->filled('openai_api_key')) {
            Setting::setEncrypted('openai_api_key', $validated['openai_api_key']);
        }
        unset($validated['openai_api_key']);

        if ($request->filled('notify_telegram_bot_token')) {
            Setting::setEncrypted('notify_telegram_bot_token', $validated['notify_telegram_bot_token']);
        }
        unset($validated['notify_telegram_bot_token']);

        // Stock photo API keys (chiffrés)
        foreach (['pexels_api_key', 'pixabay_api_key', 'unsplash_access_key'] as $stockKey) {
            if ($request->filled($stockKey)) {
                Setting::setEncrypted($stockKey, $validated[$stockKey]);
            }
            unset($validated[$stockKey]);
        }

        // IA Gratuite : clés API chiffrées (5 providers)
        foreach (['groq_api_key', 'openrouter_api_key', 'google_ai_api_key', 'mistral_api_key', 'together_api_key'] as $llmKey) {
            if ($request->filled($llmKey)) {
                Setting::setEncrypted($llmKey, $validated[$llmKey]);
            }
            unset($validated[$llmKey]);
        }

        // Checkbox stock auto-fallback (absent = false)
        Setting::set('stock_photos_auto_fallback', $request->boolean('stock_photos_auto_fallback') ? '1' : '0');
        unset($validated['stock_photos_auto_fallback']);

        // Handle inbox platform toggles (checkboxes: absent = false)
        $inboxPlatforms = ['facebook', 'instagram', 'threads', 'youtube', 'bluesky', 'telegram', 'reddit', 'twitter'];
        foreach ($inboxPlatforms as $slug) {
            $key = "inbox_platform_{$slug}_enabled";
            $validated[$key] = $request->has($key) ? true : false;
        }

        // Handle inbox_use_persona checkbox
        $validated['inbox_use_persona'] = $request->has('inbox_use_persona') ? true : false;

        // Handle notify_publish_error checkbox
        $validated['notify_publish_error'] = $request->has('notify_publish_error') ? true : false;

        foreach ($validated as $key => $value) {
            Setting::set($key, $value);
        }

        return redirect()->route('settings.index', ['tab' => $request->input('_active_tab', 'ia')])->with('status', 'settings-updated');
    }

    public function testNotification(Request $request): JsonResponse
    {
        if (! $request->user()->isManager()) {
            abort(403);
        }

        $success = TelegramNotificationService::sendTest();

        return response()->json(['success' => $success, 'error' => $success ? null : 'Echec envoi']);
    }

    public function refreshFreeLlms(Request $request, FreeLlmDiscoveryService $discovery): RedirectResponse
    {
        if (! $request->user()->isManager()) {
            abort(403);
        }

        $results = $discovery->refresh();
        $total = array_sum($results);
        $detail = collect($results)->map(fn ($n, $p) => "{$p}: {$n}")->implode(', ');

        return redirect()
            ->route('settings.index', ['tab' => 'ia_libre'])
            ->with('status', "free-llms-refreshed: {$total} ({$detail})");
    }
}
