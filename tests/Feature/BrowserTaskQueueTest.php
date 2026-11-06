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
