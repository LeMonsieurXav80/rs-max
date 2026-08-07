<?php

namespace Tests\Feature;

use App\Models\ExternalPost;
use App\Models\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Couvre le flux des publications natives : ce qui doit y apparaitre, ce qui
 * doit en etre masque (publications emises par RS-Max, cartes ecartees), et la
 * normalisation des medias remontes par les services d'import.
 */
class ExternalFeedTest extends TestCase
{
    use RefreshDatabase;

    private Platform $platform;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform = Platform::create([
            'slug' => 'facebook',
            'name' => 'Facebook',
            'auth_type' => 'oauth2',
        ]);

        $this->account = SocialAccount::create([
            'platform_id' => $this->platform->id,
            'platform_account_id' => '42',
            'name' => 'Page de test',
            'credentials' => ['access_token' => 't'],
        ]);
    }

    private function manager(): User
    {
        $user = User::factory()->create(['role' => 'manager']);
        $user->socialAccounts()->attach($this->account->id, ['is_active' => true]);

        return $user;
    }

    private function externalPost(array $attributes = []): ExternalPost
    {
        return ExternalPost::create(array_merge([
            'social_account_id' => $this->account->id,
            'platform_id' => $this->platform->id,
            'external_id' => 'ext-'.uniqid(),
            'content' => 'Publication faite a la main',
            'post_url' => 'https://facebook.com/post',
            'published_at' => now()->subDay(),
            'metrics' => ['views' => 10, 'likes' => 2, 'comments' => 0, 'shares' => 0],
        ], $attributes));
    }

    public function test_le_flux_liste_les_publications_natives(): void
    {
        $post = $this->externalPost(['content' => 'Coucou depuis mon telephone']);

        $this->actingAs($this->manager())
            ->get(route('external.index'))
            ->assertOk()
            ->assertSee('Coucou depuis mon telephone');

        $this->assertTrue(ExternalPost::adoptable()->where('id', $post->id)->exists());
    }

    public function test_une_publication_emise_par_rs_max_est_masquee(): void
    {
        $user = $this->manager();

        $post = Post::create([
            'user_id' => $user->id,
            'content_fr' => 'Publiee par RS-Max',
            'status' => 'published',
        ]);

        PostPlatform::create([
            'post_id' => $post->id,
            'social_account_id' => $this->account->id,
            'platform_id' => $this->platform->id,
            'status' => 'published',
            'external_id' => 'deja-connue',
            'published_at' => now(),
        ]);

        // Le meme post revient dans l'import : il ne doit pas etre proposable.
        $this->externalPost([
            'external_id' => 'deja-connue',
            'content' => 'Publiee par RS-Max',
        ]);

        $this->assertSame(0, ExternalPost::adoptable()->count());

        $this->actingAs($user)
            ->get(route('external.index'))
            ->assertOk()
            ->assertDontSee('Publiee par RS-Max');
    }

    public function test_ecarter_puis_remettre_une_publication_dans_le_flux(): void
    {
        $user = $this->manager();
        $post = $this->externalPost();

        $this->actingAs($user)
            ->post(route('external.ignore'), ['ids' => [$post->id]])
            ->assertRedirect();

        $this->assertNotNull($post->fresh()->ignored_at);
        $this->assertSame(0, ExternalPost::adoptable()->count());

        $this->actingAs($user)
            ->post(route('external.restore'), ['ids' => [$post->id]])
            ->assertRedirect();

        $this->assertNull($post->fresh()->ignored_at);
    }

    public function test_on_ne_peut_pas_ecarter_la_publication_d_un_compte_qui_n_est_pas_le_sien(): void
    {
        $autre = User::factory()->create(['role' => 'manager']);
        $post = $this->externalPost();

        // Le compte social n'est rattache a personne d'autre : l'id est ignore.
        $this->actingAs($autre)
            ->post(route('external.ignore'), ['ids' => [$post->id]])
            ->assertRedirect();

        $this->assertNull($post->fresh()->ignored_at);
    }

    public function test_une_publication_adoptee_sort_du_flux(): void
    {
        $user = $this->manager();

        $post = Post::create([
            'user_id' => $user->id,
            'content_fr' => 'Adoptee',
            'status' => 'published',
        ]);

        $external = $this->externalPost([
            'adopted_post_id' => $post->id,
            'adopted_at' => now(),
        ]);

        $this->assertTrue($external->isAdopted());
        $this->assertSame(0, ExternalPost::adoptable()->count());
    }

    public function test_les_medias_sont_normalises_et_dedupliques(): void
    {
        $external = $this->externalPost([
            'media' => [
                ['url' => 'https://cdn/a.jpg', 'type' => 'image'],
                ['url' => 'https://cdn/a.jpg', 'type' => 'image'], // doublon
                ['url' => 'https://cdn/b.mp4', 'type' => 'video', 'thumbnail_url' => 'https://cdn/b.jpg'],
                ['url' => null],
            ],
        ]);

        $items = $external->mediaItems();

        $this->assertCount(2, $items);
        $this->assertSame('https://cdn/a.jpg', $items[0]['url']);
        $this->assertSame('video', $items[1]['type']);
        // La vignette prefere la miniature au fichier source.
        $this->assertSame('https://cdn/a.jpg', $external->thumbnailUrl());
    }

    public function test_les_anciennes_lignes_sans_media_retombent_sur_media_url(): void
    {
        $external = $this->externalPost([
            'media' => null,
            'media_url' => 'https://cdn/legacy.jpg',
        ]);

        $this->assertSame(
            [['url' => 'https://cdn/legacy.jpg', 'type' => 'image', 'thumbnail_url' => null, 'external_media_id' => null]],
            $external->mediaItems()
        );
    }

    public function test_le_tableau_ouvre_une_colonne_par_reseau(): void
    {
        $instagram = Platform::create([
            'slug' => 'instagram',
            'name' => 'Instagram',
            'auth_type' => 'oauth2',
        ]);

        $compteIg = SocialAccount::create([
            'platform_id' => $instagram->id,
            'platform_account_id' => '99',
            'name' => 'Compte Insta',
            'credentials' => ['access_token' => 't'],
        ]);

        $user = $this->manager();
        $user->socialAccounts()->attach($compteIg->id, ['is_active' => true]);

        $this->externalPost(['content' => 'Cote Facebook']);
        ExternalPost::create([
            'social_account_id' => $compteIg->id,
            'platform_id' => $instagram->id,
            'external_id' => 'ig-1',
            'content' => 'Cote Instagram',
            'published_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($user)->get(route('external.index'));

        $response->assertOk()
            ->assertSee('Cote Facebook')
            ->assertSee('Cote Instagram');

        $columns = $response->viewData('columns');
        $this->assertCount(2, $columns);
        $this->assertSame(['Facebook', 'Instagram'], $columns->pluck('platform.name')->all());
    }

    public function test_ne_garder_qu_un_compte_ne_laisse_qu_une_colonne(): void
    {
        $instagram = Platform::create([
            'slug' => 'instagram',
            'name' => 'Instagram',
            'auth_type' => 'oauth2',
        ]);

        $compteIg = SocialAccount::create([
            'platform_id' => $instagram->id,
            'platform_account_id' => '99',
            'name' => 'Compte Insta',
            'credentials' => ['access_token' => 't'],
        ]);

        $user = $this->manager();
        $user->socialAccounts()->attach($compteIg->id, ['is_active' => true]);

        $this->externalPost(['content' => 'Cote Facebook']);

        $response = $this->actingAs($user)
            ->get(route('external.index', ['accounts' => [$compteIg->id]]));

        $columns = $response->assertOk()->viewData('columns');

        $this->assertCount(1, $columns);
        $this->assertSame('Instagram', $columns->first()['platform']->name);
        $response->assertDontSee('Cote Facebook');
    }

    public function test_un_simple_utilisateur_n_accede_pas_au_flux(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->get(route('external.index'))
            ->assertForbidden();
    }
}
