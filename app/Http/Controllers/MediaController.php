<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaController extends Controller
{
    private function maxImageDimension(): int
    {
        return (int) Setting::get('image_max_dimension', 2048);
    }

    private function jpegQuality(): int
    {
        return (int) Setting::get('image_jpeg_quality', 82);
    }

    private function pngQuality(): int
    {
        return (int) Setting::get('image_png_quality', 8);
    }

    /**
     * Media library page.
     */
    public function index(Request $request): View
    {
        $disk = Storage::disk('local');
        $files = $disk->files('media');

        $filter = $request->input('filter', 'all');

        // Build media items with metadata
        $items = collect($files)->map(function ($path) use ($disk) {
            $filename = basename($path);
            $mimeType = $disk->mimeType($path);
            $size = $disk->size($path);
            $lastModified = $disk->lastModified($path);
            $isImage = str_starts_with($mimeType, 'image/');
            $isVideo = str_starts_with($mimeType, 'video/');

            return [
                'filename' => $filename,
                'path' => $path,
                'url' => "/media/{$filename}",
                'mimetype' => $mimeType,
                'size' => $size,
                'size_human' => $this->humanFileSize($size),
                'is_image' => $isImage,
                'is_video' => $isVideo,
                'type' => $isImage ? 'image' : ($isVideo ? 'video' : 'other'),
                'last_modified' => $lastModified,
                'date' => date('d/m/Y H:i', $lastModified),
            ];
        })->filter(function ($item) use ($filter) {
            if ($filter === 'images') {
                return $item['is_image'];
            }
            if ($filter === 'videos') {
                return $item['is_video'];
            }

            return true;
        })->sortByDesc('last_modified')->values();

        // Find which posts reference each media file
        $posts = Post::whereNotNull('media')->with('postPlatforms.platform')->get();
        $mediaPostMap = [];

        foreach ($posts as $post) {
            if (! is_array($post->media)) {
                continue;
            }
            foreach ($post->media as $mediaItem) {
                $url = is_string($mediaItem) ? $mediaItem : ($mediaItem['url'] ?? '');
                $fname = basename($url);
                if ($fname) {
                    $statusDotClass = match ($post->status) {
                        'draft' => 'bg-gray-400',
                        'scheduled' => 'bg-blue-500',
                        'publishing' => 'bg-yellow-500',
                        'published' => 'bg-green-500',
                        'failed' => 'bg-red-500',
                        default => 'bg-gray-300',
                    };
                    $mediaPostMap[$fname][] = [
                        'id' => $post->id,
                        'preview' => Str::limit($post->content_fr, 50),
                        'status' => $post->status,
                        'status_dot' => $statusDotClass,
                    ];
                }
            }
        }

        $imageCount = collect($files)->filter(fn ($f) => str_starts_with(Storage::disk('local')->mimeType($f), 'image/'))->count();
        $videoCount = collect($files)->filter(fn ($f) => str_starts_with(Storage::disk('local')->mimeType($f), 'video/'))->count();

        return view('media.index', compact('items', 'mediaPostMap', 'filter', 'imageCount', 'videoCount'));
    }

    /**
     * Upload a media file (AJAX). Images are compressed with GD.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:51200|mimes:jpeg,jpg,png,gif,webp,mp4,mov,avi,webm',
        ]);

        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $mimeType = $file->getMimeType();
        $isImage = str_starts_with($mimeType, 'image/');

        // Generate unique filename
        $extension = $isImage ? $this->outputExtension($mimeType, $file->getRealPath()) : $file->getClientOriginalExtension();
        $filename = date('Ymd_His') . '_' . Str::random(8) . '.' . $extension;

        if ($isImage) {
            // Compress and resize with GD
            $result = $this->processImage($file->getRealPath(), $mimeType, $filename);

            if (! $result['success']) {
                return response()->json(['error' => $result['error']], 422);
            }

            return response()->json([
                'url' => "/media/{$filename}",
                'mimetype' => $result['mimetype'],
                'size' => $result['size'],
                'title' => $originalName,
                'width' => $result['width'],
                'height' => $result['height'],
            ]);
        }

        // Video: store as-is
        $file->storeAs('media', $filename, 'local');

        $storedSize = Storage::disk('local')->size("media/{$filename}");

        return response()->json([
            'url' => "/media/{$filename}",
            'mimetype' => $mimeType,
            'size' => $storedSize,
            'title' => $originalName,
        ]);
    }

    /**
     * Delete a media file.
     */
    public function destroy(Request $request, string $filename): JsonResponse
    {
        $path = "media/{$filename}";

        if (! Storage::disk('local')->exists($path)) {
            return response()->json(['error' => 'Fichier introuvable.'], 404);
        }

        Storage::disk('local')->delete($path);

        return response()->json(['success' => true, 'message' => 'Fichier supprimé.']);
    }

    /**
     * List all media files as JSON (for the library picker in post forms).
     */
    public function list(Request $request): JsonResponse
    {
        $disk = Storage::disk('local');
        $files = $disk->files('media');

        $items = collect($files)->map(function ($path) use ($disk) {
            $filename = basename($path);
            $mimeType = $disk->mimeType($path);

            return [
                'filename' => $filename,
                'url' => "/media/{$filename}",
                'mimetype' => $mimeType,
                'size' => $disk->size($path),
                'size_human' => $this->humanFileSize($disk->size($path)),
                'is_image' => str_starts_with($mimeType, 'image/'),
                'is_video' => str_starts_with($mimeType, 'video/'),
                'date' => date('d/m/Y H:i', $disk->lastModified($path)),
            ];
        })->sortByDesc(fn ($item) => $item['date'])->values();

        return response()->json($items);
    }

    /**
     * Serve a video thumbnail (generated via ffmpeg, cached).
     */
    public function thumbnail(Request $request, string $filename): BinaryFileResponse
    {
        $videoPath = Storage::disk('local')->path("media/{$filename}");
        if (! file_exists($videoPath)) {
            abort(404);
        }

        $thumbDir = Storage::disk('local')->path('media/thumbnails');
        if (! is_dir($thumbDir)) {
            mkdir($thumbDir, 0755, true);
        }

        $thumbFilename = pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
        $thumbPath = $thumbDir . '/' . $thumbFilename;

        if (! file_exists($thumbPath)) {
            $ffmpeg = trim(exec('which ffmpeg 2>/dev/null'));
            if (! $ffmpeg) {
                // Homebrew paths not in PHP's PATH
                foreach (['/opt/homebrew/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/usr/bin/ffmpeg'] as $path) {
                    if (file_exists($path)) {
                        $ffmpeg = $path;
                        break;
                    }
                }
            }
            if (! $ffmpeg) {
                abort(404);
            }

            // Extract frame at ~5 seconds
            exec(sprintf(
                '%s -ss 5 -i %s -frames:v 1 -q:v 3 -vf "scale=480:-1" -update 1 %s 2>/dev/null',
                escapeshellarg($ffmpeg),
                escapeshellarg($videoPath),
                escapeshellarg($thumbPath)
            ));

            // If 5s failed (video too short), try first frame
            if (! file_exists($thumbPath) || filesize($thumbPath) === 0) {
                exec(sprintf(
                    '%s -i %s -frames:v 1 -q:v 3 -vf "scale=480:-1" -update 1 %s 2>/dev/null',
                    escapeshellarg($ffmpeg),
                    escapeshellarg($videoPath),
                    escapeshellarg($thumbPath)
                ));
            }

            if (! file_exists($thumbPath) || filesize($thumbPath) === 0) {
                abort(404);
            }
        }

        return response()->file($thumbPath, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }

    /**
     * Serve a private media file.
     */
    public function show(Request $request, string $filename): BinaryFileResponse
    {
        if (! $request->user() && ! $request->hasValidSignature()) {
            abort(403);
        }

        $path = "media/{$filename}";

        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        $fullPath = Storage::disk('local')->path($path);
        $mimeType = Storage::disk('local')->mimeType($path);

        return response()->file($fullPath, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'private, max-age=86400',
            'Accept-Ranges' => 'bytes',
        ]);
    }

    /**
     * Process an image: resize if needed, compress, and store.
     */
    private function processImage(string $sourcePath, string $mimeType, string $filename): array
    {
        $image = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($sourcePath),
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/gif' => @imagecreatefromgif($sourcePath),
            'image/webp' => @imagecreatefromwebp($sourcePath),
            default => null,
        };

        if (! $image) {
            return ['success' => false, 'error' => 'Format image non supporté.'];
        }

        $origWidth = imagesx($image);
        $origHeight = imagesy($image);
        $newWidth = $origWidth;
        $newHeight = $origHeight;

        // Resize if the longest side exceeds max dimension
        $maxDim = $this->maxImageDimension();
        if ($origWidth > $maxDim || $origHeight > $maxDim) {
            if ($origWidth >= $origHeight) {
                $newWidth = $maxDim;
                $newHeight = (int) round($origHeight * ($maxDim / $origWidth));
            } else {
                $newHeight = $maxDim;
                $newWidth = (int) round($origWidth * ($maxDim / $origHeight));
            }

            $resized = imagecreatetruecolor($newWidth, $newHeight);

            // Preserve transparency for PNG
            if ($mimeType === 'image/png') {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
                imagefilledrectangle($resized, 0, 0, $newWidth, $newHeight, $transparent);
            }

            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
            imagedestroy($image);
            $image = $resized;
        }

        // Determine output format
        $storagePath = Storage::disk('local')->path("media/{$filename}");
        $dir = dirname($storagePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $outputMime = $mimeType;
        if ($mimeType === 'image/png' && ! $this->hasTransparency($image)) {
            // Convert opaque PNG to JPEG for smaller file size
            $filename = preg_replace('/\.png$/i', '.jpg', $filename);
            $storagePath = Storage::disk('local')->path("media/{$filename}");
            $outputMime = 'image/jpeg';
        }

        $jpegQ = $this->jpegQuality();
        $pngQ = $this->pngQuality();

        $saved = match ($outputMime) {
            'image/jpeg' => imagejpeg($image, $storagePath, $jpegQ),
            'image/png' => imagepng($image, $storagePath, $pngQ),
            'image/gif' => imagegif($image, $storagePath),
            'image/webp' => imagewebp($image, $storagePath, $jpegQ),
            default => imagejpeg($image, $storagePath, $jpegQ),
        };

        imagedestroy($image);

        if (! $saved) {
            return ['success' => false, 'error' => 'Erreur lors de la compression de l\'image.'];
        }

        return [
            'success' => true,
            'mimetype' => $outputMime,
            'size' => filesize($storagePath),
            'width' => $newWidth,
            'height' => $newHeight,
        ];
    }

    /**
     * Determine output file extension.
     */
    private function outputExtension(string $mimeType, string $filePath): string
    {
        // PNG with no transparency will be converted to JPEG
        if ($mimeType === 'image/png') {
            $image = @imagecreatefrompng($filePath);
            if ($image && ! $this->hasTransparency($image)) {
                imagedestroy($image);

                return 'jpg';
            }
            if ($image) {
                imagedestroy($image);
            }

            return 'png';
        }

        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }

    /**
     * Check if a GD image resource has any transparent pixels.
     */
    private function hasTransparency(\GdImage $image): bool
    {
        $width = imagesx($image);
        $height = imagesy($image);

        // Sample a grid of pixels for performance
        $step = max(1, (int) ($width * $height / 1000));
        for ($i = 0; $i < $width * $height; $i += $step) {
            $x = $i % $width;
            $y = (int) ($i / $width);
            $rgba = imagecolorat($image, $x, $y);
            $alpha = ($rgba >> 24) & 0x7F;
            if ($alpha > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Format file size for display.
     */
    private function humanFileSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 0) . ' KB';
        }

        return $bytes . ' B';
    }
}
