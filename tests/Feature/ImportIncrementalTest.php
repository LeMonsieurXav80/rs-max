<?php

namespace Tests\Feature;

use App\Models\ExternalPost;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Services\Import\Concerns\ImportsIncrementally;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L'import ne sert plus a rattraper l'historique mais a ramener les nouveautes.
 * Couvre le point de reprise par compte, la coupure de pagination, et la regle
 * qui evite de payer un appel d'insights pour rien.
 */
class ImportIncrementalTest extends TestCase
{
    use RefreshDatabase;

    private Platform $platform;

    private SocialAccount $account;

    /** Expose le trait pour le tester sans passer par une API. */
    private object $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform = Platform::create([
            'slug' => 'instagram',
            'name' => 'Instagram',
            'auth_type' => 'oauth2',
        ]);

        $this->account = SocialAccount::create([
            'platform_id' => $this->platform->id,
            'platform_account_id' => '42',
            'name' => 'Compte de test',
            'credentials' => ['access_token' => 't'],
        ]);

        $this->service = new class
        {
            use ImportsIncrementally {
                importSince as public;
                isBeforeWindow as public;
                needsMetricsRefresh as public;
                newestKnownExternalId as public;
            }
        };
    }

    private function externalPost(array $attributes = []): ExternalPost
    {
        return ExternalPost::create(array_merge([
            'social_account_id' => $this->account->id,
            'platform_id' => $this->platform->id,
            'external_id' => 'ext-'.uniqid(),
            'published_at' => now()->subDay(),
        ], $attributes));
    }

    public function test_sans_historique_on_ne_remonte_pas_au_dela_de_la_fenetre_initiale(): void
    {
        config(['import.first_run_days' => 30]);

        $since = $this->service->importSince($this->account);

        $this->assertEqualsWithDelta(30, $since->diffInDays(now()), 1);
    }

    public function test_avec_historique_on_repart_de_la_derniere_publication_connue(): void
    {
        config(['import.overlap_hours' => 12]);

        $this->externalPost(['published_at' => now()->subDays(200)]);
        $this->externalPost(['published_at' => now()->subDays(3)]);

        $since = $this->service->importSince($this->account);

        // Reprise a J-3 moins le recouvrement, PAS a J-200 ni a J-30.
        $this->assertEqualsWithDelta(3.5, $since->diffInDays(now()), 0.2);
    }

    public function test_un_compte_endormi_ne_fait_pas_remonter_l_import_a_des_annees(): void
    {
        config(['import.first_run_days' => 30]);

        // Ce compte n'a plus rien publie depuis deux ans : repartir de sa
        // derniere publication connue reviendrait a repaginer 800 jours.
        $this->externalPost(['published_at' => now()->subDays(800)]);

        $since = $this->service->importSince($this->account);

        $this->assertEqualsWithDelta(30, $since->diffInDays(now()), 1);
    }

    public function test_la_fenetre_coupe_les_publications_trop_anciennes(): void
    {
        $since = CarbonImmutable::now()->subDays(30);

        $this->assertTrue($this->service->isBeforeWindow(now()->subDays(60)->toIso8601String(), $since));
        $this->assertFalse($this->service->isBeforeWindow(now()->subDays(2)->toIso8601String(), $since));
        // Date absente ou illisible : on garde, plutot que de perdre la publication.
        $this->assertFalse($this->service->isBeforeWindow(null, $since));
        $this->assertFalse($this->service->isBeforeWindow('pas une date', $since));
    }

    public function test_une_publication_inconnue_merite_toujours_un_appel_d_insights(): void
    {
        $this->assertTrue($this->service->needsMetricsRefresh(null));
    }

    public function test_on_ne_paie_pas_d_insights_pour_des_statistiques_stabilisees(): void
    {
        config(['import.metrics_settle_days' => 30]);

        $vieux = $this->externalPost([
            'published_at' => now()->subDays(90),
            'metrics_synced_at' => now()->subYear(),
        ]);

        // Publication d'il y a 90 jours : ses stats ne bougent plus, inutile de payer.
        $this->assertFalse($this->service->needsMetricsRefresh($vieux));
    }

    public function test_on_ne_paie_pas_d_insights_pour_des_statistiques_fraiches(): void
    {
        config(['import.metrics_ttl_hours' => 24]);

        $frais = $this->externalPost([
            'published_at' => now()->subDay(),
            'metrics_synced_at' => now()->subHours(2),
        ]);

        $this->assertFalse($this->service->needsMetricsRefresh($frais));

        $perime = $this->externalPost([
            'published_at' => now()->subDay(),
            'metrics_synced_at' => now()->subHours(48),
        ]);

        $this->assertTrue($this->service->needsMetricsRefresh($perime));
    }

    public function test_le_dernier_identifiant_connu_sert_de_point_de_reprise(): void
    {
        $this->externalPost(['external_id' => 'vieux', 'published_at' => now()->subDays(10)]);
        $this->externalPost(['external_id' => 'recent', 'published_at' => now()->subHour()]);

        $this->assertSame('recent', $this->service->newestKnownExternalId($this->account));
    }
}
