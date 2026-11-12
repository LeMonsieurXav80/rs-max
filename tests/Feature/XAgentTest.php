<?php

namespace Tests\Feature;

use App\Jobs\VerifyXEngagementReply;
use App\Models\AccountActionEvent;
use App\Models\BrowserTask;
use App\Models\InboxItem;
use App\Models\Persona;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\SocialContact;
use App\Models\User;
use App\Models\XEngagement;
use App\Services\Agent\AccountActionBudget;
use App\Services\Agent\AgentCatalog;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-07T12:00:00Z')->setTimezone(config('app.timezone')));
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

    private function finish(BrowserTask $task, array $result, string $status = 'done'): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/extension/tasks/'.$task->id.'/finish', ['status' => $status, 'result' => $result])->assertOk();
        Sanctum::actingAs($this->user);
    }

    public function test_lecture_json_scope_compte_et_memoire_contacts_sans_ia(): void
    {
        $this->mock(BrowserTaskQueue::class)->shouldReceive('run')->once()->withArgs(fn ($a, $kind, $p) => $a->id === $this->a->id && $kind === 'x.agent.read' && str_starts_with($p['url'], 'https://x.com/search?'))->andReturn(['items' => [$this->target()], 'observed' => 1, 'complete' => false]);
        $this->postJson($this->url('read'), ['action' => 'search.read', 'query' => '#mode'])->assertOk()->assertJsonPath('observed', 1)->assertJsonStructure(['snapshot_id']);
        $this->assertDatabaseHas('social_contacts', ['social_account_id' => $this->a->id, 'handle' => 'visitor', 'source' => 'search.read', 'follow_back' => 'unknown']);
        $this->getJson($this->url('contacts'))->assertOk();
        $this->getJson('/api/community-manager/logs?account_id='.$this->a->id.'&action=agent.search.read')
            ->assertOk()->assertJsonPath('logs.0.text', '#mode');
        Http::assertNothingSent();
    }

    public function test_un_fil_deja_commente_et_aime_n_est_pas_relu_et_sort_du_prefiltre(): void
    {
        $t = $this->target();
        $thread = ['target' => $t, 'conversation' => [$t], 'context_complete' => true,
            'visible_replies' => [['id' => '988', 'author' => 'Owner', 'text' => 'Merci !']]];
        $this->mock(BrowserTaskQueue::class)->shouldReceive('run')->once()->andReturn($thread);
        $read = ['action' => 'thread.read', 'url' => 'https://x.com/visitor/status/987'];
        $this->postJson($this->url('read'), $read)->assertOk();

        $this->assertSame(['987'], app(\App\Services\Agent\XEngagementService::class)->replyPrefilterIds($this->a, ['987', '555']));
        InboxItem::create(['social_account_id' => $this->a->id, 'platform_id' => $this->a->platform_id, 'external_id' => '987',
            'type' => 'comment', 'status' => 'replied', 'liked_at' => now()]);
        $this->postJson($this->url('read'), $read)->assertConflict()->assertJsonPath('skip_item', true);
    }

    public function test_un_commentaire_lu_sous_le_fil_peut_etre_aime_mais_pas_repondu(): void
    {
        $id = (string) \Illuminate\Support\Str::uuid();
        $t = $this->target();
        $comment = ['id' => '990', 'author' => 'fan', 'url' => 'https://x.com/fan/status/990', 'text' => 'Superbe', 'published_at' => now()->toIso8601String()];
        Cache::put('x-agent-snapshot:'.$id, ['account_id' => $this->a->id, 'result' => ['target' => $t, 'conversation' => [$t],
            'context_complete' => true, 'visible_replies' => [$comment]], 'source' => []], now()->addHour());

        $this->postJson($this->url('prepare'), ['snapshot_id' => $id, 'action' => 'like', 'target_id' => '990'])->assertCreated();
        $this->assertSame('https://x.com/fan/status/990', XEngagement::latest('id')->first()->payload['url']);
        $this->postJson($this->url('prepare'), ['snapshot_id' => $id, 'action' => 'reply', 'target_id' => '990', 'text' => 'Merci'])
            ->assertUnprocessable();
    }

    public function test_le_catalogue_n_est_journalise_qu_une_fois_par_demi_heure(): void
    {
        $this->getJson('/api/community-manager/accounts')->assertOk();
        $this->getJson('/api/community-manager/accounts')->assertOk();
        $this->assertSame(1, \App\Models\BotActionLog::where('action_type', 'agent.catalog.read')->count());
        $this->travel(31)->minutes();
        $this->getJson('/api/community-manager/accounts')->assertOk();
        $this->assertSame(2, \App\Models\BotActionLog::where('action_type', 'agent.catalog.read')->count());
    }

    public function test_la_recherche_qualifiee_garde_le_texte_exact_jusquau_navigateur(): void
    {
        $query = str_repeat('x', 255).' lang:fr';
        $this->mock(BrowserTaskQueue::class)->shouldReceive('run')->once()->withArgs(function ($account, $kind, $payload) use ($query) {
            parse_str(parse_url($payload['url'], PHP_URL_QUERY), $params);

            return $account->is($this->a) && $kind === 'x.agent.read' && $params['q'] === $query && $params['f'] === 'live';
        })->andReturn(['items' => [], 'observed' => 0, 'complete' => false]);
        $this->postJson($this->url('read'), ['action' => 'search.read', 'query' => $query])->assertOk();
        $this->postJson($this->url('read'), ['action' => 'search.read', 'query' => $query.'x'])->assertUnprocessable();
        $this->getJson('/api/community-manager/logs?account_id='.$this->a->id.'&action=agent.search.read')
            ->assertOk()->assertJsonPath('logs.0.text', $query);
    }

    public function test_recherche_signale_les_reponses_connues_avant_ouverture_du_fil(): void
    {
        $other = SocialAccount::create(['name' => 'Autre compte', 'platform_id' => $this->a->platform_id]);
        $local = ['social_account_id' => $this->a->id, 'author' => 'visitor',
            'context_json' => '[]', 'persona_hash' => str_repeat('a', 64), 'reply_text' => 'Merci !'];
        DB::table('extension_x_replies')->insert([
            ['tweet_id' => '991', 'status' => 'uncertain'] + $local,
            ['tweet_id' => '996', 'status' => 'prepared'] + $local,
            ['tweet_id' => '997', 'status' => 'published', 'social_account_id' => $other->id] + $local,
        ]);
        InboxItem::create(['social_account_id' => $this->a->id, 'platform_id' => $this->a->platform_id,
            'external_id' => '992', 'type' => 'comment', 'status' => 'reply_failed', 'reply_attempts' => 1]);
        foreach (['993' => ['reply', 'queued'], '995' => ['reply', 'prepared'], '998' => ['like', 'confirmed']] as $id => [$action, $status]) {
            XEngagement::create(['social_account_id' => $this->a->id, 'target_key' => hash('sha256', (string) $id),
                'action' => $action, 'status' => $status, 'payload' => []]);
        }
        BrowserTask::create(['social_account_id' => $this->a->id, 'profile' => 'nas-test', 'kind' => 'x.post',
            'payload' => ['url' => 'https://x.com/compose/post?in_reply_to=994']]);
        BrowserTask::create(['social_account_id' => $this->a->id, 'profile' => 'nas-test', 'kind' => 'x.post',
            'payload' => ['url' => 'https://x.com/compose/post?quote_tweet_id=999']]);

        $ids = ['994', '991', '992', '993', '995', '996', '997', '998', '999'];
        $items = array_map(fn ($id) => array_replace($this->target(), [
            'id' => $id, 'url' => 'https://x.com/visitor/status/'.$id,
        ]), $ids);
        $ids[] = '1000';
        $items[] = array_replace($this->target(), ['id' => '1000', 'author' => 'Owner', 'url' => 'https://x.com/Owner/status/1000']);
        $blocked = ['994', '991', '992', '993'];
        $replyBlocked = [...$blocked, '1000'];
        $this->mock(BrowserTaskQueue::class)->shouldReceive('run')->once()->andReturn([
            'items' => $items, 'observed' => count($items), 'complete' => false,
        ]);
        $snapshot = $this->postJson($this->url('read'), ['action' => 'search.read', 'query' => '#mode'])
            ->assertOk()->assertJsonPath('reply_blocked_ids', $replyBlocked)
            ->assertJsonCount(count($ids), 'items')->json('snapshot_id');
        $this->postJson('/api/extension/x/assisted/check', ['ids' => $ids, 'platform_account_id' => '12345', 'username' => 'owner'])
            ->assertOk()->assertJsonPath('blocked', $blocked);
        $this->getJson('/api/community-manager/logs?account_id='.$this->a->id.'&action=agent.search.read')
            ->assertOk()->assertJsonPath('logs.0.steps.0.details.count', count($replyBlocked));
        $this->postJson($this->url('prepare'), ['action' => 'like', 'snapshot_id' => $snapshot, 'target_id' => '991'])
            ->assertCreated()->assertJsonPath('status', 'prepared');
        $this->assertDatabaseCount('account_action_events', 0);
        $this->assertDatabaseCount('browser_tasks', 2);
        Http::assertNothingSent();
    }

    public function test_les_reponses_ecartees_ne_rouvrent_pas_le_fil_sur_x(): void
    {
        $other = SocialAccount::create(['name' => 'Autre compte', 'platform_id' => $this->a->platform_id]);
        $base = ['social_account_id' => $this->a->id, 'platform_id' => $this->a->platform_id, 'type' => 'comment', 'status' => 'unread'];
        $deferred = ['agent_review_reason' => 'not_relevant', 'agent_reviewed_at' => now()->subHour(), 'agent_retry_at' => now()->addDay()];
        InboxItem::create(['external_id' => '991'] + $base + $deferred);
        InboxItem::create(['external_id' => '992', 'status' => 'ignored', 'agent_review_reason' => 'x_account_suspended'] + $base);
        InboxItem::create(['external_id' => '993', 'agent_retry_at' => now()->subMinute()] + $base + $deferred);
        InboxItem::create(['external_id' => '994', 'social_account_id' => $other->id] + $base + $deferred);
        InboxItem::create(['external_id' => '995', 'type' => 'dm'] + $base + $deferred);
        InboxItem::create(['external_id' => '996', 'agent_review_reason' => 'missing_facts', 'agent_reviewed_at' => now()->subDay()]
            + $base + $deferred);
        $ids = ['991', '992', '993', '994', '995', '996', '997'];
        $posts = array_map(fn ($id) => array_replace($this->target(), ['id' => $id, 'url' => 'https://x.com/visitor/status/'.$id]), $ids);
        $posts[6]['published_at'] = now()->subDays(15)->toIso8601String();
        $this->mock(BrowserTaskQueue::class)->shouldReceive('run')->once()->andReturn(['items' => $posts, 'complete' => false]);

        $snapshot = $this->postJson($this->url('read'), ['action' => 'feed.read', 'feed' => 'following'])
            ->assertOk()->assertJsonPath('reply_blocked_ids', ['991', '992', '997'])->assertJsonPath('like_blocked_ids', [])
            ->assertJsonCount(7, 'items')->json('snapshot_id');
        $batchIds = [...$ids, ...array_map('strval', range(1000, 1292))];
        $this->postJson('/api/extension/x/assisted/check', ['ids' => $batchIds, 'platform_account_id' => '12345', 'username' => 'owner'])
            ->assertOk()->assertJsonPath('blocked', ['991', '992']);
        $this->postJson($this->url('prepare'), ['action' => 'like', 'snapshot_id' => $snapshot, 'target_id' => '991'])->assertCreated();
        $this->assertDatabaseCount('account_action_events', 0);
        $this->assertDatabaseCount('browser_tasks', 0);
    }

    public function test_la_decouverte_prefiltre_les_likes_de_l_inbox_sans_bloquer_une_reponse(): void
    {
        InboxItem::create(['social_account_id' => $this->a->id, 'platform_id' => $this->a->platform_id,
            'external_id' => '987', 'type' => 'comment', 'status' => 'unread', 'liked_at' => now()]);
        $pending = $this->target();
        $pending['id'] = '988';
        BrowserTask::create(['social_account_id' => $this->a->id, 'profile' => 'nas-test', 'kind' => 'x.like',
            'payload' => ['target' => $pending]]);
        $this->mock(BrowserTaskQueue::class)->shouldReceive('run')->once()->andReturn([
            'items' => [$this->target(), $pending], 'observed' => 2, 'complete' => false,
        ]);
        $snapshot = $this->postJson($this->url('read'), ['action' => 'feed.read', 'feed' => 'following'])
            ->assertOk()->assertJsonPath('like_blocked_ids', ['987', '988'])->assertJsonPath('reply_blocked_ids', [])->json('snapshot_id');
        foreach (['987', '988'] as $target) {
            $this->postJson($this->url('prepare'), ['snapshot_id' => $snapshot, 'action' => 'like', 'target_id' => $target])->assertConflict();
            $this->postJson('/api/extension/x/assisted/reactions/reserve', ['action' => 'like', 'target_id' => $target,
                'author' => 'visitor', 'platform_account_id' => '12345', 'username' => 'owner'])->assertConflict();
        }
        $this->assertDatabaseCount('browser_tasks', 1);
        $this->assertDatabaseCount('x_engagements', 0);
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

    public function test_suivi_local_partage_la_reservation_et_conserve_son_origine(): void
    {
        $snapshot = $this->snapshot();
        $data = Cache::get('x-agent-snapshot:'.$snapshot);
        $data['result'] = ['profile' => ['handle' => 'visitor', 'following' => false]];
        Cache::put('x-agent-snapshot:'.$snapshot, $data, now()->addHour());
        $draft = $this->prepare($snapshot, 'follow');
        $base = '/api/extension/x/assisted/reactions/';
        $identity = ['platform_account_id' => '12345', 'username' => 'owner'];
        $target = ['action' => 'follow', 'handle' => 'Visitor'] + $identity;
        $this->postJson($base.'reserve', ['handle' => 'owner'] + $target)->assertConflict();
        $id = $this->postJson($base.'reserve', $target)->assertCreated()->assertJsonPath('status', 'running')->json('id');
        $this->assertSame($draft->id, $id);
        $this->postJson($base.'reserve', $target)->assertConflict();
        $this->postJson($this->url('prepare'), ['snapshot_id' => $snapshot, 'action' => 'follow'])
            ->assertOk()->assertJsonPath('id', $id)->assertJsonPath('status', 'running');
        $finish = $base.$id.'/finish';
        $proof = ['status' => 'confirmed', 'handle' => 'visitor', 'following' => true, 'proof' => 'after_reload'] + $identity;
        $this->postJson($finish, ['handle' => 'other'] + $proof)->assertUnprocessable();
        $this->postJson($finish, ['following' => false] + $proof)->assertUnprocessable();
        $this->postJson($finish, ['proof' => 'visible'] + $proof)->assertUnprocessable();
        $this->assertDatabaseCount('social_contacts', 0);
        $this->postJson($finish, $proof)->assertOk()->assertJsonPath('status', 'confirmed');
        $this->postJson($finish, $proof)->assertOk();
        $contact = SocialContact::sole();
        $this->assertNotNull($contact->followed_at);
        $this->assertSame('extension', $contact->metadata['follow_source']);
        $this->assertDatabaseHas('bot_action_logs', ['source' => 'extension', 'action_type' => 'x.follow', 'target_author' => 'visitor', 'success' => true]);

        $other = $this->postJson($base.'reserve', ['handle' => 'already'] + $target)->assertCreated()->json('id');
        $this->postJson($base.$other.'/finish', ['status' => 'unchanged', 'handle' => 'already', 'proof' => 'visible'] + $proof)->assertOk();
        $this->assertDatabaseCount('social_contacts', 1);

        app(AgentCatalog::class)->configure($this->a, 'unfollow', true, 3);
        $contact->update(['followed_at' => now()->subDays(8), 'follow_back' => 'no', 'follow_back_checked_at' => now()]);
        $unfollow = $this->prepare($snapshot, 'unfollow');
        $this->postJson('/api/community-manager/x/engagements/'.$unfollow->id.'/execute', ['dry_run' => false])->assertConflict();
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

    private function uncertainReply(?string $candidateId): XEngagement
    {
        $snapshot = $this->snapshot(true);
        $context = $this->getJson($this->url('context').'?snapshot_id='.$snapshot)->assertOk()->json('context_id');
        $engagement = $this->prepare($snapshot, 'reply', ['context_id' => $context, 'text' => 'Merci pour ce gentil mot 🖤']);
        $this->execute($engagement);
        $task = (new BrowserTaskQueue)->claim('nas-test');
        $this->finish($task, ['id' => $candidateId], 'uncertain');
        $this->assertSame('uncertain', $engagement->fresh()->status);

        return $engagement->fresh();
    }

    public function test_reponse_incertaine_rapprochee_par_lecture_differee_sans_second_geste(): void
    {
        Queue::fake([VerifyXEngagementReply::class]);
        $replyId = (string) ((now()->getTimestampMs() - 1288834974657) << 22);
        $engagement = $this->uncertainReply($replyId);
        $this->assertSame($replyId, VerifyXEngagementReply::candidateId($engagement->browserTask));
        $task = $engagement->browserTask;
        $url = 'https://x.com/owner/status/'.$replyId;
        $status = '/api/community-manager/x/engagements/'.$engagement->id;
        $this->getJson($status)->assertOk()->assertJsonPath('status', 'uncertain');
        Queue::assertPushed(VerifyXEngagementReply::class, fn ($job) => $job->engagementId === $engagement->id
            && $job->delay->equalTo(now()->addSeconds(45)));
        Queue::assertPushed(VerifyXEngagementReply::class, 1);
        $this->travel(46)->seconds();
        $reply = ['id' => $replyId, 'author' => 'owner', 'url' => $url, 'text' => "Merci pour ce gentil mot\n🖤"];
        $browser = $this->mock(BrowserTaskQueue::class);
        $browser->shouldReceive('run')->once()
            ->withArgs(fn ($account, $kind, $payload, $timeout) => $account->id === $this->a->id && $kind === 'x.agent.read'
                && $payload['url'] === $url && $payload['target_id'] === $replyId
                && $payload['action'] === 'thread.read' && $payload['target']['reply_parent_id'] === '987' && $timeout === 100)
            ->andReturn(['context_complete' => true, 'target' => $reply, 'conversation' => [$this->target(), $reply]]);

        $job = new VerifyXEngagementReply($engagement->id);
        $job->handle($browser, app(AccountActionBudget::class));
        $job->handle($browser, app(AccountActionBudget::class));

        $this->getJson($status)->assertOk()->assertJsonPath('confirmed', true)
            ->assertJsonPath('result.reply_id', $replyId)->assertJsonPath('result.parent_id', '987');
        $this->postJson($status.'/execute', ['dry_run' => false])->assertConflict();
        $this->assertSame(BrowserTask::DONE, $task->fresh()->status);
        $this->assertTrue($task->finished_at->equalTo($task->fresh()->finished_at));
        $this->assertDatabaseHas('bot_action_logs', ['browser_task_id' => $task->id, 'success' => true]);
        $this->assertDatabaseCount('browser_tasks', 1);
        $this->assertDatabaseCount('account_action_events', 1);
        $this->assertSame('confirmed', AccountActionEvent::first()->status);
    }

    public function test_relecture_ne_confirme_pas_si_le_parent_immediat_est_different(): void
    {
        Queue::fake([VerifyXEngagementReply::class]);
        $replyId = (string) ((now()->getTimestampMs() - 1288834974657) << 22);
        $engagement = $this->uncertainReply($replyId);
        $this->assertSame($replyId, VerifyXEngagementReply::candidateId($engagement->browserTask));
        $reply = ['id' => $replyId, 'author' => 'owner', 'url' => 'https://x.com/owner/status/'.$replyId, 'text' => 'Merci pour ce gentil mot 🖤'];
        $browser = $this->mock(BrowserTaskQueue::class);
        $browser->shouldReceive('run')->once()->andReturn(['context_complete' => true, 'target' => $reply,
            'conversation' => [$this->target(), ['id' => '988', 'author' => 'visitor', 'text' => 'Autre parent'], $reply]]);

        (new VerifyXEngagementReply($engagement->id))->handle($browser, app(AccountActionBudget::class));

        $this->assertSame('uncertain', $engagement->fresh()->status);
        $this->assertSame(BrowserTask::UNCERTAIN, $engagement->browserTask->fresh()->status);
        $this->assertSame('uncertain', AccountActionEvent::first()->status);
        $this->assertDatabaseCount('browser_tasks', 1);
    }

    public function test_identifiant_anterieur_a_la_tentative_ne_planifie_pas_de_relecture(): void
    {
        Queue::fake([VerifyXEngagementReply::class]);
        $oldId = (string) ((now()->subHour()->getTimestampMs() - 1288834974657) << 22);
        $this->uncertainReply($oldId);

        Queue::assertNotPushed(VerifyXEngagementReply::class);
        $this->assertSame('uncertain', AccountActionEvent::first()->status);
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

    public function test_catalogue_propose_un_profil_eligible_sans_oublier_les_suivis_et_lectures(): void
    {
        $this->a->update(['agent_strategy' => [
            'target_accounts' => ['@owner', '@excluded', 'https://x.com/checked', '@reserved', '@configured'],
            'exclusions' => ['@EXCLUDED'],
        ]]);
        BrowserTask::create(['social_account_id' => $this->a->id, 'profile' => 'nas-test', 'kind' => 'x.agent.read',
            'payload' => ['action' => 'profile.read', 'handle' => 'checked']]);
        XEngagement::create(['social_account_id' => $this->a->id, 'target_key' => hash('sha256', 'reserved'),
            'action' => 'follow', 'status' => 'uncertain', 'payload' => []]);
        SocialContact::create(['social_account_id' => $this->a->id, 'handle' => 'found', 'source' => 'search.read', 'last_read_at' => now()]);
        $catalogue = '/api/community-manager/accounts';
        $this->getJson($catalogue)->assertOk()->assertJsonPath('accounts.0.follow_candidate', ['handle' => 'configured', 'source' => 'configured']);
        SocialContact::create(['social_account_id' => $this->a->id, 'handle' => 'configured', 'followed_at' => now(),
            'metadata' => ['follow_source' => 'extension']]);
        $this->getJson($catalogue)->assertOk()->assertJsonPath('accounts.0.follow_candidate', ['handle' => 'found', 'source' => 'search']);
        app(AgentCatalog::class)->configure($this->a, 'follow', false, 3);
        $this->getJson($catalogue)->assertOk()->assertJsonMissingPath('accounts.0.follow_candidate');
        $this->assertDatabaseCount('account_action_events', 0);
        $this->assertDatabaseCount('browser_tasks', 1);
    }

    public function test_un_suivi_observe_ecarte_le_profil_sans_creer_un_abonnement_agent(): void
    {
        $this->a->update(['agent_strategy' => ['target_accounts' => ['@visitor', '@fallback']]]);
        app(AgentCatalog::class)->configure($this->a, 'profile.read', true, 4);
        $profile = ['handle' => 'visitor', 'name' => 'Visiteur'];
        $this->mock(BrowserTaskQueue::class)->shouldReceive('run')->times(3)->andReturn(
            ['profile' => $profile + ['following' => true]],
            ['profile' => $profile],
            ['profile' => $profile + ['following' => false]],
        );
        $data = ['action' => 'profile.read', 'handle' => 'visitor'];
        $catalogue = '/api/community-manager/accounts';

        $this->postJson($this->url('read'), $data)->assertOk();
        $this->travel(2)->hours();
        $this->getJson($catalogue)->assertOk()->assertJsonPath('accounts.0.follow_candidate.handle', 'fallback');
        $contact = SocialContact::where('handle', 'visitor')->firstOrFail();
        $this->assertNull($contact->followed_at);
        $this->assertArrayNotHasKey('follow_source', $contact->metadata);

        $this->postJson($this->url('read'), $data)->assertOk();
        $this->getJson($catalogue)->assertOk()->assertJsonPath('accounts.0.follow_candidate.handle', 'fallback');

        $this->postJson($this->url('read'), $data)->assertOk();
        $this->getJson($catalogue)->assertOk()->assertJsonPath('accounts.0.follow_candidate.handle', 'visitor');
        $this->assertDatabaseCount('account_action_events', 0);
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
        $snapshot = $this->snapshot();
        $row = $this->prepare($snapshot);
        $this->execute($row);
        $service = app(\App\Services\Inbox\TwitterBrowserInboxService::class);
        $this->assertFalse($service->like($this->a, $item)['success']);
        $this->assertDatabaseCount('browser_tasks', 1);
        $task = $row->fresh()->browserTask;
        app(BrowserTaskQueue::class)->claim('nas-test');
        $this->finish($task->fresh(), ['confirmed' => true, 'proof' => 'after_reload', 'target' => $this->target()]);
        $this->assertNotNull($item->fresh()->liked_at);
        $this->assertTrue($service->like($this->a, $item->fresh())['success']);
        $this->postJson($this->url('prepare'), ['snapshot_id' => $snapshot, 'action' => 'like', 'target_id' => '987'])
            ->assertOk()->assertJsonPath('id', $row->id)->assertJsonPath('confirmed', true)->assertJsonPath('source', 'browser')
            ->assertJsonPath('reused', true)->assertJsonPath('new_gesture_confirmed', false);
        $this->assertDatabaseCount('browser_tasks', 1);
    }
}
