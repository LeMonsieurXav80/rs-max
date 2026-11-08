<?php

namespace Tests\Feature;

use App\Models\BrowserTask;
use App\Models\Persona;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ThreadsAgentTest extends TestCase
{
    use RefreshDatabase;

    private SocialAccount $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-08T12:00:00Z'));
        $u = User::factory()->create(['role' => 'admin']);
        $p = Persona::create(['name' => 'Voix', 'system_prompt' => 'Bienveillance', 'is_active' => true]);
        $this->a = SocialAccount::create(['name' => 'owner', 'platform_id' => Platform::firstOrCreate(['slug' => 'threads'], ['name' => 'Threads'])->id, 'platform_account_id' => '123', 'persona_id' => $p->id, 'driver' => 'api', 'browser_profile' => 'nas-test', 'browser_username' => 'owner', 'browser_session_status' => 'connected', 'credentials' => ['access_token' => 'test'], 'agent_hourly_limit' => 20]);
        $this->a->users()->attach($u, ['is_active' => true]);
        foreach (['reply', 'feed.read', 'search.read', 'thread.read', 'profile.read', 'like', 'comment'] as $key) {
            $this->a->agentActions()->create(['action' => $key, 'enabled' => true, 'daily_limit' => 5]);
        }Sanctum::actingAs($u);
    }

    private function prefix(): string
    {
        return '/api/community-manager/accounts/'.$this->a->id.'/threads';
    }

    private function target(): array
    {
        return ['id' => 'ABC123', 'author' => 'visitor', 'text' => 'Une belle promenade.', 'url' => 'https://www.threads.com/@visitor/post/ABC123', 'liked' => false];
    }

    private function read(string $action = 'thread.read'): string
    {
        $t = $this->target();
        $this->mock(BrowserTaskQueue::class)->shouldReceive('run')->once()->andReturn($action === 'thread.read' ? ['target' => $t, 'conversation' => [$t], 'context_complete' => true, 'complete' => false] : ['items' => [$t], 'complete' => false]);
        $r = $this->postJson($this->prefix().'/read', ['action' => $action, 'url' => $t['url'], 'query' => 'promenade']);

        return $r->assertOk()->json('snapshot_id');
    }

    public function test_lecture_et_commentaire_contextuel_idempotent_avec_driver_api_conserve(): void
    {
        $snapshot = $this->read();
        $ctx = $this->getJson($this->prefix().'/context?snapshot_id='.$snapshot)->assertOk()->json('context_id');
        $d = ['action' => 'comment', 'snapshot_id' => $snapshot, 'context_id' => $ctx, 'text' => 'Quelle jolie lumière !', 'request_key' => (string) Str::uuid(), 'dry_run' => false];
        $id = $this->postJson($this->prefix().'/actions', $d)->assertAccepted()->assertJsonPath('status', 'pending')->json('id');
        $this->postJson($this->prefix().'/actions', $d)->assertAccepted()->assertJsonPath('id', $id);
        $this->postJson($this->prefix().'/actions', array_replace($d, ['text' => 'Autre texte']))->assertConflict();
        $this->assertSame(1, BrowserTask::count());
        $this->assertSame('api', $this->a->fresh()->driver);
    }

    public function test_like_sans_clic_en_apercu_et_persona_manquant_refuse_commentaire(): void
    {
        $snapshot = $this->read('feed.read');
        $this->postJson($this->prefix().'/actions', ['action' => 'like', 'snapshot_id' => $snapshot])->assertOk()->assertJsonPath('dry_run', true);
        $this->assertSame(0, BrowserTask::count());
        $this->getJson($this->prefix().'/context?snapshot_id='.$snapshot)->assertUnprocessable();
    }

    public function test_changement_session_ou_activation_annule_la_tache_avant_geste(): void
    {
        $snapshot = $this->read('feed.read');
        $id = $this->postJson($this->prefix().'/actions', ['action' => 'like', 'snapshot_id' => $snapshot, 'dry_run' => false, 'request_key' => (string) Str::uuid()])->json('id');
        $this->a->update(['browser_username' => 'autre']);
        $this->app->forgetInstance(BrowserTaskQueue::class);
        $this->assertNull(app(BrowserTaskQueue::class)->claim('nas-test'));
        $this->assertSame('cancelled', BrowserTask::find($id)->status);
        $this->assertSame(0, \App\Models\AccountActionEvent::count());
    }

    public function test_fausse_confirmation_reste_incertaine(): void
    {
        $snapshot = $this->read('feed.read');
        $id = $this->postJson($this->prefix().'/actions', ['action' => 'like', 'snapshot_id' => $snapshot, 'dry_run' => false, 'request_key' => (string) Str::uuid()])->json('id');
        $this->app->forgetInstance(BrowserTaskQueue::class);
        app(BrowserTaskQueue::class)->claim('nas-test');
        $this->postJson('/api/extension/tasks/'.$id.'/finish', ['status' => 'done', 'result' => ['confirmed' => true, 'proof' => 'after_reload', 'username' => 'owner', 'target_url' => $this->target()['url'], 'action' => 'like', 'liked' => false]])->assertOk();
        $this->assertSame('uncertain', BrowserTask::find($id)->status);
    }

    public function test_session_refuse_autre_identite_api_et_domaine_cookie_externe(): void
    {
        Http::fake(['*' => Http::response(['id' => '999', 'username' => 'owner'])]);
        $cookie = ['name' => 'sessionid', 'value' => 'secret', 'domain' => '.threads.com'];
        $data = ['username' => 'owner', 'cookies' => [$cookie, ['name' => 'csrftoken', 'value' => 'csrf', 'domain' => '.threads.com']]];
        $this->postJson('/api/extension/threads/session', $data)->assertConflict();
        $this->assertSame('connected', $this->a->fresh()->browser_session_status);
        $data['cookies'][0]['domain'] = '.example.com';
        $this->postJson('/api/extension/threads/session', $data)->assertUnprocessable();
        $this->assertSame(0, BrowserTask::count());
    }

    public function test_derniere_place_reservee_autorise_le_geste_mais_la_desactivation_le_bloque(): void
    {
        $this->a->agentActions()->where('action', 'like')->update(['daily_limit' => 1]);
        $snapshot = $this->read('feed.read');
        $id = $this->postJson($this->prefix().'/actions', ['action' => 'like', 'snapshot_id' => $snapshot, 'dry_run' => false, 'request_key' => (string) Str::uuid()])->assertAccepted()->json('id');
        $this->app->forgetInstance(BrowserTaskQueue::class);
        app(BrowserTaskQueue::class)->claim('nas-test');
        $this->postJson('/api/extension/tasks/'.$id.'/authorize-threads')->assertOk();
        $this->a->agentActions()->where('action', 'like')->update(['enabled' => false]);
        $this->postJson('/api/extension/tasks/'.$id.'/authorize-threads')->assertConflict();
    }

    public function test_transfert_session_valide_conserve_driver_api(): void
    {
        Http::fake(['*' => Http::response(['id' => '123', 'username' => 'owner'])]);
        $this->mock(BrowserTaskQueue::class)->shouldReceive('run')->twice()->andReturn(['username' => 'owner']);
        $data = ['username' => 'owner', 'cookies' => [['name' => 'sessionid', 'value' => 'secret', 'domain' => '.threads.com'], ['name' => 'csrftoken', 'value' => 'csrf', 'domain' => '.threads.com']]];
        $this->postJson('/api/extension/threads/session', $data)->assertOk()->assertJsonPath('status', 'connected');
        $this->assertSame('api', $this->a->fresh()->driver);
        $this->assertNotSame(json_encode($data['cookies']), $this->a->fresh()->getRawOriginal('browser_session'));
    }

    public function test_rapprochement_threads_est_une_lecture_sans_second_geste(): void
    {
        $snapshot = $this->read('feed.read');
        $id = $this->postJson($this->prefix().'/actions', ['action' => 'like', 'snapshot_id' => $snapshot, 'dry_run' => false, 'request_key' => (string) Str::uuid()])->json('id');
        $this->app->forgetInstance(BrowserTaskQueue::class);
        app(BrowserTaskQueue::class)->claim('nas-test');
        $t = BrowserTask::findOrFail($id);
        $t->update(['status' => 'uncertain', 'error' => 'Délai dépassé']);
        $proof = ['confirmed' => true, 'proof' => 'after_reload', 'username' => 'owner', 'target_url' => $this->target()['url'], 'action' => 'like', 'liked' => true];
        $this->mock(BrowserTaskQueue::class)->shouldReceive('run')->twice()->with(\Mockery::type(SocialAccount::class), 'threads.agent.check', \Mockery::type('array'), 120)->andReturn($proof);
        $url = '/api/community-manager/threads/actions/'.$id.'/verify';
        $this->postJson($url)->assertOk()->assertJsonPath('dry_run', true);
        $this->assertSame('uncertain', $t->fresh()->status);
        $this->postJson($url, ['dry_run' => false])->assertOk()->assertJsonPath('confirmed', true)->assertJsonPath('sent', false);
        $this->assertSame(1, \App\Models\AccountActionEvent::count());
        $this->assertSame('confirmed', \App\Models\AccountActionEvent::first()->status);
    }
}
