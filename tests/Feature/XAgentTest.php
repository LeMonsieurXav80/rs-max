<?php

namespace Tests\Feature;

use App\Models\AccountActionEvent;
use App\Models\BrowserTask;
use App\Models\Persona;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\SocialContact;
use App\Models\User;
use App\Models\XEngagement;
use App\Services\Agent\AgentCatalog;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class XAgentTest extends TestCase
{
    use RefreshDatabase;

    private SocialAccount $a;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-07T12:00:00Z'));
        Http::preventStrayRequests();
        $this->user = User::factory()->create(['role' => 'manager']);
        $persona = Persona::create(['name' => 'Persona associé', 'system_prompt' => 'Voix chaleureuse et sobre', 'is_active' => true]);
        $this->a = SocialAccount::create(['name' => 'Compte X attribué', 'platform_id' => Platform::firstOrCreate(['slug' => 'twitter'], ['name' => 'X', 'auth_type' => 'oauth2'])->id,
            'platform_account_id' => '12345', 'driver' => 'browser', 'browser_profile' => 'nas-test', 'browser_username' => 'owner', 'browser_session_status' => 'connected', 'persona_id' => $persona->id, 'agent_hourly_limit' => 2]);
        $this->a->users()->attach($this->user, ['is_active' => true]);
        Sanctum::actingAs($this->user);
        foreach (['feed.read', 'search.read', 'thread.read', 'profile.read', 'reply', 'like', 'follow'] as $action) {
            app(AgentCatalog::class)->configure($this->a, $action, true, 3);
        }
    }

    private function url(string $suffix): string
    {
        return '/api/community-manager/accounts/'.$this->a->id.'/x/'.$suffix;
    }

    private function target(): array
    {
        return ['id' => '987', 'author' => 'visitor', 'url' => 'https://x.com/visitor/status/987', 'text' => 'Très jolie tenue', 'liked' => false, 'published_at' => now()->subHour()->toIso8601String()];
    }

    private function snapshot(bool $thread = false): string
    {
        $id = (string) \Illuminate\Support\Str::uuid();
        $t = $this->target();
        $result = $thread ? ['target' => $t, 'conversation' => [['id' => '900', 'author' => 'owner', 'text' => 'Mon post'], $t], 'context_complete' => true, 'visible_replies' => []] : ['items' => [$t], 'complete' => false];
        Cache::put('x-agent-snapshot:'.$id, ['account_id' => $this->a->id, 'result' => $result, 'source' => []], now()->addHour());

        return $id;
    }

    private function prepare(string $snapshot, string $action = 'like', array $extra = []): XEngagement
    {
        $id = $this->postJson($this->url('prepare'), $extra + ['snapshot_id' => $snapshot, 'action' => $action, 'target_id' => '987'])->assertCreated()->json('id');

        return XEngagement::findOrFail($id);
    }

    private function execute(XEngagement $e): void
    {
        $this->postJson('/api/community-manager/x/engagements/'.$e->id.'/execute', ['dry_run' => false])->assertAccepted();
    }

    private function finish(BrowserTask $task, array $result): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/extension/tasks/'.$task->id.'/finish', ['status' => 'done', 'result' => $result])->assertOk();
        Sanctum::actingAs($this->user);
    }

    public function test_lecture_json_scope_compte_et_memoire_contacts_sans_ia(): void
    {
        $this->mock(BrowserTaskQueue::class)->shouldReceive('run')->once()->withArgs(fn ($a, $kind, $p) => $a->id === $this->a->id && $kind === 'x.agent.read' && str_starts_with($p['url'], 'https://x.com/search?'))->andReturn(['items' => [$this->target()], 'observed' => 1, 'complete' => false]);
        $this->postJson($this->url('read'), ['action' => 'search.read', 'query' => '#mode'])->assertOk()->assertJsonPath('observed', 1)->assertJsonStructure(['snapshot_id']);
        $this->assertDatabaseHas('social_contacts', ['social_account_id' => $this->a->id, 'handle' => 'visitor', 'source' => 'search.read', 'follow_back' => 'unknown']);
        $this->getJson($this->url('contacts'))->assertOk();
        Http::assertNothingSent();
    }

    public function test_jeton_et_snapshots_ne_traversent_pas_un_compte_non_attribue(): void
    {
        $other = SocialAccount::create(['name' => 'Non attribué', 'platform_id' => $this->a->platform_id]);
        $this->postJson('/api/community-manager/accounts/'.$other->id.'/x/read', ['action' => 'feed.read'])->assertNotFound();
        $s = $this->snapshot();
        Cache::put('x-agent-snapshot:'.$s, ['account_id' => $other->id, 'result' => ['items' => [$this->target()]]], now()->addHour());
        $this->postJson($this->url('prepare'), ['action' => 'like', 'snapshot_id' => $s, 'target_id' => '987'])->assertUnprocessable();
    }

    public function test_preparation_et_aperçu_ne_creent_pas_de_tache_puis_unique_execution(): void
    {
        $e = $this->prepare($this->snapshot());
        $path = '/api/community-manager/x/engagements/'.$e->id.'/execute';
        $this->postJson($path)->assertOk()->assertJsonPath('dry_run', true);
        $this->assertSame(0, BrowserTask::count());
        $this->execute($e);
        $this->postJson($path, ['dry_run' => false])->assertConflict();
        $this->assertSame(1, BrowserTask::count());
        $this->assertSame(1, app(AgentCatalog::class)->usedToday($this->a, 'like'));
    }

    public function test_companion_et_hermes_partagent_la_reservation_et_le_journal_des_reactions(): void
    {
        app(AgentCatalog::class)->configure($this->a, 'repost', true, 3);
        $snapshot = $this->snapshot();
        $identity = ['platform_account_id' => '12345', 'username' => 'owner'];
        $base = '/api/extension/x/assisted/reactions/';
        foreach (['like' => 'confirmed', 'repost' => 'uncertain'] as $action => $status) {
            $draft = $this->prepare($snapshot, $action);
            $target = ['action' => $action, 'target_id' => '987', 'author' => 'visitor'] + $identity;
            $id = $this->postJson($base.'reserve', $target)->assertCreated()->assertJsonPath('status', 'running')->json('id');
            $this->assertSame($draft->id, $id);
            $this->postJson($base.'reserve', $target)->assertConflict();
            $this->postJson($this->url('prepare'), ['snapshot_id' => $snapshot, 'action' => $action, 'target_id' => '987'])
                ->assertOk()->assertJsonPath('id', $id)->assertJsonPath('status', 'running');
            $execute = '/api/community-manager/x/engagements/'.$id.'/execute';
            $this->postJson($execute, ['dry_run' => false])->assertConflict();
            $finish = $base.$id.'/finish';
            $this->postJson($finish, ['status' => 'confirmed', 'target_id' => '988', 'proof' => 'visible'] + $identity)->assertUnprocessable();
            $outcome = ['status' => $status, 'target_id' => '987', 'proof' => 'after_reload'] + $identity;
            $this->postJson($finish, $outcome)->assertOk()->assertJsonPath('status', $status);
            $this->postJson($finish, $outcome)->assertOk();
            $this->postJson($execute, ['dry_run' => false])->assertConflict();
            $this->assertDatabaseHas('bot_action_logs', ['social_account_id' => $this->a->id, 'source' => 'extension',
                'action_type' => 'x.'.$action, 'success' => $status === 'confirmed']);
        }
        $this->assertDatabaseCount('bot_action_logs', 2);
        $this->assertDatabaseCount('browser_tasks', 0);
    }

    public function test_compteur_reserve_au_claim_et_confirmation_identifiee_apres_reload(): void
    {
        $e = $this->prepare($this->snapshot());
        $this->execute($e);
        $q = new BrowserTaskQueue;
        $task = $q->claim('nas-test');
        $this->assertSame(1, AccountActionEvent::count());
        $this->assertSame('running', $e->fresh()->status);
        $this->finish($task, ['confirmed' => true, 'proof' => 'after_reload', 'target' => $this->target()]);
        $this->assertSame('confirmed', $e->fresh()->status);
        $this->assertSame('confirmed', AccountActionEvent::first()->status);
        $this->getJson('/api/community-manager/x/engagements/'.$e->id)->assertOk()->assertJsonPath('confirmed', true);
    }

    public function test_un_simple_ok_ou_une_preuve_sur_un_autre_post_reste_incertain(): void
    {
        foreach ([['confirmed' => true], ['confirmed' => true, 'proof' => 'after_reload', 'target' => ['id' => '999', 'author' => 'other', 'text' => 'Autre']]] as $n => $proof) {
            $target = $this->target();
            $target['id'] = (string) (987 + $n);
            $s = $this->snapshot();
            $state = Cache::get('x-agent-snapshot:'.$s);
            $state['result']['items'] = [$target];
            Cache::put('x-agent-snapshot:'.$s, $state, now()->addHour());
            $id = $this->postJson($this->url('prepare'), ['snapshot_id' => $s, 'action' => 'like', 'target_id' => $target['id']])->assertCreated()->json('id');
            $e = XEngagement::findOrFail($id);
            $this->execute($e);
            $task = (new BrowserTaskQueue)->claim('nas-test');
            $this->assertNotNull($task, json_encode(BrowserTask::latest('id')->first()->only(['id', 'status', 'error'])));
            $this->finish($task, $proof);
            $this->assertSame('uncertain', $e->fresh()->status);
            $this->assertSame('uncertain', $task->fresh()->status);
            $this->postJson('/api/community-manager/x/engagements/'.$id.'/execute', ['dry_run' => false])->assertConflict();
        }
    }

    public function test_reponse_persona_contexte_et_modification_avant_claim(): void
    {
        $s = $this->snapshot(true);
        $ctx = $this->getJson($this->url('context').'?snapshot_id='.$s)->assertOk()->assertJsonPath('persona.instructions', 'Voix chaleureuse et sobre')->json('context_id');
        $this->postJson($this->url('prepare'), ['action' => 'reply', 'snapshot_id' => $s, 'context_id' => $ctx, 'text' => str_repeat('進', 141)])
            ->assertUnprocessable();
        $e = $this->prepare($s, 'reply', ['context_id' => $ctx, 'text' => 'Merci pour ce gentil mot 🖤']);
        $this->execute($e);
        $this->a->persona->update(['system_prompt' => 'Autre voix']);
        $this->assertNull((new BrowserTaskQueue)->claim('nas-test'));
        $this->assertSame('failed', $e->fresh()->status);
        $this->assertSame(0, AccountActionEvent::count());
        Http::assertNothingSent();
    }

    public function test_age_de_la_cible_filtre_avant_le_vault_et_le_contexte_reste_disponible(): void
    {
        \App\Models\Setting::set("x_reply_vault_account_{$this->a->id}", json_encode([
            'persona_id' => $this->a->persona_id, 'corpus' => 'test',
        ], JSON_THROW_ON_ERROR));
        Http::fake(['http://localhost/lookup' => Http::response(['revision' => 'test123', 'sources' => []])]);
        $snapshot = $this->snapshot(true);
        $cacheKey = 'x-agent-snapshot:'.$snapshot;
        $data = Cache::get($cacheKey);
        $data['result']['target']['published_at'] = now()->subDays(15)->toIso8601String();
        Cache::put($cacheKey, $data, now()->addHour());

        $url = $this->url('context').'?snapshot_id='.$snapshot;
        $this->getJson($url)->assertConflict();
        Http::assertNothingSent();

        $data['result']['conversation'][0]['published_at'] = $data['result']['target']['published_at'];
        $data['result']['target']['published_at'] = now()->subHour()->toIso8601String();
        $data['result']['conversation'][1] = $data['result']['target'];
        Cache::put($cacheKey, $data, now()->addHour());

        $this->getJson($url)->assertOk()->assertJsonPath('vault.revision', 'test123');
        Http::assertSentCount(1);
        $this->assertSame(0, XEngagement::count());
    }

    public function test_x_en_decouverte_utilise_la_citation_de_la_cible_et_non_celle_du_parent(): void
    {
        $snapshot = $this->snapshot(true);
        $key = 'x-agent-snapshot:'.$snapshot;
        $data = Cache::get($key);
        $target = array_replace($data['result']['target'], ['text' => '', 'quote' => 'La citation lisible.']);
        $data['result']['target'] = $target;
        $data['result']['conversation'] = [
            ['id' => '900', 'author' => 'owner', 'text' => '', 'quote' => 'Publication citée à l’origine.'],
            ['id' => '986', 'author' => 'visitor', 'text' => 'Un échange précédent.'],
            $target,
        ];
        Cache::put($key, $data, now()->addHour());
        $url = $this->url('context').'?snapshot_id='.$snapshot;
        $this->getJson($url)->assertOk()->assertJsonPath('target.quote', 'La citation lisible.')
            ->assertJsonPath('conversation.0.quote', 'Publication citée à l’origine.');

        $data['result']['target']['text'] = 'Une réaction.';
        $data['result']['conversation'][2]['text'] = 'Une réaction.';
        $data['result']['conversation'][2]['quote'] = null;
        Cache::put($key, $data, now()->addHour());

        $this->getJson($url)->assertConflict();
        $this->assertSame(0, XEngagement::count());
    }

    public function test_pas_de_generation_api_ni_reponse_sans_contexte(): void
    {
        $this->postJson($this->url('prepare'), ['action' => 'reply', 'snapshot_id' => $this->snapshot(true), 'target_id' => '987', 'text' => 'Merci'])->assertConflict();
        Http::assertNothingSent();
    }

    public function test_nuit_et_tache_perimee_aucun_geste(): void
    {
        $e = $this->prepare($this->snapshot());
        $this->execute($e);
        $this->travel(1)->day();
        $this->assertNull((new BrowserTaskQueue)->claim('nas-test'));
        $this->getJson('/api/community-manager/x/engagements/'.$e->id)->assertOk()->assertJsonPath('status', 'failed');
        $this->assertSame(0, AccountActionEvent::count());
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-08T23:00:00Z'));
        $this->postJson($this->url('read'), ['action' => 'feed.read'])->assertStatus(423);
    }

    public function test_resultat_reste_lisible_si_la_session_expire(): void
    {
        $e = $this->prepare($this->snapshot());
        $this->a->update(['browser_session_status' => 'expired']);
        $this->getJson('/api/community-manager/x/engagements/'.$e->id)->assertOk();
        $this->postJson('/api/community-manager/x/engagements/'.$e->id.'/execute', ['dry_run' => false])->assertUnprocessable();
    }

    public function test_un_follow_deja_present_ninvente_pas_une_date_de_follow_agent(): void
    {
        $s = $this->snapshot();
        $state = Cache::get('x-agent-snapshot:'.$s);
        $state['result'] = ['profile' => ['handle' => 'visitor', 'following' => true, 'follow_back' => 'yes']];
        Cache::put('x-agent-snapshot:'.$s, $state, now()->addHour());
        $e = $this->prepare($s, 'follow');
        $this->execute($e);
        $task = (new BrowserTaskQueue)->claim('nas-test');
        $this->finish($task, ['confirmed' => true, 'proof' => 'after_reload', 'unchanged' => true, 'profile' => ['handle' => 'visitor', 'following' => true]]);
        $this->assertSame('confirmed', $e->fresh()->status);
        $this->assertSame(0, SocialContact::whereNotNull('followed_at')->count());
        $this->assertSame('unchanged', AccountActionEvent::first()->status);
    }

    public function test_absence_de_retour_ne_devient_prouvee_quapres_deux_lectures_espacees(): void
    {
        $this->mock(BrowserTaskQueue::class)->shouldReceive('run')->times(3)->andReturn(['profile' => ['handle' => 'visitor', 'name' => 'Visiteur', 'following' => true, 'follow_back' => 'unknown', 'absence_observed' => true]]);
        $data = ['action' => 'profile.read', 'handle' => 'visitor'];
        $this->postJson($this->url('read'), $data)->assertOk();
        $this->assertSame('unknown', SocialContact::first()->follow_back);
        $this->travel(1)->minute();
        $this->postJson($this->url('read'), $data)->assertOk();
        $this->assertSame('unknown', SocialContact::first()->follow_back);
        $this->travel(15)->minutes();
        $this->postJson($this->url('read'), $data)->assertOk();
        $this->assertSame('no', SocialContact::first()->follow_back);
        $this->assertNotNull(SocialContact::first()->follow_back_checked_at);
    }

    public function test_desuivi_refuse_sans_delai_et_non_retour_prouves_et_revalide_avant_claim(): void
    {
        app(AgentCatalog::class)->configure($this->a, 'unfollow', true, 3);
        $s = $this->snapshot();
        $state = Cache::get('x-agent-snapshot:'.$s);
        $state['result'] = ['profile' => ['handle' => 'visitor', 'following' => true, 'follow_back' => 'unknown']];
        Cache::put('x-agent-snapshot:'.$s, $state, now()->addHour());
        $e = $this->prepare($s, 'unfollow');
        $path = '/api/community-manager/x/engagements/'.$e->id.'/execute';
        $this->postJson($path, ['dry_run' => false])->assertConflict();
        $this->assertSame(0, BrowserTask::count());
        $contact = SocialContact::create(['social_account_id' => $this->a->id, 'handle' => 'visitor', 'followed_at' => now()->subDays(8), 'follow_back' => 'no', 'follow_back_checked_at' => now()]);
        $this->execute($e);
        $contact->update(['follow_back' => 'yes', 'follow_back_checked_at' => now()]);
        $this->assertNull((new BrowserTaskQueue)->claim('nas-test'));
        $this->assertSame('failed', $e->fresh()->status);
        $this->assertSame(0, AccountActionEvent::count());
    }

    public function test_la_citation_liee_a_sa_source_ne_recupere_pas_de_persona(): void
    {
        app(AgentCatalog::class)->configure($this->a, 'quote', true, 1);
        $this->a->update(['persona_id' => null]);
        $snapshot = $this->snapshot(true);
        $context = $this->getJson($this->url('context').'?snapshot_id='.$snapshot.'&purpose=quote')->assertOk()->assertJsonMissingPath('persona')->json('context_id');
        $row = $this->prepare($snapshot, 'quote', ['context_id' => $context, 'text' => 'Une idée à garder.']);
        $this->assertNull($row->persona_hash);
        $this->assertSame('provided_text', $row->payload['draft_source']);
        Http::assertNothingSent();
    }

    public function test_un_like_du_flux_confirme_met_aussi_a_jour_la_messagerie_du_meme_compte(): void
    {
        $item = \App\Models\InboxItem::create(['social_account_id' => $this->a->id, 'platform_id' => $this->a->platform_id, 'external_id' => '987', 'type' => 'comment', 'status' => 'unread']);
        $row = $this->prepare($this->snapshot());
        $this->execute($row);
        $task = $row->fresh()->browserTask;
        app(BrowserTaskQueue::class)->claim('nas-test');
        $this->finish($task->fresh(), ['confirmed' => true, 'proof' => 'after_reload', 'target' => $this->target()]);
        $this->assertNotNull($item->fresh()->liked_at);
    }
}
