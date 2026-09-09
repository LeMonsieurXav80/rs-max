<?php

namespace Tests\Feature;

use App\Models\Partner;
use App\Models\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Stats\EmvService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Valorisation des retombées (EMV).
 *
 * Le point sensible n'est pas l'arithmétique : c'est l'honnêteté du total.
 * Un réseau sans vues ne vaut pas « 0 € d'impressions », il n'est pas mesurable
 * — et un total qui l'ignore silencieusement serait faux.
 */
class EmvTest extends TestCase
{
    use RefreshDatabase;

    private function platform(string $slug): Platform
    {
        // Les plateformes sont deja seedees : firstOrCreate, sinon l'unique saute.
        return Platform::firstOrCreate(
            ['slug' => $slug],
            ['name' => ucfirst($slug), 'auth_type' => 'oauth2'],
        );
    }

    /**
     * @param  array<string,mixed>  $metrics
     */
    private function diffusion(Platform $platform, array $metrics, ?Post $post = null): PostPlatform
    {
        $account = SocialAccount::create([
            'platform_id' => $platform->id,
            'platform_account_id' => (string) random_int(1000, 9999),
            'name' => 'Compte '.$platform->slug,
            'credentials' => ['access_token' => 't'],
        ]);

        $post ??= Post::create([
            'user_id' => User::factory()->create()->id,
            'content_fr' => 'Contenu',
            'status' => 'published',
        ]);

        return PostPlatform::create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'platform_id' => $platform->id,
            'status' => 'published',
            'external_id' => (string) random_int(1000, 9999),
            'published_at' => now(),
            'metrics' => $metrics,
        ]);
    }

    public function test_methode_impressions_applique_le_cpm_du_reseau(): void
    {
        // Facebook : CPM 7,5 € → 50 000 vues = 375 €
        $emv = app(EmvService::class)->forMetrics('facebook', ['views' => 50000]);

        $this->assertSame(375.0, $emv['cpm']);
    }

    public function test_methode_engagement_somme_les_valeurs_unitaires(): void
    {
        // Facebook : 10 likes × 0,05 + 4 commentaires × 0,30 + 2 partages × 0,60
        $emv = app(EmvService::class)->forMetrics('facebook', [
            'views' => 1000,
            'likes' => 10,
            'comments' => 4,
            'shares' => 2,
        ]);

        $this->assertSame(2.9, $emv['ayzenberg']);
    }

    public function test_la_vue_ne_compte_pas_deux_fois(): void
    {
        // Sans quoi cumuler les deux méthodes doublerait l'audience.
        $emv = app(EmvService::class)->forMetrics('facebook', ['views' => 100000]);

        $this->assertSame(0.0, $emv['ayzenberg']);
    }

    public function test_un_reseau_sans_vues_n_est_pas_valorise_a_zero(): void
    {
        $emv = app(EmvService::class)->forMetrics('bluesky', ['views' => 0, 'likes' => 10]);

        // null, pas 0.0 : la méthode n'est pas calculable, ce n'est pas un montant nul.
        $this->assertNull($emv['cpm']);
        $this->assertSame(0.4, $emv['ayzenberg']);
    }

    public function test_l_agregat_signale_les_reseaux_non_couverts(): void
    {
        $fb = $this->platform('facebook');
        $bsky = $this->platform('bluesky');

        $this->diffusion($fb, ['views' => 10000, 'likes' => 100]);
        $this->diffusion($bsky, ['likes' => 50]);

        $items = PostPlatform::with('platform')->whereNotNull('metrics')->get();

        $emv = app(EmvService::class)->forItems($items);

        $this->assertSame(75.0, $emv['cpm']);           // 10 000 vues × 7,5 / 1000
        $this->assertSame(7.0, $emv['ayzenberg']);      // 100 × 0,05 + 50 × 0,04
        $this->assertSame(82.0, $emv['total']);
        $this->assertSame(2, $emv['coverage']['items']);
        $this->assertSame(1, $emv['coverage']['measurable']);
        $this->assertSame(['bluesky'], $emv['coverage']['uncovered_platforms']);
    }

    public function test_les_tarifs_sont_surchargeables_sans_deploiement(): void
    {
        $emv = app(EmvService::class);
        $emv->saveOverrides(['platforms' => ['facebook' => ['cpm' => 15.0]]]);

        // Instance neuve : la surcharge vient de la base, pas d'un cache local.
        $this->assertSame(150.0, app(EmvService::class)->forMetrics('facebook', ['views' => 10000])['cpm']);

        // Une surcharge partielle ne doit pas effacer les valeurs par action.
        $this->assertSame(0.05, app(EmvService::class)->ratesFor('facebook')['actions']['like']);
    }

    public function test_le_compte_rendu_partenaire_affiche_l_emv(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $partner = Partner::create([
            'name' => 'Marque Test',
            'slug' => Partner::slugFor('Marque Test'),
            'origin' => 'manual',
            'is_active' => true,
        ]);

        $post = Post::create([
            'user_id' => $manager->id,
            'content_fr' => 'Contenu',
            'status' => 'published',
        ]);
        $this->diffusion($this->platform('facebook'), ['views' => 20000, 'likes' => 10], $post);
        $partner->posts()->attach($post->id, ['source' => 'manual']);

        $response = $this->actingAs($manager)->get(route('partners.posts', $partner));

        $response->assertOk();
        // 20 000 × 7,5 / 1000 = 150 € + 10 × 0,05 = 150,50 €
        $response->assertSee('150,50');
    }

    public function test_l_api_expose_l_emv_du_partenaire(): void
    {
        $user = User::factory()->create();
        $partner = Partner::create([
            'name' => 'Marque API',
            'slug' => Partner::slugFor('Marque API'),
            'origin' => 'manual',
            'is_active' => true,
        ]);

        $post = Post::create([
            'user_id' => $user->id,
            'content_fr' => 'Contenu',
            'status' => 'published',
        ]);
        $this->diffusion($this->platform('instagram'), ['views' => 10000, 'likes' => 100], $post);
        $partner->posts()->attach($post->id, ['source' => 'auto']);

        $response = $this->actingAs($user)->getJson("/api/partners/{$partner->id}/emv");

        $response->assertOk()
            // 85 et non 85.0 : json_encode laisse tomber la decimale nulle.
            ->assertJsonPath('emv.cpm', 85)          // 10 000 × 8,5 / 1000
            ->assertJsonPath('emv.ayzenberg', 6)     // 100 × 0,06
            ->assertJsonPath('emv.coverage.items', 1);
    }
}
