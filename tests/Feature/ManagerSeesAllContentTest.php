<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Thread;
use App\Models\ThreadSegment;
use App\Models\ThreadSegmentPlatform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A deux sur l'outil, chacun doit voir ce que l'autre publie.
 *
 * `User::seesAllContent()` (manager ou admin) ouvre le droit de REGARD sur les
 * publications, les fils, la boite de reception et les stats, quel que soit
 * l'auteur et quel que soit le compte social. Il n'ouvre PAS l'ecriture :
 * modifier, taguer et supprimer le contenu d'autrui restent sur `isAdmin()`.
 * Seule exception, demandee explicitement : relancer un reseau tombe en erreur.
 *
 * Ces tests prennent le point de vue du manager, celui qui change ; le cas
 * admin est couvert par PostListVisibilityTest.
 */
class ManagerSeesAllContentTest extends TestCase
{
    use RefreshDatabase;

    private Platform $platform;

    private SocialAccount $compteDeXavier;

    private User $xavier;

    private User $caroline;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform = Platform::create([
            'slug' => 'facebook',
            'name' => 'Facebook',
            'auth_type' => 'oauth2',
        ]);

        // Un compte social auquel Caroline n'est PAS rattachee.
        $this->compteDeXavier = SocialAccount::create([
            'platform_id' => $this->platform->id,
            'platform_account_id' => '42',
            'name' => 'Page de Xavier',
            'credentials' => ['access_token' => 't'],
        ]);

        $this->xavier = User::factory()->create(['role' => 'admin']);
        $this->xavier->socialAccounts()->attach($this->compteDeXavier->id, ['is_active' => true]);

        $this->caroline = User::factory()->create(['role' => 'manager']);
    }

    private function publicationDeXavier(string $statutDiffusion = 'published'): Post
    {
        $post = Post::create([
            'user_id' => $this->xavier->id,
            'content_fr' => 'Publication signee Xavier',
            'status' => $statutDiffusion === 'published' ? 'published' : 'failed',
            'published_at' => now()->subHour(),
        ]);

        PostPlatform::create([
            'post_id' => $post->id,
            'platform_id' => $this->platform->id,
            'social_account_id' => $this->compteDeXavier->id,
            'status' => $statutDiffusion,
            'published_at' => $statutDiffusion === 'published' ? now()->subHour() : null,
        ]);

        return $post;
    }

    private function filDeXavier(string $statutDiffusion = 'published'): Thread
    {
        $thread = Thread::create([
            'user_id' => $this->xavier->id,
            'title' => 'Fil signe Xavier',
            'status' => $statutDiffusion === 'published' ? 'published' : 'failed',
            'published_at' => now()->subHour(),
        ]);

        $thread->socialAccounts()->attach($this->compteDeXavier->id, [
            'platform_id' => $this->platform->id,
        ]);

        $segment = ThreadSegment::create([
            'thread_id' => $thread->id,
            'position' => 0,
            'content_fr' => 'Premier segment',
        ]);

        ThreadSegmentPlatform::create([
            'thread_segment_id' => $segment->id,
            'social_account_id' => $this->compteDeXavier->id,
            'platform_id' => $this->platform->id,
            'status' => $statutDiffusion,
        ]);

        return $thread;
    }

    // ---------------------------------------------------------------- lecture

    public function test_le_manager_voit_les_publications_de_l_admin(): void
    {
        $this->publicationDeXavier();

        $this->actingAs($this->caroline)->get('/posts')
            ->assertOk()
            ->assertSee('Publication signee Xavier');
    }

    /**
     * Sans le nom de l'auteur, une liste commune devient illisible : on ne sait
     * plus qui a publie quoi. Le badge etait reserve a l'admin.
     */
    public function test_la_liste_nomme_l_auteur_des_publications_des_autres(): void
    {
        $this->publicationDeXavier();

        $this->actingAs($this->caroline)->get('/posts')
            ->assertOk()
            ->assertSee($this->xavier->name);
    }

    public function test_le_manager_ouvre_la_fiche_d_une_publication_de_l_admin(): void
    {
        $post = $this->publicationDeXavier();

        $this->actingAs($this->caroline)->get('/posts/'.$post->id)
            ->assertOk()
            ->assertSee('Publication signee Xavier');
    }

    /**
     * Le coeur de la demande : « il faut qu'elle voie sur tous les reseaux ».
     * Les diffusions etaient filtrees par les comptes rattaches au LECTEUR, si
     * bien que la fiche montrait une partie des reseaux sans rien signaler.
     */
    public function test_la_fiche_montre_les_reseaux_sur_lesquels_le_manager_n_est_pas_rattache(): void
    {
        $post = $this->publicationDeXavier();

        $this->actingAs($this->caroline)->get('/posts/'.$post->id)
            ->assertOk()
            ->assertSee('Page de Xavier');
    }

    public function test_le_manager_voit_les_fils_de_l_admin(): void
    {
        $this->filDeXavier();

        $this->actingAs($this->caroline)->get('/threads')
            ->assertOk()
            ->assertSee('Fil signe Xavier');
    }

    public function test_le_manager_ouvre_la_fiche_d_un_fil_de_l_admin(): void
    {
        $thread = $this->filDeXavier();

        $this->actingAs($this->caroline)->get('/threads/'.$thread->id)
            ->assertOk()
            ->assertSee('Fil signe Xavier');
    }

    public function test_le_manager_voit_les_comptes_des_autres_dans_les_stats(): void
    {
        $this->actingAs($this->caroline)->get('/stats')
            ->assertOk()
            ->assertSee('Page de Xavier');
    }

    /**
     * Les ecrans sont RENDUS, pas seulement construits : appeler la methode du
     * controleur renvoie un objet View sans l'evaluer, ce qui laisse passer une
     * variable manquante dans le Blade. `compact('isAdmin')` a survecu ainsi au
     * renommage de la variable.
     */
    public function test_les_ecrans_partages_se_rendent_pour_le_manager(): void
    {
        $this->publicationDeXavier();
        $this->filDeXavier();

        foreach (['/dashboard', '/posts', '/threads', '/stats', '/inbox'] as $url) {
            $this->actingAs($this->caroline)->get($url)
                ->assertOk("l'ecran {$url} doit se rendre sans erreur");
        }
    }

    // ------------------------------------------------------- ecriture refusee

    public function test_le_manager_ne_peut_pas_modifier_une_publication_de_l_admin(): void
    {
        $post = $this->publicationDeXavier();

        $this->actingAs($this->caroline)->get('/posts/'.$post->id.'/edit')->assertForbidden();

        $this->actingAs($this->caroline)
            ->put('/posts/'.$post->id, ['content_fr' => 'Detourne'])
            ->assertForbidden();

        $this->assertSame('Publication signee Xavier', $post->fresh()->content_fr);
    }

    public function test_le_manager_ne_peut_pas_supprimer_une_publication_de_l_admin(): void
    {
        $post = $this->publicationDeXavier();

        $this->actingAs($this->caroline)->delete('/posts/'.$post->id)->assertForbidden();

        $this->assertDatabaseHas('posts', ['id' => $post->id]);
    }

    public function test_le_manager_ne_peut_pas_modifier_un_fil_de_l_admin(): void
    {
        $thread = $this->filDeXavier();

        $this->actingAs($this->caroline)->get('/threads/'.$thread->id.'/edit')->assertForbidden();
        $this->actingAs($this->caroline)->delete('/threads/'.$thread->id)->assertForbidden();

        $this->assertDatabaseHas('threads', ['id' => $thread->id]);
    }

    // -------------------------------------------------------------- relance

    /**
     * Relancer une diffusion EN ECHEC est permis : voir l'echec d'un collegue
     * sans pouvoir le rattraper n'aurait servi a rien.
     *
     * On ne verifie pas la publication elle-meme (le jeton est bidon) mais
     * l'absence de 403 : le garde-fou d'autorisation s'ouvre bien.
     */
    public function test_le_manager_peut_relancer_un_reseau_en_echec(): void
    {
        $post = $this->publicationDeXavier('failed');
        $diffusion = $post->postPlatforms()->first();

        $response = $this->actingAs($this->caroline)
            ->postJson('/posts/platform/'.$diffusion->id.'/publish');

        $this->assertNotSame(403, $response->status(), 'reponse: '.$response->content());
    }

    /**
     * Mais pas une diffusion EN ATTENTE : ce serait publier le brouillon d'un
     * collegue a sa place, a l'heure qu'elle choisit.
     */
    public function test_le_manager_ne_peut_pas_publier_une_diffusion_en_attente_de_l_admin(): void
    {
        $post = $this->publicationDeXavier('pending');
        $diffusion = $post->postPlatforms()->first();

        $this->actingAs($this->caroline)
            ->postJson('/posts/platform/'.$diffusion->id.'/publish')
            ->assertForbidden();
    }

    /**
     * Ni remettre a zero une diffusion DEJA EN LIGNE : `resetOne` efface
     * l'external_id, donc le lien vers la publication reelle.
     */
    public function test_le_manager_ne_peut_pas_remettre_a_zero_une_diffusion_publiee_de_l_admin(): void
    {
        $post = $this->publicationDeXavier('published');
        $diffusion = $post->postPlatforms()->first();

        $this->actingAs($this->caroline)
            ->postJson('/posts/platform/'.$diffusion->id.'/reset')
            ->assertForbidden();

        $this->assertSame('published', $diffusion->fresh()->status);
    }

    // ------------------------------------------- l'utilisateur simple cloisonne

    public function test_un_utilisateur_simple_ne_voit_toujours_rien_des_autres(): void
    {
        $post = $this->publicationDeXavier();
        $this->filDeXavier();

        $simple = User::factory()->create(['role' => 'user']);

        $this->actingAs($simple)->get('/posts')
            ->assertOk()
            ->assertDontSee('Publication signee Xavier');

        $this->actingAs($simple)->get('/threads')
            ->assertOk()
            ->assertDontSee('Fil signe Xavier');

        $this->actingAs($simple)->get('/posts/'.$post->id)->assertForbidden();
    }
}
