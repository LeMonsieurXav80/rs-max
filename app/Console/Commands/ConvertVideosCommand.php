<?php

namespace App\Console\Commands;

use App\Models\Post;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ConvertVideosCommand extends Command
{
    protected $signature = 'media:convert-videos {--dry-run : Voir ce qui serait fait sans modifier les fichiers}';

    protected $description = 'Convertir toutes les vidéos non-MP4 en MP4 (H.264/AAC) et mettre à jour les références en base';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $mediaPath = Storage::disk('local')->path('media');

        $ffmpeg = $this->findFfmpeg();
        if (! $ffmpeg) {
            $this->error('ffmpeg introuvable. Installez-le pour convertir les vidéos.');

            return Command::FAILURE;
        }

        $this->info("ffmpeg: {$ffmpeg}");
        if ($dryRun) {
            $this->warn('DRY RUN — aucun fichier ne sera modifié');
        }
        $this->newLine();

        // Find all non-MP4 video files
        $extensions = ['mov', 'avi', 'webm', 'mkv', 'MOV', 'AVI', 'WEBM', 'MKV'];
        $videos = [];
        foreach ($extensions as $ext) {
            $videos = array_merge($videos, glob("{$mediaPath}/*.{$ext}"));
        }

        if (empty($videos)) {
            $this->info('Aucune vidéo non-MP4 trouvée.');

            return Command::SUCCESS;
        }

        $this->info(count($videos) . ' vidéo(s) à convertir :');
        $this->newLine();

        $converted = 0;
        $failed = 0;

        foreach ($videos as $videoPath) {
            $filename = basename($videoPath);
            $mp4Filename = pathinfo($filename, PATHINFO_FILENAME) . '.mp4';
            $mp4Path = "{$mediaPath}/{$mp4Filename}";

            $sizeBefore = filesize($videoPath);
            $this->line("  {$filename} (" . $this->formatSize($sizeBefore) . ')');

            if ($dryRun) {
                $this->line("    → serait converti en {$mp4Filename}");
                $this->updatePostReferences($filename, $mp4Filename, 'video/mp4', true);
                $converted++;

                continue;
            }

            // Convert with ffmpeg
            $this->line('    Conversion en cours...');
            exec(sprintf(
                '%s -i %s -c:v libx264 -preset fast -crf 23 -c:a aac -b:a 128k -movflags +faststart -y %s 2>&1',
                escapeshellarg($ffmpeg),
                escapeshellarg($videoPath),
                escapeshellarg($mp4Path)
            ), $output, $returnCode);

            if ($returnCode !== 0 || ! file_exists($mp4Path) || filesize($mp4Path) === 0) {
                $this->error("    ERREUR de conversion pour {$filename}");
                @unlink($mp4Path);
                $failed++;

                continue;
            }

            $sizeAfter = filesize($mp4Path);
            $this->line("    → {$mp4Filename} (" . $this->formatSize($sizeAfter) . ')');

            // Update post references in database
            $updated = $this->updatePostReferences($filename, $mp4Filename, 'video/mp4', false);
            if ($updated > 0) {
                $this->line("    → {$updated} post(s) mis à jour en base");
            }

            // Delete original
            @unlink($videoPath);

            // Delete old thumbnail if exists
            $thumbPath = "{$mediaPath}/thumbnails/" . pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
            if (file_exists($thumbPath)) {
                @unlink($thumbPath);
                $this->line('    → ancien thumbnail supprimé');
            }

            $converted++;
        }

        $this->newLine();
        $this->info("Terminé : {$converted} converti(s), {$failed} erreur(s).");

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function updatePostReferences(string $oldFilename, string $newFilename, string $newMimetype, bool $dryRun): int
    {
        $posts = Post::whereNotNull('media')
            ->where('media', 'LIKE', "%{$oldFilename}%")
            ->get();

        $count = 0;

        foreach ($posts as $post) {
            $media = $post->media;
            $changed = false;

            foreach ($media as &$item) {
                if (is_array($item)) {
                    $url = $item['url'] ?? '';
                    if (basename($url) === $oldFilename) {
                        $item['url'] = '/media/' . $newFilename;
                        $item['mimetype'] = $newMimetype;
                        $changed = true;
                    }
                }
            }

            if ($changed && ! $dryRun) {
                $post->media = $media;
                $post->save();
            }

            if ($changed) {
                $label = $dryRun ? 'serait mis à jour' : '';
                $this->line("    → Post #{$post->id} {$label}");
                $count++;
            }
        }

        return $count;
    }

    private function findFfmpeg(): ?string
    {
        $ffmpeg = trim(exec('which ffmpeg 2>/dev/null'));
        if ($ffmpeg) {
            return $ffmpeg;
        }

        foreach (['/opt/homebrew/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/usr/bin/ffmpeg'] as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' Mo';
        }

        return round($bytes / 1024) . ' Ko';
    }
}
