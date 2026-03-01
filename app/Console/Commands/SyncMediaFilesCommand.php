<?php

namespace App\Console\Commands;

use App\Models\MediaFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class SyncMediaFilesCommand extends Command
{
    protected $signature = 'media:sync {--dry-run : Show what would be done without doing it}';

    protected $description = 'Sync physical media files to the media_files database table';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $files = $disk->files('media');
        $dryRun = $this->option('dry-run');

        $existingFilenames = MediaFile::pluck('filename')->flip();

        $created = 0;
        $skipped = 0;

        foreach ($files as $path) {
            $filename = basename($path);

            // Skip thumbnails directory files.
            if (str_starts_with($filename, '.')) {
                $skipped++;
                continue;
            }

            if ($existingFilenames->has($filename)) {
                $skipped++;
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

            if ($dryRun) {
                $this->line("Would create: {$filename} ({$mimeType}, {$size} bytes)");
            } else {
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

            $created++;
        }

        $action = $dryRun ? 'Would sync' : 'Synced';
        $this->info("{$action} {$created} files. Skipped {$skipped} (already in DB or hidden).");

        return self::SUCCESS;
    }
}
