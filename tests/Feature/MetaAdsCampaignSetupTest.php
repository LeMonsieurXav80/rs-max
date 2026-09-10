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
 * Choix de l'objectif, de l'audience, du budget et de la durée.
 *
 * Ce qui se joue ici : ces quatre réglages ne sont plus en dur. Les tests
 * portent donc moins sur « l'appel part » que sur « ce qui part est ce qui a
 * été demandé » — un ciblage silencieusement remplacé ou un budget quotidien
 * pris pour un total sont des erreurs qui se paient en argent réel.
 */
class MetaAdsCampaignSetupTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        Setting::setEncrypted('meta_ads_token', 'EAA'.str_repeat('x', 40));
        Setting::set('meta_ads_account_id', 'act_123');
        config(['meta_ads.write_enabled' => true]);

        return User::factory()->create(['role' => 'manager']);
    }

    private function diffusion(string $slug = 'instagram'): PostPlatform
    {
        $platform = Platform::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug), 'auth_type' => 'oauth2']);
        $account = SocialAccount::create([
            'platform_id' => $platform->id,
            'platform_account_id' => '178414',
            'name' => 'Compte '.$slug,
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

            return Http::response(['id' => 'x', 'name' => 'x', 'status' => 'PAUSED']);
        }]);
    }

    private function audience(array $spec = []): MetaAudience
    {
        return MetaAudience::create([
            'name' => 'Portugal surf',
            'spec' => $spec + [
                'countries' => ['PT'],
                'age_min' => 25,
                'age_max' => 45,
                'genders' => [2],
                'interests' => [['id' => '6003139266461', 'name' => 'Surf']],
                'publisher_platforms' => ['instagram'],
                'instagram_positions' => ['stream', 'reels'],
            ],
        ]);
    }

    /** Le catalogue existe pour que le couple objectif/optimisation ne se devine pas. */
    public function test_le_catalogue_des_objectifs_est_expose(): void
    {
        $response = $this->actingAs($this->manager())->getJson('/api/meta-ads/objectives');

        $response->assertOk()
            ->assertJsonPath('objectives.OUTCOME_ENGAGEMENT.default_goal', 'POST_ENGAGEMENT')
            ->assertJsonPath('limits.max_days', (int) config('meta_ads.max_days'));

        // OUTCOME_APP_PROMOTION n'a pas de sens pour promouvoir un post.
        $this->assertArrayNotHasKey('OUTCOME_APP_PROMOTION', $response->json('objectives'));
    }

    public function test_un_couple_objectif_optimisation_impossible_est_refuse(): void
    {
        $this->fakeMeta();

        $this->actingAs($this->manager())->postJson('/api/meta-ads/boost', [
            'post_platform_id' => $this->diffusion()->id,
            'objective' => 'OUTCOME_AWARENESS',
            'optimization_goal' => 'OFFSITE_CONVERSIONS', // n'existe pas pour la notoriété
            'budget' => 10,
            'days' => 5,
        ])->assertStatus(422);

        // Rien n'est parti chez Meta : le refus est local, avant tout appel.
        Http::assertNothingSent();
    }

    public function test_l_objectif_choisi_est_celui_envoye_a_meta(): void
    {
        $this->fakeMeta();

        $this->actingAs($this->manager())->postJson('/api/meta-ads/boost', [
            'post_platform_id' => $this->diffusion()->id,
            'objective' => 'OUTCOME_TRAFFIC',
            'optimization_goal' => 'LANDING_PAGE_VIEWS',
            'budget' => 10,
            'days' => 5,
            'dry_run' => false,
        ])->assertStatus(201);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/campaigns') && $r['objective'] === 'OUTCOME_TRAFFIC');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/adsets') && $r['optimization_goal'] === 'LANDING_PAGE_VIEWS');
    }

    public function test_l_audience_choisie_devient_le_ciblage_de_l_ad_set(): void
    {
        $this->fakeMeta();
        $audience = $this->audience();

        $this->actingAs($this->manager())->postJson('/api/meta-ads/boost', [
            'post_platform_id' => $this->diffusion()->id,
            'audience_id' => $audience->id,
            'budget' => 10,
            'days' => 5,
            'dry_run' => false,
        ])->assertStatus(201);

        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), '/adsets')) {
                return false;
            }

            $targeting = json_decode($r['targeting'], true);

            return $targeting['geo_locations']['countries'] === ['PT']
                && $targeting['age_min'] === 25
                && $targeting['genders'] === [2]
                // Les intérêts passent par flexible_spec (OU), pas à plat.
                && $targeting['flexible_spec'][0]['interests'][0]['id'] === '6003139266461'
                && $targeting['instagram_positions'] === ['stream', 'reels'];
        });

        // Le ciblage est figé dans le boost : l'audience pourra changer ensuite.
        $this->assertSame(['PT'], MetaAdsBoost::first()->targeting['geo_locations']['countries']);
    }

    public function test_une_categorie_speciale_retire_l_age_le_genre_et_les_interets(): void
    {
        $this->fakeMeta();

        $this->actingAs($this->manager())->postJson('/api/meta-ads/boost', [
            'post_platform_id' => $this->diffusion()->id,
            'audience_id' => $this->audience()->id,
            'special_ad_categories' => ['HOUSING'],
            'budget' => 10,
            'days' => 5,
            'dry_run' => false,
        ])->assertStatus(201);

        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), '/adsets')) {
                return false;
            }

            $targeting = json_decode($r['targeting'], true);

            // Meta interdit ces critères sur une catégorie spéciale : les
            // laisser passer ferait rejeter l'annonce sans dire pourquoi.
            return ! isset($targeting['genders'])
                && ! isset($targeting['flexible_spec'])
                && $targeting['age_min'] === 18;
        });

        Http::assertSent(fn ($r) => str_contains($r->url(), '/campaigns')
            && json_decode($r['special_ad_categories'], true) === ['HOUSING']);
    }

    public function test_un_budget_quotidien_est_envoye_comme_tel_mais_reste_borne_dans_le_temps(): void
    {
        $this->fakeMeta();

        $this->actingAs($this->manager())->postJson('/api/meta-ads/boost', [
            'post_platform_id' => $this->diffusion()->id,
            'budget' => 5,
            'budget_type' => 'daily',
            'days' => 10,
            'dry_run' => false,
        ])->assertStatus(201);

        // Un budget quotidien SANS date de fin tournerait indéfiniment.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/adsets')
            && $r['daily_budget'] === '500'
            && ! isset($r['lifetime_budget'])
            && ! empty($r['end_time']));
    }

    public function test_le_plafond_quotidien_s_applique_au_budget_quotidien(): void
    {
        $this->fakeMeta();
        Setting::set('meta_ads_max_daily_budget', '10');

        $this->actingAs($this->manager())->postJson('/api/meta-ads/boost', [
            'post_platform_id' => $this->diffusion()->id,
            'budget' => 50,
            'budget_type' => 'daily',
            'days' => 30,
            'dry_run' => false,
        ])->assertStatus(422);

        $this->assertSame(0, MetaAdsBoost::count());
    }

    public function test_la_duree_borne_la_fin_de_diffusion(): void
    {
        $this->fakeMeta();

        $response = $this->actingAs($this->manager())->postJson('/api/meta-ads/boost', [
            'post_platform_id' => $this->diffusion()->id,
            'budget' => 10,
            'days' => 3,
        ]);

        $plan = $response->assertOk()->json('plan');

        $this->assertEqualsWithDelta(
            3,
            \Illuminate\Support\Carbon::parse($plan['debut'])->diffInDays(\Illuminate\Support\Carbon::parse($plan['fin'])),
            0.01,
        );
        $this->assertEquals(10, $plan['depense_maximale']);
    }

    public function test_une_enchere_plafonnee_sans_montant_est_refusee(): void
    {
        $this->fakeMeta();

        $this->actingAs($this->manager())->postJson('/api/meta-ads/boost', [
            'post_platform_id' => $this->diffusion()->id,
            'budget' => 10,
            'days' => 5,
            'bid_strategy' => 'COST_CAP',
            'dry_run' => false,
        ])->assertStatus(422);

        Http::assertNothingSent();
    }

    /** Le plan doit dire ce qui se passera, y compris ce qui manque. */
    public function test_le_plan_avertit_quand_l_objectif_exige_un_pixel_absent(): void
    {
        $this->fakeMeta();

        $response = $this->actingAs($this->manager())->postJson('/api/meta-ads/boost', [
            'post_platform_id' => $this->diffusion()->id,
            'objective' => 'OUTCOME_SALES',
            'optimization_goal' => 'OFFSITE_CONVERSIONS',
            'budget' => 10,
            'days' => 5,
        ]);

        $response->assertOk();
        $this->assertTrue(collect($response->json('warnings'))->contains(fn ($w) => str_contains($w, 'pixel')));
    }

    public function test_une_audience_s_enregistre_et_se_relit_en_ciblage_meta(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => []])]);

        $response = $this->actingAs($this->manager())->postJson('/api/meta-ads/audiences', [
            'name' => 'Lisbonne 25-45',
            'spec' => [
                'countries' => ['PT'],
                'age_min' => 25,
                'age_max' => 45,
                'publisher_platforms' => ['facebook', 'instagram'],
                'facebook_positions' => ['feed'],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('audience.targeting.geo_locations.countries', ['PT'])
            ->assertJsonPath('audience.targeting.facebook_positions', ['feed'])
            // Explicite dans les deux sens : sinon Meta élargit tout seul.
            ->assertJsonPath('audience.targeting.targeting_automation.advantage_audience', 0);
    }

    public function test_un_simple_utilisateur_n_enregistre_pas_d_audience(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)->postJson('/api/meta-ads/audiences', [
            'name' => 'Test',
            'spec' => ['countries' => ['PT']],
        ])->assertStatus(403);
    }

    /** Une position dont la régie n'est pas cochée serait refusée par Meta. */
    public function test_une_position_sans_sa_regie_n_est_pas_envoyee(): void
    {
        $audience = MetaAudience::create([
            'name' => 'Instagram seul',
            'spec' => [
                'countries' => ['PT'],
                'publisher_platforms' => ['instagram'],
                'facebook_positions' => ['feed'],
                'instagram_positions' => ['reels'],
            ],
        ]);

        $targeting = $audience->toTargetingSpec();

        $this->assertArrayNotHasKey('facebook_positions', $targeting);
        $this->assertSame(['reels'], $targeting['instagram_positions']);
    }
}
