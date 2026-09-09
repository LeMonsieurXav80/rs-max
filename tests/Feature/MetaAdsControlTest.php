<?php

namespace Tests\Feature;

use App\Models\MetaAdsActionLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pilotage des campagnes Meta par API.
 *
 * Ces endpoints sont faits pour être appelés par une IA et dépensent de
 * l'argent réel : ce qui est testé ici, ce ne sont pas les chemins heureux,
 * c'est que les refus tiennent.
 */
class MetaAdsControlTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        Setting::setEncrypted('meta_ads_token', 'EAA'.str_repeat('x', 40));
        Setting::set('meta_ads_account_id', 'act_123');
        config(['meta_ads.write_enabled' => true]);

        return User::factory()->create(['role' => 'manager']);
    }

    /**
     * Campagne courante : 20 €/jour, active. Budget en unités mineures côté Meta.
     */
    private function fakeMeta(int $dailyBudgetMinor = 2000, string $status = 'ACTIVE'): void
    {
        Http::fake([
            'graph.facebook.com/*' => function ($request) use ($dailyBudgetMinor, $status) {
                if ($request->method() === 'POST') {
                    return Http::response(['success' => true]);
                }

                return Http::response([
                    'id' => '120000',
                    'name' => 'Campagne Test',
                    'status' => $status,
                    'effective_status' => $status,
                    'daily_budget' => (string) $dailyBudgetMinor,
                ]);
            },
        ]);
    }

    public function test_l_ecriture_est_fermee_par_defaut(): void
    {
        $this->fakeMeta();
        $user = $this->manager();
        // L'interrupteur général reprend sa valeur de config.
        config(['meta_ads.write_enabled' => false]);

        $response = $this->actingAs($user)->postJson('/api/meta-ads/120000/status', ['status' => 'PAUSED']);

        $response->assertStatus(403)->assertJsonPath('error', 'Le pilotage en écriture des campagnes est désactivé.');
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_un_simple_utilisateur_ne_pilote_pas_les_campagnes(): void
    {
        $this->fakeMeta();
        $this->manager();
        $simple = User::factory()->create(['role' => 'user']);

        $this->actingAs($simple)
            ->postJson('/api/meta-ads/120000/status', ['status' => 'PAUSED'])
            ->assertStatus(403);
    }

    public function test_sans_dry_run_explicite_rien_n_est_envoye_a_meta(): void
    {
        $this->fakeMeta();

        $response = $this->actingAs($this->manager())
            ->postJson('/api/meta-ads/120000/status', ['status' => 'PAUSED']);

        $response->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('applied', false);

        // Le plan a été calculé, mais aucune écriture n'est partie.
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_dry_run_false_applique_reellement(): void
    {
        $this->fakeMeta();

        $response = $this->actingAs($this->manager())
            ->postJson('/api/meta-ads/120000/status', ['status' => 'PAUSED', 'dry_run' => false]);

        $response->assertOk()->assertJsonPath('applied', true);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['status'] === 'PAUSED');
    }

    public function test_une_hausse_de_budget_excessive_est_refusee(): void
    {
        $this->fakeMeta(2000); // 20 €/jour

        // 20 € → 80 € = +300 %, au-dessus du maximum de 50 %.
        $response = $this->actingAs($this->manager())
            ->postJson('/api/meta-ads/120000/budget', ['amount' => 80, 'dry_run' => false]);

        $response->assertStatus(422);
        $this->assertStringContainsString('300.0%', $response->json('error'));
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_force_autorise_la_hausse_mais_pas_le_plafond_absolu(): void
    {
        $this->fakeMeta(2000);
        $user = $this->manager();

        // Sous le plafond absolu (100 €) : `force` débloque.
        $this->actingAs($user)
            ->postJson('/api/meta-ads/120000/budget', ['amount' => 90, 'force' => true, 'dry_run' => false])
            ->assertOk()
            ->assertJsonPath('applied', true);

        // Au-dessus du plafond : `force` ne doit RIEN changer, sinon ce n'est pas un plafond.
        $this->actingAs($user)
            ->postJson('/api/meta-ads/120000/budget', ['amount' => 250, 'force' => true, 'dry_run' => false])
            ->assertStatus(422)
            ->assertJsonPath('hint', 'Ce plafond n\'est pas contournable par `force`. Il se règle dans /settings, onglet Statistiques.');
    }

    public function test_le_budget_est_converti_en_unites_mineures(): void
    {
        $this->fakeMeta(2000);

        $this->actingAs($this->manager())
            ->postJson('/api/meta-ads/120000/budget', ['amount' => 25.50, 'dry_run' => false])
            ->assertOk();

        // 25,50 € doit partir en 2550, pas en 25.5 — sinon budget divisé par 100.
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['daily_budget'] === '2550');
    }

    public function test_un_statut_deja_en_place_ne_consomme_pas_d_ecriture(): void
    {
        $this->fakeMeta(2000, 'PAUSED');

        $this->actingAs($this->manager())
            ->postJson('/api/meta-ads/120000/status', ['status' => 'PAUSED', 'dry_run' => false])
            ->assertOk()
            ->assertJsonPath('applied', false)
            ->assertJsonPath('reason', 'Le statut est déjà PAUSED.');

        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_tout_geste_est_journalise_dry_run_compris(): void
    {
        $this->fakeMeta();
        $user = $this->manager();

        $this->actingAs($user)->postJson('/api/meta-ads/120000/status', ['status' => 'PAUSED']);
        $this->actingAs($user)->postJson('/api/meta-ads/120000/status', ['status' => 'PAUSED', 'dry_run' => false]);

        $logs = MetaAdsActionLog::orderBy('id')->get();

        $this->assertCount(2, $logs);
        $this->assertTrue($logs[0]->dry_run);
        $this->assertFalse($logs[1]->dry_run);
        // L'état précédent est conservé : sans lui, un retour arrière se fait à l'aveugle.
        $this->assertSame('ACTIVE', $logs[1]->previous['status']);
        $this->assertSame('PAUSED', $logs[1]->requested['status']);
        $this->assertSame($user->id, $logs[1]->user_id);
    }

    public function test_les_plafonds_sont_reglables_sans_deploiement(): void
    {
        $this->fakeMeta(2000); // 20 €/jour
        $user = $this->manager();

        // Plafond abaissé à 15 depuis /settings : 18 € doit désormais être refusé,
        // alors que la config le laisserait passer (100).
        Setting::set('meta_ads_max_daily_budget', '15');

        $this->actingAs($user)
            ->postJson('/api/meta-ads/120000/budget', ['amount' => 18, 'force' => true, 'dry_run' => false])
            ->assertStatus(422);

        // Hausse max relevée à 100 % : 20 € → 40 € passe, ce que 50 % refusait.
        Setting::set('meta_ads_max_daily_budget', '50');
        Setting::set('meta_ads_max_budget_increase_pct', '100');

        $this->actingAs($user)
            ->postJson('/api/meta-ads/120000/budget', ['amount' => 40, 'dry_run' => false])
            ->assertOk()
            ->assertJsonPath('applied', true);
    }

    public function test_un_reglage_vide_retombe_sur_la_config(): void
    {
        $this->fakeMeta(2000);
        $user = $this->manager();

        // Un champ vidé ne doit pas valoir zéro : un plafond à 0 bloquerait tout.
        Setting::set('meta_ads_max_daily_budget', '');

        $this->actingAs($user)
            ->postJson('/api/meta-ads/120000/budget', ['amount' => 25, 'force' => true, 'dry_run' => false])
            ->assertOk();
    }

    public function test_les_budgets_sont_lus_en_devise_pas_en_centimes(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [
            ['id' => '1', 'name' => 'A', 'status' => 'ACTIVE', 'daily_budget' => '2500'],
        ]])]);

        $response = $this->actingAs($this->manager())->getJson('/api/meta-ads/campaigns');

        // 25 et non 25.0 : json_encode laisse tomber la décimale nulle.
        $response->assertOk()->assertJsonPath('campaigns.0.daily_budget', 25);
    }
}
