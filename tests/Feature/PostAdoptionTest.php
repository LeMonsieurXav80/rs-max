<?php

namespace Tests\Feature;

use App\Models\ExternalPost;
use App\Models\MediaFile;
use App\Models\Partner;
use App\Models\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Import\PostAdoptionService;
use App\Services\PartnerTagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Adoption : plusieurs publications natives, une par reseau, fusionnees en une
 * seule publication RS-Max. Couvre la structure produite, la deduplication des
 * photos et l'heritage des partenaires.
 */
class PostAdoptionTest extends TestCase
{
    use RefreshDatabase;

    private array $platforms = [];

    private array $accounts = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram'] as $slug => $name) {
            $platform = Platform::create(['slug' => $slug, 'name' => $name, 'auth_type' => 'oauth2']);

            $this->platforms[$slug] = $platform;
            $this->accounts[$slug] = SocialAccount::create([
                'platform_id' => $platform->id,
                'platform_account_id' => 'acc-'.$slug,
                'name' => 'Compte '.$name,
                'credentials' => ['access_token' => 't'],
            ]);
        }
    }

    private function manager(): User
    {
        $user = User::factory()->create(['role' => 'manager']);

        foreach ($this->accounts as $account) {
            $user->socialAccounts()->attach($account->id, ['is_active' => true]);
        }

        return $user;
    }

    private function externalPost(string $slug, array $attributes = []): ExternalPost
    {
        return ExternalPost::create(array_merge([
            'social_account_id' => $this->accounts[$slug]->id,
            'platform_id' => $this->platforms[$slug]->id,
            'external_id' => $slug.'-'.uniqid(),
            'content' => 'Texte '.$slug,
            'published_at' => now()->subDay(),
        ], $attributes));
    }

    /** Une image JPEG minuscule mais valide, pour que GD sache la lire. */
    private function jpeg(int $r = 200, int $g = 100, int $b = 50): string
    {
        return $this->jpegAvecQualite(90, $r, $g, $b);
    }

    /**
     * La meme image, encodee a une qualite donnee : simule le re-encodage que
     * chaque reseau applique a la photo qu'on lui envoie.
     */
    private function jpegAvecQualite(int $quality, int $r = 200, int $g = 100, int $b = 50): string
    {
        $image = imagecreatetruecolor(64, 64);

        // Un degrade : un aplat uni donnerait le meme dhash a n'importe quelle
        // image, le test ne prouverait rien.
        for ($x = 0; $x < 64; $x++) {
            for ($y = 0; $y < 64; $y++) {
                $color = imagecolorallocate(
                    $image,
                    min(255, (int) ($r * $x / 64)),
                    min(255, (int) ($g * $y / 64)),
                    $b
                );
                imagesetpixel($image, $x, $y, $color);
            }
        }

        ob_start();
        imagejpeg($image, null, $quality);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    public function test_l_adoption_cree_une_publication_avec_un_reseau_par_ligne(): void
    {
        $user = $this->manager();

        $fb = $this->externalPost('facebook', ['content' => 'Version Facebook, la plus longue de toutes']);
        $ig = $this->externalPost('instagram', ['content' => 'Version courte']);

        $this->actingAs($user)
            ->post(route('external.adopt'), ['ids' => [$fb->id, $ig->id], 'reference_id' => $fb->id])
            ->assertRedirect();

        $post = Post::first();

        $this->assertSame('Version Facebook, la plus longue de toutes', $post->content_fr);
        $this->assertSame('published', $post->status);
        $this->assertSame('native', $post->source_type);

        // Chaque reseau conserve son propre texte.
        $this->assertSame([
            'facebook' => 'Version Facebook, la plus longue de toutes',
            'instagram' => 'Version courte',
        ], $post->platform_contents);

        // Une ligne post_platform par reseau, avec l'id d'origine conserve.
        $this->assertCount(2, $post->postPlatforms);
        $this->assertEqualsCanonicalizing(
            [$fb->external_id, $ig->external_id],
            $post->postPlatforms->pluck('external_id')->all()
        );
        $this->assertSame(['published', 'published'], $post->postPlatforms->pluck('status')->all());
    }

    public function test_les_publications_adoptees_sortent_du_flux(): void
    {
        $user = $this->manager();
        $fb = $this->externalPost('facebook');

        $this->actingAs($user)->post(route('external.adopt'), ['ids' => [$fb->id]]);

        $fb->refresh();

        $this->assertNotNull($fb->adopted_post_id);
        $this->assertNotNull($fb->adopted_at);
        $this->assertSame(0, ExternalPost::adoptable()->count());
    }

    public function test_la_meme_photo_sur_deux_reseaux_n_est_telechargee_qu_une_fois(): void
    {
        $user = $this->manager();
        $bytes = $this->jpeg();
        $appels = 0;

        // Chaque reseau sert SA copie de la photo, a une URL differente.
        Http::fake(function (Request $request) use ($bytes, &$appels) {
            $appels++;

            return Http::response($bytes, 200, ['Content-Type' => 'image/jpeg']);
        });

        $fb = $this->externalPost('facebook', [
            'media' => [['url' => 'https://cdn.facebook.test/photo.jpg', 'type' => 'image']],
        ]);
        $ig = $this->externalPost('instagram', [
            'media' => [['url' => 'https://cdn.instagram.test/photo.jpg', 'type' => 'image']],
        ]);

        $this->actingAs($user)->post(route('external.adopt'), ['ids' => [$fb->id, $ig->id]]);

        $post = Post::first();

        // Les deux URLs sont bien sollicitees, mais un seul fichier est conserve.
        $this->assertSame(2, $appels);
        $this->assertCount(1, $post->media);
        $this->assertSame(1, MediaFile::where('source', 'external_import')->count());
    }

    public function test_la_meme_photo_re_encodee_par_chaque_reseau_est_reconnue(): void
    {
        $user = $this->manager();

        // Le cas reel : Facebook et Instagram servent chacun LEUR encodage de la
        // meme photo. Les octets different, un SHA-256 ne les rapproche pas.
        $facebook = $this->jpegAvecQualite(35);
        $instagram = $this->jpegAvecQualite(95);

        $this->assertNotSame($facebook, $instagram, 'les deux encodages doivent differer');

        Http::fake([
            'cdn.facebook.test/*' => Http::response($facebook, 200, ['Content-Type' => 'image/jpeg']),
            'cdn.instagram.test/*' => Http::response($instagram, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $fb = $this->externalPost('facebook', [
            'media' => [['url' => 'https://cdn.facebook.test/p.jpg', 'type' => 'image']],
        ]);
        $ig = $this->externalPost('instagram', [
            'media' => [['url' => 'https://cdn.instagram.test/p.jpg', 'type' => 'image']],
        ]);

        $this->actingAs($user)->post(route('external.adopt'), ['ids' => [$fb->id, $ig->id]]);

        $this->assertCount(1, Post::first()->media);
        $this->assertSame(1, MediaFile::where('source', 'external_import')->count());
        $this->assertNotNull(MediaFile::where('source', 'external_import')->value('dhash'));
    }

    public function test_les_videos_ne_sont_pas_rapatriees(): void
    {
        $user = $this->manager();

        Http::fake();

        $fb = $this->externalPost('facebook', [
            'media' => [['url' => 'https://cdn.facebook.test/clip.mp4', 'type' => 'video']],
        ]);

        $this->actingAs($user)->post(route('external.adopt'), ['ids' => [$fb->id]]);

        $this->assertNull(Post::first()->media);
        // La table n'est pas vide au depart : une migration y recense les
        // fichiers presents sur le disque. On ne compte donc que les imports.
        $this->assertSame(0, MediaFile::where('source', 'external_import')->count());
        Http::assertNothingSent();
    }

    public function test_la_publication_herite_des_partenaires_de_ses_photos(): void
    {
        $user = $this->manager();
        $bytes = $this->jpeg();

        Http::fake(fn () => Http::response($bytes, 200, ['Content-Type' => 'image/jpeg']));

        $fb = $this->externalPost('facebook', [
            'media' => [['url' => 'https://cdn.facebook.test/photo.jpg', 'type' => 'image']],
        ]);

        $this->actingAs($user)->post(route('external.adopt'), ['ids' => [$fb->id]]);

        // La photo rapatriee est ensuite taguee : le report se fait au save suivant.
        $media = MediaFile::where('source', 'external_import')->firstOrFail();
        app(PartnerTagService::class)->syncMediaNames($media, ['Decathlon']);

        $post = Post::first();
        app(PartnerTagService::class)->syncPost($post);

        $this->assertSame(['Decathlon'], $post->fresh()->partners->pluck('name')->all());
        $this->assertSame(1, Partner::count());
    }

    public function test_deux_publications_du_meme_reseau_sont_refusees(): void
    {
        $user = $this->manager();

        $a = $this->externalPost('facebook');
        $b = $this->externalPost('facebook');

        $this->actingAs($user)
            ->post(route('external.adopt'), ['ids' => [$a->id, $b->id]])
            ->assertSessionHasErrors('ids');

        $this->assertSame(0, Post::count());
    }

    public function test_une_publication_deja_adoptee_ne_l_est_pas_deux_fois(): void
    {
        $user = $this->manager();
        $fb = $this->externalPost('facebook');

        $this->actingAs($user)->post(route('external.adopt'), ['ids' => [$fb->id]]);
        $this->assertSame(1, Post::count());

        $this->actingAs($user)
            ->post(route('external.adopt'), ['ids' => [$fb->id]])
            ->assertSessionHasErrors('ids');

        $this->assertSame(1, Post::count());
    }

    public function test_sans_reference_le_texte_le_plus_long_fait_foi(): void
    {
        $user = $this->manager();

        $this->externalPost('instagram', ['content' => 'Court']);
        $this->externalPost('facebook', ['content' => 'Un texte nettement plus fourni que l autre']);

        $ids = ExternalPost::pluck('id')->all();

        $this->actingAs($user)->post(route('external.adopt'), ['ids' => $ids]);

        $this->assertSame('Un texte nettement plus fourni que l autre', Post::first()->content_fr);
    }

    public function test_le_service_est_appelable_directement(): void
    {
        $user = $this->manager();
        $fb = $this->externalPost('facebook');

        $result = app(PostAdoptionService::class)->adopt(
            ExternalPost::with('platform')->whereKey($fb->id)->get(),
            $user
        );

        $this->assertInstanceOf(Post::class, $result['post']);
        $this->assertSame(0, $result['downloaded']);
    }
}
