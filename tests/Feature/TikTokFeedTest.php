<?php

namespace Tests\Feature;

use App\Jobs\ExecuteTikTokEngagement;
use App\Models\Persona;
use App\Models\Platform;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Agent\AgentCatalog;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TikTokFeedTest extends TestCase
{
    use RefreshDatabase;

    private SocialAccount $account;

    private string $base;

    private string $url = 'https://www.tiktok.com/@creator/video/123456789';

    private bool $complete = true;

    private array $result = ['confirmed' => true];

    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create(['role' => 'manager']);
        Sanctum::actingAs($user);
        $persona = Persona::create(['name' => 'Persona test', 'system_prompt' => 'Voix du compte associé', 'is_active' => true]);
        $this->account = SocialAccount::create(['platform_id' => Platform::where('slug', 'tiktok')->firstOrFail()->id, 'name' => 'Compte test',
            'driver' => 'browser', 'browser_profile' => 'nas-test', 'browser_username' => 'test', 'browser_session_status' => 'connected', 'persona_id' => $persona->id]);
        $this->account->users()->attach($user, ['is_active' => true]);
        $this->base = '/api/agent/accounts/'.$this->account->id.'/tiktok';
        foreach (['feed.read', 'video.comments', 'video.like', 'comment.like', 'video.comment'] as $action) {
            app(AgentCatalog::class)->configure($this->account, $action, true, 5);
        }
        $test = $this;
        $browser = \Mockery::mock(BrowserTaskQueue::class);
        $browser->shouldReceive('run')->andReturnUsing(function ($account, $kind, $payload) use ($test) {
            $test->calls[] = compact('kind', 'payload');
            if ($kind === 'tiktok.video.comments') {
                return ['video' => ['url' => $test->url, 'caption' => 'Une jolie tenue'], 'comments' => [
                    ['key' => str_repeat('a', 64), 'author' => 'visitor', 'text' => 'Bravo !', 'parent' => null, 'liked' => false],
                ], 'complete' => $test->complete];
            }

            return $test->result;
        });
        $this->app->instance(BrowserTaskQueue::class, $browser);
        Setting::setEncrypted('openai_api_key', 'test-only');
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'Cette tenue est très réussie ! ✨']]]])]);
        Queue::fake();
    }

    private function snapshot(): string
    {
        return $this->postJson($this->base.'/read', ['action' => 'video.comments', 'url' => $this->url])
            ->assertOk()->assertJsonPath('complete', $this->complete)->json('snapshot_id');
    }

    private function prepare(string $action = 'video.comment', ?string $snapshot = null): int
    {
        return $this->postJson($this->base.'/prepare', ['action' => $action, 'snapshot_id' => $snapshot ?? $this->snapshot(), 'comment_key' => str_repeat('a', 64)])
            ->assertCreated()->assertJsonPath('status', 'prepared')->json('id');
    }

    public function test_lecture_limitee_a_tiktok_et_au_compte_actif(): void
    {
        $this->postJson($this->base.'/read', ['action' => 'video.comments', 'url' => 'https://evil.test/@c/video/1'])->assertUnprocessable();
        $this->assertCount(0, $this->calls);
        $this->account->users()->detach();
        $this->postJson($this->base.'/read', ['action' => 'feed.read'])->assertForbidden();
    }

    public function test_lecture_partielle_interdit_la_redaction_mais_permet_le_like_precis(): void
    {
        $this->complete = false;
        $snapshot = $this->snapshot();
        $this->postJson($this->base.'/prepare', ['action' => 'video.comment', 'snapshot_id' => $snapshot])->assertUnprocessable();
        Http::assertNothingSent();
        $this->prepare('comment.like', $snapshot);
    }

    public function test_redaction_utilise_le_persona_et_toute_la_discussion_serveur(): void
    {
        $this->prepare();
        Http::assertSent(fn ($r) => str_contains($r['messages'][0]['content'], 'Voix du compte associé')
            && str_contains($r['messages'][0]['content'], 'bienveillant')
            && str_contains($r['messages'][1]['content'], 'Bravo !'));
        Queue::assertNothingPushed();
    }

    public function test_persona_et_snapshot_du_meme_compte_obligatoires(): void
    {
        $snapshot = $this->snapshot();
        $this->account->persona->update(['is_active' => false]);
        $this->postJson($this->base.'/prepare', ['action' => 'video.comment', 'snapshot_id' => $snapshot])->assertUnprocessable();
        $this->postJson($this->base.'/prepare', ['action' => 'video.like', 'snapshot_id' => (string) \Illuminate\Support\Str::uuid()])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_validation_et_reservation_unique_sans_renvoi(): void
    {
        $snapshot = $this->snapshot();
        $id = $this->prepare('video.comment', $snapshot);
        $this->postJson($this->base.'/prepare', ['action' => 'video.comment', 'snapshot_id' => $snapshot])->assertOk()->assertJsonPath('id', $id);
        Http::assertSentCount(1);
        $this->postJson('/api/tiktok/engagements/'.$id.'/send')->assertOk()->assertJsonPath('dry_run', true);
        Queue::assertNothingPushed();
        $this->postJson('/api/tiktok/engagements/'.$id.'/send', ['dry_run' => false])->assertAccepted();
        $this->postJson('/api/tiktok/engagements/'.$id.'/send', ['dry_run' => false])->assertConflict();
        Queue::assertPushed(ExecuteTikTokEngagement::class, 1);
    }

    public function test_persona_change_bloque_le_brouillon_avant_tout_geste(): void
    {
        $id = $this->prepare();
        $this->account->persona->update(['system_prompt' => 'Nouvelle voix']);
        $this->postJson('/api/tiktok/engagements/'.$id.'/send', ['dry_run' => false])->assertConflict();
        Queue::assertNothingPushed();
    }

    public function test_brouillon_visible_et_validable_depuis_la_page_tiktok(): void
    {
        $id = $this->prepare();
        $this->actingAs(auth()->user())->get('/agent/tiktok')->assertOk()->assertSee('Cette tenue est très réussie !')->assertSee('Valider cette action');
        $this->post(route('agent.tiktok.send', $id))->assertRedirect();
        $this->assertDatabaseHas('tiktok_engagements', ['id' => $id, 'status' => 'queued']);
        Queue::assertPushed(ExecuteTikTokEngagement::class, 1);
    }

    public function test_preuve_obligatoire_et_job_non_rejouable(): void
    {
        $id = $this->prepare('video.like');
        $this->postJson('/api/tiktok/engagements/'.$id.'/send', ['dry_run' => false])->assertAccepted();
        $this->result = ['confirmed' => false];
        $job = new ExecuteTikTokEngagement($id);
        $job->handle(app(BrowserTaskQueue::class), app(AgentCatalog::class));
        $this->assertDatabaseHas('tiktok_engagements', ['id' => $id, 'status' => 'uncertain']);
        $count = count($this->calls);
        $job->handle(app(BrowserTaskQueue::class), app(AgentCatalog::class));
        $this->assertCount($count, $this->calls);
        $this->postJson('/api/tiktok/engagements/'.$id.'/send', ['dry_run' => false])->assertConflict();
    }

    public function test_geste_confirme_decompte_le_quota(): void
    {
        $id = $this->prepare('comment.like');
        $this->postJson('/api/tiktok/engagements/'.$id.'/send', ['dry_run' => false])->assertAccepted();
        (new ExecuteTikTokEngagement($id))->handle(app(BrowserTaskQueue::class), app(AgentCatalog::class));
        $this->assertDatabaseHas('tiktok_engagements', ['id' => $id, 'status' => 'confirmed']);
        $this->assertSame(1, app(AgentCatalog::class)->usedToday($this->account, 'comment.like'));
        $this->assertSame('tiktok.comment.like', $this->calls[1]['kind']);
        $this->assertSame('visitor', $this->calls[1]['payload']['target']['author']);
    }
}
