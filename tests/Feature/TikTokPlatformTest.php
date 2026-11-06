<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
