<?php

namespace App\Concerns;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

trait ProcessesImages
{
    private function maxImageDimension(): int
    {
        return (int) Setting::get('image_max_dimension', 2048);
    }

    private function imageTargetMinKb(): int
    {
        return (int) Setting::get('image_target_min_kb', 200);
    }

    private function imageTargetMaxKb(): int
    {
        return (int) Setting::get('image_target_max_kb', 500);
    }

    private function imageMinQuality(): int
    {
        return (int) Setting::get('image_min_quality', 60);
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
            $filename = preg_replace('/\.png$/i', '.jpg', $filename);
            $storagePath = Storage::disk('local')->path("media/{$filename}");
            $outputMime = 'image/jpeg';
        }

        $targetMinBytes = $this->imageTargetMinKb() * 1024;
        $targetMaxBytes = $this->imageTargetMaxKb() * 1024;
        $minQuality = $this->imageMinQuality();

        if ($outputMime === 'image/png') {
            imagepng($image, $storagePath, 8);
            imagedestroy($image);
            $saved = true;
        } elseif ($outputMime === 'image/gif') {
            imagegif($image, $storagePath);
            imagedestroy($image);
            $saved = true;
        } else {
            // JPEG/WebP: adaptive compression between target min and max
            $saveFunc = $outputMime === 'image/webp' ? 'imagewebp' : 'imagejpeg';

            // First pass at max quality (90%)
            $saveFunc($image, $storagePath, 90);
            $fileSize = filesize($storagePath);

            if ($fileSize <= $targetMaxBytes) {
                // Already under max target — keep at 90%
                imagedestroy($image);
                $saved = true;
            } else {
                // Binary search for the right quality between minQuality and 85
                $low = $minQuality;
                $high = 85;
                $bestQuality = $minQuality;

                while ($low <= $high) {
                    $mid = (int) (($low + $high) / 2);
                    $saveFunc($image, $storagePath, $mid);
                    clearstatcache(true, $storagePath);
                    $fileSize = filesize($storagePath);

                    if ($fileSize <= $targetMaxBytes && $fileSize >= $targetMinBytes) {
                        // In the sweet spot
                        $bestQuality = $mid;
                        break;
                    } elseif ($fileSize > $targetMaxBytes) {
                        // Too big, lower quality
                        $high = $mid - 1;
                        $bestQuality = $mid;
                    } else {
                        // Too small, raise quality
                        $low = $mid + 1;
                        $bestQuality = $mid;
                    }
                }

                // Final save at best quality found
                $saveFunc($image, $storagePath, $bestQuality);
                imagedestroy($image);
                $saved = true;
            }
        }

        if (! $saved) {
            return ['success' => false, 'error' => 'Erreur lors de la compression de l\'image.'];
        }

        return [
            'success' => true,
            'filename' => $filename,
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
}
