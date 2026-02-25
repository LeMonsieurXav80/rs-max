<?php

namespace App\Console\Commands;

use App\Models\Post;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ConvertVideosCommand extends Command
{
    protected $signature = 'media:convert-videos {--dry-run : Voir ce qui serait fait sans modifier les fichiers}';

    protected $description = 'Convertir toutes les vidéos non-H.264 en MP4 (H.264/AAC) et mettre à jour les références en base';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $mediaPath = Storage::disk('local')->path('media');

        $ffmpeg = $this->findBinary('ffmpeg');
        if (! $ffmpeg) {
            $this->error('ffmpeg introuvable. Installez-le pour convertir les vidéos.');

            return Command::FAILURE;
        }

        $ffprobe = $this->findBinary('ffprobe');

        $this->info("ffmpeg: {$ffmpeg}");
        if ($dryRun) {
            $this->warn('DRY RUN — aucun fichier ne sera modifié');
        }
        $this->newLine();

        // Find all video files (non-MP4 + MP4 with non-H.264 codec)
        $allExtensions = ['mov', 'avi', 'webm', 'mkv', 'MOV', 'AVI', 'WEBM', 'MKV', 'mp4', 'MP4'];
        $allVideos = [];
        foreach ($allExtensions as $ext) {
            $allVideos = array_merge($allVideos, glob("{$mediaPath}/*.{$ext}"));
        }

        $videos = [];
        foreach ($allVideos as $videoPath) {
            $ext = strtolower(pathinfo($videoPath, PATHINFO_EXTENSION));

            if ($ext !== 'mp4') {
                // Non-MP4: always convert
                $videos[] = ['path' => $videoPath, 'reason' => 'format non-MP4'];
            } elseif ($ffprobe && $this->isNonH264($ffprobe, $videoPath)) {
                // MP4 but with HEVC/VP9/AV1 codec: re-encode
                $codec = $this->getCodec($ffprobe, $videoPath);
                $videos[] = ['path' => $videoPath, 'reason' => "codec {$codec}"];
            }
        }

        if (empty($videos)) {
            $this->info('Toutes les vidéos sont déjà en MP4 H.264.');

            return Command::SUCCESS;
        }

        $this->info(count($videos) . ' vidéo(s) à convertir :');
        $this->newLine();

        $converted = 0;
        $failed = 0;

        foreach ($videos as $entry) {
            $videoPath = $entry['path'];
            $reason = $entry['reason'];
            $filename = basename($videoPath);
            $mp4Filename = pathinfo($filename, PATHINFO_FILENAME) . '.mp4';
            $isAlreadyMp4 = strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'mp4';

            $sizeBefore = filesize($videoPath);
            $this->line("  {$filename} (" . $this->formatSize($sizeBefore) . ") — {$reason}");

            if ($dryRun) {
                $this->line('    → serait ré-encodé en H.264');
                if (! $isAlreadyMp4) {
                    $this->updatePostReferences($filename, $mp4Filename, 'video/mp4', true);
                }
                $converted++;

                continue;
            }

            // Use temp output if re-encoding in-place (same .mp4 extension)
            $outputPath = $isAlreadyMp4
                ? "{$mediaPath}/{$mp4Filename}.tmp.mp4"
                : "{$mediaPath}/{$mp4Filename}";

            $this->line('    Conversion en cours...');
            exec(sprintf(
                '%s -i %s -c:v libx264 -preset fast -crf 23 -c:a aac -b:a 128k -movflags +faststart -y %s 2>&1',
                escapeshellarg($ffmpeg),
                escapeshellarg($videoPath),
                escapeshellarg($outputPath)
            ), $output, $returnCode);

            if ($returnCode !== 0 || ! file_exists($outputPath) || filesize($outputPath) === 0) {
                $this->error("    ERREUR de conversion pour {$filename}");
                @unlink($outputPath);
                $failed++;

                continue;
            }

            if ($isAlreadyMp4) {
                @unlink($videoPath);
                rename($outputPath, "{$mediaPath}/{$mp4Filename}");
            } else {
                @unlink($videoPath);
            }

            $sizeAfter = filesize("{$mediaPath}/{$mp4Filename}");
            $this->line("    → {$mp4Filename} (" . $this->formatSize($sizeAfter) . ')');

            // Update post references in database (only if filename changed)
            if (! $isAlreadyMp4) {
                $updated = $this->updatePostReferences($filename, $mp4Filename, 'video/mp4', false);
                if ($updated > 0) {
                    $this->line("    → {$updated} post(s) mis à jour en base");
                }
            }

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

    private function isNonH264(string $ffprobe, string $filePath): bool
    {
        $codec = $this->getCodec($ffprobe, $filePath);

        return $codec && ! in_array($codec, ['h264', 'avc']);
    }

    private function getCodec(string $ffprobe, string $filePath): ?string
    {
        $output = [];
        exec(sprintf(
            '%s -v quiet -select_streams v:0 -show_entries stream=codec_name -of csv=p=0 %s 2>/dev/null',
            escapeshellarg($ffprobe),
            escapeshellarg($filePath)
        ), $output);

        $codec = trim($output[0] ?? '');

        return $codec ?: null;
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

    private function formatSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' Mo';
        }

        return round($bytes / 1024) . ' Ko';
    }
}
