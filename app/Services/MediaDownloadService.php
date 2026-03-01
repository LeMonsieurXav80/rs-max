<?php

namespace App\Services;

use App\Concerns\ProcessesImages;
use App\Models\MediaFile;
use App\Models\MediaFolder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaDownloadService
{
    use ProcessesImages;

    /**
     * Download an image from a URL, compress it, and store it locally.
     *
     * @param  string  $imageUrl  The remote image URL
     * @param  string  $source    Source identifier (og_image, rss, wordpress)
     * @param  int|null  $folderId  Target folder ID (defaults to "Flux Pictures")
     */
    public function downloadAndStore(string $imageUrl, string $source = 'og_image', ?int $folderId = null): ?MediaFile
    {
        // Deduplication: check if we already downloaded this URL.
        $existing = MediaFile::findBySourceUrl($imageUrl);
        if ($existing) {
            return $existing;
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; RS-Max/1.0; +https://rs-max.app)',
                    'Accept' => 'image/*',
                ])
                ->get($imageUrl);

            if (! $response->successful()) {
                Log::info('MediaDownloadService: HTTP error', [
                    'url' => $imageUrl,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $contentType = $response->header('Content-Type', '');
            $mimeType = explode(';', $contentType)[0];

            if (! str_starts_with($mimeType, 'image/')) {
                Log::info('MediaDownloadService: Not an image', [
                    'url' => $imageUrl,
                    'content_type' => $contentType,
                ]);

                return null;
            }

            // Save to temp file.
            $tempPath = tempnam(sys_get_temp_dir(), 'rsmedia_');
            file_put_contents($tempPath, $response->body());

            // Determine extension and filename.
            $extension = $this->outputExtension($mimeType, $tempPath);
            $filename = date('Ymd_His') . '_' . Str::random(8) . '.' . $extension;

            // Compress and resize.
            $result = $this->processImage($tempPath, $mimeType, $filename);
            @unlink($tempPath);

            if (! $result['success']) {
                Log::warning('MediaDownloadService: Image processing failed', [
                    'url' => $imageUrl,
                    'error' => $result['error'],
                ]);

                return null;
            }

            // Use the filename from processImage (may have changed for PNG→JPG).
            $finalFilename = $result['filename'] ?? $filename;

            // Default folder = "Flux Pictures".
            if (! $folderId) {
                $folderId = MediaFolder::ensureDefaultFolder()->id;
            }

            $mediaFile = MediaFile::create([
                'folder_id' => $folderId,
                'filename' => $finalFilename,
                'original_name' => basename(parse_url($imageUrl, PHP_URL_PATH) ?: 'image'),
                'mime_type' => $result['mimetype'],
                'size' => $result['size'],
                'width' => $result['width'],
                'height' => $result['height'],
                'source' => $source,
                'source_url' => $imageUrl,
            ]);

            return $mediaFile;

        } catch (\Exception $e) {
            Log::error('MediaDownloadService: Exception', [
                'url' => $imageUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
