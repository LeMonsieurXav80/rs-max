<?php

namespace Tests\Feature;

use App\Models\MetaAdsBoost;
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
 * Sponsorisation d'une publication organique existante.
 *
 * Le point sensible : on sponsorise le post D'ORIGINE (via une reference), on
 * n'en publie pas un double. Et la structure est creee EN PAUSE — monter une
 * campagne ne coute rien, l'activer depense.
 */
class MetaAdsBoostTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        Setting::setEncrypted('meta_ads_token', 'EAA'.str_repeat('x', 40));
        Setting::set('meta_ads_account_id', 'act_123');
        config(['meta_ads.write_enabled' => true]);

        return User::factory()->create(['role' => 'manager']);
    }

    private function diffusion(string $slug, string $externalId, string $accountId): PostPlatform
    {
        $platform = Platform::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug), 'auth_type' => 'oauth2']);
        $account = SocialAccount::create([
            'platform_id' => $platform->id,
            'platform_account_id' => $accountId,
            'name' => 'Compte '.$slug,
            'credentials' => ['access_token' => 't'],
        ]);
        $post = Post::create(['user_id' => User::factory()->create()->id, 'content_fr' => 'Contenu', 'status' => 'published']);

        return PostPlatform::create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'platform_id' => $platform->id,
            'status' => 'published',
            'external_id' => $externalId,
            'published_at' => now(),
        ]);
    }

    /** Chaque POST rend un id : campagne, ad set, creatif, annonce. */
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

    public function test_le_dry_run_demande_a_meta_de_valider_sans_rien_creer(): void
    {
        $this->fakeMeta();
        $pp = $this->diffusion('instagram', '17904923001244826', '17841400083313511');

        $response = $this->actingAs($this->manager())
            ->postJson('/api/meta-ads/boost', ['post_platform_id' => $pp->id, 'budget' => 10, 'days' => 5]);

        $response->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('applied', false)
            ->assertJsonPath('promotable', true)
            ->assertJsonPath('plan.budget_quotidien_equivalent', 2);

        // Un seul appel : la validation. Aucun objet cree.
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r->body(), 'validate_only'));
        $this->assertSame(0, MetaAdsBoost::count());
    }

    public function test_instagram_est_reference_par_son_media_id(): void
    {
        $this->fakeMeta();
        $pp = $this->diffusion('instagram', '17904923001244826', '17841400083313511');

        $this->actingAs($this->manager())
            ->postJson('/api/meta-ads/boost', ['post_platform_id' => $pp->id, 'budget' => 10, 'days' => 5, 'dry_run' => false])
            ->assertStatus(201);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/adcreatives')
            && $r['source_instagram_media_id'] === '17904923001244826'
            && $r['instagram_user_id'] === '17841400083313511');
    }

    public function test_facebook_compose_page_id_et_post_id(): void
    {
        $this->fakeMeta();
        // external_id non composite : la Page doit etre prefixee.
        $pp = $this->diffusion('facebook', '1337140515271447', '207154223492618');

        $this->actingAs($this->manager())
            ->postJson('/api/meta-ads/boost', ['post_platform_id' => $pp->id, 'budget' => 10, 'days' => 5, 'dry_run' => false])
            ->assertStatus(201);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/adcreatives')
            && $r['object_story_id'] === '207154223492618_1337140515271447');
    }

    public function test_un_external_id_deja_composite_n_est_pas_prefixe_deux_fois(): void
    {
        $this->fakeMeta();
        $pp = $this->diffusion('facebook', '207154223492618_1337140515271447', '207154223492618');

        $this->actingAs($this->manager())
            ->postJson('/api/meta-ads/boost', ['post_platform_id' => $pp->id, 'budget' => 10, 'days' => 5, 'dry_run' => false])
            ->assertStatus(201);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/adcreatives')
            && $r['object_story_id'] === '207154223492618_1337140515271447');
    }

    public function test_la_campagne_est_creee_en_pause(): void
    {
        $this->fakeMeta();
        $pp = $this->diffusion('instagram', '179049', '178414');

        $response = $this->actingAs($this->manager())
            ->postJson('/api/meta-ads/boost', ['post_platform_id' => $pp->id, 'budget' => 10, 'days' => 5, 'dry_run' => false]);

        $response->assertStatus(201)->assertJsonPath('plan.cree_en', 'PAUSED');

        // Aucun des quatre objets ne doit partir en ACTIVE.
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && ($r['status'] ?? null) === 'ACTIVE');
        $this->assertSame('paused', MetaAdsBoost::first()->status);
    }

    public function test_le_budget_est_borne_dans_le_temps(): void
    {
        $this->fakeMeta();
        $pp = $this->diffusion('instagram', '179049', '178414');

        $this->actingAs($this->manager())
            ->postJson('/api/meta-ads/boost', ['post_platform_id' => $pp->id, 'budget' => 10, 'days' => 5, 'dry_run' => false])
            ->assertStatus(201);

        // lifetime_budget + end_time, jamais un daily_budget sans fin.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/adsets')
            && $r['lifetime_budget'] === '1000'
            && ! empty($r['end_time'])
            && ! isset($r['daily_budget']));
    }

    public function test_le_plafond_s_applique_au_budget_quotidien_equivalent(): void
    {
        $this->fakeMeta();
        $pp = $this->diffusion('instagram', '179049', '178414');
        Setting::set('meta_ads_max_daily_budget', '10');

        // 300 € sur 3 jours = 100 €/jour : doit etre refuse malgre un total
        // qui pourrait sembler raisonnable sur une longue periode.
        $this->actingAs($this->manager())
            ->postJson('/api/meta-ads/boost', ['post_platform_id' => $pp->id, 'budget' => 300, 'days' => 3, 'dry_run' => false])
            ->assertStatus(422);

        $this->assertSame(0, MetaAdsBoost::count());
    }

    public function test_une_publication_non_publiee_est_refusee(): void
    {
        $this->fakeMeta();
        $pp = $this->diffusion('instagram', '179049', '178414');
        $pp->update(['status' => 'failed']);

        $this->actingAs($this->manager())
            ->postJson('/api/meta-ads/boost', ['post_platform_id' => $pp->id, 'budget' => 10, 'days' => 5, 'dry_run' => false])
            ->assertStatus(422);
    }

    public function test_les_reseaux_hors_meta_sont_refuses(): void
    {
        $this->fakeMeta();
        $pp = $this->diffusion('bluesky', 'abc', 'did:plc:xyz');

        $this->actingAs($this->manager())
            ->postJson('/api/meta-ads/boost', ['post_platform_id' => $pp->id, 'budget' => 10, 'days' => 5, 'dry_run' => false])
            ->assertStatus(422);
    }

    public function test_un_echec_en_cours_de_route_ne_laisse_pas_d_orphelin(): void
    {
        $pp = $this->diffusion('instagram', '179049', '178414');
        $n = 0;
        // La campagne et l'ad set passent, le creatif echoue.
        Http::fake(['graph.facebook.com/*' => function ($request) use (&$n) {
            $n++;
            if ($n === 3) {
                return Http::response(['error' => ['message' => 'Invalid parameter', 'error_user_msg' => 'Publication indisponible']], 400);
            }

            return Http::response(['id' => 'created_'.$n]);
        }]);

        $this->actingAs($this->manager())
            ->postJson('/api/meta-ads/boost', ['post_platform_id' => $pp->id, 'budget' => 10, 'days' => 5, 'dry_run' => false])
            ->assertStatus(502);

        // La campagne creee doit avoir ete supprimee.
        Http::assertSent(fn ($r) => $r->url() === 'https://graph.facebook.com/v21.0/created_1'
            && ($r['status'] ?? null) === 'DELETED');
        $this->assertSame('failed', MetaAdsBoost::first()->status);
    }

    public function test_l_ecriture_fermee_bloque_aussi_le_boost(): void
    {
        $this->fakeMeta();
        $pp = $this->diffusion('instagram', '179049', '178414');
        $user = $this->manager();
        config(['meta_ads.write_enabled' => false]);

        $this->actingAs($user)
            ->postJson('/api/meta-ads/boost', ['post_platform_id' => $pp->id, 'budget' => 10, 'days' => 5])
            ->assertStatus(403);
    }
}
