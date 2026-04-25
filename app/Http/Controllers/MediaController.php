<?php

namespace App\Http\Controllers;

use App\Concerns\ProcessesImages;
use App\Models\MediaFile;
use App\Models\MediaFolder;
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
                'width' => $mf->width,
                'height' => $mf->height,
                'source' => $mf->source,
                'description_fr' => $mf->description_fr,
                'thematic_tags' => $mf->thematic_tags ?? [],
                'people_ids' => $mf->people_ids ?? [],
                'pool_suggested' => $mf->pool_suggested,
                'allow_wildycaro' => (bool) $mf->allow_wildycaro,
                'allow_pdc_vantour' => (bool) $mf->allow_pdc_vantour,
                'allow_mamawette' => (bool) $mf->allow_mamawette,
                'intimacy_level' => $mf->intimacy_level,
                'pending_analysis' => (bool) $mf->pending_analysis,
                'source_context' => $mf->source_context,
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
        $unclassifiedCount = MediaFile::where('mime_type', 'like', 'image/%')
            ->where('allow_wildycaro', false)
            ->where('allow_pdc_vantour', false)
            ->where('allow_mamawette', false)
            ->where('intimacy_level', '!=', 'never_publish')
            ->count();
        $currentFolder = $folderId;

        return view('media.index', compact(
            'items', 'mediaPostMap', 'filter', 'imageCount', 'videoCount',
            'folders', 'totalCount', 'uncategorizedCount', 'unclassifiedCount', 'currentFolder'
        ));
    }

    /**
     * Upload a media file (AJAX). Images are compressed with GD.
     */
    public function upload(Request $request): JsonResponse
    {
        $file = $request->file('file');
        $mimeType = $file?->getMimeType() ?? '';
        $isImage = str_starts_with($mimeType, 'image/');

        // Read max upload size from app settings (MB → KB).
        $maxKb = $isImage
            ? (int) Setting::get('image_max_upload_mb', 50) * 1024
            : (int) Setting::get('video_max_upload_mb', 500) * 1024;

        $request->validate([
            'file' => "required|file|max:{$maxKb}|mimes:jpeg,jpg,png,gif,webp,mp4,mov,avi,webm",
            'folder_id' => 'nullable|exists:media_folders,id',
        ]);

        $originalName = $file->getClientOriginalName();
        $folderId = $request->input('folder_id');

        // Generate unique filename
        $extension = $isImage ? $this->outputExtension($mimeType, $file->getRealPath()) : $file->getClientOriginalExtension();
        $filename = date('Ymd_His').'_'.Str::random(8).'.'.$extension;

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

        // Video: store, then compress to MP4 H.264/AAC using app settings.
        $file->storeAs('media', $filename, 'local');

        $compressed = $this->compressVideo($filename);
        if ($compressed) {
            $filename = $compressed['filename'];
            $mimeType = 'video/mp4';
        }

        $storedPath = Storage::disk('local')->path("media/{$filename}");
        $storedSize = Storage::disk('local')->size("media/{$filename}");
        $resolution = $this->getVideoResolution($storedPath);

        MediaFile::create([
            'folder_id' => $folderId,
            'filename' => $filename,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'size' => $storedSize,
            'width' => $resolution['width'] ?: null,
            'height' => $resolution['height'] ?: null,
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
     * Recherche dans les banques d'images externes (Pexels, Pixabay, Unsplash).
     * Web route — session auth, accessible depuis l'éditeur de threads.
     * Les images ne sont jamais stockées localement, on retourne les URLs et l'attribution.
     */
    public function searchStockPhotos(Request $request, \App\Services\StockPhotoService $stock): JsonResponse
    {
        $params = $request->validate([
            'q' => 'required|string|min:1|max:100',
            'limit' => 'nullable|integer|min:1|max:30',
            'source' => 'nullable|in:pexels,pixabay,unsplash,all',
        ]);

        $query = trim($params['q']);
        $limit = $params['limit'] ?? 8;
        $source = $params['source'] ?? 'all';

        $results = match ($source) {
            'pexels' => $stock->searchPexels($query, $limit),
            'pixabay' => $stock->searchPixabay($query, $limit),
            'unsplash' => $stock->searchUnsplash($query, $limit),
            default => $stock->searchAll($query, $limit),
        };

        return response()->json([
            'query' => $query,
            'count' => count($results),
            'available_providers' => $stock->availableProviders(),
            'results' => $results,
        ]);
    }

    /**
     * Liste les photos non classées (allow_wildycaro=false ET allow_pdc_vantour=false).
     * Utilisée par Caroline (uploads sans pool) et pour rattraper les photos legacy.
     */
    public function unclassified(Request $request): View
    {
        $perPage = (int) $request->input('per_page', 48);
        $perPage = max(12, min($perPage, 200));

        $query = MediaFile::query()
            ->where('mime_type', 'like', 'image/%')
            ->where('allow_wildycaro', false)
            ->where('allow_pdc_vantour', false)
            ->where('allow_mamawette', false)
            ->where('intimacy_level', '!=', 'never_publish')
            ->latest('id');

        $items = $query->paginate($perPage)->through(fn (MediaFile $m) => [
            'id' => $m->id,
            'filename' => $m->filename,
            'url' => "/media/{$m->filename}",
            'original_name' => $m->original_name,
            'description_fr' => $m->description_fr,
            'thematic_tags' => $m->thematic_tags ?? [],
            'people_ids' => $m->people_ids ?? [],
            'pool_suggested' => $m->pool_suggested,
            'pending_analysis' => (bool) $m->pending_analysis,
            'source' => $m->source,
        ]);

        $totalUnclassified = MediaFile::where('mime_type', 'like', 'image/%')
            ->where('allow_wildycaro', false)
            ->where('allow_pdc_vantour', false)
            ->where('allow_mamawette', false)
            ->where('intimacy_level', '!=', 'never_publish')
            ->count();

        return view('media.unclassified', [
            'items' => $items,
            'totalUnclassified' => $totalUnclassified,
        ]);
    }

    /**
     * Classe une photo dans un pool depuis la vue "Photos à classer".
     */
    public function classify(Request $request, MediaFile $media): JsonResponse
    {
        $data = $request->validate([
            'action' => 'required|in:wildycaro,pdc_vantour,mamawette,never_publish',
            'intimacy_level' => 'nullable|in:public,prive,never_publish',
        ]);

        // Chaque pool a son intimacy par défaut. mamawette est privé par construction
        // (compte privé), les autres sont publics.
        $update = match ($data['action']) {
            'wildycaro' => [
                'allow_wildycaro' => true, 'allow_pdc_vantour' => false, 'allow_mamawette' => false,
                'intimacy_level' => 'public',
            ],
            'pdc_vantour' => [
                'allow_wildycaro' => false, 'allow_pdc_vantour' => true, 'allow_mamawette' => false,
                'intimacy_level' => 'public',
            ],
            'mamawette' => [
                'allow_wildycaro' => false, 'allow_pdc_vantour' => false, 'allow_mamawette' => true,
                'intimacy_level' => 'prive',
            ],
            'never_publish' => ['intimacy_level' => 'never_publish'],
        };

        if (! empty($data['intimacy_level']) && $data['action'] !== 'never_publish') {
            $update['intimacy_level'] = $data['intimacy_level'];
        }

        $media->update($update);

        return response()->json([
            'id' => $media->id,
            'allow_wildycaro' => $media->allow_wildycaro,
            'allow_pdc_vantour' => $media->allow_pdc_vantour,
            'allow_mamawette' => $media->allow_mamawette,
            'intimacy_level' => $media->intimacy_level,
        ]);
    }

    /**
     * Classe plusieurs photos d'un coup dans le même pool.
     */
    public function classifyBatch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'integer',
            'action' => 'required|in:wildycaro,pdc_vantour,mamawette,never_publish',
        ]);

        $update = match ($data['action']) {
            'wildycaro' => [
                'allow_wildycaro' => true, 'allow_pdc_vantour' => false, 'allow_mamawette' => false,
                'intimacy_level' => 'public',
            ],
            'pdc_vantour' => [
                'allow_wildycaro' => false, 'allow_pdc_vantour' => true, 'allow_mamawette' => false,
                'intimacy_level' => 'public',
            ],
            'mamawette' => [
                'allow_wildycaro' => false, 'allow_pdc_vantour' => false, 'allow_mamawette' => true,
                'intimacy_level' => 'prive',
            ],
            'never_publish' => ['intimacy_level' => 'never_publish'],
        };

        $count = MediaFile::whereIn('id', $data['ids'])->update($update);

        return response()->json([
            'count' => $count,
            'ids' => $data['ids'],
            'action' => $data['action'],
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

        $thumbFilename = pathinfo($filename, PATHINFO_FILENAME).'.jpg';
        $thumbPath = $thumbDir.'/'.$thumbFilename;

        if (! file_exists($thumbPath)) {
            $ffmpeg = $this->findBinary('ffmpeg');
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
     * Get video resolution (width, height) using ffprobe.
     */
    private function getVideoResolution(string $filePath): array
    {
        $ffprobe = $this->findBinary('ffprobe');
        if (! $ffprobe) {
            return ['width' => 0, 'height' => 0];
        }

        $output = [];
        exec(sprintf(
            '%s -v quiet -select_streams v:0 -show_entries stream=width,height -of csv=p=0 %s 2>/dev/null',
            escapeshellarg($ffprobe),
            escapeshellarg($filePath)
        ), $output);

        $parts = explode(',', trim($output[0] ?? ''));

        return [
            'width' => (int) ($parts[0] ?? 0),
            'height' => (int) ($parts[1] ?? 0),
        ];
    }

    /**
     * Compress video to MP4 H.264/AAC using ffmpeg and app settings.
     */
    private function compressVideo(string $filename): ?array
    {
        $ffmpeg = $this->findBinary('ffmpeg');
        if (! $ffmpeg) {
            return null;
        }

        $originalPath = Storage::disk('local')->path("media/{$filename}");
        $originalSize = filesize($originalPath);
        $mp4Filename = pathinfo($filename, PATHINFO_FILENAME).'.mp4';

        // Read compression settings.
        $bitrate1080 = (int) Setting::get('video_bitrate_1080p', 6000);
        $bitrate720 = (int) Setting::get('video_bitrate_720p', 2500);
        $audioBitrate = (int) Setting::get('video_audio_bitrate', 128);

        // Choose bitrate based on resolution.
        $resolution = $this->getVideoResolution($originalPath);
        $videoBitrate = $resolution['height'] >= 1080 ? $bitrate1080 : $bitrate720;
        $maxRate = (int) ($videoBitrate * 1.5);
        $bufSize = $videoBitrate * 2;

        // Use temp file if input is already .mp4.
        $sameFile = strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'mp4';
        $outputPath = $sameFile
            ? Storage::disk('local')->path("media/{$mp4Filename}.tmp.mp4")
            : Storage::disk('local')->path("media/{$mp4Filename}");

        // Cap longest side to 1920 (Meta/IG Reels max). Preserves aspect ratio,
        // never upscales, forces even dimensions required by libx264.
        $scaleFilter = "scale='if(gt(iw,ih),min(1920,iw),-2)':'if(gt(iw,ih),-2,min(1920,ih))'";

        exec(sprintf(
            '%s -i %s -vf %s -c:v libx264 -preset fast -b:v %dk -maxrate %dk -bufsize %dk -c:a aac -b:a %dk -movflags +faststart -y %s 2>&1',
            escapeshellarg($ffmpeg),
            escapeshellarg($originalPath),
            escapeshellarg($scaleFilter),
            $videoBitrate,
            $maxRate,
            $bufSize,
            $audioBitrate,
            escapeshellarg($outputPath)
        ), $output, $returnCode);

        if ($returnCode === 0 && file_exists($outputPath) && filesize($outputPath) > 0) {
            $compressedSize = filesize($outputPath);

            // If source was already ≤1080p (Meta-compatible) AND compression didn't shrink it,
            // keep original — no downscale happened, just a re-encode that didn't help.
            $wasMetaCompatible = max($resolution['width'], $resolution['height']) <= 1920;

            if ($wasMetaCompatible && $compressedSize >= $originalSize && strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'mp4') {
                @unlink($outputPath);

                return null;
            }

            if ($sameFile) {
                @unlink($originalPath);
                rename($outputPath, Storage::disk('local')->path("media/{$mp4Filename}"));
            } else {
                @unlink($originalPath);
            }

            return ['filename' => $mp4Filename];
        }

        // Compression failed — keep original.
        @unlink($outputPath);

        return null;
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
            return round($bytes / 1048576, 1).' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 0).' KB';
        }

        return $bytes.' B';
    }
}
