<?php

namespace Tests\Feature;

use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\User;
use App\Services\Media\MediaVariantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Couvre POST /api/media/upload et MediaVariantService.
 *
 * Ce qui est verifie en priorite, parce que c'est la raison d'etre du chemin :
 * les octets stockes sont EXACTEMENT ceux envoyes. Un test qui se contenterait
 * de verifier le code 201 laisserait passer une recompression.
 */
class MediaPreserveOriginalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * Un PNG volumineux, non compressible : 1200x1200 de bruit aleatoire.
     * Un aplat uni se compresserait si bien qu'il passerait sous la cible de
     * `processImage` — le test ne prouverait alors rien.
     */
    private function noisyPng(int $side = 1200): string
    {
        $image = imagecreatetruecolor($side, $side);
        for ($x = 0; $x < $side; $x += 2) {
            for ($y = 0; $y < $side; $y += 2) {
                $color = imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255));
                imagefilledrectangle($image, $x, $y, $x + 1, $y + 1, $color);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'rsmax').'.png';
        imagepng($image, $path, 0);
        imagedestroy($image);

        return $path;
    }

    public function test_upload_conserve_les_octets_a_l_identique(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $source = $this->noisyPng();
        $expectedBytes = file_get_contents($source);

        $response = $this->postJson('/api/media/upload', [
            'file' => new UploadedFile($source, 'slide-01.png', 'image/png', null, true),
        ])->assertCreated();

        $filename = $response->json('filename');

        // L'extension n'a pas bascule en .jpg : c'est la conversion PNG -> JPEG
        // de `ProcessesImages` qu'on vient precisement eviter.
        $this->assertStringEndsWith('.png', $filename);
        $this->assertSame($expectedBytes, Storage::disk('local')->get("media/{$filename}"));
        $this->assertTrue($response->json('preserve_original'));

        $this->assertDatabaseHas('media_files', [
            'filename' => $filename,
            'preserve_original' => true,
            'source' => 'cli',
            'mime_type' => 'image/png',
            'width' => 1200,
            'height' => 1200,
        ]);
    }

    public function test_upload_accepte_une_video(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        // Pas de vrai conteneur MP4 : on verifie le stockage et la ligne creee,
        // pas le decodage. `probeDimensions` rend 0 sans ffprobe exploitable,
        // et la publication n'en depend pas.
        $response = $this->postJson('/api/media/upload', [
            'file' => UploadedFile::fake()->create('reel.mp4', 2048, 'video/mp4'),
        ])->assertCreated();

        $filename = $response->json('filename');
        $this->assertStringEndsWith('.mp4', $filename);
        Storage::disk('local')->assertExists("media/{$filename}");

        $this->assertDatabaseHas('media_files', [
            'filename' => $filename,
            'mime_type' => 'video/mp4',
            'preserve_original' => true,
        ]);
    }

    public function test_renvoyer_le_meme_fichier_ne_cree_pas_de_doublon(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $source = $this->noisyPng(400);

        $first = $this->postJson('/api/media/upload', [
            'file' => new UploadedFile($source, 'slide.png', 'image/png', null, true),
        ])->assertCreated();

        $second = $this->postJson('/api/media/upload', [
            'file' => new UploadedFile($source, 'slide.png', 'image/png', null, true),
        ])->assertOk();

        $this->assertSame('exists', $second->json('status'));
        $this->assertSame($first->json('id'), $second->json('id'));

        // Compte restreint au hash : la migration `sync_existing_media_files`
        // importe les fichiers presents sur le disque reel au moment des
        // migrations, donc `MediaFile::count()` n'est pas a zero au depart.
        $this->assertSame(1, MediaFile::where('content_hash', hash_file('sha256', $source))->count());
    }

    public function test_un_dossier_prive_escalade_la_visibilite(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $folder = MediaFolder::create([
            'name' => 'Brouillons',
            'slug' => 'brouillons',
            'is_private' => true,
        ]);

        $response = $this->postJson('/api/media/upload', [
            'file' => new UploadedFile($this->noisyPng(300), 'x.png', 'image/png', null, true),
            'folder_id' => $folder->id,
        ])->assertCreated();

        $this->assertDatabaseHas('media_files', [
            'id' => $response->json('id'),
            'intimacy_level' => 'never_publish',
        ]);
    }

    public function test_un_media_non_preserve_est_rendu_tel_quel(): void
    {
        // Garde-fou de non-regression : les quatre chemins d'entree historiques
        // compressent a dessein, et ne doivent jamais passer par une variante.
        MediaFile::create([
            'filename' => 'flux.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 900_000_000,
            'source' => 'upload',
            'preserve_original' => false,
        ]);
        Storage::disk('local')->put('media/flux.jpg', 'peu importe');

        $this->assertSame(
            'flux.jpg',
            app(MediaVariantService::class)->filenameFor('flux.jpg', 'bluesky')
        );
    }

    public function test_une_image_sous_le_plafond_part_en_original(): void
    {
        $bytes = random_bytes(200_000);
        MediaFile::create([
            'filename' => 'slide.jpg',
            'mime_type' => 'image/jpeg',
            'size' => strlen($bytes),
            'source' => 'cli',
            'preserve_original' => true,
        ]);
        Storage::disk('local')->put('media/slide.jpg', $bytes);

        $variants = app(MediaVariantService::class);

        // 200 ko passent partout, y compris sous le plafond de 1 Mo de Bluesky.
        $this->assertSame('slide.jpg', $variants->filenameFor('slide.jpg', 'bluesky'));
        $this->assertSame('slide.jpg', $variants->filenameFor('slide.jpg', 'instagram'));
    }

    public function test_une_image_trop_lourde_donne_une_variante_pour_le_seul_reseau_contraint(): void
    {
        $source = $this->noisyPng(1400);
        $bytes = file_get_contents($source);
        $this->assertGreaterThan(1048576, strlen($bytes), 'le fixture doit depasser le plafond Bluesky');

        MediaFile::create([
            'filename' => 'slide.png',
            'mime_type' => 'image/png',
            'size' => strlen($bytes),
            'source' => 'cli',
            'preserve_original' => true,
        ]);
        Storage::disk('local')->put('media/slide.png', $bytes);

        $variants = app(MediaVariantService::class);

        // Instagram plafonne a 8 Mo : l'original part tel quel.
        $this->assertSame('slide.png', $variants->filenameFor('slide.png', 'instagram'));

        // Bluesky plafonne a 1 Mo : variante nommee par le plafond, pas par le
        // reseau, pour etre partagee par tout reseau aux memes contraintes.
        $served = $variants->filenameFor('slide.png', 'bluesky');
        $this->assertSame('slide--i1.jpg', $served, 'nom attendu : base--i{plafond}.jpg');
        Storage::disk('local')->assertExists("media/{$served}");

        // L'original n'a pas ete touche — c'est tout l'objet du changement.
        $this->assertSame($bytes, Storage::disk('local')->get('media/slide.png'));

        // Et la variante est reutilisee, pas refabriquee.
        $mtime = filemtime(Storage::disk('local')->path("media/{$served}"));
        $this->assertSame($served, $variants->filenameFor('slide.png', 'bluesky'));
        $this->assertSame($mtime, filemtime(Storage::disk('local')->path("media/{$served}")));
    }

    public function test_youtube_ne_fabrique_jamais_de_variante(): void
    {
        $bytes = random_bytes(300_000);
        MediaFile::create([
            'filename' => 'gros.mp4',
            'mime_type' => 'video/mp4',
            'size' => strlen($bytes),
            'source' => 'cli',
            'preserve_original' => true,
        ]);
        Storage::disk('local')->put('media/gros.mp4', $bytes);

        // Profil entierement a null : aucune contrainte, donc aucun appel ffprobe.
        $this->assertSame(
            'gros.mp4',
            app(MediaVariantService::class)->filenameFor('gros.mp4', 'youtube')
        );
    }

    public function test_un_reseau_inconnu_rend_l_original(): void
    {
        MediaFile::create([
            'filename' => 'slide.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 10,
            'source' => 'cli',
            'preserve_original' => true,
        ]);
        Storage::disk('local')->put('media/slide.jpg', 'xx');

        $this->assertSame(
            'slide.jpg',
            app(MediaVariantService::class)->filenameFor('slide.jpg', 'mastodon')
        );
    }
}
