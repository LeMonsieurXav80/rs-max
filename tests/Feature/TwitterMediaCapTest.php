<?php

namespace Tests\Feature;

use App\Services\Adapters\TwitterAdapter;
use Tests\TestCase;

/**
 * Un tweet porte 4 images AU PLUS, ou 1 video, jamais les deux.
 *
 * Un carrousel de 9 photos partait tel quel : 9 uploads aboutis, puis le tweet
 * refuse en bloc par X avec "$.media.media_ids: there must be a maximum of 4
 * items in the array" — HTTP 400 dont le detail ne remontait pas a l'ecran.
 * Le reseau etait perdu pour la publication entiere alors que 4 photos sur 9
 * auraient tenu. BlueskyAdapter, soumis a la meme limite, tronquait deja.
 *
 * Le plafond est verifie directement sur `capMedia` : passer par `publish()`
 * demanderait de simuler le telechargement des medias, or `Http::fake()`
 * n'honore pas l'option `sink` dont se sert `uploadMedia` — le fichier
 * temporaire resterait vide et l'upload echouerait pour une raison etrangere
 * a ce qu'on veut prouver.
 */
class TwitterMediaCapTest extends TestCase
{
    /**
     * @param  array<int, array<string, string>>  $media
     * @return array<int, array<string, string>>
     */
    private function cap(array $media): array
    {
        $methode = new \ReflectionMethod(TwitterAdapter::class, 'capMedia');
        $methode->setAccessible(true);

        return $methode->invoke(new TwitterAdapter, $media);
    }

    /**
     * @return array<int, array{url: string, mimetype: string}>
     */
    private function images(int $nombre): array
    {
        return array_map(fn (int $i) => [
            'url' => "https://exemple.test/photo-{$i}.jpg",
            'mimetype' => 'image/jpeg',
        ], range(1, $nombre));
    }

    public function test_neuf_images_sont_tronquees_a_quatre(): void
    {
        $gardees = $this->cap($this->images(9));

        $this->assertCount(4, $gardees);
        // Les quatre PREMIERES : l'ordre du carrousel porte le sens
        // (couverture d'abord), une troncature par la fin serait absurde.
        $this->assertSame('https://exemple.test/photo-1.jpg', $gardees[0]['url']);
        $this->assertSame('https://exemple.test/photo-4.jpg', $gardees[3]['url']);
    }

    public function test_quatre_images_passent_toutes(): void
    {
        $this->assertCount(4, $this->cap($this->images(4)));
    }

    public function test_une_seule_image_passe(): void
    {
        $this->assertCount(1, $this->cap($this->images(1)));
    }

    public function test_une_video_part_seule_sans_les_images(): void
    {
        $media = array_merge($this->images(3), [[
            'url' => 'https://exemple.test/clip.mp4',
            'mimetype' => 'video/mp4',
        ]]);

        $gardees = $this->cap($media);

        $this->assertCount(1, $gardees, 'une video ne tolere aucun compagnon');
        $this->assertSame('video/mp4', $gardees[0]['mimetype']);
    }

    public function test_deux_videos_ne_laissent_que_la_premiere(): void
    {
        $gardees = $this->cap([
            ['url' => 'https://exemple.test/a.mp4', 'mimetype' => 'video/mp4'],
            ['url' => 'https://exemple.test/b.mp4', 'mimetype' => 'video/mp4'],
        ]);

        $this->assertCount(1, $gardees);
        $this->assertSame('https://exemple.test/a.mp4', $gardees[0]['url']);
    }

    public function test_un_media_sans_mimetype_est_traite_comme_une_image(): void
    {
        $gardees = $this->cap([
            ['url' => 'https://exemple.test/inconnu-1'],
            ['url' => 'https://exemple.test/inconnu-2'],
        ]);

        $this->assertCount(2, $gardees);
    }

    public function test_aucun_media_ne_donne_aucun_media(): void
    {
        $this->assertSame([], $this->cap([]));
    }
}
