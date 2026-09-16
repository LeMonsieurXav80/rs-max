<?php

namespace App\Services\Import;

use App\Models\ExternalPost;
use App\Services\Media\PerceptualHasher;
use Illuminate\Support\Facades\Http;

/**
 * Empreinte perceptuelle de la premiere image d'une publication native.
 *
 * C'est ce qui permet de reconnaitre la meme publication d'un reseau a l'autre
 * quand ni l'heure ni le texte ne le permettent — une photo Instagram
 * republiee le lendemain sur Pinterest, avec sa propre description.
 *
 * DOIT etre appele au fil de l'import, pas apres coup : Instagram signe ses
 * URL de CDN et elles expirent. Un rattrapage a posteriori se prend des 403
 * sur tout ce qui n'est pas tout frais — mesure en prod, 165 echecs sur 309
 * publications, dont la quasi-totalite du cote Instagram.
 *
 * On ne hache que la PREMIERE image : un carrousel republie ailleurs garde son
 * ouverture, et comparer toutes les images multiplierait le cout pour rien.
 */
class ExternalMediaHasher
{
    public function __construct(private readonly PerceptualHasher $hasher) {}

    /**
     * @param  iterable<ExternalPost>  $posts
     * @return array{hashed: int, without_image: int, failed: int}
     */
    public function hashAll(iterable $posts): array
    {
        $result = ['hashed' => 0, 'without_image' => 0, 'failed' => 0];

        foreach ($posts as $post) {
            $outcome = $this->hash($post);
            $result[$outcome]++;
        }

        return $result;
    }

    /**
     * @return 'hashed'|'without_image'|'failed'
     */
    public function hash(ExternalPost $post): string
    {
        $first = $post->mediaItems()[0] ?? null;

        // La miniature suffit et pese moins : l'empreinte travaille de toute
        // facon sur une image reduite a quelques pixels.
        $url = $first['thumbnail_url'] ?? $first['url'] ?? null;

        if (! $url) {
            $post->update(['media_hashed_at' => now()]);

            return 'without_image';
        }

        $hash = $this->hashOf($url);

        if ($hash === null) {
            // Pas de `media_hashed_at` : une URL momentanement injoignable doit
            // etre retentee, une URL expiree ne le sera de toute facon jamais.
            return 'failed';
        }

        $post->update(['media_hash' => $hash, 'media_hashed_at' => now()]);

        return 'hashed';
    }

    private function hashOf(string $url): ?string
    {
        $temp = null;

        try {
            $response = Http::timeout(20)->get($url);

            if (! $response->successful()) {
                return null;
            }

            $temp = tempnam(sys_get_temp_dir(), 'rshash_');
            file_put_contents($temp, $response->body());

            return $this->hasher->hash($temp);
        } catch (\Throwable) {
            return null;
        } finally {
            if ($temp) {
                @unlink($temp);
            }
        }
    }
}
