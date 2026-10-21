<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Thread;
use App\Models\ThreadSegment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * L'API doit montrer le meme perimetre que l'interface web.
 *
 * Elle filtrait en dur sur `user_id`, sans jamais consulter
 * `User::seesAllContent()` : avec le jeton de l'admin, un
 * `GET /api/posts?status=scheduled` rendait ZERO ligne alors que la
 * publication existait et que sa fiche `/api/posts/{id}` s'ouvrait. Un
 * inventaire par l'API passait donc a cote de tout ce que l'autre avait
 * programme, sans la moindre erreur pour le signaler.
 */
class ApiCrossUserVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Platform $platform;

    private SocialAccount $compteDeCaroline;

    private User $xavier;

    private User $caroline;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform = Platform::create([
            'slug' => 'instagram',
            'name' => 'Instagram',
            'auth_type' => 'oauth2',
        ]);

        // Un compte auquel Xavier n'est PAS rattache.
        $this->compteDeCaroline = SocialAccount::create([
            'platform_id' => $this->platform->id,
            'platform_account_id' => '77',
            'name' => 'Compte de Caroline',
            'credentials' => ['access_token' => 't'],
        ]);

        $this->xavier = User::factory()->create(['role' => 'admin']);
        $this->caroline = User::factory()->create(['role' => 'user']);
        $this->caroline->socialAccounts()->attach($this->compteDeCaroline->id, ['is_active' => true]);
    }

    private function publicationPlanifieeDeCaroline(): Post
    {
        $post = Post::create([
            'user_id' => $this->caroline->id,
            'content_fr' => 'Reel programme par Caroline',
            'status' => 'scheduled',
            'scheduled_at' => now()->addDay(),
        ]);

        PostPlatform::create([
            'post_id' => $post->id,
            'platform_id' => $this->platform->id,
            'social_account_id' => $this->compteDeCaroline->id,
            'status' => 'pending',
        ]);

        return $post;
    }

    public function test_la_liste_api_montre_la_publication_planifiee_de_l_autre(): void
    {
        $post = $this->publicationPlanifieeDeCaroline();

        Sanctum::actingAs($this->xavier);

        $this->getJson('/api/posts?status=scheduled')
            ->assertOk()
            ->assertJsonFragment(['id' => $post->id]);
    }

    public function test_la_fiche_api_s_ouvre_dans_les_deux_sens(): void
    {
        $post = $this->publicationPlanifieeDeCaroline();

        Sanctum::actingAs($this->xavier);
        $this->getJson('/api/posts/'.$post->id)->assertOk();

        $postDeXavier = Post::create([
            'user_id' => $this->xavier->id,
            'content_fr' => 'Publication de Xavier',
            'status' => 'draft',
        ]);

        Sanctum::actingAs($this->caroline);
        $this->getJson('/api/posts/'.$postDeXavier->id)->assertOk();
    }

    public function test_la_liste_api_des_fils_montre_celui_de_l_autre(): void
    {
        $thread = Thread::create([
            'user_id' => $this->caroline->id,
            'title' => 'Fil de Caroline',
            'status' => 'scheduled',
            'scheduled_at' => now()->addDay(),
        ]);
        ThreadSegment::create([
            'thread_id' => $thread->id,
            'position' => 0,
            'content_fr' => 'Premier segment',
        ]);

        Sanctum::actingAs($this->xavier);

        $this->getJson('/api/threads')
            ->assertOk()
            ->assertJsonFragment(['id' => $thread->id]);
    }

    public function test_les_compteurs_de_stats_api_couvrent_les_deux_auteurs(): void
    {
        $this->publicationPlanifieeDeCaroline();

        Sanctum::actingAs($this->xavier);

        $this->getJson('/api/stats/overview')
            ->assertOk()
            ->assertJsonPath('posts.scheduled', 1);
    }

    /**
     * Voir n'est pas ecrire : l'API garde l'ecriture fermee sur le contenu
     * d'autrui, exactement comme l'interface web.
     */
    public function test_l_api_refuse_toujours_de_modifier_la_publication_de_l_autre(): void
    {
        $postDeXavier = Post::create([
            'user_id' => $this->xavier->id,
            'content_fr' => 'Publication de Xavier',
            'status' => 'draft',
        ]);

        Sanctum::actingAs($this->caroline);

        $this->putJson('/api/posts/'.$postDeXavier->id, ['content_fr' => 'modifie'])
            ->assertForbidden();
        $this->deleteJson('/api/posts/'.$postDeXavier->id)->assertForbidden();

        $this->assertSame('Publication de Xavier', $postDeXavier->fresh()->content_fr);
    }
}
