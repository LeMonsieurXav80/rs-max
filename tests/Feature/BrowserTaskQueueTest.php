<?php

namespace Tests\Feature;

use App\Models\BotActionLog;
use App\Models\BrowserTask;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Browser\BrowserException;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BrowserTaskQueueTest extends TestCase
{
    use RefreshDatabase;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $platform = Platform::firstOrCreate(['slug' => 'twitter'], ['name' => 'X']);
        $this->account = SocialAccount::create(['platform_id' => $platform->id, 'platform_account_id' => '77', 'name' => 'Wildy', 'driver' => 'browser', 'browser_profile' => 'wildycaro', 'languages' => ['fr']]);
    }

    private function task(string $status = BrowserTask::PENDING, string $profile = 'wildycaro'): BrowserTask
    {
        return BrowserTask::create(['social_account_id' => $this->account->id, 'profile' => $profile, 'kind' => 'x.post', 'payload' => ['url' => 'https://x.com', 'text' => 'Bonjour'], 'status' => $status]);
    }

    public function test_une_tache_nest_donnee_qua_son_profil_et_une_seule_fois(): void
    {
        $task = $this->task();

        $this->getJson('/api/extension/tasks/next?profile=autre')->assertOk()->assertJson(['id' => null]);
        $this->getJson('/api/extension/tasks/next?profile=wildycaro')->assertOk()->assertJson(['id' => $task->id, 'kind' => 'x.post']);
        $this->getJson('/api/extension/tasks/next?profile=wildycaro')->assertOk()->assertJson(['id' => null]);
    }

    public function test_un_profil_nexecute_quune_tache_a_la_fois(): void
    {
        $this->task(BrowserTask::RUNNING);
        $this->task();

        $this->getJson('/api/extension/tasks/next?profile=wildycaro')->assertOk()->assertJson(['id' => null]);
    }

    public function test_le_resultat_est_journalise_et_ne_peut_pas_etre_reecrit(): void
    {
        $task = $this->task(BrowserTask::RUNNING);

        $this->postJson("/api/extension/tasks/{$task->id}/finish", ['status' => 'done', 'result' => ['id' => '500']])->assertOk();
        $this->postJson("/api/extension/tasks/{$task->id}/finish", ['status' => 'failed'])->assertStatus(409);

        $this->assertSame('500', $task->fresh()->result['id']);
        $this->assertSame(1, BotActionLog::where('action_type', 'x.post')->where('source', 'browser')->where('success', true)->count());
    }

    public function test_une_tache_x_annonce_le_compte_attendu(): void
    {
        $this->task();

        $this->getJson('/api/extension/tasks/next?profile=wildycaro')->assertOk()->assertJson(['account_id' => '77']);
    }

    public function test_une_tache_hors_x_nannonce_aucun_compte(): void
    {
        BrowserTask::create(['social_account_id' => $this->account->id, 'profile' => 'wildycaro', 'kind' => 'session.apply', 'payload' => ['cookies' => []]]);

        $this->getJson('/api/extension/tasks/next?profile=wildycaro')->assertOk()->assertJson(['account_id' => null]);
    }

    public function test_deux_comptes_partagent_un_profil_sans_jamais_agir_en_meme_temps(): void
    {
        $other = SocialAccount::create(['platform_id' => $this->account->platform_id, 'platform_account_id' => '88', 'name' => 'Planète', 'driver' => 'browser', 'browser_profile' => 'wildycaro', 'languages' => ['fr']]);
        $first = $this->task();
        $second = BrowserTask::create(['social_account_id' => $other->id, 'profile' => 'wildycaro', 'kind' => 'x.post', 'payload' => ['url' => 'https://x.com']]);

        $this->getJson('/api/extension/tasks/next?profile=wildycaro')->assertJson(['id' => $first->id, 'account_id' => '77']);
        $this->getJson('/api/extension/tasks/next?profile=wildycaro')->assertJson(['id' => null]);
        $this->postJson("/api/extension/tasks/{$first->id}/finish", ['status' => 'done'])->assertOk();
        $this->getJson('/api/extension/tasks/next?profile=wildycaro')->assertJson(['id' => $second->id, 'account_id' => '88']);
    }

    public function test_la_session_nest_remise_qua_une_tache_en_cours(): void
    {
        $this->account->update(['browser_session' => [['name' => 'twid', 'value' => 'u=77']]]);
        $pending = $this->task();
        $running = $this->task(BrowserTask::RUNNING);

        $this->getJson("/api/extension/tasks/{$pending->id}/session")->assertStatus(409);
        $this->getJson("/api/extension/tasks/{$running->id}/session")->assertOk()->assertJsonPath('cookies.0.value', 'u=77');
    }

    public function test_sans_session_enregistree_rien_nest_remis(): void
    {
        $task = $this->task(BrowserTask::RUNNING);

        $this->getJson("/api/extension/tasks/{$task->id}/session")->assertStatus(422);
    }

    public function test_seul_un_admin_recoit_une_session(): void
    {
        $this->account->update(['browser_session' => [['name' => 'twid', 'value' => 'u=77']]]);
        $task = $this->task(BrowserTask::RUNNING);
        Sanctum::actingAs(User::factory()->create(['role' => 'user']));

        $this->getJson("/api/extension/tasks/{$task->id}/session")->assertForbidden();
    }

    public function test_seul_un_admin_recoit_les_taches(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'user']));

        $this->getJson('/api/extension/tasks/next?profile=wildycaro')->assertForbidden();
    }

    public function test_une_tache_jamais_prise_est_annulee_et_le_dit(): void
    {
        $this->expectException(BrowserException::class);
        $this->expectExceptionMessage('rien n’a été fait');

        try {
            app(BrowserTaskQueue::class)->run($this->account, 'x.post', ['url' => 'https://x.com'], 0);
        } finally {
            $this->assertSame(BrowserTask::CANCELLED, BrowserTask::first()->status);
        }
    }
}
