<?php

namespace Tests\Feature;

use App\Models\BotActionLog;
use App\Models\BrowserTask;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Agent\AccountActionBudget;
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

    public function test_un_controle_tiktok_en_cours_ne_retient_pas_la_file_x_distincte(): void
    {
        $tiktok = SocialAccount::create(['platform_id' => Platform::where('slug', 'tiktok')->firstOrFail()->id,
            'name' => 'Compte de test TikTok', 'browser_profile' => 'nas-tiktok']);
        $verification = BrowserTask::create(['social_account_id' => $tiktok->id, 'profile' => 'nas-tiktok',
            'kind' => 'tiktok.verify', 'payload' => [], 'status' => BrowserTask::RUNNING, 'claimed_at' => now()]);
        $read = BrowserTask::create(['social_account_id' => $this->account->id, 'profile' => 'nas-x',
            'kind' => 'x.agent.read', 'payload' => ['action' => 'search.read']]);

        $this->getJson('/api/extension/tasks/next?profile=nas-tiktok')->assertOk()->assertJson(['id' => null]);
        $this->getJson('/api/extension/tasks/next?profile=nas-x')->assertOk()
            ->assertJson(['id' => $read->id, 'kind' => 'x.agent.read', 'account_id' => '77']);
        $this->assertSame(BrowserTask::RUNNING, $read->fresh()->status);
        $this->assertSame(BrowserTask::RUNNING, $verification->fresh()->status);
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

    public function test_une_livraison_deja_prouvee_est_reutilisee_sans_second_ordre(): void
    {
        $task = $this->task(BrowserTask::DONE);
        $task->update(['idempotency_key' => 'post-platform:9', 'result' => ['id' => '500', 'confirmed' => true, 'proof' => 'after_reload']]);
        $result = app(BrowserTaskQueue::class)->run($this->account, 'x.post', ['text' => 'Bonjour', 'publication_key' => 'post-platform:9']);
        $this->assertSame('500', $result['id']);
        $this->assertSame(1, BrowserTask::count());
    }

    public function test_une_livraison_incertaine_ne_peut_pas_etre_reenvoyee(): void
    {
        $this->task(BrowserTask::UNCERTAIN)->update(['idempotency_key' => 'post-platform:9']);
        $this->expectException(\App\Services\Browser\BrowserUncertainException::class);
        try {
            app(BrowserTaskQueue::class)->run($this->account, 'x.post', ['text' => 'Bonjour', 'publication_key' => 'post-platform:9']);
        } finally {
            $this->assertSame(1, BrowserTask::count());
        }
    }

    public function test_une_publication_tiktok_annulee_par_la_pause_peut_repartir_sur_demande(): void
    {
        $this->account->update([
            'platform_id' => Platform::where('slug', 'tiktok')->firstOrFail()->id,
            'agent_adaptation' => ['enabled' => true],
            'agent_adaptation_state' => ['cooldown_until' => now()->addMinutes(15)->toIso8601String()],
        ]);
        $payload = ['url' => 'https://www.tiktok.com/tiktokstudio/upload', 'text' => 'Bonjour',
            'publication_key' => 'post-platform:9', 'deadline' => now()->addMinutes(15)->toIso8601String()];
        $cancelled = $this->task(BrowserTask::CANCELLED);
        $cancelled->updateOrFail([
            'kind' => 'tiktok.publish', 'idempotency_key' => $payload['publication_key'], 'payload' => $payload,
            'error' => AccountActionBudget::ADAPTIVE_PAUSE, 'finished_at' => now(),
        ]);
        $queue = app(BrowserTaskQueue::class);
        $payload['deadline'] = now()->addMinutes(16)->toIso8601String();
        BrowserTask::created(function (BrowserTask $task) use ($queue) {
            $claimed = $queue->claim($task->profile);
            $this->assertSame($task->id, $claimed?->id);
            $claimed->update(['status' => BrowserTask::DONE, 'result' => ['confirmed' => true]]);
        });
        $result = $queue->run($this->account, 'tiktok.publish', $payload);
        $this->assertTrue($result['confirmed']);
        $this->assertSame(2, BrowserTask::count());
        $this->assertSame(AccountActionBudget::ADAPTIVE_PAUSE, $cancelled->fresh()->error);
        $this->assertNull($cancelled->fresh()->idempotency_key);
        $this->assertSame($payload['deadline'], BrowserTask::where('idempotency_key', $payload['publication_key'])->sole()->payload['deadline']);
        $this->assertSame($result, $queue->run($this->account, 'tiktok.publish', $payload));
        $this->assertSame(2, BrowserTask::count());
    }

    public function test_changer_les_medias_dune_livraison_existante_est_refuse(): void
    {
        $this->task(BrowserTask::DONE)->update(['idempotency_key' => 'post-platform:9', 'payload' => ['text' => 'Bonjour', 'media' => [['url' => 'https://rs/a.jpg', 'mimetype' => 'image/jpeg']]]]);
        $this->expectException(BrowserException::class);
        app(BrowserTaskQueue::class)->run($this->account, 'x.post', ['text' => 'Bonjour', 'publication_key' => 'post-platform:9', 'media' => [['url' => 'https://rs/b.jpg', 'mimetype' => 'image/jpeg']]]);
    }

    public function test_le_delai_de_redistribution_depasse_le_timeout_des_publications_video(): void
    {
        $this->assertGreaterThan((new \App\Jobs\PublishToPlatformJob(new \App\Models\PostPlatform))->timeout, config('queue.connections.database.retry_after'));
    }

    public function test_un_second_poll_du_meme_profil_ne_depasse_pas_le_verrou_de_prise(): void
    {
        $this->task();
        $lock = \Illuminate\Support\Facades\Cache::lock('browser-claim:wildycaro', 10);
        $lock->get();
        try {
            $this->assertNull(app(BrowserTaskQueue::class)->claim('wildycaro'));
        } finally {
            $lock->release();
        }
        $this->assertNotNull(app(BrowserTaskQueue::class)->claim('wildycaro'));
    }
}
