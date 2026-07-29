<?php

namespace App\Services\Media;

use App\Models\MediaFile;
use Illuminate\Support\Facades\Storage;

/**
 * Génère et localise les vignettes légères (JPEG ~480px) des médias.
 *
 * Une vignette existe pour les images ET les vidéos, stockée sous
 * media/thumbnails/{base}.jpg. Les images passent par GD, les vidéos par ffmpeg
 * (frame extraite ~5s). Utilisé à l'upload, dans le job async, en fallback à la
 * volée dans MediaController::thumbnail(), et par la commande de backfill.
 */
class ThumbnailService
{
    /** Largeur cible de la vignette (le côté le plus long est ramené à cette valeur). */
    public const THUMB_MAX = 480;

    /** Chemin relatif (disque local) de la vignette d'un fichier média. */
    public function relativePath(string $filename): string
    {
        return 'media/thumbnails/'.pathinfo($filename, PATHINFO_FILENAME).'.jpg';
    }

    /** Chemin absolu de la vignette sur le disque local. */
    public function absolutePath(string $filename): string
    {
        return Storage::disk('local')->path($this->relativePath($filename));
    }

    /** La vignette existe-t-elle déjà sur le disque ? */
    public function exists(string $filename): bool
    {
        $path = $this->absolutePath($filename);

        return file_exists($path) && filesize($path) > 0;
    }

    /**
     * Génère la vignette d'un média (si absente, ou si $force).
     * Retourne le chemin relatif en cas de succès, null en cas d'échec.
     */
    public function generate(MediaFile $media, bool $force = false): ?string
    {
        $sourceRel = "media/{$media->filename}";
        if (! Storage::disk('local')->exists($sourceRel)) {
            return null;
        }

        $thumbRel = $this->relativePath($media->filename);
        $thumbAbs = Storage::disk('local')->path($thumbRel);

        if (! $force && file_exists($thumbAbs) && filesize($thumbAbs) > 0) {
            return $thumbRel;
        }

        $dir = dirname($thumbAbs);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $sourceAbs = Storage::disk('local')->path($sourceRel);
        $ok = str_starts_with((string) $media->mime_type, 'video/')
            ? $this->fromVideo($sourceAbs, $thumbAbs)
            : $this->fromImage($sourceAbs, $thumbAbs);

        return $ok ? $thumbRel : null;
    }

    /**
     * Vignette JPEG depuis une image, via GD. Redimensionne le plus grand côté
     * à THUMB_MAX, aplatit la transparence sur fond blanc.
     */
    public function fromImage(string $src, string $dest): bool
    {
        $info = @getimagesize($src);
        if (! $info) {
            return false;
        }

        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
            IMAGETYPE_PNG => @imagecreatefrompng($src),
            IMAGETYPE_GIF => @imagecreatefromgif($src),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : null,
            default => null,
        };
        if (! $image) {
            return false;
        }

        $origW = imagesx($image);
        $origH = imagesy($image);
        $scale = min(1, self::THUMB_MAX / max($origW, $origH));
        $newW = max(1, (int) round($origW * $scale));
        $newH = max(1, (int) round($origH * $scale));

        $thumb = imagecreatetruecolor($newW, $newH);
        // Fond blanc (aplatit la transparence PNG/WebP puisque la sortie est JPEG).
        $white = imagecolorallocate($thumb, 255, 255, 255);
        imagefilledrectangle($thumb, 0, 0, $newW, $newH, $white);
        imagecopyresampled($thumb, $image, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        imagedestroy($image);

        $ok = imagejpeg($thumb, $dest, 80);
        imagedestroy($thumb);

        return $ok && file_exists($dest) && filesize($dest) > 0;
    }

    /**
     * Vignette JPEG depuis une vidéo, via ffmpeg. Tente une frame à ~5s,
     * puis retombe sur la première frame si la vidéo est trop courte.
     */
    public function fromVideo(string $src, string $dest): bool
    {
        $ffmpeg = $this->findBinary('ffmpeg');
        if (! $ffmpeg) {
            return false;
        }

        exec(sprintf(
            '%s -ss 5 -i %s -frames:v 1 -q:v 3 -vf "scale=%d:-1" -update 1 %s 2>/dev/null',
            escapeshellarg($ffmpeg),
            escapeshellarg($src),
            self::THUMB_MAX,
            escapeshellarg($dest)
        ));

        if (! file_exists($dest) || filesize($dest) === 0) {
            exec(sprintf(
                '%s -i %s -frames:v 1 -q:v 3 -vf "scale=%d:-1" -update 1 %s 2>/dev/null',
                escapeshellarg($ffmpeg),
                escapeshellarg($src),
                self::THUMB_MAX,
                escapeshellarg($dest)
            ));
        }

        return file_exists($dest) && filesize($dest) > 0;
    }

    /** Localise un binaire (ffmpeg) via which puis chemins Homebrew/usr classiques. */
    private function findBinary(string $name): ?string
    {
        $path = trim((string) exec("which {$name} 2>/dev/null"));
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
}
