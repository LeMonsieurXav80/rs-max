<?php

namespace App\Http\Controllers;

use App\Services\PromptGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PromptGeneratorController extends Controller
{
    public function __construct(private PromptGeneratorService $generator) {}

    /**
     * Image prompt generator page.
     */
    public function image(): View
    {
        return view('prompts.image');
    }

    /**
     * Video prompt generator page.
     */
    public function video(): View
    {
        return view('prompts.video');
    }

    /**
     * Generate image prompts via AI.
     */
    public function generateImage(Request $request): JsonResponse
    {
        $request->validate([
            'description' => 'nullable|string|max:500',
            'content_type' => 'nullable|string|max:100',
            'season' => 'nullable|string|max:50',
            'time_of_day' => 'nullable|string|max:50',
            'vehicle' => 'nullable|string|max:100',
            'photo_style' => 'nullable|string|max:100',
            'shot_type' => 'nullable|string|max:100',
            'animals' => 'nullable|string|max:200',
            'safe_mode' => 'nullable|boolean',
            'count' => 'nullable|integer|min:1|max:10',
        ]);

        $result = $this->generator->generateImagePrompts($request->all());

        if (! $result['success']) {
            return response()->json(['error' => $result['error']], 422);
        }

        return response()->json($result);
    }

    /**
     * Analyze a photo for video animation via Vision API.
     */
    public function analyzePhoto(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => 'required|file|mimes:jpg,jpeg,png,webp|max:10240',
        ]);

        $file = $request->file('photo');
        $mimeType = $file->getMimeType();

        // Resize for vision API (max 1024px)
        $image = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($file->getPathname()),
            'image/png' => @imagecreatefrompng($file->getPathname()),
            'image/webp' => @imagecreatefromwebp($file->getPathname()),
            default => null,
        };

        if (! $image) {
            return response()->json(['error' => 'Impossible de lire l\'image.'], 422);
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $maxDim = 1024;

        if ($width > $maxDim || $height > $maxDim) {
            if ($width >= $height) {
                $newWidth = $maxDim;
                $newHeight = (int) round($height * ($maxDim / $width));
            } else {
                $newHeight = $maxDim;
                $newWidth = (int) round($width * ($maxDim / $height));
            }

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }

        // Convert to base64 data URL
        ob_start();
        imagejpeg($image, null, 85);
        $imageData = ob_get_clean();
        imagedestroy($image);

        $dataUrl = 'data:image/jpeg;base64,' . base64_encode($imageData);

        $result = $this->generator->analyzePhotoForVideo($dataUrl);

        if (! $result['success']) {
            return response()->json(['error' => $result['error']], 422);
        }

        return response()->json($result);
    }

    /**
     * Generate video animation prompts from analysis.
     */
    public function generateVideo(Request $request): JsonResponse
    {
        $request->validate([
            'analysis' => 'required|string|max:3000',
            'description' => 'nullable|string|max:500',
            'mode' => 'nullable|in:standard,advanced',
            'movement_type' => 'nullable|in:subtle,moderate,dynamic,cinematic',
            'video_style' => 'nullable|in:realistic,cinematic,dreamy,energetic,slow_motion',
            'count' => 'nullable|integer|min:1|max:10',
        ]);

        $result = $this->generator->generateVideoPrompts(
            $request->input('analysis'),
            $request->all()
        );

        if (! $result['success']) {
            return response()->json(['error' => $result['error']], 422);
        }

        return response()->json($result);
    }
}
