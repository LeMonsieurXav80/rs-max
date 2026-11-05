<?php

namespace App\Services\Media;

use App\Models\MediaFile;

/**
 * Ratio des medias : le libelle affiche (« 4:5 ») et la regle des carrousels Instagram.
 *
 * Instagram ne REFUSE pas un carrousel de ratios differents : il recadre tout sur
 * la premiere image, sans le signaler. Le resultat part en ligne avec des photos
 * coupees, et rien ne remonte. C'est donc a RS-Max de l'interdire.
 *
 * La regle est appliquee a deux niveaux :
 *  - a l'enregistrement d'un post planifie ou publie tout de suite (message immediat) ;
 *  - dans `InstagramAdapter`, seul passage commun au job, a la publication synchrone,
 *    a l'API et aux fils. Un brouillon reste enregistrable, il bute ici.
 *
 * Seules les IMAGES de dimensions connues comptent :
 *  - une image hors mediatheque (banque de photos, URL externe) n'a pas de dimensions
 *    fiables : la bloquer interdirait de la publier, sans recours ;
 *  - ffprobe rend les dimensions d'une video SANS sa rotation : un portrait de
 *    telephone s'y lit en paysage, ce qui fabriquerait un faux conflit.
 */
class MediaRatioService
{
    /** Ecart relatif tolere : 1080x1349 reste un 4:5, a l'arrondi de redimensionnement pres. */
    public const TOLERANCE = 0.01;

    private const NAMED = [
        '1:1' => 1,
        '4:5' => 4 / 5,
        '5:4' => 5 / 4,
        '3:4' => 3 / 4,
        '4:3' => 4 / 3,
        '2:3' => 2 / 3,
        '3:2' => 3 / 2,
        '9:16' => 9 / 16,
        '16:9' => 16 / 9,
        '1.91:1' => 1.91,
    ];

    public static function label(?int $width, ?int $height): ?string
    {
        if (! $width || ! $height) {
            return null;
        }

        $ratio = $width / $height;

        foreach (self::NAMED as $label => $named) {
            if (self::sameRatio($ratio, $named)) {
                return $label;
            }
        }

        return number_format($ratio, 2, '.', '').':1';
    }

    public static function sameRatio(float $a, float $b): bool
    {
        return abs($a / $b - 1) <= self::TOLERANCE;
    }

    /**
     * Le message d'erreur si `$media` est un carrousel Instagram de ratios differents.
     * La reference est la 1re image : c'est sur elle qu'Instagram recadre.
     *
     * @param  array<int, mixed>|null  $media
     */
    public function instagramConflict(?array $media): ?string
    {
        $ratios = [];
        foreach ($this->items($media) as $item) {
            $dimensions = $this->dimensions($item);
            if ($dimensions && $dimensions['is_image']) {
                $ratios[] = $dimensions['width'] / $dimensions['height'];
            }
        }

        if (count($ratios) < 2) {
            return null;
        }

        $differentes = array_filter($ratios, fn (float $ratio) => ! self::sameRatio($ratio, $ratios[0]));
        if ($differentes === []) {
            return null;
        }

        $labels = array_unique(array_map(fn (float $ratio) => self::label((int) round($ratio * 10000), 10000), $ratios));

        return 'Carrousel Instagram refusé : les images n\'ont pas le même ratio ('.implode(', ', $labels).'). '
            .'Instagram recadrerait tout le carrousel sur celui de la première. '
            .'Retirez les images différentes, ou retirez Instagram de cette publication.';
    }

    /**
     * @param  array<int, mixed>|null  $media
     * @param  iterable<\App\Models\SocialAccount>  $accounts
     */
    public function conflictForAccounts(?array $media, iterable $accounts): ?string
    {
        foreach ($accounts as $account) {
            if ($account->platform?->slug === 'instagram') {
                return $this->instagramConflict($media);
            }
        }

        return null;
    }

    /**
     * Complete des items enregistres avant l'affichage du ratio dans le composer.
     *
     * @param  array<int, mixed>|null  $media
     * @return array<int, array<string, mixed>>
     */
    public function enrich(?array $media): array
    {
        return array_map(function (array $item) {
            $dimensions = $this->dimensions($item);

            return $dimensions ? [
                ...$item,
                'width' => $dimensions['width'],
                'height' => $dimensions['height'],
                'ratio' => self::label($dimensions['width'], $dimensions['height']),
            ] : $item;
        }, $this->items($media));
    }

    /**
     * Le formulaire web envoie du JSON, l'API des objets ou de simples URL.
     *
     * @param  array<int, mixed>|null  $media
     * @return array<int, array<string, mixed>>
     */
    private function items(?array $media): array
    {
        $items = [];
        foreach ($media ?? [] as $item) {
            if (is_string($item)) {
                $item = json_decode($item, true) ?? ['url' => $item];
            }
            if (is_array($item) && isset($item['url'])) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Lit la base puis le disque, jamais les `width`/`height` portes par l'item :
     * ils viennent du navigateur ou de l'API.
     *
     * Une variante (`--i1`) n'a pas de ligne en base mais garde le ratio de son
     * original : la lecture du fichier suffit.
     *
     * @param  array<string, mixed>  $item
     * @return array{width: int, height: int, is_image: bool}|null
     */
    private function dimensions(array $item): ?array
    {
        $filename = $this->localFilename($item);
        if ($filename === null) {
            return null;
        }

        $mediaFile = MediaFile::where('filename', $filename)->first();

        if ($mediaFile?->width && $mediaFile->height) {
            return ['width' => $mediaFile->width, 'height' => $mediaFile->height, 'is_image' => $mediaFile->is_image];
        }

        $path = $item['local_path'] ?? storage_path("app/private/media/{$filename}");
        $size = is_file($path) ? @getimagesize($path) : false;

        return $size ? ['width' => (int) $size[0], 'height' => (int) $size[1], 'is_image' => true] : null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function localFilename(array $item): ?string
    {
        if (! empty($item['local_path'])) {
            return basename($item['local_path']);
        }

        $path = parse_url((string) $item['url'], PHP_URL_PATH) ?: '';

        return preg_match('#/media/([^/]+)$#', $path, $m) ? $m[1] : null;
    }
}
