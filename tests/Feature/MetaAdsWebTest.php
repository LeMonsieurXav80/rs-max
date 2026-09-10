<?php

namespace Tests\Feature;

use App\Models\MetaAdsBoost;
use App\Models\MetaAudience;
use App\Models\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Les écrans Publicités.
 *
 * Le point à ne pas lâcher : l'interface passe par les mêmes garde-fous que
 * l'API. Un écran qui contournerait un plafond serait une porte dérobée, et
 * c'est exactement le genre de chose qu'on ne remarque qu'à la facture.
 */
class MetaAdsWebTest extends TestCase
{
    use RefreshDatabase;

    private function manager(bool $writeEnabled = true): User
    {
        Setting::setEncrypted('meta_ads_token', 'EAA'.str_repeat('x', 40));
        Setting::set('meta_ads_account_id', 'act_123');
        config(['meta_ads.write_enabled' => $writeEnabled]);

        return User::factory()->create(['role' => 'manager']);
    }

    private function diffusion(): PostPlatform
    {
        $platform = Platform::firstOrCreate(['slug' => 'instagram'], ['name' => 'Instagram', 'auth_type' => 'oauth2']);
        $account = SocialAccount::create([
            'platform_id' => $platform->id,
            'platform_account_id' => '178414',
            'name' => 'Compte IG',
            'credentials' => ['access_token' => 't'],
        ]);
        $post = Post::create(['user_id' => User::factory()->create()->id, 'content_fr' => 'Contenu', 'status' => 'published']);

        return PostPlatform::create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'platform_id' => $platform->id,
            'status' => 'published',
            'external_id' => '179049',
            'published_at' => now(),
        ]);
    }

    private function fakeMeta(): void
    {
        $n = 0;
        Http::fake(['graph.facebook.com/*' => function ($request) use (&$n) {
            if ($request->method() === 'POST') {
                return Http::response(['id' => 'created_'.(++$n)]);
            }

            return Http::response(['data' => [], 'id' => 'x', 'name' => 'x', 'status' => 'PAUSED', 'currency' => 'EUR']);
        }]);
    }

    public function test_la_liste_des_campagnes_s_affiche(): void
    {
        $this->fakeMeta();

        $this->actingAs($this->manager())->get('/ads')
            ->assertOk()
            ->assertSee('Campagnes');
    }

    public function test_un_simple_utilisateur_n_accede_pas_aux_publicites(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'user']))->get('/ads')->assertStatus(403);
    }

    public function test_le_formulaire_de_sponsorisation_propose_objectif_audience_budget_et_duree(): void
    {
        $this->fakeMeta();
        MetaAudience::create(['name' => 'Portugal', 'spec' => ['countries' => ['PT']], 'is_default' => true]);

        $this->actingAs($this->manager())->get('/ads/boost/'.$this->diffusion()->id)
            ->assertOk()
            ->assertSee('1. Objectif')
            ->assertSee('2. Audience')
            ->assertSee('3. Budget et durée')
            ->assertSee('Portugal');
    }

    public function test_l_interface_simule_sans_rien_creer(): void
    {
        $this->fakeMeta();

        $response = $this->actingAs($this->manager())
            ->postJson('/ads/boost/'.$this->diffusion()->id, [
                'objective' => 'OUTCOME_ENGAGEMENT',
                'optimization_goal' => 'POST_ENGAGEMENT',
                'budget' => 20,
                'budget_type' => 'lifetime',
                'days' => 7,
                'dry_run' => 1,
            ]);

        $response->assertOk()->assertJsonPath('dry_run', true);
        $this->assertSame(0, MetaAdsBoost::count());
    }

    public function test_l_interface_cree_la_campagne_en_pause(): void
    {
        $this->fakeMeta();

        $this->actingAs($this->manager())
            ->post('/ads/boost/'.$this->diffusion()->id, [
                'objective' => 'OUTCOME_ENGAGEMENT',
                'optimization_goal' => 'POST_ENGAGEMENT',
                'budget' => 20,
                'budget_type' => 'lifetime',
                'days' => 7,
                'dry_run' => 0,
            ])
            ->assertRedirect();

        $this->assertSame('paused', MetaAdsBoost::first()->status);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && ($r['status'] ?? null) === 'ACTIVE');
    }

    public function test_l_interface_obeit_au_plafond_absolu(): void
    {
        $this->fakeMeta();
        Setting::set('meta_ads_max_daily_budget', '10');

        $this->actingAs($this->manager())
            ->post('/ads/boost/'.$this->diffusion()->id, [
                'objective' => 'OUTCOME_ENGAGEMENT',
                'optimization_goal' => 'POST_ENGAGEMENT',
                'budget' => 100,
                'budget_type' => 'daily',
                'days' => 5,
                'dry_run' => 0,
                'force' => 1, // le plafond absolu ignore `force`, c'est un mur
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, MetaAdsBoost::count());
    }

    public function test_l_ecriture_fermee_bloque_aussi_l_interface(): void
    {
        $this->fakeMeta();

        $this->actingAs($this->manager(writeEnabled: false))
            ->post('/ads/boost/'.$this->diffusion()->id, [
                'objective' => 'OUTCOME_ENGAGEMENT',
                'optimization_goal' => 'POST_ENGAGEMENT',
                'budget' => 20,
                'budget_type' => 'lifetime',
                'days' => 7,
                'dry_run' => 0,
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, MetaAdsBoost::count());
    }

    public function test_les_ecrans_audiences_et_campagne_s_affichent(): void
    {
        $this->fakeMeta();
        $audience = MetaAudience::create(['name' => 'Portugal', 'spec' => ['countries' => ['PT']]]);
        $manager = $this->manager();

        $this->actingAs($manager)->get('/ads/audiences')->assertOk()->assertSee('Portugal');
        $this->actingAs($manager)->get('/ads/audiences/create')->assertOk();
        $this->actingAs($manager)->get(route('ads.audiences.edit', $audience))->assertOk();
        $this->actingAs($manager)->get('/ads/120000000')->assertOk();
    }

    public function test_une_audience_se_cree_depuis_le_formulaire(): void
    {
        $this->fakeMeta();

        $this->actingAs($this->manager())->post('/ads/audiences', [
            'name' => 'Lisbonne',
            'spec' => [
                'countries' => ['PT'],
                'age_min' => 25,
                'age_max' => 45,
                // Le formulaire transporte les listes riches en JSON.
                'interests' => json_encode([['id' => '6003', 'name' => 'Surf']]),
                'publisher_platforms' => ['instagram'],
            ],
        ])->assertRedirect(route('ads.audiences.index'));

        $audience = MetaAudience::first();

        $this->assertSame('Surf', $audience->spec['interests'][0]['name']);
        $this->assertSame('6003', $audience->toTargetingSpec()['flexible_spec'][0]['interests'][0]['id']);
    }
}
