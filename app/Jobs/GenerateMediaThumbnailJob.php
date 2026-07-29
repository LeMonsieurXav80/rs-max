<?php

namespace App\Jobs;

use App\Models\MediaFile;
use App\Services\Media\ThumbnailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Génère (hors requête HTTP) la vignette d'un média et persiste thumbnail_path.
 * Dispatché à l'upload des vidéos (ffmpeg coûteux) et par la commande de backfill.
 */
class GenerateMediaThumbnailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(
        public int $mediaFileId,
        public bool $force = false,
    ) {}

    public function handle(ThumbnailService $thumbnails): void
    {
        $media = MediaFile::find($this->mediaFileId);
        if (! $media) {
            return;
        }

        $rel = $thumbnails->generate($media, $this->force);

        if ($rel) {
            $media->update(['thumbnail_path' => $rel]);
        } else {
            Log::warning('Génération de vignette échouée', [
                'media_file_id' => $media->id,
                'filename' => $media->filename,
            ]);
        }
    }
}
