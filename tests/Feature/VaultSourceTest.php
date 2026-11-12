<?php

namespace Tests\Feature;

use App\Models\Persona;
use App\Models\Platform;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\VaultSource;
use App\Services\Agent\XReplyVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VaultSourceTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $name = 'Wildy'): SocialAccount
    {
        $persona = Persona::create(['name' => 'P '.$name, 'system_prompt' => 'Chaleureuse.', 'is_active' => true]);

        return SocialAccount::create(['name' => $name, 'platform_id' => Platform::firstOrCreate(['slug' => 'twitter'], ['name' => 'X'])->id,
            'platform_account_id' => (string) random_int(1, 99999), 'browser_username' => strtolower($name), 'persona_id' => $persona->id]);
    }

    public function test_le_gestionnaire_rattache_des_dossiers_sans_jamais_ouvrir_une_zone_sensible(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $account = $this->account();
        $account->users()->attach($manager, ['is_active' => true]);
        $form = ['name' => 'Wildy — relations', 'corpus' => 'cerveau-xav', 'format' => 'notes', 'is_active' => '1',
            'folders' => "03-Resources/Livres\n01-Projects/Wildycaro/Posts/", 'accounts' => [$account->id]];

        $this->actingAs(User::factory()->create(['role' => 'user']))->post('/vault-sources', $form)->assertForbidden();
        $this->actingAs($manager)->post('/vault-sources', ['folders' => "03-Resources/Livres\n02-Areas/Mails"] + $form)
            ->assertSessionHasErrors('folders');
        $this->post('/vault-sources', ['folders' => '07-Raw/Livres'] + $form)->assertSessionHasErrors('folders');
        $this->post('/vault-sources', $form)->assertRedirect('/vault-sources');

        $source = VaultSource::sole();
        $this->assertSame(['03-Resources/Livres', '01-Projects/Wildycaro/Posts'], $source->folders);
        $this->assertSame([$account->id], $source->socialAccounts->pluck('id')->all());
        $this->withoutVite();
        $this->get('/vault-sources')->assertOk()->assertSee('Wildy — relations');
        $this->get("/vault-sources/{$source->id}/edit")->assertOk()->assertSee('03-Resources/Livres');
    }

    public function test_chaque_compte_interroge_ses_sources_et_l_ancien_reglage_est_migre(): void
    {
        $wildy = $this->account();
        $cyril = $this->account('Cyril');
        $notes = VaultSource::create(['name' => 'Livres', 'corpus' => 'cerveau-xav', 'format' => 'notes',
            'folders' => ['03-Resources/Livres', 'Compta'], 'brands' => ['Prozis']]);
        $notes->socialAccounts()->attach($wildy);
        Setting::set("x_reply_vault_account_{$cyril->id}", json_encode(['corpus' => 'dix', 'persona_id' => $cyril->persona_id, 'brands' => ['DIX']]));
        Http::fake(['http://localhost/lookup' => fn (Request $request) => Http::response(['revision' => 'r1',
            'sources' => [['path' => $request['folders'][0].'/Note.md', 'title' => 'Note', 'excerpt' => 'Extrait.']]])]);
        $vault = app(XReplyVault::class);

        $this->assertSame('03-Resources/Livres/Note.md', $vault->search($wildy, 'habitudes')['sources'][0]['path']);
        $this->assertSame(['Prozis'], $vault->brandsFor($wildy));
        Http::assertSent(fn (Request $request) => $request['format'] === 'notes' && $request['folders'] === ['03-Resources/Livres']);
        $this->assertSame('Recherches/Note.md', $vault->search($cyril, 'créatine')['sources'][0]['path']);
        Http::assertSent(fn (Request $request) => $request['format'] === 'pubmed' && $request['folders'] === ['Recherches']);

        $this->artisan('vault:migrate-legacy')->assertSuccessful();
        $this->assertDatabaseCount('vault_sources', 1);
        $this->artisan('vault:migrate-legacy', ['--commit' => true])->assertSuccessful();
        $migrated = VaultSource::where('corpus', 'dix')->sole();
        $this->assertSame(['pubmed', ['Recherches'], ['DIX']], [$migrated->format, $migrated->folders, $migrated->brands]);
        $this->assertSame([$cyril->id], $migrated->socialAccounts->pluck('id')->all());
        $this->assertNull(Setting::get("x_reply_vault_account_{$cyril->id}"));
        $this->assertSame(['DIX'], $vault->brandsFor($cyril->fresh()));

        $notes->update(['is_active' => false]);
        $this->assertNull($vault->search($wildy->fresh(), 'habitudes'));
    }

    public function test_l_api_gere_les_sources_en_apercu_par_defaut(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $account = $this->account();
        $account->users()->attach($manager, ['is_active' => true]);
        $this->withToken($manager->createToken('api')->plainTextToken);
        $payload = ['name' => 'Livres', 'corpus' => 'cerveau-xav', 'format' => 'notes',
            'folders' => ['03-Resources/Livres'], 'accounts' => [$account->id]];

        $this->postJson('/api/vault-sources', $payload)->assertOk()->assertJsonPath('dry_run', true);
        $this->assertDatabaseCount('vault_sources', 0);
        $this->postJson('/api/vault-sources', ['folders' => ['Compta/2026']] + $payload)->assertUnprocessable()->assertJsonValidationErrors('folders');
        $this->postJson('/api/vault-sources', ['accounts' => [999999]] + $payload)->assertUnprocessable()->assertJsonValidationErrors('accounts');
        $id = $this->postJson('/api/vault-sources', ['dry_run' => false] + $payload)->assertCreated()
            ->assertJsonPath('source.accounts.0.id', $account->id)->json('source.id');

        $this->putJson("/api/vault-sources/{$id}", ['folders' => '03-Resources/Livres
01-Projects/Wildycaro/Posts'])->assertOk()->assertJsonPath('dry_run', true);
        $this->assertSame(['03-Resources/Livres'], VaultSource::find($id)->folders);
        $this->putJson("/api/vault-sources/{$id}", ['folders' => ['03-Resources/Livres', '01-Projects/Wildycaro/Posts'], 'dry_run' => false])->assertOk()
            ->assertJsonPath('source.folders.1', '01-Projects/Wildycaro/Posts')->assertJsonPath('source.accounts.0.id', $account->id);

        Http::fake(['http://localhost/lookup' => Http::response(['revision' => 'r2', 'sources' => [['path' => '03-Resources/Livres/A.md', 'title' => 'A', 'excerpt' => 'E']]])]);
        $this->postJson("/api/vault-sources/{$id}/test", ['text' => 'habitudes'])->assertOk()->assertJsonPath('sources.0.path', '03-Resources/Livres/A.md');
        $this->getJson('/api/vault-sources')->assertOk()->assertJsonPath('sources.0.name', 'Livres');
        $this->deleteJson("/api/vault-sources/{$id}")->assertOk()->assertJsonPath('deleted', false);
        $this->deleteJson("/api/vault-sources/{$id}", ['dry_run' => false])->assertOk()->assertJsonPath('deleted', true);
        $this->assertDatabaseCount('vault_sources', 0);

        $this->app['auth']->forgetGuards();
        $this->withToken(User::factory()->create(['role' => 'user'])->createToken('api')->plainTextToken)
            ->getJson('/api/vault-sources')->assertForbidden();
    }
}
