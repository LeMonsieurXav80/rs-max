<?php

namespace App\Services\Media;

use App\Models\MediaFile;
use Illuminate\Support\Facades\Log;

/**
 * Enregistre la filiation d'une image générée : quelles photos de la médiathèque
 * ont servi à la composer.
 *
 * Appelé au moment du rendu (Studio web et API carrousel), là où l'information est
 * certaine — après coup elle n'est plus reconstituable que par phash approximatif
 * (voir la commande media:reconcile-derivations).
 *
 * Le repérage se fait sur les références locales `/media/{fichier}` présentes dans
 * les données de la slide, quel que soit le nom du slot : les briques stockées en
 * base déduisent leurs slots du gabarit, il n'y a donc pas de liste figée de noms
 * de slots image à connaître ici.
 */
class MediaDerivationService
{
    /**
     * Lie une image générée aux photos sources trouvées dans les données de sa slide.
     *
     * @param  array{brick?: string, data?: array}  $slide  Slide normalisée (BrickRegistry)
     * @return list<MediaFile> Photos sources effectivement liées
     */
    public function linkSlide(MediaFile $derived, array $slide): array
    {
        $refs = $this->collectMediaRefs($slide['data'] ?? []);
        if ($refs === []) {
            return [];
        }

        $brick = is_string($slide['brick'] ?? null) ? $slide['brick'] : null;
        $linked = [];

        foreach ($refs as $slot => $filename) {
            $source = MediaFile::where('filename', $filename)->first();
            // Une image générée peut être réemployée comme fond d'une autre : on
            // garde le lien direct (la chaîne se remonte de proche en proche).
            if (! $source || $source->id === $derived->id) {
                continue;
            }

            try {
                $derived->sources()->syncWithoutDetaching([
                    $source->id => [
                        'slot' => is_string($slot) ? substr($slot, 0, 64) : null,
                        'brick' => $brick ? substr($brick, 0, 64) : null,
                        'match_method' => 'render',
                        'match_confidence' => null,
                    ],
                ]);
                $linked[] = $source;
            } catch (\Throwable $e) {
                // La filiation est de la traçabilité : ne jamais faire échouer un rendu.
                Log::warning('MediaDerivationService: échec du lien de filiation', [
                    'derived_media_file_id' => $derived->id,
                    'source_media_file_id' => $source->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $linked;
    }

    /**
     * Noms de fichiers référencés dans les données d'une slide, indexés par slot.
     * Parcourt récursivement (un gabarit peut imbriquer des listes d'items).
     *
     * @return array<string, string> slot => nom de fichier
     */
    private function collectMediaRefs(mixed $data, string $prefix = ''): array
    {
        if (! is_array($data)) {
            return [];
        }

        $refs = [];
        foreach ($data as $key => $value) {
            $slot = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $refs += $this->collectMediaRefs($value, $slot);

                continue;
            }
            if (! is_string($value) || ! str_contains($value, '/media/')) {
                continue;
            }

            // Les slots image sont normalisés en `/media/{fichier}` par BrickRegistry
            // (les URL externes ont déjà été écartées) ; on ne garde que le nom.
            $filename = basename(parse_url($value, PHP_URL_PATH) ?: $value);
            if ($filename !== '') {
                $refs[$slot] = $filename;
            }
        }

        return $refs;
    }
}
