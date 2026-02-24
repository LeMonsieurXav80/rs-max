<?php

namespace App\Console\Commands;

use App\Models\Post;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SecureMediaCommand extends Command
{
    protected $signature = 'media:secure {--dry-run : Afficher sans exécuter}';

    protected $description = 'Déplace les médias vers le stockage privé avec des noms UUID';

    public function handle(): int
    {
        $posts = Post::whereNotNull('media')->get();
        $moved = 0;
        $skipped = 0;
        $failed = 0;

        // Ensure private media directory exists
        Storage::disk('local')->makeDirectory('media');

        foreach ($posts as $post) {
            $media = $post->media;
            if (empty($media)) continue;

            $updated = false;

            foreach ($media as $index => $item) {
                $url = is_string($item) ? $item : ($item['url'] ?? '');

                // Skip if already using the new route format
                if (str_starts_with($url, '/media/') && ! str_starts_with($url, '/media/post_')) {
                    $skipped++;
                    continue;
                }

                // Determine the source file path
                $sourcePath = null;
                $sourceDisk = null;

                if (str_starts_with($url, '/storage/media/')) {
                    // Currently in public storage
                    $relativePath = str_replace('/storage/', '', $url);
                    if (Storage::disk('public')->exists($relativePath)) {
                        $sourcePath = $relativePath;
                        $sourceDisk = 'public';
                    }
                }

                if (! $sourcePath) {
                    $this->warn("  Fichier introuvable: {$url}");
                    $failed++;
                    continue;
                }

                // Generate UUID filename
                $extension = pathinfo($url, PATHINFO_EXTENSION) ?: 'jpg';
                $uuid = Str::uuid()->toString();
                $newFilename = "{$uuid}.{$extension}";
                $newPath = "media/{$newFilename}";

                if ($this->option('dry-run')) {
                    $this->line("  {$url} -> /media/{$newFilename}");
                    $moved++;
                    continue;
                }

                // Copy to private storage
                $contents = Storage::disk($sourceDisk)->get($sourcePath);
                Storage::disk('local')->put($newPath, $contents);

                // Delete from public storage
                Storage::disk($sourceDisk)->delete($sourcePath);

                // Update the media item
                $media[$index]['url'] = "/media/{$newFilename}";
                $updated = true;
                $moved++;
            }

            if ($updated) {
                $post->update(['media' => $media]);
            }
        }

        $this->newLine();
        $this->table(
            ['Métrique', 'Valeur'],
            [
                ['Déplacés/Renommés', $moved],
                ['Déjà sécurisés', $skipped],
                ['Échoués', $failed],
            ]
        );

        if (! $this->option('dry-run') && $moved > 0) {
            // Clean up empty public media directory
            $remaining = Storage::disk('public')->files('media');
            if (empty($remaining)) {
                Storage::disk('public')->deleteDirectory('media');
                $this->info('Dossier public/storage/media/ supprimé.');
            }
        }

        return Command::SUCCESS;
    }
}
