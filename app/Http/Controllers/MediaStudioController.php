<?php

namespace App\Http\Controllers;

use App\Models\MediaFolder;
use App\Models\Setting;
use App\Services\MediaStudioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class MediaStudioController extends Controller
{
    public function __construct(private MediaStudioService $studio) {}

    /**
     * Studio page.
     */
    public function index(): View
    {
        $folders = MediaFolder::orderBy('name')->get();

        return view('media.studio', compact('folders'));
    }

    /**
     * Process uploaded file(s) through the studio pipeline.
     */
    public function process(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:' . ((int) Setting::get('video_max_upload_mb', 500) * 1024),
            'format' => 'nullable|in:vertical,square,original',
            'logo_enabled' => 'nullable|boolean',
            'text_enabled' => 'nullable|boolean',
            'text_content' => 'nullable|string|max:100',
            'strip_metadata' => 'nullable|boolean',
            'strip_exif' => 'nullable|boolean',
            'watermark_enabled' => 'nullable|boolean',
            'watermark_text' => 'nullable|string|max:100',
            'folder_id' => 'nullable|exists:media_folders,id',
        ]);

        $file = $request->file('file');
        $mimeType = $file->getMimeType();
        $isVideo = str_starts_with($mimeType, 'video/');
        $isImage = str_starts_with($mimeType, 'image/');

        if (! $isVideo && ! $isImage) {
            return response()->json(['error' => 'Type de fichier non supporté.'], 422);
        }

        // Store source temporarily
        $tempName = 'studio_tmp_' . uniqid() . '.' . $file->getClientOriginalExtension();
        $file->storeAs('studio_tmp', $tempName, 'local');
        $sourcePath = Storage::disk('local')->path("studio_tmp/{$tempName}");

        try {
            $options = [
                'original_name' => $file->getClientOriginalName(),
                'folder_id' => $request->input('folder_id'),
            ];

            if ($isVideo) {
                $options['format'] = $request->input('format', 'original');
                $options['logo_enabled'] = $request->boolean('logo_enabled');
                $options['logo_path'] = Setting::get('studio_logo_path');
                $options['text_enabled'] = $request->boolean('text_enabled');
                $options['text_content'] = $request->input('text_content', '');
                $options['strip_metadata'] = $request->boolean('strip_metadata', true);

                $result = $this->studio->processVideo($sourcePath, $options);
            } else {
                $options['strip_exif'] = $request->boolean('strip_exif', true);
                $options['watermark_enabled'] = $request->boolean('watermark_enabled');
                $options['watermark_text'] = $request->input('watermark_text', '');

                $result = $this->studio->processPhoto($sourcePath, $mimeType, $options);
            }

            if (! $result['success']) {
                return response()->json(['error' => $result['error']], 422);
            }

            return response()->json($result);
        } finally {
            // Always clean up source
            @unlink($sourcePath);
            // Clean up temp dir if empty
            $tmpDir = Storage::disk('local')->path('studio_tmp');
            if (is_dir($tmpDir) && count(scandir($tmpDir)) <= 2) {
                @rmdir($tmpDir);
            }
        }
    }

    /**
     * Upload a logo image for the studio overlay.
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => 'required|file|mimes:png,webp,jpg,jpeg|max:2048',
        ]);

        $file = $request->file('logo');
        $filename = 'studio_logo.' . $file->getClientOriginalExtension();
        $file->storeAs('studio', $filename, 'local');

        Setting::set('studio_logo_path', "studio/{$filename}");

        return response()->json([
            'success' => true,
            'path' => "studio/{$filename}",
        ]);
    }
}
