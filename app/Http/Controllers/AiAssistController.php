<?php

namespace App\Http\Controllers;

use App\Models\MediaFile;
use App\Models\SocialAccount;
use App\Services\AiAssistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AiAssistController extends Controller
{
    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'content' => 'nullable|string|max:10000',
            'account_id' => 'required|exists:social_accounts,id',
        ]);

        $account = SocialAccount::with(['platform', 'persona'])->findOrFail($validated['account_id']);
        $persona = $account->persona;

        if (! $persona) {
            return response()->json([
                'error' => 'Aucune persona configurée pour ce compte. Allez dans Comptes > Modifier pour associer une persona.',
            ], 422);
        }

        $service = new AiAssistService;
        $result = $service->generate($validated['content'] ?? '', $persona, $account);

        if (! $result) {
            return response()->json([
                'error' => 'Impossible de générer le contenu. Vérifiez la clé API OpenAI dans les paramètres.',
            ], 422);
        }

        return response()->json(['content' => $result]);
    }

    public function generateForPlatforms(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'content' => 'nullable|string|max:10000',
            'platforms' => 'required|array|min:1',
            'platforms.*' => 'string|in:facebook,instagram,threads,twitter,telegram,youtube,bluesky',
            'account_id' => 'required|exists:social_accounts,id',
        ]);

        $account = SocialAccount::with(['platform', 'persona'])->findOrFail($validated['account_id']);
        $persona = $account->persona;

        if (! $persona) {
            return response()->json([
                'error' => 'Aucune persona configurée pour ce compte. Allez dans Comptes > Modifier pour associer une persona.',
            ], 422);
        }

        $service = new AiAssistService;
        $result = $service->generateForPlatforms(
            $validated['content'] ?? '',
            $validated['platforms'],
            $persona,
            $account
        );

        if (! $result) {
            return response()->json([
                'error' => 'Impossible de générer le contenu. Vérifiez la clé API OpenAI dans les paramètres.',
            ], 422);
        }

        return response()->json(['platform_contents' => $result]);
    }

    public function generateFromMedia(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'media_urls' => 'required|array|min:1',
            'media_urls.*' => 'required|string',
            'platforms' => 'required|array|min:1',
            'platforms.*' => 'string|in:facebook,instagram,threads,twitter,telegram,youtube,bluesky',
            'account_id' => 'required|exists:social_accounts,id',
            'content' => 'nullable|string|max:10000',
        ]);

        $account = SocialAccount::with(['platform', 'persona'])->findOrFail($validated['account_id']);
        $persona = $account->persona;

        if (! $persona) {
            return response()->json([
                'error' => 'Aucune persona configurée pour ce compte.',
            ], 422);
        }

        $imageDataUrls = [];

        foreach ($validated['media_urls'] as $url) {
            $filename = basename($url);
            $filePath = Storage::disk('local')->path("media/{$filename}");

            if (! file_exists($filePath)) {
                Log::warning('AiAssistController: Media file not found', ['url' => $url, 'filename' => $filename, 'path' => $filePath]);

                continue;
            }

            $mimeType = mime_content_type($filePath);

            if (str_starts_with($mimeType, 'image/')) {
                $dataUrl = $this->resizeImageForVision($filePath);
                if ($dataUrl) {
                    $imageDataUrls[] = $dataUrl;
                }
            } elseif (str_starts_with($mimeType, 'video/')) {
                $frames = $this->extractVideoFrames($filePath);
                $imageDataUrls = array_merge($imageDataUrls, $frames);
            }
        }

        if (empty($imageDataUrls)) {
            Log::warning('AiAssistController: No valid media data URLs generated', [
                'media_urls' => $validated['media_urls'],
            ]);

            return response()->json([
                'error' => 'Aucun média valide trouvé à analyser.',
            ], 422);
        }

        Log::info('AiAssistController: Sending to Vision API', [
            'image_count' => count($imageDataUrls),
            'platforms' => $validated['platforms'],
        ]);

        $service = new AiAssistService;
        $result = $service->generateFromMediaForPlatforms(
            $imageDataUrls,
            $validated['platforms'],
            $persona,
            $account,
            $validated['content'] ?? ''
        );

        if ($result === 'refused') {
            return response()->json([
                'error' => 'L\'IA a refusé d\'analyser cette image (politique de contenu OpenAI). Essayez avec une autre photo.',
            ], 422);
        }

        if (! $result || ! is_array($result)) {
            return response()->json([
                'error' => 'Impossible de générer le contenu. Vérifiez la clé API OpenAI dans les paramètres.',
            ], 422);
        }

        return response()->json(['platform_contents' => $result]);
    }

    public function generateFromPhotoInfos(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'media_urls' => 'required|array|min:1',
            'media_urls.*' => 'required|string',
            'platforms' => 'required|array|min:1',
            'platforms.*' => 'string|in:facebook,instagram,threads,twitter,telegram,youtube,bluesky',
            'account_id' => 'required|exists:social_accounts,id',
            'content' => 'nullable|string|max:10000',
        ]);

        $account = SocialAccount::with(['platform', 'persona'])->findOrFail($validated['account_id']);
        $persona = $account->persona;

        if (! $persona) {
            return response()->json([
                'error' => 'Aucune persona configurée pour ce compte.',
            ], 422);
        }

        $filenames = array_map(fn ($url) => basename($url), $validated['media_urls']);
        $mediaFiles = MediaFile::whereIn('filename', $filenames)->get();

        $brief = $this->buildPhotoInfosBrief($mediaFiles, $validated['content'] ?? '');

        if ($brief === null) {
            return response()->json([
                'error' => 'Aucune des photos sélectionnées n\'a d\'informations enrichies (description, tags, marques, lieu...). Allez dans Médias pour les analyser ou les taguer.',
            ], 422);
        }

        $service = new AiAssistService;
        $result = $service->generateForPlatforms(
            $brief,
            $validated['platforms'],
            $persona,
            $account
        );

        if (! $result) {
            return response()->json([
                'error' => 'Impossible de générer le contenu. Vérifiez la clé API OpenAI dans les paramètres.',
            ], 422);
        }

        return response()->json(['platform_contents' => $result]);
    }

    /**
     * Construit un brief textuel à partir des métadonnées de photos.
     * Retourne null si aucune photo n'a d'informations exploitables.
     */
    private function buildPhotoInfosBrief($mediaFiles, string $userNote): ?string
    {
        $blocks = [];
        $index = 1;

        foreach ($mediaFiles as $media) {
            $lines = [];

            if (! empty($media->description_fr)) {
                $lines[] = 'Description : '.trim($media->description_fr);
            }

            $tags = is_array($media->thematic_tags) ? array_filter($media->thematic_tags) : [];
            if (! empty($tags)) {
                $lines[] = 'Sujets : '.implode(', ', $tags);
            }

            $brands = is_array($media->brands) ? array_filter($media->brands) : [];
            if (! empty($brands)) {
                $lines[] = 'Marques : '.implode(', ', $brands);
            }

            $location = array_filter([$media->city, $media->region, $media->country]);
            if (! empty($location)) {
                $lines[] = 'Lieu : '.implode(', ', $location);
            }

            if (! empty($media->event)) {
                $lines[] = 'Événement : '.$media->event;
            }

            if ($media->taken_at) {
                $lines[] = 'Date : '.$media->taken_at->locale('fr')->isoFormat('MMMM YYYY');
            }

            if (empty($lines)) {
                continue;
            }

            $blocks[] = "— Photo {$index} :\n  ".implode("\n  ", $lines);
            $index++;
        }

        if (empty($blocks)) {
            return null;
        }

        $brief = "Photos sélectionnées avec leurs informations enrichies :\n\n".implode("\n\n", $blocks);

        if (trim($userNote) !== '') {
            $brief .= "\n\nNote de l'utilisateur : ".trim($userNote);
        }

        $brief .= "\n\nGénère une publication créative et engageante en t'appuyant sur ces informations (sans inventer de détails non mentionnés).";

        return $brief;
    }

    private function resizeImageForVision(string $filePath): ?string
    {
        $mimeType = mime_content_type($filePath);
        $image = match ($mimeType) {
            'image/png' => imagecreatefrompng($filePath),
            'image/gif' => imagecreatefromgif($filePath),
            'image/webp' => imagecreatefromwebp($filePath),
            default => imagecreatefromjpeg($filePath),
        };

        if (! $image) {
            return null;
        }

        $w = imagesx($image);
        $h = imagesy($image);
        $maxDim = 1024;

        if ($w > $maxDim || $h > $maxDim) {
            if ($w >= $h) {
                $newW = $maxDim;
                $newH = (int) round($h * ($maxDim / $w));
            } else {
                $newH = $maxDim;
                $newW = (int) round($w * ($maxDim / $h));
            }
            $resized = imagecreatetruecolor($newW, $newH);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newW, $newH, $w, $h);
            imagedestroy($image);
            $image = $resized;
        }

        ob_start();
        imagejpeg($image, null, 80);
        $data = ob_get_clean();
        imagedestroy($image);

        return 'data:image/jpeg;base64,'.base64_encode($data);
    }

    private function extractVideoFrames(string $videoPath): array
    {
        $dataUrls = [];
        $tempFiles = [];

        try {
            $ffmpeg = $this->findBinary('ffmpeg');
            $ffprobe = $this->findBinary('ffprobe');

            if (! $ffmpeg) {
                Log::warning('AiAssistController: ffmpeg not found, skipping video frames');

                return [];
            }

            // Get video duration
            $duration = 0;
            if ($ffprobe) {
                $output = [];
                exec(sprintf(
                    '%s -v quiet -show_entries format=duration -of csv=p=0 %s 2>/dev/null',
                    escapeshellarg($ffprobe),
                    escapeshellarg($videoPath)
                ), $output);
                $duration = (float) trim($output[0] ?? '0');
            }

            if ($duration <= 0) {
                $duration = 30; // fallback
            }

            // Check for existing thumbnail
            $basename = pathinfo(basename($videoPath), PATHINFO_FILENAME);
            $thumbPath = Storage::disk('local')->path("media/thumbnails/{$basename}.jpg");
            $hasThumb = file_exists($thumbPath) && filesize($thumbPath) > 0;

            // Calculate timestamps for frames
            $frameCount = $hasThumb ? 4 : 5;
            $timestamps = [];
            for ($i = 1; $i <= $frameCount; $i++) {
                $timestamps[] = round($duration * $i / ($frameCount + 1), 2);
            }

            // Reuse existing thumbnail as first frame
            if ($hasThumb) {
                $dataUrl = $this->resizeImageForVision($thumbPath);
                if ($dataUrl) {
                    $dataUrls[] = $dataUrl;
                }
            }

            // Extract remaining frames
            foreach ($timestamps as $ts) {
                $tempPath = tempnam(sys_get_temp_dir(), 'rsmax_frame_').'.jpg';
                $tempFiles[] = $tempPath;

                exec(sprintf(
                    '%s -ss %s -i %s -frames:v 1 -q:v 3 -vf "scale=1024:-1" -update 1 %s 2>/dev/null',
                    escapeshellarg($ffmpeg),
                    $ts,
                    escapeshellarg($videoPath),
                    escapeshellarg($tempPath)
                ));

                if (file_exists($tempPath) && filesize($tempPath) > 0) {
                    $dataUrls[] = 'data:image/jpeg;base64,'.base64_encode(file_get_contents($tempPath));
                }
            }
        } finally {
            foreach ($tempFiles as $tmp) {
                @unlink($tmp);
            }
        }

        return $dataUrls;
    }

    private function findBinary(string $name): ?string
    {
        $output = [];
        exec("which {$name} 2>/dev/null", $output);
        if (! empty($output[0]) && is_executable($output[0])) {
            return $output[0];
        }

        foreach (["/opt/homebrew/bin/{$name}", "/usr/local/bin/{$name}", "/usr/bin/{$name}"] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }
}
