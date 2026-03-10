<?php

namespace App\Services;

use App\Concerns\ProcessesImages;
use App\Models\MediaFile;
use App\Models\Setting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaStudioService
{
    use ProcessesImages;

    /**
     * Process a video: re-encode to 9:16 vertical, add logo/text overlay, strip metadata.
     */
    public function processVideo(string $sourcePath, array $options = []): array
    {
        $ffmpeg = $this->findBinary('ffmpeg');
        if (! $ffmpeg) {
            return ['success' => false, 'error' => 'FFmpeg non disponible sur ce serveur.'];
        }

        $ffprobe = $this->findBinary('ffprobe');

        // Output filename
        $filename = date('Ymd_His') . '_studio_' . Str::random(8) . '.mp4';
        $outputPath = Storage::disk('local')->path("media/{$filename}");

        // Ensure media dir exists
        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Options
        $format = $options['format'] ?? 'vertical'; // vertical (9:16), square (1:1), original
        $logoEnabled = $options['logo_enabled'] ?? false;
        $logoPath = $options['logo_path'] ?? null;
        $textEnabled = $options['text_enabled'] ?? false;
        $textContent = $options['text_content'] ?? '';
        $stripMetadata = $options['strip_metadata'] ?? true;
        $crf = (int) ($options['crf'] ?? Setting::get('studio_video_crf', 28));

        // Detect audio
        $hasAudio = $this->hasAudioStream($ffprobe, $sourcePath);

        // Build FFmpeg command
        $cmd = [$ffmpeg, '-i', $sourcePath];

        // Add logo as second input if needed
        $logoFile = null;
        if ($logoEnabled && $logoPath) {
            $logoFile = Storage::disk('local')->path($logoPath);
            if (file_exists($logoFile)) {
                $cmd = array_merge($cmd, ['-i', $logoFile]);
            } else {
                $logoEnabled = false;
            }
        }

        $cmd[] = '-y';

        // Strip metadata
        if ($stripMetadata) {
            $cmd = array_merge($cmd, ['-map_metadata', '-1']);
        }

        // Build filter chain
        $videoFilters = [];

        // Format conversion (crop to aspect ratio)
        if ($format === 'vertical') {
            // 9:16 - crop center then scale to 1080x1920
            $videoFilters[] = "scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920";
        } elseif ($format === 'square') {
            // 1:1 - crop center then scale to 1080x1080
            $videoFilters[] = "scale=1080:1080:force_original_aspect_ratio=increase,crop=1080:1080";
        }
        // 'original' = no scaling/cropping

        // Text overlay
        if ($textEnabled && $textContent !== '') {
            $escapedText = str_replace(["'", ":", "\\"], ["'\\''", "\\:", "\\\\"], $textContent);
            $fontSize = (int) Setting::get('studio_text_font_size', 28);
            $textX = (int) Setting::get('studio_text_x', 65);
            $textY = (int) Setting::get('studio_text_y', 35);
            $videoFilters[] = "drawtext=text='{$escapedText}':fontsize={$fontSize}:fontcolor=white:x={$textX}:y={$textY}:shadowcolor=black@0.5:shadowx=1:shadowy=1";
        }

        // Assemble filter_complex or -vf
        if ($logoEnabled && $logoFile && file_exists($logoFile)) {
            $logoSize = (int) Setting::get('studio_logo_size', 50);
            $logoX = (int) Setting::get('studio_logo_x', 20);
            $logoY = (int) Setting::get('studio_logo_y', 35);

            $videoChain = ! empty($videoFilters) ? ',' . implode(',', $videoFilters) : '';
            $filterComplex = "[0:v]{$videoChain}[vid];[1:v]scale={$logoSize}:{$logoSize}[logo];[vid][logo]overlay={$logoX}:{$logoY}[v]";

            // Remove leading comma if no prior filters
            $filterComplex = str_replace('[0:v],[', '[0:v][', $filterComplex);

            $cmd = array_merge($cmd, ['-filter_complex', $filterComplex]);
            $cmd = array_merge($cmd, ['-map', '[v]']);
            if ($hasAudio) {
                $cmd = array_merge($cmd, ['-map', '0:a']);
            }
        } elseif (! empty($videoFilters)) {
            $cmd = array_merge($cmd, ['-vf', implode(',', $videoFilters)]);
        }

        // Encoding parameters
        $cmd = array_merge($cmd, [
            '-c:v', 'libx264',
            '-preset', 'medium',
            '-crf', (string) $crf,
        ]);

        if ($hasAudio) {
            $audioBitrate = Setting::get('studio_audio_bitrate', '96');
            $cmd = array_merge($cmd, [
                '-c:a', 'aac',
                '-b:a', $audioBitrate . 'k',
                '-ar', '44100',
            ]);
        }

        $cmd = array_merge($cmd, [
            '-pix_fmt', 'yuv420p',
            '-movflags', '+faststart',
            $outputPath,
        ]);

        // Execute
        $process = proc_open(
            implode(' ', array_map('escapeshellarg', $cmd)),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (! is_resource($process)) {
            return ['success' => false, 'error' => 'Impossible de lancer FFmpeg.'];
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $returnCode = proc_close($process);

        if ($returnCode !== 0 || ! file_exists($outputPath) || filesize($outputPath) === 0) {
            @unlink($outputPath);

            return ['success' => false, 'error' => 'Erreur FFmpeg: ' . substr($stderr, -500)];
        }

        // Get resolution
        $resolution = $this->getVideoResolution($ffprobe, $outputPath);

        // Create MediaFile record
        $mediaFile = MediaFile::create([
            'folder_id' => $options['folder_id'] ?? null,
            'filename' => $filename,
            'original_name' => $options['original_name'] ?? $filename,
            'mime_type' => 'video/mp4',
            'size' => filesize($outputPath),
            'width' => $resolution['width'] ?: null,
            'height' => $resolution['height'] ?: null,
            'source' => 'studio',
        ]);

        return [
            'success' => true,
            'filename' => $filename,
            'url' => "/media/{$filename}",
            'mimetype' => 'video/mp4',
            'size' => filesize($outputPath),
            'width' => $resolution['width'],
            'height' => $resolution['height'],
            'media_file' => $mediaFile,
        ];
    }

    /**
     * Process a photo: strip EXIF, add watermark, save to media library.
     */
    public function processPhoto(string $sourcePath, string $mimeType, array $options = []): array
    {
        $stripExif = $options['strip_exif'] ?? true;
        $watermarkEnabled = $options['watermark_enabled'] ?? false;
        $watermarkText = $options['watermark_text'] ?? '';

        // Load image
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

        $width = imagesx($image);
        $height = imagesy($image);

        // Add watermark text
        if ($watermarkEnabled && $watermarkText !== '') {
            $fontSize = max(12, (int) ($width * 0.025)); // 2.5% of width
            $color = imagecolorallocatealpha($image, 255, 255, 255, 40); // semi-transparent white
            $shadow = imagecolorallocatealpha($image, 0, 0, 0, 60); // semi-transparent black

            // Position: bottom-right corner
            $bbox = imagettfbbox($fontSize, 0, $this->findSystemFont(), $watermarkText);
            if ($bbox) {
                $textWidth = $bbox[2] - $bbox[0];
                $x = $width - $textWidth - 20;
                $y = $height - 20;
                $font = $this->findSystemFont();
                imagettftext($image, $fontSize, 0, $x + 1, $y + 1, $shadow, $font, $watermarkText);
                imagettftext($image, $fontSize, 0, $x, $y, $color, $font, $watermarkText);
            }
        }

        // Determine output format (always JPEG for EXIF stripping, unless PNG with transparency)
        $outputMime = $mimeType;
        if ($stripExif && $mimeType !== 'image/png') {
            $outputMime = 'image/jpeg';
        }

        $extension = match ($outputMime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        $filename = date('Ymd_His') . '_studio_' . Str::random(8) . '.' . $extension;
        $storagePath = Storage::disk('local')->path("media/{$filename}");
        $dir = dirname($storagePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Save (this inherently strips EXIF since GD doesn't preserve it)
        match ($outputMime) {
            'image/png' => imagepng($image, $storagePath, 8),
            'image/webp' => imagewebp($image, $storagePath, 90),
            default => imagejpeg($image, $storagePath, 90),
        };

        $finalWidth = imagesx($image);
        $finalHeight = imagesy($image);
        imagedestroy($image);

        $mediaFile = MediaFile::create([
            'folder_id' => $options['folder_id'] ?? null,
            'filename' => $filename,
            'original_name' => $options['original_name'] ?? $filename,
            'mime_type' => $outputMime,
            'size' => filesize($storagePath),
            'width' => $finalWidth,
            'height' => $finalHeight,
            'source' => 'studio',
        ]);

        return [
            'success' => true,
            'filename' => $filename,
            'url' => "/media/{$filename}",
            'mimetype' => $outputMime,
            'size' => filesize($storagePath),
            'width' => $finalWidth,
            'height' => $finalHeight,
            'media_file' => $mediaFile,
        ];
    }

    private function hasAudioStream(?string $ffprobe, string $path): bool
    {
        if (! $ffprobe) {
            return false;
        }

        $cmd = sprintf(
            '%s -v quiet -show_streams -select_streams a -of csv=p=0 %s',
            escapeshellarg($ffprobe),
            escapeshellarg($path)
        );

        exec($cmd, $output, $returnCode);

        return $returnCode === 0 && ! empty(trim(implode('', $output)));
    }

    private function getVideoResolution(?string $ffprobe, string $path): array
    {
        if (! $ffprobe) {
            return ['width' => null, 'height' => null];
        }

        $cmd = sprintf(
            '%s -v quiet -select_streams v:0 -show_entries stream=width,height -of csv=p=0 %s',
            escapeshellarg($ffprobe),
            escapeshellarg($path)
        );

        exec($cmd, $output);
        $parts = explode(',', trim($output[0] ?? ''));

        return [
            'width' => (int) ($parts[0] ?? 0) ?: null,
            'height' => (int) ($parts[1] ?? 0) ?: null,
        ];
    }

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

    private function findSystemFont(): string
    {
        $candidates = [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/TTF/DejaVuSans.ttf',
            '/System/Library/Fonts/Helvetica.ttc',
            '/System/Library/Fonts/SFNSText.ttf',
        ];

        foreach ($candidates as $font) {
            if (file_exists($font)) {
                return $font;
            }
        }

        return '5'; // GD built-in font fallback
    }
}
