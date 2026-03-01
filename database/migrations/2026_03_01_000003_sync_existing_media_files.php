<?php

use App\Models\MediaFile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        $disk = Storage::disk('local');

        if (! $disk->exists('media')) {
            return;
        }

        $files = $disk->files('media');
        $existingFilenames = MediaFile::pluck('filename')->flip();

        foreach ($files as $path) {
            $filename = basename($path);

            if (str_starts_with($filename, '.') || $existingFilenames->has($filename)) {
                continue;
            }

            $fullPath = $disk->path($path);
            $mimeType = $disk->mimeType($path);
            $size = $disk->size($path);

            $width = null;
            $height = null;
            if (str_starts_with($mimeType, 'image/')) {
                $imageInfo = @getimagesize($fullPath);
                if ($imageInfo) {
                    $width = $imageInfo[0];
                    $height = $imageInfo[1];
                }
            }

            MediaFile::create([
                'filename' => $filename,
                'original_name' => $filename,
                'mime_type' => $mimeType,
                'size' => $size,
                'width' => $width,
                'height' => $height,
                'source' => 'upload',
            ]);
        }
    }

    public function down(): void
    {
        // No rollback — files stay in DB
    }
};
