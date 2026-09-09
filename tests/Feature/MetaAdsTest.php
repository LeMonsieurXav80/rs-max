<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Meta\MetaAdsService;
use App\Services\Stats\EmvService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Lecture Meta Ads et calibrage de l'EMV sur le CPM réellement payé.
 *
 * Le jeton est traité comme une clé API (chiffrée), pas comme un compte social :
 * pas d'OAuth, donc rien à ré-autoriser quand un mot de passe change.
 */
class MetaAdsTest extends TestCase
{
    use RefreshDatabase;

    private function configure(string $accountId = 'act_123'): void
    {
        Setting::setEncrypted('meta_ads_token', 'EAA'.str_repeat('x', 40));
        Setting::set('meta_ads_account_id', $accountId);
        Cache::flush();
    }

    /**
     * Ventilation Meta par régie, telle que renvoyée par /insights.
     */
    private function insightsResponse(): array
    {
        return ['data' => [
            ['publisher_platform' => 'facebook', 'spend' => '150.00', 'impressions' => '30000', 'account_currency' => 'EUR'],
            ['publisher_platform' => 'instagram', 'spend' => '240.00', 'impressions' => '20000', 'account_currency' => 'EUR'],
            // Sans équivalent publiable chez nous : doit être ignoré.
            ['publisher_platform' => 'audience_network', 'spend' => '90.00', 'impressions' => '90000', 'account_currency' => 'EUR'],
        ]];
    }

    public function test_le_cpm_est_recalcule_depuis_la_depense_reelle(): void
    {
        Http::fake(['*/insights*' => Http::response($this->insightsResponse())]);
        $this->configure();

        $observed = app(MetaAdsService::class)->observedCpm();

        $this->assertTrue($observed['available']);
        $this->assertSame('EUR', $observed['currency']);
        // 150 € / 30 000 impressions × 1000 = 5 €
        $this->assertSame(5.0, $observed['platforms']['facebook']['cpm']);
        // 240 € / 20 000 × 1000 = 12 €
        $this->assertSame(12.0, $observed['platforms']['instagram']['cpm']);
        $this->assertArrayNotHasKey('audience_network', $observed['platforms']);
    }

    public function test_l_emv_utilise_le_cpm_constate_quand_il_est_active(): void
    {
        Http::fake(['*/insights*' => Http::response($this->insightsResponse())]);
        $this->configure();
        Setting::set(EmvService::SOURCE_KEY, 'meta_observed');

        $emv = app(EmvService::class)->forMetrics('facebook', ['views' => 10000]);

        // CPM constaté 5 € (et non le barème 7,5 €) → 10 000 / 1000 × 5 = 50 €
        $this->assertSame(50.0, $emv['cpm']);
        $this->assertSame('meta_observed', $emv['cpm_source']);
    }

    public function test_le_bareme_reste_la_source_par_defaut(): void
    {
        Http::fake(['*/insights*' => Http::response($this->insightsResponse())]);
        $this->configure();
        // `emv_cpm_source` non renseigné : aucun appel à Meta ne doit décider du prix.

        $emv = app(EmvService::class)->forMetrics('facebook', ['views' => 10000]);

        $this->assertSame(75.0, $emv['cpm']);
        $this->assertSame('reference', $emv['cpm_source']);
    }

    public function test_un_reseau_hors_meta_garde_son_bareme(): void
    {
        Http::fake(['*/insights*' => Http::response($this->insightsResponse())]);
        $this->configure();
        Setting::set(EmvService::SOURCE_KEY, 'meta_observed');

        // LinkedIn n'est pas une régie Meta : rien à constater.
        $emv = app(EmvService::class)->forMetrics('linkedin', ['views' => 10000]);

        $this->assertSame(250.0, $emv['cpm']);
        $this->assertSame('reference', $emv['cpm_source']);
    }

    public function test_une_devise_differente_ne_contamine_pas_la_valorisation(): void
    {
        Http::fake(['*/insights*' => Http::response(['data' => [
            ['publisher_platform' => 'facebook', 'spend' => '150.00', 'impressions' => '30000', 'account_currency' => 'USD'],
        ]])]);
        $this->configure();
        Setting::set(EmvService::SOURCE_KEY, 'meta_observed');

        $emv = app(EmvService::class)->forMetrics('facebook', ['views' => 10000]);

        // Un CPM en USD appliqué à une valorisation en EUR serait faux : barème conservé.
        $this->assertSame(75.0, $emv['cpm']);
        $this->assertSame('reference', $emv['cpm_source']);
    }

    public function test_un_jeton_mort_remonte_un_message_actionnable(): void
    {
        // Le cas réel : code 190 / subcode 460, session invalidée par un
        // changement de mot de passe — ce qu'un token système évite.
        Http::fake(['*/adaccounts*' => Http::response([
            'error' => [
                'message' => 'Error validating access token: The session has been invalidated.',
                'code' => 190,
                'error_subcode' => 460,
            ],
        ], 400)]);
        $this->configure();

        $result = app(MetaAdsService::class)->adAccounts();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('utilisateur système', $result['error']);
    }

    public function test_sans_configuration_aucun_appel_n_est_fait(): void
    {
        Http::fake();

        $observed = app(MetaAdsService::class)->observedCpm();

        $this->assertFalse($observed['available']);
        Http::assertNothingSent();
    }

    public function test_le_jeton_est_stocke_chiffre(): void
    {
        $this->configure();

        // La valeur brute en base ne doit pas être lisible.
        $raw = Setting::where('key', 'meta_ads_token')->value('value');

        $this->assertNotSame('EAA'.str_repeat('x', 40), $raw);
        $this->assertSame('EAA'.str_repeat('x', 40), Setting::getEncrypted('meta_ads_token'));
    }
}
