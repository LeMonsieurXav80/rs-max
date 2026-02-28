<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
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
        'stats_facebook_max_days',
        'stats_instagram_max_days',
        'stats_twitter_max_days',
        'stats_youtube_max_days',
        'stats_threads_max_days',
        // Platform character limits
        'platform_char_limit_twitter',
        'platform_char_limit_facebook',
        'platform_char_limit_instagram',
        'platform_char_limit_threads',
        'platform_char_limit_youtube',
        'platform_char_limit_telegram',
        // AI models
        'ai_model_text',
        'ai_model_vision',
        'ai_model_translation',
        'ai_model_rss',
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
        'stats_facebook_max_days' => 30,
        'stats_instagram_max_days' => 30,
        'stats_twitter_max_days' => 14,
        'stats_youtube_max_days' => 30,
        'stats_threads_max_days' => 30,
        // Platform character limits
        'platform_char_limit_twitter' => 280,
        'platform_char_limit_facebook' => 63206,
        'platform_char_limit_instagram' => 2200,
        'platform_char_limit_threads' => 500,
        'platform_char_limit_youtube' => 5000,
        'platform_char_limit_telegram' => 4096,
        // AI models
        'ai_model_text' => 'gpt-4o-mini',
        'ai_model_vision' => 'gpt-4o',
        'ai_model_translation' => 'gpt-4o-mini',
        'ai_model_rss' => 'gpt-4o-mini',
    ];

    public function index(Request $request): View
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $settings = [];
        foreach (self::SETTINGS_KEYS as $key) {
            $settings[$key] = Setting::get($key, self::DEFAULTS[$key]);
        }

        $hasOpenaiKey = (bool) Setting::getEncrypted('openai_api_key');

        return view('settings.index', compact('settings', 'hasOpenaiKey'));
    }

    public function update(Request $request)
    {
        if (! $request->user()->is_admin) {
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
            'stats_facebook_max_days' => 'required|integer|min:1|max:365',
            'stats_instagram_max_days' => 'required|integer|min:1|max:365',
            'stats_twitter_max_days' => 'required|integer|min:1|max:365',
            'stats_youtube_max_days' => 'required|integer|min:1|max:365',
            'stats_threads_max_days' => 'required|integer|min:1|max:365',
            // Platform character limits
            'platform_char_limit_twitter' => 'required|integer|min:1|max:100000',
            'platform_char_limit_facebook' => 'required|integer|min:1|max:100000',
            'platform_char_limit_instagram' => 'required|integer|min:1|max:100000',
            'platform_char_limit_threads' => 'required|integer|min:1|max:100000',
            'platform_char_limit_youtube' => 'required|integer|min:1|max:100000',
            'platform_char_limit_telegram' => 'required|integer|min:1|max:100000',
            // AI models
            'ai_model_text' => 'required|string|max:50',
            'ai_model_vision' => 'required|string|max:50',
            'ai_model_translation' => 'required|string|max:50',
            'ai_model_rss' => 'required|string|max:50',
        ]);

        // Handle OpenAI key separately (encrypted storage)
        if ($request->filled('openai_api_key')) {
            Setting::setEncrypted('openai_api_key', $validated['openai_api_key']);
        }
        unset($validated['openai_api_key']);

        foreach ($validated as $key => $value) {
            Setting::set($key, $value);
        }

        return redirect()->route('settings.index', ['tab' => $request->input('_active_tab', 'ia')])->with('status', 'settings-updated');
    }
}
