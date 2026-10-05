<?php

namespace App\Services\Media;

use App\Models\MediaFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Fabrique, au moment de publier, la version d'un fichier qu'un reseau donne
 * accepte — en laissant l'original intact.
 *
 * LE PROBLEME QU'IL CORRIGE
 * =========================
 * La normalisation avait lieu a l'UPLOAD, une seule fois, pour tous les reseaux.
 * Elle etait donc calee sur la contrainte la plus severe (Bluesky, 50 Mo) et
 * `MediaController::compressVideo` SUPPRIMAIT l'original (`@unlink`). Instagram,
 * qui accepte bien davantage, recevait quand meme le fichier degrade, et le
 * reseau recompressait par-dessus : on perdait deux fois.
 *
 * Ici, la decision est prise par reseau et le resultat ecrit dans une VARIANTE
 * a cote. Instagram recoit l'original, seul Bluesky recoit une version reduite.
 *
 * CE QU'IL NE TOUCHE PAS
 * ======================
 * Un fichier sans `preserve_original` est rendu tel quel, sans la moindre
 * verification. Les quatre chemins d'entree historiques (upload web,
 * /media/ingest, telechargement d'URL, import externe) compressent a dessein
 * et gardent exactement le comportement qu'ils avaient.
 *
 * LA VARIANTE EST UN VRAI FICHIER, PAS UN TEMPORAIRE
 * ==================================================
 * Deux raisons, et la seconde est la vraie :
 *  1. la publication est asynchrone (un job par compte) — un temporaire
 *     supprime en fin de job serait a refaire pour chaque reseau ;
 *  2. Threads, Bluesky et Telegram ne recoivent pas des octets mais une URL
 *     qu'ils viennent lire eux-memes. Il faut donc que la variante soit
 *     servable par `media.show`, ce qui exige un fichier dans `media/`.
 *
 * Le nom porte le plafond, pas le reseau (`--v50`, `--i1`) : deux reseaux aux
 * memes contraintes partagent la meme variante au lieu d'en produire deux
 * identiques. La variante est reutilisee tant qu'elle est plus recente que
 * l'original.
 *
 * EN CAS D'ECHEC, L'ORIGINAL
 * ==========================
 * Si ffmpeg manque ou echoue, on rend l'original et on journalise. Le reseau
 * refusera peut-etre le fichier, et son message d'erreur sera plus parlant
 * qu'une publication annulee ici sans explication.
 */
class MediaVariantService
{
    public function __construct(private VideoNormalizer $normalizer) {}

    /**
     * Nom du fichier a publier sur `$platform` : l'original s'il passe les
     * plafonds, une variante sinon.
     */
    public function filenameFor(string $filename, string $platform): string
    {
        $media = MediaFile::where('filename', $filename)->first();

        if (! $media || ! $media->preserve_original) {
            return $filename;
        }

        $profile = config("media_variants.platforms.{$platform}");
        if (! is_array($profile)) {
            return $filename;
        }

        $originalPath = Storage::disk('local')->path("media/{$filename}");
        if (! is_file($originalPath)) {
            return $filename;
        }

        $isVideo = str_starts_with((string) $media->mime_type, 'video/');

        return $isVideo
            ? $this->videoFilename($filename, $originalPath, $profile, $platform)
            : $this->imageFilename($filename, $originalPath, $profile, $platform);
    }

    /**
     * Variante video : codec/pix_fmt incompatible, taille ou resolution au-dessus
     * du plafond du reseau. `VideoNormalizer` fait foi sur le diagnostic.
     */
    private function videoFilename(string $filename, string $originalPath, array $profile, string $platform): string
    {
        $maxMb = $profile['video_max_mb'] ?? null;
        $requiresH264 = (bool) ($profile['video_requires_h264'] ?? false);
        $maxDimension = $profile['video_max_dimension'] ?? null;

        // Reseau sans contrainte exploitable (YouTube) : rien a faire.
        if ($maxMb === null && ! $requiresH264 && $maxDimension === null) {
            return $filename;
        }

        $meta = $this->normalizer->analyze($originalPath);
        if (empty($meta)) {
            // Sans ffprobe, aucun diagnostic possible : tenter l'original plutot
            // que de fabriquer une variante a l'aveugle.
            Log::warning('MediaVariantService: ffprobe indisponible, original conserve', [
                'filename' => $filename,
                'platform' => $platform,
            ]);

            return $filename;
        }

        // `needsNormalization` fusionne les trois diagnostics (codec, taille,
        // resolution). On lui passe un plafond infini quand le reseau n'en a pas,
        // et on retire ses motifs non pertinents pour ce reseau.
        $reasons = $this->normalizer->needsNormalization($meta, $maxMb ?? PHP_INT_MAX);
        $reasons = $this->filterVideoReasons($reasons, $requiresH264, $maxDimension, $meta);

        if ($reasons === []) {
            return $filename;
        }

        // Le plafond nomme la variante : deux reseaux aux memes contraintes la
        // partagent. Une video normalisee est toujours en MP4.
        $label = 'v'.($maxMb ?? 'x');
        $variant = $this->variantName($filename, $label, 'mp4');

        if ($this->isFresh($variant, $originalPath)) {
            return $variant;
        }

        $variantPath = Storage::disk('local')->path("media/{$variant}");
        $budgetMb = $maxMb ?? (int) ceil(filesize($originalPath) / 1048576);

        if (! $this->normalizer->normalize($originalPath, $variantPath, $budgetMb)) {
            Log::error('MediaVariantService: normalisation video echouee, original conserve', [
                'filename' => $filename,
                'platform' => $platform,
                'reasons' => $reasons,
            ]);

            return $filename;
        }

        Log::info('MediaVariantService: variante video creee', [
            'filename' => $filename,
            'variant' => $variant,
            'platform' => $platform,
            'reasons' => $reasons,
            'original_mb' => round(filesize($originalPath) / 1048576, 1),
            'variant_mb' => round(filesize($variantPath) / 1048576, 1),
        ]);

        return $variant;
    }

    /**
     * Ecarte les motifs de normalisation qui ne concernent pas ce reseau :
     * Telegram accepte un codec exotique, et un reseau sans plafond de
     * resolution n'a pas besoin qu'on redimensionne.
     *
     * @param  list<string>  $reasons
     * @return list<string>
     */
    private function filterVideoReasons(array $reasons, bool $requiresH264, ?int $maxDimension, array $meta): array
    {
        $longestSide = max((int) ($meta['width'] ?? 0), (int) ($meta['height'] ?? 0));

        return array_values(array_filter($reasons, function (string $reason) use ($requiresH264, $maxDimension, $longestSide) {
            $isCodecReason = str_starts_with($reason, 'codec=')
                || str_starts_with($reason, 'pix_fmt=')
                || str_starts_with($reason, 'profile=');

            if ($isCodecReason) {
                return $requiresH264;
            }

            // Motif resolution : pertinent seulement si le reseau plafonne, et
            // au-dessus de SON plafond (celui de VideoNormalizer est 1920).
            if (str_contains($reason, 'px > ')) {
                return $maxDimension !== null && $longestSide > $maxDimension;
            }

            // Reste le motif de taille, toujours pertinent.
            return true;
        }));
    }

    /**
     * Variante image : uniquement quand le fichier depasse le plafond d'octets
     * du reseau. Aucun redimensionnement — c'est la reduction a 2048 px de
     * `ProcessesImages` qui abimait les slides, on ne la reproduit pas. On
     * baisse la qualite JPEG par paliers, et on s'arrete au plancher.
     */
    private function imageFilename(string $filename, string $originalPath, array $profile, string $platform): string
    {
        $maxMb = $profile['image_max_mb'] ?? null;
        if ($maxMb === null) {
            return $filename;
        }

        $maxBytes = $maxMb * 1048576;
        if (filesize($originalPath) <= $maxBytes) {
            return $filename;
        }

        $variant = $this->variantName($filename, 'i'.$maxMb, 'jpg');

        if ($this->isFresh($variant, $originalPath)) {
            return $variant;
        }

        $image = @imagecreatefromstring((string) file_get_contents($originalPath));
        if (! $image) {
            Log::warning('MediaVariantService: image illisible par GD, original conserve', [
                'filename' => $filename,
                'platform' => $platform,
            ]);

            return $filename;
        }

        $variantPath = Storage::disk('local')->path("media/{$variant}");
        $qualityMax = (int) config('media_variants.image_quality_max', 92);
        $qualityMin = (int) config('media_variants.image_quality_min', 80);

        for ($quality = $qualityMax; $quality >= $qualityMin; $quality -= 4) {
            imagejpeg($image, $variantPath, $quality);
            clearstatcache(true, $variantPath);

            if (filesize($variantPath) <= $maxBytes) {
                break;
            }
        }

        imagedestroy($image);

        // Le plancher de qualite n'a pas suffi : on garde la variante quand meme,
        // c'est ce qui reste de plus proche de la contrainte du reseau.
        if (filesize($variantPath) > $maxBytes) {
            Log::warning('MediaVariantService: plafond image non atteint au plancher de qualite', [
                'filename' => $filename,
                'platform' => $platform,
                'variant_mb' => round(filesize($variantPath) / 1048576, 2),
                'max_mb' => $maxMb,
            ]);
        }

        return $variant;
    }

    /**
     * `20261005_ab12cd34.mp4` + `v50` → `20261005_ab12cd34--v50.mp4`.
     * Le double tiret evite toute collision avec un nom genere par l'upload
     * (`date_random`, qui ne contient qu'un seul tiret bas).
     */
    private function variantName(string $filename, string $label, string $extension): string
    {
        return pathinfo($filename, PATHINFO_FILENAME)."--{$label}.{$extension}";
    }

    /**
     * Une variante n'est reutilisee que si elle est plus recente que l'original :
     * un reupload sous le meme nom doit la perimer.
     */
    private function isFresh(string $variant, string $originalPath): bool
    {
        $path = Storage::disk('local')->path("media/{$variant}");

        return is_file($path)
            && filesize($path) > 0
            && filemtime($path) >= filemtime($originalPath);
    }
}
