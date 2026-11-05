<?php

namespace Tests\Feature;

use App\Models\MediaFile;
use App\Models\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Adapters\InstagramAdapter;
use App\Services\Media\MediaRatioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Instagram ne refuse pas un carrousel de ratios differents : il recadre tout sur
 * la premiere image, sans le signaler. RS-Max l'interdit donc lui-meme.
 *
 * Les tests verifient autant ce qui passe que ce qui bloque : un faux positif
 * n'a aucun contournement, il empecherait purement et simplement de publier.
 */
class InstagramCarouselRatioTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private SocialAccount $instagram;

    private SocialAccount $facebook;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'admin']);
        $this->instagram = $this->compte('instagram');
        $this->facebook = $this->compte('facebook');

        $this->media('portrait.jpg', 1080, 1350);
        $this->media('portrait-arrondi.jpg', 1080, 1349);
        $this->media('carre.jpg', 1080, 1080);
    }

    private function compte(string $slug): SocialAccount
    {
        $platform = Platform::create(['slug' => $slug, 'name' => $slug, 'auth_type' => 'oauth2', 'is_active' => true]);
        $account = SocialAccount::create([
            'platform_id' => $platform->id,
            'platform_account_id' => "id-{$slug}",
            'name' => $slug,
            'credentials' => ['account_id' => '1', 'access_token' => 't'],
        ]);
        $this->user->socialAccounts()->attach($account->id, ['is_active' => true]);

        return $account;
    }

    private function media(string $filename, ?int $width, ?int $height, string $mime = 'image/jpeg'): void
    {
        MediaFile::create([
            'filename' => $filename,
            'original_name' => $filename,
            'mime_type' => $mime,
            'size' => 1000,
            'width' => $width,
            'height' => $height,
            'source' => 'upload',
        ]);
    }

    /**
     * @return array<int, array{url: string, mimetype: string}>
     */
    private function items(string ...$filenames): array
    {
        return array_map(fn (string $f) => ['url' => "/media/{$f}", 'mimetype' => 'image/jpeg'], $filenames);
    }

    /**
     * @param  array<int, array<string, string>>  $media
     * @return array<string, mixed>
     */
    private function formulaire(array $media, string $status = 'scheduled', ?SocialAccount $account = null): array
    {
        return [
            'content_fr' => 'Texte',
            'status' => $status,
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'accounts' => [($account ?? $this->instagram)->id],
            'media' => array_map('json_encode', $media),
        ];
    }

    public function test_les_libelles_de_ratio(): void
    {
        $this->assertSame('4:5', MediaRatioService::label(1080, 1350));
        $this->assertSame('4:5', MediaRatioService::label(1080, 1349));
        $this->assertSame('1:1', MediaRatioService::label(1080, 1080));
        $this->assertSame('3:4', MediaRatioService::label(3024, 4032));
        $this->assertSame('9:16', MediaRatioService::label(1080, 1920));
        $this->assertSame('1.30:1', MediaRatioService::label(1300, 1000));
        $this->assertNull(MediaRatioService::label(null, 1080));
        $this->assertNull(MediaRatioService::label(0, 0));
    }

    public function test_la_bibliotheque_expose_le_ratio(): void
    {
        $response = $this->actingAs($this->user)->getJson(route('media.list'));

        $response->assertOk();
        $items = collect($response->json('items'))->keyBy('filename');
        $this->assertSame('4:5', $items['portrait.jpg']['ratio']);
        $this->assertSame('1:1', $items['carre.jpg']['ratio']);
        // Le composer compare les dimensions, pas les libelles.
        $this->assertSame(1350, $items['portrait.jpg']['height']);
    }

    public function test_le_formulaire_refuse_de_planifier_des_ratios_differents(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('posts.store'), $this->formulaire($this->items('portrait.jpg', 'carre.jpg')));

        $response->assertSessionHasErrors('media');
        $this->assertStringContainsString('4:5, 1:1', session('errors')->first('media'));
        $this->assertSame(0, Post::count());
    }

    public function test_publier_tout_de_suite_est_refuse_aussi(): void
    {
        $donnees = [...$this->formulaire($this->items('portrait.jpg', 'carre.jpg'), 'draft'), 'publish_now' => '1'];

        $this->actingAs($this->user)->postJson(route('posts.store'), $donnees)
            ->assertStatus(422)
            ->assertJsonPath('errors.media.0', fn (string $m) => str_contains($m, 'Carrousel Instagram'));
    }

    public function test_un_brouillon_reste_enregistrable(): void
    {
        $this->actingAs($this->user)
            ->post(route('posts.store'), $this->formulaire($this->items('portrait.jpg', 'carre.jpg'), 'draft'))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Post::count());
    }

    public function test_sans_instagram_les_ratios_mixtes_passent(): void
    {
        $this->actingAs($this->user)
            ->post(route('posts.store'), $this->formulaire($this->items('portrait.jpg', 'carre.jpg'), account: $this->facebook))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Post::count());
    }

    public function test_un_arrondi_de_redimensionnement_n_est_pas_un_conflit(): void
    {
        $this->actingAs($this->user)
            ->post(route('posts.store'), $this->formulaire($this->items('portrait.jpg', 'portrait-arrondi.jpg')))
            ->assertSessionHasNoErrors();
    }

    public function test_ce_qui_n_a_pas_de_dimensions_fiables_ne_bloque_pas(): void
    {
        $this->media('sans-dimensions.jpg', null, null);
        $this->media('rotation.mp4', 1920, 1080, 'video/mp4');
        $service = app(MediaRatioService::class);

        $this->assertNull($service->instagramConflict([
            ...$this->items('portrait.jpg', 'sans-dimensions.jpg', 'rotation.mp4'),
            ['url' => 'https://images.pexels.com/photos/1/a.jpeg', 'mimetype' => 'image/jpeg'],
            // Dimensions annoncees par le client : jamais crues.
            ['url' => 'https://exemple.test/b.jpg', 'width' => 1000, 'height' => 1000],
        ]));
    }

    public function test_la_mise_a_jour_web_refuse_aussi(): void
    {
        $post = Post::create([
            'user_id' => $this->user->id,
            'content_fr' => 'Texte',
            'media' => $this->items('portrait.jpg'),
            'status' => 'draft',
        ]);

        $this->actingAs($this->user)
            ->put(route('posts.update', $post), $this->formulaire($this->items('portrait.jpg', 'carre.jpg')))
            ->assertSessionHasErrors('media');

        $this->assertCount(1, $post->fresh()->media);
    }

    public function test_le_composer_s_affiche(): void
    {
        $this->actingAs($this->user)->get(route('posts.create'))
            ->assertOk()
            ->assertSee('Ratios différents', false);
    }

    public function test_le_bulk_refuse_avec_un_message(): void
    {
        $this->actingAs($this->user)->postJson(route('posts.bulk.saveRow'), [
            'content_fr' => 'Texte',
            'accounts' => [$this->instagram->id],
            'media' => array_map('json_encode', $this->items('portrait.jpg', 'carre.jpg')),
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, Post::count());
    }

    public function test_l_api_refuse_a_la_creation(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/posts', [
            'content_fr' => 'Texte',
            'status' => 'scheduled',
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'accounts' => [$this->instagram->id],
            'media' => $this->items('portrait.jpg', 'carre.jpg'),
        ])->assertStatus(422);
    }

    public function test_l_api_controle_l_etat_final_d_un_put_partiel(): void
    {
        Sanctum::actingAs($this->user);
        $post = $this->brouillonMixte();

        // Le PUT ne porte que le statut : medias et comptes sont en base.
        $this->putJson("/api/posts/{$post->id}", [
            'status' => 'scheduled',
            'scheduled_at' => now()->addDay()->toDateTimeString(),
        ])->assertStatus(422);

        $this->assertSame('draft', $post->fresh()->status);
    }

    public function test_l_api_ne_lance_pas_la_publication_d_un_brouillon_mixte(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson("/api/posts/{$this->brouillonMixte()->id}/publish")->assertStatus(422);
    }

    public function test_l_adapter_ne_contacte_pas_meta(): void
    {
        Http::fake();

        $result = (new InstagramAdapter)->publish($this->instagram, 'Texte', $this->items('portrait.jpg', 'carre.jpg'));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Carrousel Instagram', $result['error']);
        Http::assertNothingSent();
    }

    public function test_l_edition_affiche_le_ratio_des_anciens_posts(): void
    {
        $post = Post::create([
            'user_id' => $this->user->id,
            'content_fr' => 'Texte',
            'media' => [['url' => '/media/portrait.jpg']],
            'status' => 'draft',
        ]);

        $this->actingAs($this->user)->get(route('posts.edit', $post))
            ->assertOk()
            ->assertSee('accounts-changed', false)
            ->assertSee('4:5', false);
    }

    private function brouillonMixte(): Post
    {
        $post = Post::create([
            'user_id' => $this->user->id,
            'content_fr' => 'Texte',
            'media' => $this->items('portrait.jpg', 'carre.jpg'),
            'status' => 'draft',
        ]);

        PostPlatform::create([
            'post_id' => $post->id,
            'social_account_id' => $this->instagram->id,
            'platform_id' => $this->instagram->platform_id,
            'status' => 'pending',
        ]);

        return $post;
    }
}
