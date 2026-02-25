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
        ]);

        // Handle OpenAI key separately (encrypted storage)
        if ($request->filled('openai_api_key')) {
            Setting::setEncrypted('openai_api_key', $validated['openai_api_key']);
        }
        unset($validated['openai_api_key']);

        foreach ($validated as $key => $value) {
            Setting::set($key, $value);
        }

        return redirect()->route('settings.index')->with('status', 'settings-updated');
    }
}
