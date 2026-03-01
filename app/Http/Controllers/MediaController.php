<?php

namespace App\Http\Controllers;

use App\Concerns\ProcessesImages;
use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaController extends Controller
{
    use ProcessesImages;

    /**
     * Media library page.
     */
    public function index(Request $request): View
    {
        $filter = $request->input('filter', 'all');
        $folderId = $request->input('folder');

        // Query from database
        $query = MediaFile::with('folder')->latest();

        if ($folderId === 'uncategorized') {
            $query->whereNull('folder_id');
        } elseif ($folderId) {
            $query->where('folder_id', $folderId);
        }

        if ($filter === 'images') {
            $query->where('mime_type', 'like', 'image/%');
        } elseif ($filter === 'videos') {
            $query->where('mime_type', 'like', 'video/%');
        }

        $mediaFiles = $query->get();

        $items = $mediaFiles->map(function (MediaFile $mf) {
            $isImage = $mf->is_image;
            $isVideo = $mf->is_video;

            $item = [
                'id' => $mf->id,
                'filename' => $mf->filename,
                'url' => $mf->url,
                'mimetype' => $mf->mime_type,
                'size' => $mf->size,
                'size_human' => $mf->size_human,
                'is_image' => $isImage,
                'is_video' => $isVideo,
                'type' => $isImage ? 'image' : ($isVideo ? 'video' : 'other'),
                'date' => $mf->created_at->format('d/m/Y H:i'),
                'folder_id' => $mf->folder_id,
                'folder_name' => $mf->folder?->name,
                'folder_color' => $mf->folder?->color,
            ];

            if ($isVideo) {
                $item['thumbnail_url'] = route('media.thumbnail', $mf->filename);
            }

            return $item;
        })->values();

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
                    $platforms = $post->postPlatforms->map(fn ($pp) => [
                        'slug' => $pp->platform->slug,
                        'name' => $pp->platform->name,
                        'status' => $pp->status,
                    ])->values()->toArray();
                    $mediaPostMap[$fname][] = [
                        'id' => $post->id,
                        'preview' => Str::limit($post->content_fr, 50),
                        'status' => $post->status,
                        'status_dot' => $statusDotClass,
                        'platforms' => $platforms,
                        'scheduled_at' => $post->scheduled_at?->format('d/m/Y H:i'),
                        'published_at' => $post->published_at?->format('d/m/Y H:i'),
                    ];
                }
            }
        }

        $folders = MediaFolder::ordered()->withCount('files')->get();
        $imageCount = MediaFile::where('mime_type', 'like', 'image/%')->count();
        $videoCount = MediaFile::where('mime_type', 'like', 'video/%')->count();
        $totalCount = MediaFile::count();
        $uncategorizedCount = MediaFile::whereNull('folder_id')->count();
        $currentFolder = $folderId;

        return view('media.index', compact(
            'items', 'mediaPostMap', 'filter', 'imageCount', 'videoCount',
            'folders', 'totalCount', 'uncategorizedCount', 'currentFolder'
        ));
    }

    /**
     * Upload a media file (AJAX). Images are compressed with GD.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:51200|mimes:jpeg,jpg,png,gif,webp,mp4,mov,avi,webm',
            'folder_id' => 'nullable|exists:media_folders,id',
        ]);

        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $mimeType = $file->getMimeType();
        $isImage = str_starts_with($mimeType, 'image/');
        $folderId = $request->input('folder_id');

        // Generate unique filename
        $extension = $isImage ? $this->outputExtension($mimeType, $file->getRealPath()) : $file->getClientOriginalExtension();
        $filename = date('Ymd_His') . '_' . Str::random(8) . '.' . $extension;

        if ($isImage) {
            // Compress and resize with GD
            $result = $this->processImage($file->getRealPath(), $mimeType, $filename);

            if (! $result['success']) {
                return response()->json(['error' => $result['error']], 422);
            }

            // Use the filename from processImage (may have changed for PNG→JPG)
            $finalFilename = $result['filename'] ?? $filename;

            MediaFile::create([
                'folder_id' => $folderId,
                'filename' => $finalFilename,
                'original_name' => $originalName,
                'mime_type' => $result['mimetype'],
                'size' => $result['size'],
                'width' => $result['width'],
                'height' => $result['height'],
                'source' => 'upload',
            ]);

            return response()->json([
                'url' => "/media/{$finalFilename}",
                'mimetype' => $result['mimetype'],
                'size' => $result['size'],
                'title' => $originalName,
                'width' => $result['width'],
                'height' => $result['height'],
            ]);
        }

        // Video: store, then ensure MP4 H.264/AAC for platform compatibility
        $file->storeAs('media', $filename, 'local');

        $needsConversion = $mimeType !== 'video/mp4' || $this->isHevc($filename);

        if ($needsConversion) {
            $converted = $this->convertToMp4($filename);
            if ($converted) {
                $filename = $converted['filename'];
                $mimeType = 'video/mp4';
            }
        }

        $storedSize = Storage::disk('local')->size("media/{$filename}");

        MediaFile::create([
            'folder_id' => $folderId,
            'filename' => $filename,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'size' => $storedSize,
            'source' => 'upload',
        ]);

        return response()->json([
            'url' => "/media/{$filename}",
            'mimetype' => $mimeType,
            'size' => $storedSize,
            'title' => $originalName,
            'thumbnail_url' => route('media.thumbnail', $filename),
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

        // Remove from database
        MediaFile::where('filename', $filename)->delete();

        return response()->json(['success' => true, 'message' => 'Fichier supprimé.']);
    }

    /**
     * List all media files as JSON (for the library picker in post forms).
     */
    public function list(Request $request): JsonResponse
    {
        $folderId = $request->input('folder');

        $query = MediaFile::with('folder')->latest();

        if ($folderId === 'uncategorized') {
            $query->whereNull('folder_id');
        } elseif ($folderId) {
            $query->where('folder_id', $folderId);
        }

        $items = $query->get()->map(function (MediaFile $mf) {
            $isVideo = $mf->is_video;

            $item = [
                'id' => $mf->id,
                'filename' => $mf->filename,
                'url' => $mf->url,
                'mimetype' => $mf->mime_type,
                'size' => $mf->size,
                'size_human' => $mf->size_human,
                'is_image' => $mf->is_image,
                'is_video' => $isVideo,
                'date' => $mf->created_at->format('d/m/Y H:i'),
                'folder_id' => $mf->folder_id,
                'folder_name' => $mf->folder?->name,
            ];

            if ($isVideo) {
                $item['thumbnail_url'] = route('media.thumbnail', $mf->filename);
            }

            return $item;
        })->values();

        $folders = MediaFolder::ordered()->withCount('files')->get()->map(fn ($f) => [
            'id' => $f->id,
            'name' => $f->name,
            'color' => $f->color,
            'files_count' => $f->files_count,
        ]);

        return response()->json([
            'items' => $items,
            'folders' => $folders,
        ]);
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
            $ffmpeg = $this->findFfmpeg();
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
     * Check if a video uses HEVC/H.265 codec (not supported for inline playback on Telegram).
     */
    private function isHevc(string $filename): bool
    {
        $ffprobe = $this->findBinary('ffprobe');
        if (! $ffprobe) {
            return false;
        }

        $filePath = Storage::disk('local')->path("media/{$filename}");
        $output = [];
        exec(sprintf(
            '%s -v quiet -select_streams v:0 -show_entries stream=codec_name -of csv=p=0 %s 2>/dev/null',
            escapeshellarg($ffprobe),
            escapeshellarg($filePath)
        ), $output);

        $codec = trim($output[0] ?? '');

        return in_array($codec, ['hevc', 'h265', 'vp9', 'av1']);
    }

    /**
     * Convert a video to MP4 (H.264/AAC) using ffmpeg.
     */
    private function convertToMp4(string $filename): ?array
    {
        $ffmpeg = $this->findFfmpeg();
        if (! $ffmpeg) {
            return null;
        }

        $originalPath = Storage::disk('local')->path("media/{$filename}");
        $mp4Filename = pathinfo($filename, PATHINFO_FILENAME) . '.mp4';

        // If input is already .mp4 (HEVC re-encode), use a temp file
        $sameFile = strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'mp4';
        $outputPath = $sameFile
            ? Storage::disk('local')->path("media/{$mp4Filename}.tmp.mp4")
            : Storage::disk('local')->path("media/{$mp4Filename}");

        exec(sprintf(
            '%s -i %s -c:v libx264 -preset fast -crf 23 -c:a aac -b:a 128k -movflags +faststart -y %s 2>&1',
            escapeshellarg($ffmpeg),
            escapeshellarg($originalPath),
            escapeshellarg($outputPath)
        ), $output, $returnCode);

        if ($returnCode === 0 && file_exists($outputPath) && filesize($outputPath) > 0) {
            if ($sameFile) {
                @unlink($originalPath);
                rename($outputPath, Storage::disk('local')->path("media/{$mp4Filename}"));
            } else {
                @unlink($originalPath);
            }

            return ['filename' => $mp4Filename];
        }

        // Conversion failed — keep original
        @unlink($outputPath);

        return null;
    }

    /**
     * Locate the ffmpeg binary.
     */
    private function findFfmpeg(): ?string
    {
        return $this->findBinary('ffmpeg');
    }

    /**
     * Locate a binary (ffmpeg, ffprobe, etc.).
     */
    private function findBinary(string $name): ?string
    {
        $path = trim(exec("which {$name} 2>/dev/null"));
        if ($path) {
            return $path;
        }

        foreach (["/opt/homebrew/bin/{$name}", "/usr/local/bin/{$name}", "/usr/bin/{$name}"] as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
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
