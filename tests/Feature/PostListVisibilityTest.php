<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ce que l'admin voit dans /posts.
 *
 * La liste exigeait que la publication porte au moins une diffusion sur un
 * compte social rattache a l'utilisateur COURANT. L'admin, non rattache aux
 * comptes des autres, ne voyait donc ni leurs publications dans la liste ni
 * dans le calendrier — alors que la fiche /posts/{id} s'ouvrait sans erreur.
 * Un echec de publication chez un autre utilisateur devenait introuvable.
 */
class PostListVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Platform $platform;

    private SocialAccount $accountDeCaroline;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform = Platform::create([
            'slug' => 'facebook',
            'name' => 'Facebook',
            'auth_type' => 'oauth2',
        ]);

        $this->accountDeCaroline = SocialAccount::create([
            'platform_id' => $this->platform->id,
            'platform_account_id' => '42',
            'name' => 'Page de Caroline',
            'credentials' => ['access_token' => 't'],
        ]);
    }

    /**
     * Une publication en erreur, partie uniquement sur le compte de Caroline.
     */
    private function publicationDeCaroline(): Post
    {
        $caroline = User::factory()->create(['role' => 'user']);
        $caroline->socialAccounts()->attach($this->accountDeCaroline->id, ['is_active' => true]);

        $post = Post::create([
            'user_id' => $caroline->id,
            'content_fr' => 'La publication introuvable',
            'status' => 'failed',
            'scheduled_at' => now()->subHour(),
        ]);

        PostPlatform::create([
            'post_id' => $post->id,
            'platform_id' => $this->platform->id,
            'social_account_id' => $this->accountDeCaroline->id,
            'status' => 'failed',
            'error_message' => 'Erreur de test',
        ]);

        return $post;
    }

    public function test_admin_voit_la_publication_d_un_autre_utilisateur(): void
    {
        $post = $this->publicationDeCaroline();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get('/posts')
            ->assertOk()
            ->assertSee('La publication introuvable');

        $this->actingAs($admin)->get('/posts?status=failed')
            ->assertOk()
            ->assertSee('La publication introuvable');

        $this->actingAs($admin)->get('/posts/'.$post->id)->assertOk();
    }

    public function test_admin_voit_la_publication_dans_le_calendrier(): void
    {
        $this->publicationDeCaroline();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get('/posts?month='.now()->subHour()->format('Y-m'))
            ->assertOk()
            ->assertSee('La publication introuvable');
    }

    public function test_admin_peut_filtrer_sur_le_compte_d_un_autre_utilisateur(): void
    {
        $this->publicationDeCaroline();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get('/posts?account_id='.$this->accountDeCaroline->id)
            ->assertOk()
            ->assertSee('La publication introuvable');
    }

    /**
     * La liste deroulante de filtrage doit REELLEMENT contenir le compte, pas
     * seulement laisser passer un account_id saisi a la main.
     *
     * Ce test existe parce qu'un premier correctif filtrait les comptes sur
     * `social_accounts.is_active`, colonne partie sur le pivot en fevrier 2026.
     * En MySQL c'est une erreur 1054 franche ; en SQLite, un identifiant inconnu
     * entre guillemets doubles est degrade en CHAINE LITTERALE (`'is_active' = 1`
     * est faux), la requete rend zero ligne sans erreur. Le menu etait donc vide
     * en test comme en prod, et seule la prod le disait.
     *
     * On verifie la presence de l'optgroup : il n'apparait que si la collection
     * de comptes est non vide, la ou le nom du compte peut figurer ailleurs
     * dans la page.
     */
    public function test_le_menu_de_filtrage_de_l_admin_contient_les_comptes_des_autres(): void
    {
        $this->publicationDeCaroline();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get('/posts')
            ->assertOk()
            ->assertSee('<optgroup label="Facebook">', false);
    }

    public function test_un_utilisateur_ordinaire_ne_voit_pas_les_publications_des_autres(): void
    {
        $this->publicationDeCaroline();

        $autre = User::factory()->create(['role' => 'user']);

        $this->actingAs($autre)->get('/posts')
            ->assertOk()
            ->assertDontSee('La publication introuvable');
    }
}
