<?php

namespace App\Concerns;

use App\Models\MediaFile;
use App\Services\Media\MediaVariantService;
use Illuminate\Support\Facades\URL;

/**
 * Prepare les items media juste avant de les remettre a un adapter.
 *
 * Trois chemins publient un Post — le job de queue, la publication synchrone
 * depuis l'interface, le repartage — et portaient chacun sa copie de cette
 * methode, `guessMimetypeFromFilename` compris. Trois copies, c'etaient trois
 * occasions d'oublier un plafond le jour ou le choix du fichier a envoyer
 * cesse d'etre trivial. C'est exactement ce qui est arrive avec l'arrivee de
 * `preserve_original`.
 *
 * (`ThreadPublishingService` garde la sienne : les fils publient par URL, sans
 * `local_path`, et ecrasent le mimetype depuis la base sans condition.)
 *
 * Deux responsabilites :
 *
 *  1. GARANTIR UN MIMETYPE. Les posts anciens ont des items sans `mimetype`,
 *     et les adapters lisent `$item['mimetype']` sans filet. On l'enrichit
 *     depuis la base, puis depuis l'extension.
 *
 *  2. CHOISIR LE FICHIER QUI PART. Pour un media `preserve_original`, ce n'est
 *     plus forcement le fichier stocke : `MediaVariantService` rend l'original
 *     si le reseau l'accepte, une variante calculee pour ce reseau sinon.
 *     D'ou le `$platformSlug` obligatoire — resoudre les medias une fois pour
 *     plusieurs reseaux enverrait a tous la variante du premier.
 */
trait ResolvesPublishableMedia
{
    /**
     * @param  string  $platformSlug  Reseau destinataire. Decide de la variante.
     */
    protected function resolveMediaUrls(?array $media, string $platformSlug): ?array
    {
        if (empty($media)) {
            return $media;
        }

        $variants = app(MediaVariantService::class);

        return array_map(function ($item) use ($variants, $platformSlug) {
            $url = $item['url'] ?? '';

            if (str_starts_with($url, '/media/')) {
                $filename = basename($url);

                // Metadonnees lues sur l'ORIGINAL : une variante n'a pas de
                // ligne en base, et c'est voulu — ce n'est pas un media du
                // catalogue, c'est une copie technique pour un reseau.
                if (empty($item['mimetype']) || empty($item['size'])) {
                    $mediaFile = MediaFile::where('filename', $filename)->first();
                    if ($mediaFile) {
                        $item['mimetype'] = $item['mimetype'] ?? $mediaFile->mime_type;
                        $item['size'] = $item['size'] ?? $mediaFile->size;
                        $item['title'] = $item['title'] ?? $mediaFile->original_name;
                    }
                }

                if (empty($item['mimetype'])) {
                    $item['mimetype'] = $this->guessMimetypeFromFilename($filename);
                }

                $served = $variants->filenameFor($filename, $platformSlug);
                $item['local_path'] = storage_path("app/private/media/{$served}");

                // La taille doit suivre la variante : un adapter qui verifie un
                // plafond raisonnerait sinon sur le poids de l'original.
                if ($served !== $filename && is_file($item['local_path'])) {
                    $item['size'] = filesize($item['local_path']);
                }

                // L'URL signee doit designer LE MEME fichier que `local_path` :
                // Threads, Bluesky et Telegram ne recoivent pas d'octets, ils
                // viennent lire l'URL eux-memes.
                $item['url'] = URL::temporarySignedRoute(
                    'media.show',
                    now()->addHours(4),
                    ['filename' => $served]
                );
            }

            if (empty($item['mimetype'])) {
                $item['mimetype'] = ($item['type'] ?? 'image') === 'video' ? 'video/mp4' : 'image/jpeg';
            }

            return $item;
        }, $media);
    }

    private function guessMimetypeFromFilename(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'mp4', 'm4v' => 'video/mp4',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            default => 'application/octet-stream',
        };
    }
}
