<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Browser\BrowserTaskQueue;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TikTokPlatformTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Platform $platform;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'admin']);
        $this->platform = Platform::where('slug', 'tiktok')->firstOrFail();
    }

    private function account(): SocialAccount
    {
        $this->actingAs($this->user)->post(route('accounts.store'), [
            'platform_id' => $this->platform->id, 'name' => 'Wildy Caro', 'languages' => ['fr'],
        ])->assertRedirect(route('accounts.index'));

        return SocialAccount::where('platform_id', $this->platform->id)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function cookies(): array
    {
        return ['cookies' => [['name' => 'sessionid', 'value' => 'secret', 'domain' => '.tiktok.com']]];
    }

    private function fakeBrowser(?string $username): void
    {
        $this->app->instance(BrowserTaskQueue::class, new class($username) extends BrowserTaskQueue
        {
            public function __construct(private ?string $username) {}

            public function run(SocialAccount $account, string $kind, array $payload, int $timeout = 120): array
            {
                return $kind === 'tiktok.whoami' ? ['username' => $this->username] : [];
            }
        });
    }

    public function test_la_session_senregistre_dans_le_compte_quand_studio_confirme(): void
    {
        $account = $this->account();
        $this->fakeBrowser('wildycaro');
        Sanctum::actingAs($this->user);

        $this->postJson('/api/extension/tiktok/session', ['username' => 'WildyCaro'] + $this->cookies())
            ->assertOk()->assertJson(['status' => 'connected', 'username' => 'wildycaro']);

        $account->refresh();
        $this->assertSame('secret', $account->browser_session[0]['value']);
        $this->assertSame('wildycaro', $account->browser_username);
        $this->actingAs($this->user)->get(route('accounts.edit', $account))->assertOk()->assertSee('Session enregistrée (@wildycaro)');
    }

    public function test_un_autre_compte_vu_dans_studio_nest_pas_une_session_valide(): void
    {
        $account = $this->account();
        $this->fakeBrowser('quelquun');
        Sanctum::actingAs($this->user);

        $this->postJson('/api/extension/tiktok/session', ['username' => 'wildycaro'] + $this->cookies())
            ->assertStatus(409)->assertJson(['status' => 'expired']);

        // L'identité attendue reste mémorisée pour refuser un autre compte.
        $this->assertSame('wildycaro', $account->fresh()->browser_username);
        $this->assertSame('expired', $account->fresh()->browser_session_status);
        $this->assertFalse(app(\App\Services\Browser\TikTokSessionService::class)->verify($account->fresh(), 'wildycaro'));
    }

    public function test_une_session_perdue_est_restauree_depuis_la_copie(): void
    {
        $account = $this->account();
        $account->update(['browser_username' => 'wildycaro', 'browser_session' => $this->cookies()['cookies']]);
        $queue = new class extends BrowserTaskQueue
        {
            public array $kinds = [];

            public function __construct() {}

            public function run(SocialAccount $account, string $kind, array $payload, int $timeout = 120): array
            {
                $this->kinds[] = $kind;

                return ['username' => in_array('session.apply', $this->kinds, true) ? 'wildycaro' : null];
            }
        };
        $this->app->instance(BrowserTaskQueue::class, $queue);

        $this->artisan('tiktok:session-check')->assertSuccessful();

        $this->assertSame(['tiktok.whoami', 'session.apply', 'tiktok.whoami'], $queue->kinds);
        $this->assertSame('connected', $account->fresh()->browser_session_status);
    }

    public function test_un_navigateur_non_connecte_nenvoie_rien(): void
    {
        $this->account();
        Sanctum::actingAs($this->user);

        $this->postJson('/api/extension/tiktok/session', ['username' => 'wildycaro', 'cookies' => [['name' => 'ttwid', 'value' => 'x', 'domain' => '.tiktok.com']]])
            ->assertStatus(422);
    }

    public function test_des_cookies_dun_autre_site_sont_refuses(): void
    {
        $this->account();
        Sanctum::actingAs($this->user);

        $this->postJson('/api/extension/tiktok/session', ['username' => 'wildycaro', 'cookies' => [['name' => 'sessionid', 'value' => 'x', 'domain' => '.x.com']]])
            ->assertStatus(422);
    }

    public function test_deux_comptes_libres_ne_se_devinent_pas(): void
    {
        $this->account();
        SocialAccount::create(['platform_id' => $this->platform->id, 'name' => 'Planète', 'driver' => 'browser', 'languages' => ['fr']])
            ->users()->attach($this->user->id, ['is_active' => true]);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/extension/tiktok/session', ['username' => 'wildycaro'] + $this->cookies())->assertStatus(409);
    }

    public function test_le_seeder_du_demarrage_garde_un_compte_tiktok_en_mode_navigateur(): void
    {
        $this->seed(PlatformSeeder::class);
        $this->platform->refresh();

        $this->assertTrue($this->platform->config['browser_only']);
        $this->assertSame('browser', $this->account()->driver);
    }

    public function test_la_page_tiktok_liste_les_comptes_et_figure_au_menu(): void
    {
        $this->account();

        $this->actingAs($this->user)->get(route('platforms.tiktok'))->assertOk()
            ->assertSee('Comptes TikTok')->assertSee('Wildy Caro')->assertSee('Aucune session active')
            ->assertSee(url('/platforms/tiktok'));
    }

    public function test_le_bouton_de_la_page_preselectionne_tiktok(): void
    {
        $this->actingAs($this->user)->get(route('accounts.create', ['platform_id' => $this->platform->id]))->assertOk()
            ->assertSee("platformId: '{$this->platform->id}'", false);
    }

    public function test_tiktok_se_choisit_dans_lajout_dun_compte(): void
    {
        $this->actingAs($this->user)->get(route('accounts.create'))->assertOk()->assertSee('TikTok');
    }

    public function test_un_compte_tiktok_se_cree_sans_identifiants_en_mode_navigateur(): void
    {
        $account = $this->account();

        $this->assertSame('browser', $account->driver);
        $this->assertSame('wildycaro', $account->browser_profile);
    }

    public function test_un_compte_tiktok_nest_pas_publiable_mais_reste_rattache(): void
    {
        $account = $this->account();

        $this->assertFalse($this->user->activeSocialAccounts()->whereKey($account->id)->exists());
        $this->assertTrue($this->user->connectedSocialAccounts()->whereKey($account->id)->exists());
    }

    public function test_le_controle_de_session_x_ignore_tiktok(): void
    {
        $this->account();

        $this->artisan('x:session-check')->assertSuccessful();
        $this->assertDatabaseCount('browser_tasks', 0);
    }

    public function test_la_liste_des_comptes_affiche_tiktok(): void
    {
        $this->account();

        $this->actingAs($this->user)->get(route('accounts.index'))->assertOk()->assertSee('TikTok')->assertSee('Wildy Caro');
    }

    public function test_les_autres_plateformes_restent_en_mode_api(): void
    {
        $telegram = Platform::firstOrCreate(['slug' => 'telegram'], ['name' => 'Telegram']);

        $this->actingAs($this->user)->post(route('accounts.store'), [
            'platform_id' => $telegram->id, 'name' => 'Canal', 'languages' => ['fr'], 'credentials' => ['chat_id' => '1'],
        ])->assertRedirect(route('accounts.index'));

        $this->assertSame('api', SocialAccount::where('platform_id', $telegram->id)->first()->driver);
    }
}
