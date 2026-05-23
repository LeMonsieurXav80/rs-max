<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Log;

/**
 * Normalise les videos pour qu'elles soient acceptees par toutes les plateformes
 * cibles (Instagram, Bluesky, Telegram, Twitter…).
 *
 * Probleme racine : Meta refuse les pixel formats 10-bit (yuv420p10le, High 10),
 * Bluesky plafonne a 50 MB, Telegram bot API a 50 MB. Le normalizer :
 *  - sonde le fichier via ffprobe ;
 *  - decide si une normalisation est necessaire (incompatibilite codec/pix_fmt
 *    OU taille au-dessus du seuil OU resolution > 1920) ;
 *  - transcode en H.264 high profile / yuv420p / AAC avec une strategie qualite
 *    maximale (CRF 20 + maxrate calcule pour viser la taille cible).
 *
 * NOTE : `TwitterAdapter::compressVideo` fait une compression beaucoup plus
 * agressive (cap 800 kbps pour rester sous 5 MB free tier) ; il pourrait
 * appeler ce service en passant un `maxSizeMb` plus petit, mais ce n'est pas
 * fait pour le moment afin de ne pas modifier son comportement teste.
 */
class VideoNormalizer
{
    /**
     * Sonde un fichier video via ffprobe et renvoie un tableau de metadonnees :
     * codec_name, profile, pix_fmt, width, height, duration, bit_rate, size.
     * Retourne un tableau vide si ffprobe est indisponible ou en cas d'echec.
     */
    public function analyze(string $absolutePath): array
    {
        $ffprobe = $this->findBinary('ffprobe');
        if (! $ffprobe || ! is_file($absolutePath)) {
            return [];
        }

        $cmd = sprintf(
            '%s -v error -select_streams v:0 -show_entries stream=codec_name,profile,pix_fmt,width,height,duration,bit_rate -of default=nw=1 %s 2>/dev/null',
            escapeshellarg($ffprobe),
            escapeshellarg($absolutePath)
        );

        $output = [];
        exec($cmd, $output);

        $meta = [];
        foreach ($output as $line) {
            if (str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
                $meta[trim($k)] = trim($v);
            }
        }

        $meta['size'] = filesize($absolutePath) ?: 0;
        $meta['width'] = (int) ($meta['width'] ?? 0);
        $meta['height'] = (int) ($meta['height'] ?? 0);
        $meta['duration'] = (float) ($meta['duration'] ?? 0);

        return $meta;
    }

    /**
     * Liste les raisons pour lesquelles une video doit etre normalisee. Tableau
     * vide = aucune normalisation requise (compatible Meta + sous le seuil).
     */
    public function needsNormalization(array $meta, int $maxSizeMb = 50): array
    {
        $reasons = [];
        $pixFmt = $meta['pix_fmt'] ?? '';
        $profile = $meta['profile'] ?? '';
        $codec = $meta['codec_name'] ?? '';
        $sizeMb = ($meta['size'] ?? 0) / 1048576;
        $longestSide = max($meta['width'] ?? 0, $meta['height'] ?? 0);

        if ($codec && $codec !== 'h264') {
            $reasons[] = "codec={$codec} (attendu h264)";
        }

        if ($pixFmt && $pixFmt !== 'yuv420p') {
            $reasons[] = "pix_fmt={$pixFmt} (attendu yuv420p)";
        }

        // High 10, High 4:2:2, High 4:4:4 → tous refuses par Meta.
        if ($profile && (stripos($profile, '10') !== false || str_contains($profile, '422') || str_contains($profile, '444'))) {
            $reasons[] = "profile={$profile} incompatible";
        }

        if ($sizeMb > $maxSizeMb) {
            $reasons[] = sprintf('%.1f MB > %d MB', $sizeMb, $maxSizeMb);
        }

        if ($longestSide > 1920) {
            $reasons[] = "{$longestSide}px > 1920";
        }

        return $reasons;
    }

    /**
     * Transcode la video pour la rendre compatible avec toutes les plateformes
     * tout en preservant le plus de qualite possible.
     *
     * Strategie :
     *  - `-c:v libx264 -profile:v high -pix_fmt yuv420p` : compatibilite Meta/IG ;
     *  - `-preset slow -crf 20` : qualite quasi-visuellement-sans-perte ;
     *  - `-maxrate Xk -bufsize 2Xk` : plafond calcule pour viser `maxSizeMb` ;
     *    si CRF 20 produit moins, on garde la qualite CRF (cas typique) ;
     *  - scale max 1920 sur le plus long cote (preserve l'aspect, dimensions paires).
     *
     * @return bool true si le fichier de sortie a ete cree avec succes
     */
    public function normalize(string $inputPath, string $outputPath, int $maxSizeMb = 50, int $audioKbps = 128): bool
    {
        $ffmpeg = $this->findBinary('ffmpeg');
        if (! $ffmpeg || ! is_file($inputPath)) {
            return false;
        }

        $meta = $this->analyze($inputPath);
        $duration = max(1.0, (float) ($meta['duration'] ?? 0));

        // Budget bitrate video pour viser maxSizeMb (marge 8% pour le conteneur).
        // 1 MB = 8 Mbits ; cible_kbps = (size_mb * 8192 * 0.92) / duration - audio.
        $videoMaxKbps = max(800, (int) round(($maxSizeMb * 8192 * 0.92) / $duration - $audioKbps));

        // Cap longest side to 1920 (limite Meta/IG Reels). Preserve l'aspect,
        // jamais d'upscale, dimensions paires requises par libx264.
        $scaleFilter = "scale='if(gt(iw,ih),min(1920,iw),-2)':'if(gt(iw,ih),-2,min(1920,ih))'";

        $cmd = sprintf(
            '%s -i %s -vf %s '
            .'-c:v libx264 -profile:v high -pix_fmt yuv420p -preset slow '
            .'-crf 20 -maxrate %dk -bufsize %dk '
            .'-c:a aac -b:a %dk '
            .'-movflags +faststart -y %s 2>&1',
            escapeshellarg($ffmpeg),
            escapeshellarg($inputPath),
            escapeshellarg($scaleFilter),
            $videoMaxKbps,
            $videoMaxKbps * 2,
            $audioKbps,
            escapeshellarg($outputPath)
        );

        $output = [];
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 || ! is_file($outputPath) || filesize($outputPath) <= 0) {
            Log::error('VideoNormalizer: ffmpeg failed', [
                'input' => $inputPath,
                'return_code' => $returnCode,
                'tail' => implode("\n", array_slice($output, -10)),
            ]);
            @unlink($outputPath);

            return false;
        }

        return true;
    }

    private function findBinary(string $name): ?string
    {
        $path = trim((string) shell_exec("which {$name} 2>/dev/null"));
        if ($path !== '') {
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
