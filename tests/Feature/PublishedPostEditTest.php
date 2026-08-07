<?php

namespace Tests\Feature;

use App\Models\Partner;
use App\Models\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Modifier une publication DEJA PARTIE : compte rendu, partenaires, correction
 * de texte. Le circuit de publication ne doit jamais etre rouvert — les
 * post_platform portent les identifiants des publications reellement en ligne.
 */
class PublishedPostEditTest extends TestCase
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

    private function user(): User
    {
        $user = User::factory()->create(['role' => 'manager']);
        $user->socialAccounts()->attach($this->account->id, ['is_active' => true]);

        return $user;
    }

    private function publishedPost(User $user): Post
    {
        $post = Post::create([
            'user_id' => $user->id,
            'content_fr' => 'Texte original',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);

        PostPlatform::create([
            'post_id' => $post->id,
            'social_account_id' => $this->account->id,
            'platform_id' => $this->platform->id,
            'status' => 'published',
            'external_id' => 'fb-12345',
            'published_at' => now()->subDay(),
        ]);

        return $post;
    }

    public function test_l_ecran_d_edition_s_ouvre_sur_une_publication_deja_partie(): void
    {
        $user = $this->user();
        $post = $this->publishedPost($user);

        $response = $this->actingAs($user)->get(route('posts.edit', $post));

        $response->assertOk()
            ->assertSee('Publication déjà partie sur les réseaux')
            ->assertSee('Enregistrer dans RS-Max')
            // Les sections restent VISIBLES — on veut voir ce qui est parti —
            // mais grisees et rendues inertes.
            ->assertSee('Comptes de publication')
            ->assertSee('Date et heure de publication')
            ->assertSee('opacity-60 pointer-events-none select-none', false);

        $this->assertTrue($response->viewData('isPublished'));
    }

    public function test_le_texte_ne_peut_pas_etre_modifie_meme_par_une_requete_forgee(): void
    {
        $user = $this->user();
        $post = $this->publishedPost($user);

        $this->actingAs($user)
            ->put(route('posts.published.update', $post), [
                // Champs grises cote formulaire : ils doivent etre ignores ici,
                // sans quoi la fiche mentirait sur ce qui est en ligne.
                'content_fr' => 'Texte reecrit en douce',
                'hashtags' => '#pirate',
                'location_name' => 'Ailleurs',
            ])
            ->assertRedirect(route('posts.show', $post->id));

        $post->refresh();

        $this->assertSame('Texte original', $post->content_fr);
        $this->assertNull($post->hashtags);
        $this->assertNull($post->location_name);
        // Le statut et la date de publication ne bougent pas.
        $this->assertSame('published', $post->status);
        $this->assertNull($post->scheduled_at);

        // La ligne post_platform survit AVEC son identifiant d'origine : la
        // reconstruire ferait perdre le lien vers la publication en ligne.
        $this->assertCount(1, $post->postPlatforms);
        $this->assertSame('fb-12345', $post->postPlatforms->first()->external_id);
        $this->assertSame('published', $post->postPlatforms->first()->status);
    }

    public function test_on_peut_taguer_un_partenaire_a_posteriori(): void
    {
        $user = $this->user();
        $post = $this->publishedPost($user);
        $partner = Partner::create(['name' => 'Decathlon', 'slug' => Partner::slugFor('Decathlon')]);

        $this->actingAs($user)
            ->put(route('posts.published.update', $post), ['partners' => [$partner->id]])
            ->assertRedirect();

        $this->assertSame(['Decathlon'], $post->fresh()->partners->pluck('name')->all());
    }

    public function test_les_medias_rattaches_restent_modifiables(): void
    {
        $user = $this->user();
        $post = $this->publishedPost($user);

        // Les medias sont la reconstruction que RS-Max garde de la publication :
        // pouvoir la completer alimente le suivi d'usage des photos.
        $this->actingAs($user)
            ->put(route('posts.published.update', $post), [
                'media' => [json_encode(['url' => '/media/photo.jpg', 'mimetype' => 'image/jpeg'])],
            ])
            ->assertRedirect();

        $this->assertSame(
            [['url' => '/media/photo.jpg', 'mimetype' => 'image/jpeg']],
            $post->fresh()->media
        );
    }

    public function test_un_brouillon_passe_par_le_circuit_normal(): void
    {
        $user = $this->user();

        $brouillon = Post::create([
            'user_id' => $user->id,
            'content_fr' => 'Brouillon',
            'status' => 'draft',
        ]);

        $this->actingAs($user)
            ->put(route('posts.published.update', $brouillon), ['content_fr' => 'Modifie'])
            ->assertSessionHasErrors('status');

        $this->assertSame('Brouillon', $brouillon->fresh()->content_fr);
    }

    public function test_la_route_normale_refuse_toujours_une_publication_partie(): void
    {
        $user = $this->user();
        $post = $this->publishedPost($user);

        // Le garde-fou historique reste en place : c'est lui qui empeche de
        // relancer une publication depuis l'ecran d'edition.
        $this->actingAs($user)
            ->put(route('posts.update', $post), [
                'content_fr' => 'Tentative',
                'status' => 'draft',
                'accounts' => [$this->account->id],
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame('Texte original', $post->fresh()->content_fr);
    }

    public function test_un_autre_utilisateur_ne_peut_pas_modifier_la_publication(): void
    {
        $post = $this->publishedPost($this->user());
        $intrus = User::factory()->create(['role' => 'user']);

        $this->actingAs($intrus)
            ->put(route('posts.published.update', $post), ['content_fr' => 'Pirate'])
            ->assertForbidden();

        $this->assertSame('Texte original', $post->fresh()->content_fr);
    }
}
