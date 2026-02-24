<?php

namespace App\Console\Commands;

use App\Models\Post;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DownloadMediaCommand extends Command
{
    protected $signature = 'media:download
        {--dry-run : Afficher les fichiers sans les télécharger}';

    protected $description = 'Télécharge les médias depuis NocoDB et les stocke localement';

    public function handle(): int
    {
        $posts = Post::whereNotNull('media')->get();
        $totalFiles = 0;
        $downloaded = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($posts as $post) {
            if (empty($post->media)) continue;
            $totalFiles += count($post->media);
        }

        $this->info("Fichiers à traiter: {$totalFiles}");

        if ($this->option('dry-run')) {
            foreach ($posts as $post) {
                foreach ($post->media ?? [] as $m) {
                    $this->line("  {$m['url']}");
                }
            }
            return Command::SUCCESS;
        }

        // Create media directory
        Storage::disk('public')->makeDirectory('media');

        $bar = $this->output->createProgressBar($totalFiles);
        $bar->start();

        foreach ($posts as $post) {
            $media = $post->media;
            if (empty($media)) continue;

            $updated = false;

            foreach ($media as $index => $item) {
                $url = $item['url'] ?? '';

                // Skip if already a local URL
                if (Str::startsWith($url, '/storage/') || Str::startsWith($url, 'storage/')) {
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                // Skip if empty
                if (empty($url)) {
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                // Generate a unique filename
                $extension = $this->getExtension($item);
                $filename = "post_{$post->id}_{$index}_" . Str::random(8) . ".{$extension}";
                $storagePath = "media/{$filename}";

                try {
                    $response = Http::withOptions([
                        'timeout' => 120,
                        'connect_timeout' => 30,
                    ])->get($url);

                    if ($response->successful()) {
                        Storage::disk('public')->put($storagePath, $response->body());

                        // Update the media item URL
                        $media[$index]['url'] = "/storage/{$storagePath}";
                        $media[$index]['original_url'] = $url;
                        $updated = true;
                        $downloaded++;
                    } else {
                        $this->newLine();
                        $this->warn("  HTTP {$response->status()} pour: {$url}");
                        $failed++;
                    }
                } catch (\Throwable $e) {
                    $this->newLine();
                    $this->error("  Erreur: {$e->getMessage()} pour: {$url}");
                    $failed++;
                }

                $bar->advance();
            }

            if ($updated) {
                $post->update(['media' => $media]);
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Métrique', 'Valeur'],
            [
                ['Téléchargés', $downloaded],
                ['Déjà locaux', $skipped],
                ['Échoués', $failed],
                ['Total', $totalFiles],
            ]
        );

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function getExtension(array $item): string
    {
        $mimetype = $item['mimetype'] ?? 'image/jpeg';

        return match ($mimetype) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            default => pathinfo($item['title'] ?? 'file.jpg', PATHINFO_EXTENSION) ?: 'jpg',
        };
    }
}
