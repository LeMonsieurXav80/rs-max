<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class RecompressImagesCommand extends Command
{
    protected $signature = 'media:recompress {--dry-run : Show what would be done without changing files}';

    protected $description = 'Recompress existing images using current settings (target min/max size + min quality)';

    public function handle(): int
    {
        $mediaPath = Storage::disk('local')->path('media');
        $dryRun = $this->option('dry-run');

        $maxDim = (int) Setting::get('image_max_dimension', 2048);
        $targetMinBytes = (int) Setting::get('image_target_min_kb', 200) * 1024;
        $targetMaxBytes = (int) Setting::get('image_target_max_kb', 500) * 1024;
        $minQuality = (int) Setting::get('image_min_quality', 60);

        $this->info("Settings: max {$maxDim}px, target " . ($targetMinBytes / 1024) . '-' . ($targetMaxBytes / 1024) . "KB, min quality {$minQuality}%");
        if ($dryRun) {
            $this->warn('DRY RUN - no files will be modified');
        }
        $this->newLine();

        $files = glob($mediaPath . '/*.{jpg,jpeg,png}', GLOB_BRACE);
        $totalBefore = 0;
        $totalAfter = 0;
        $processed = 0;
        $skipped = 0;

        foreach ($files as $filePath) {
            $filename = basename($filePath);
            $sizeBefore = filesize($filePath);
            $totalBefore += $sizeBefore;

            // Already in the sweet spot
            if ($sizeBefore >= $targetMinBytes && $sizeBefore <= $targetMaxBytes) {
                $totalAfter += $sizeBefore;
                $skipped++;
                continue;
            }

            // Under min target — already small enough, skip
            if ($sizeBefore < $targetMinBytes) {
                $totalAfter += $sizeBefore;
                $skipped++;
                continue;
            }

            $mimeType = mime_content_type($filePath);
            $image = match ($mimeType) {
                'image/jpeg' => @imagecreatefromjpeg($filePath),
                'image/png' => @imagecreatefrompng($filePath),
                default => null,
            };

            if (! $image) {
                $this->warn("  Skip {$filename} (cannot read)");
                $totalAfter += $sizeBefore;
                $skipped++;
                continue;
            }

            $origWidth = imagesx($image);
            $origHeight = imagesy($image);

            // Resize if needed
            if ($origWidth > $maxDim || $origHeight > $maxDim) {
                if ($origWidth >= $origHeight) {
                    $newWidth = $maxDim;
                    $newHeight = (int) round($origHeight * ($maxDim / $origWidth));
                } else {
                    $newHeight = $maxDim;
                    $newWidth = (int) round($origWidth * ($maxDim / $origHeight));
                }

                $resized = imagecreatetruecolor($newWidth, $newHeight);
                imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
                imagedestroy($image);
                $image = $resized;
            }

            $outputPath = $dryRun ? tempnam(sys_get_temp_dir(), 'rsmax_') : $filePath;

            // Binary search for optimal quality
            $bestQuality = $this->findOptimalQuality($image, $outputPath, $targetMinBytes, $targetMaxBytes, $minQuality);

            if ($dryRun) {
                $sizeAfter = filesize($outputPath);
                unlink($outputPath);
            } else {
                clearstatcache(true, $filePath);
                $sizeAfter = filesize($filePath);
            }

            imagedestroy($image);

            $reduction = round((1 - $sizeAfter / $sizeBefore) * 100);
            $this->line("  {$filename}: " . $this->formatSize($sizeBefore) . " -> " . $this->formatSize($sizeAfter) . " (-{$reduction}%) @ q{$bestQuality}");
            $totalAfter += $sizeAfter;
            $processed++;
        }

        $this->newLine();
        $this->info("Processed: {$processed} | Skipped (already OK): {$skipped}");
        if ($totalBefore > 0) {
            $this->info('Total: ' . $this->formatSize($totalBefore) . ' -> ' . $this->formatSize($totalAfter) . ' (-' . round((1 - $totalAfter / $totalBefore) * 100) . '%)');
        }

        return Command::SUCCESS;
    }

    private function findOptimalQuality($image, string $path, int $minBytes, int $maxBytes, int $minQuality): int
    {
        // First try at 90%
        imagejpeg($image, $path, 90);
        clearstatcache(true, $path);
        if (filesize($path) <= $maxBytes) {
            return 90;
        }

        // Binary search between minQuality and 85
        $low = $minQuality;
        $high = 85;
        $bestQuality = $minQuality;

        while ($low <= $high) {
            $mid = (int) (($low + $high) / 2);
            imagejpeg($image, $path, $mid);
            clearstatcache(true, $path);
            $fileSize = filesize($path);

            if ($fileSize >= $minBytes && $fileSize <= $maxBytes) {
                $bestQuality = $mid;
                break;
            } elseif ($fileSize > $maxBytes) {
                $high = $mid - 1;
                $bestQuality = $mid;
            } else {
                $low = $mid + 1;
                $bestQuality = $mid;
            }
        }

        // Final save at best quality
        imagejpeg($image, $path, $bestQuality);

        return $bestQuality;
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        return round($bytes / 1024) . ' KB';
    }
}
