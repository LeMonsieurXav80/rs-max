<?php

namespace Tests\Feature;

use App\Models\AccountActionEvent;
use App\Models\BrowserTask;
use App\Models\MetaEngagement;
use App\Models\Persona;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\SocialContact;
use App\Models\TikTokEngagement;
use App\Models\User;
use App\Services\Agent\AccountActionBudget;
use App\Services\Agent\AgentCatalog;
use App\Services\Agent\MetaBrowserAgent;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MetaBrowserActionsTest extends TestCase
{
    use RefreshDatabase;

    private SocialAccount $a;

    private User $u;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-20T12:00:00Z'));
        $this->u = User::factory()->create(['role' => 'admin']);
        $this->a = SocialAccount::create(['name' => 'Nina', 'platform_id' => Platform::firstOrCreate(['slug' => 'facebook'], ['name' => 'Facebook'])->id,
            'platform_account_id' => '123', 'driver' => 'browser', 'browser_profile' => 'vps-test', 'browser_session_status' => 'connected',
            'persona_id' => Persona::create(['name' => 'Nina', 'system_prompt' => 'Oral et chaleureux', 'is_active' => true])->id,
            'agent_strategy' => ['facebook_groups' => ['https://www.facebook.com/groups/456/']], 'agent_hourly_limit' => 20]);
        $this->a->users()->attach($this->u, ['is_active' => true]);
        foreach (['group.read', 'thread.read', 'comment', 'comment.like', 'like', 'follow', 'unfollow', 'profile.read', 'followback.read', 'search.read'] as $key) {
            $this->a->agentActions()->create(['action' => $key, 'enabled' => true, 'daily_limit' => 10]);
        }
        Sanctum::actingAs($this->u);
    }

    private function instagram(): void
    {
        $this->a->update(['platform_id' => Platform::firstOrCreate(['slug' => 'instagram'], ['name' => 'Instagram'])->id, 'browser_username' => 'nina']);
        $this->a = $this->a->fresh(['platform', 'persona']);
    }

    private function read(string $type = 'thread.read'): BrowserTask
    {
        $ig = $this->a->platform->slug === 'instagram';
        $target = $type === 'profile.read'
            ? ['type' => 'profile', 'id' => 'friend', 'author' => 'friend', 'url' => 'https://www.instagram.com/friend/', 'text' => 'Running']
            : ['type' => 'post', 'id' => $ig ? 'ABC123' : '789', 'author' => $ig ? 'friend' : '555', 'url' => $ig ? 'https://www.instagram.com/p/ABC123/' : 'https://www.facebook.com/groups/456/posts/789/', 'text' => 'Une promenade à Lyon', 'published_at' => now()->subHour()->toIso8601String()];
        $task = BrowserTask::create(['social_account_id' => $this->a->id, 'profile' => 'vps-test', 'kind' => $this->a->platform->slug.'.agent.read', 'status' => 'done',
            'payload' => ['action' => $type, 'persona_hash' => TikTokEngagement::personaHash($this->a)], 'finished_at' => now(),
            'result' => ['account_id' => '123', 'context_complete' => true, 'target' => $target, 'comments' => [['type' => 'comment', 'id' => $ig ? 'ABC123:888' : '888', 'author' => $ig ? 'visitor' : '999', 'text' => 'Bravo !', 'url' => $target['url']]]]]);

        return $task;
    }

    private function submit(BrowserTask $read, string $action, array $extra = []): array
    {
        return app(MetaBrowserAgent::class)->submit($this->a->fresh(['platform', 'persona']), $read,
            $extra + ['action' => $action, 'target_id' => $read->result['comments'][0]['id'], 'dry_run' => false, 'request_key' => (string) Str::uuid()]);
    }

    public function test_selected_groups_and_native_hosts_are_enforced_before_any_task(): void
    {
        $url = '/api/agent/accounts/'.$this->a->id.'/meta/read';
        $this->postJson($url, ['action' => 'group.read', 'url' => 'https://web.facebook.com/groups/456?ref=share'])->assertAccepted();
        $this->assertSame('https://www.facebook.com/groups/456/', BrowserTask::first()->payload['url']);
        $this->postJson($url, ['action' => 'thread.read', 'url' => 'https://facebook.com.evil.test/groups/456/posts/789/'])->assertUnprocessable();
        $this->postJson($url, ['action' => 'thread.read', 'url' => 'https://www.facebook.com/groups/111/posts/789/'])->assertForbidden();
        $this->assertSame(1, BrowserTask::count());
        $this->assertSame(0, AccountActionEvent::count());
    }

    public function test_web_pages_expose_separate_actions_and_the_remote_execution_console(): void
    {
        $this->actingAs($this->u)->get('/agent/facebook')->assertOk()
            ->assertSee('Commenter une publication de groupe')->assertSee('Aimer les commentaires d’une publication de groupe')->assertSee('Agir depuis le VPS');
        $this->instagram();
        $this->get('/agent/instagram')->assertOk()->assertSee('Vérifier le retour d’abonnement')->assertSee('Aimer une publication');
        $this->get('/accounts/'.$this->a->id.'/edit')->assertOk()->assertSee('X et Instagram');
    }

    public function test_two_facebook_actions_have_independent_quotas_and_dry_run_creates_no_receipt(): void
    {
        $read = $this->read();
        $this->postJson('/api/agent/accounts/'.$this->a->id.'/meta/act', ['read_task_id' => $read->id, 'action' => 'comment.like', 'target_id' => '888'])
            ->assertOk()->assertJsonPath('dry_run', true);
        $this->assertSame(0, MetaEngagement::count());
        $r = $this->submit($read, 'comment.like');
        $task = app(BrowserTaskQueue::class)->claim('vps-test');
        $this->assertSame($r['task_id'], $task->id);
        $this->assertSame(1, app(AgentCatalog::class)->usedToday($this->a, 'comment.like'));
        $this->assertSame(0, app(AgentCatalog::class)->usedToday($this->a, 'comment'));
        $this->assertSame('facebook.comment.like', AccountActionEvent::first()->action);
    }

    public function test_authorization_is_single_use_and_accepts_the_last_reserved_slot(): void
    {
        $this->a->agentActions()->where('action', 'comment.like')->update(['daily_limit' => 1]);
        $this->submit($this->read(), 'comment.like');
        $t = app(BrowserTaskQueue::class)->claim('vps-test');
        $this->postJson('/api/meta/tasks/'.$t->id.'/authorize')->assertOk();
        $this->postJson('/api/meta/tasks/'.$t->id.'/authorize')->assertConflict();
        $this->postJson('/api/extension/tasks/'.$t->id.'/finish', ['status' => 'done', 'result' => ['confirmed' => true, 'proof' => 'after_reload', 'account_id' => '123', 'action' => 'comment.like', 'target_id' => '888', 'target_url' => $t->payload['url'], 'liked' => true]])->assertOk();
        $this->assertSame('done', $t->fresh()->status);
        $this->assertSame('confirmed', AccountActionEvent::first()->status);
    }

    public function test_no_proof_is_uncertain_and_permanent_receipt_prevents_a_new_task_after_deletion(): void
    {
        $read = $this->read();
        $this->submit($read, 'comment.like');
        $t = app(BrowserTaskQueue::class)->claim('vps-test');
        $this->postJson('/api/meta/tasks/'.$t->id.'/authorize')->assertOk();
        $this->postJson('/api/extension/tasks/'.$t->id.'/finish', ['status' => 'failed', 'result' => ['commit_authorized' => false], 'error' => 'Perte du résultat'])->assertOk();
        $this->assertSame('uncertain', $t->fresh()->status);
        $t->delete();
        $this->assertTrue($this->submit($read, 'comment.like')['duplicate']);
        $this->assertSame(1, BrowserTask::count());
    }

    public function test_persona_and_group_changes_cancel_the_gesture_before_click(): void
    {
        $read = $this->read();
        $this->submit($read, 'comment', ['text' => 'Oh joli !', 'persona_hash' => TikTokEngagement::personaHash($this->a)]);
        $this->a->persona->update(['system_prompt' => 'Autre voix']);
        $this->assertNull(app(BrowserTaskQueue::class)->claim('vps-test'));
        $this->assertSame('cancelled', MetaEngagement::first()->status);
        $this->assertSame(0, AccountActionEvent::count());
        $this->a->update(['agent_strategy' => ['facebook_groups' => []]]);
        $this->postJson('/api/agent/accounts/'.$this->a->id.'/meta/read', ['action' => 'group.read', 'url' => 'https://www.facebook.com/groups/456/'])->assertForbidden();
    }

    public function test_local_user_can_execute_only_tasks_owned_by_its_token_without_worker_privileges(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->a->users()->attach($user, ['is_active' => true]);
        $token = $user->createToken('Companion');
        $this->app['auth']->forgetGuards();
        $this->withToken($token->plainTextToken);
        $r = $this->postJson('/api/extension/meta/facebook/agent/read', ['platform_account_id' => '123', 'action' => 'group.read', 'url' => 'https://www.facebook.com/groups/456/'])->assertAccepted();
        $id = $r->json('task_id');
        $this->postJson('/api/meta/tasks/'.$id.'/claim')->assertOk()->assertJsonPath('account_id', '123');
        $this->postJson('/api/extension/tasks/'.$id.'/finish', ['status' => 'done', 'result' => ['account_id' => '123', 'items' => []]])->assertOk();
        $this->getJson('/api/extension/tasks/'.$id.'/session')->assertForbidden();
        $second = $user->createToken('Autre Chrome');
        $this->app['auth']->forgetGuards();
        $this->withToken($second->plainTextToken);
        $this->postJson('/api/meta/tasks/'.$id.'/claim')->assertForbidden();
    }

    public function test_instagram_follow_only_records_a_new_confirmed_follow_and_is_idempotent(): void
    {
        $this->instagram();
        $this->submit($this->read('profile.read'), 'follow');
        $t = app(BrowserTaskQueue::class)->claim('vps-test');
        $this->postJson('/api/meta/tasks/'.$t->id.'/authorize')->assertOk();
        $this->postJson('/api/extension/tasks/'.$t->id.'/finish', ['status' => 'done', 'result' => ['confirmed' => true, 'proof' => 'after_reload', 'account_id' => '123', 'action' => 'follow', 'target_id' => 'friend', 'target_url' => $t->payload['url'], 'following' => true]])->assertOk();
        $c = SocialContact::firstOrFail();
        $at = $c->followed_at;
        $this->assertSame('agent', $c->metadata['follow_source']);
        $this->travel(1)->days();
        app(AccountActionBudget::class)->finish($t->fresh());
        $this->assertTrue($c->fresh()->followed_at->eq($at));
    }

    public function test_followback_needs_two_distinct_recent_reads_spaced_by_fifteen_minutes(): void
    {
        $this->instagram();
        $c = SocialContact::create(['social_account_id' => $this->a->id, 'handle' => 'friend', 'followed_at' => now()->subDays(8), 'metadata' => ['follow_source' => 'agent']]);
        $observe = function () {
            $t = BrowserTask::create(['social_account_id' => $this->a->id, 'profile' => 'vps-test', 'kind' => 'instagram.agent.read', 'status' => 'done', 'finished_at' => now(), 'payload' => ['action' => 'followback.read', 'handle' => 'friend'], 'result' => ['account_id' => '123', 'handle' => 'friend', 'follow_back' => 'no', 'proof' => 'own_followers_search']]);
            app(MetaBrowserAgent::class)->finish($t);

            return $t;
        };
        $first = $observe();
        $this->travel(16)->minutes();
        app(MetaBrowserAgent::class)->finish($first);
        $this->assertTrue($c->fresh()->follow_back_checked_at->eq($first->finished_at));
        $this->assertFalse($c->fresh()->follow_back_checked_at->gte(now()->subMinutes(15)));
        $observe();
        app(MetaBrowserAgent::class)->assertUnfollow($this->a, 'friend');
        $c->update(['metadata' => ['follow_source' => 'manual']]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(MetaBrowserAgent::class)->assertUnfollow($this->a, 'friend');
    }

    public function test_unknown_return_and_old_or_self_targets_are_rejected(): void
    {
        $read = $this->read();
        $r = $read->result;
        $r['target']['published_at'] = now()->subDays(15)->toIso8601String();
        $read->update(['result' => $r]);
        $this->postJson('/api/agent/accounts/'.$this->a->id.'/meta/act', ['read_task_id' => $read->id, 'action' => 'comment', 'text' => 'Salut !', 'persona_hash' => TikTokEngagement::personaHash($this->a)])->assertConflict();
        $this->instagram();
        $this->postJson('/api/agent/accounts/'.$this->a->id.'/meta/act', ['read_task_id' => $this->read('profile.read')->id, 'action' => 'unfollow'])->assertConflict();
        $this->assertSame(0, MetaEngagement::count());
    }

    public function test_local_write_uses_the_same_reservation_and_after_reload_proof_as_vps(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->a->users()->attach($user, ['is_active' => true]);
        $token = $user->createToken('Companion');
        $this->app['auth']->forgetGuards();
        $this->withToken($token->plainTextToken);
        $queued = $this->postJson('/api/extension/meta/facebook/agent/read', ['platform_account_id' => '123', 'action' => 'thread.read', 'url' => 'https://www.facebook.com/groups/456/posts/789/'])->assertAccepted()->json('task_id');
        $sample = $this->read()->result;
        $this->postJson('/api/meta/tasks/'.$queued.'/claim')->assertOk();
        $this->postJson('/api/extension/tasks/'.$queued.'/finish', ['status' => 'done', 'result' => $sample])->assertOk();
        $id = $this->postJson('/api/extension/meta/facebook/agent/act', ['platform_account_id' => '123', 'read_task_id' => $queued,
            'action' => 'comment.like', 'target_id' => '888', 'dry_run' => false, 'request_key' => (string) Str::uuid()])->assertAccepted()->json('task_id');
        $this->postJson('/api/meta/tasks/'.$id.'/claim')->assertOk();
        $this->postJson('/api/meta/tasks/'.$id.'/authorize')->assertOk();
        $this->postJson('/api/extension/tasks/'.$id.'/finish', ['status' => 'done', 'result' => ['confirmed' => true, 'proof' => 'after_reload',
            'account_id' => '123', 'action' => 'comment.like', 'target_id' => '888', 'target_url' => $sample['target']['url'], 'liked' => true]])->assertOk();
        $this->assertSame('done', BrowserTask::find($id)->status);
        $this->assertSame(MetaBrowserAgent::LOCAL, BrowserTask::find($id)->profile);
        $this->assertSame(1, AccountActionEvent::count());
        $this->assertSame('confirmed', AccountActionEvent::first()->status);
        $this->postJson('/api/agent/accounts/'.$this->a->id.'/meta/act', ['read_task_id' => $queued, 'action' => 'comment.like', 'target_id' => '888'])->assertForbidden();
    }

    public function test_a_forged_client_authorization_or_another_target_is_not_confirmation(): void
    {
        $this->submit($this->read(), 'comment.like');
        $t = app(BrowserTaskQueue::class)->claim('vps-test');
        $this->postJson('/api/extension/tasks/'.$t->id.'/finish', ['status' => 'done', 'result' => ['commit_authorized' => true,
            'confirmed' => true, 'proof' => 'after_reload', 'account_id' => '123', 'action' => 'comment.like', 'target_id' => '888', 'target_url' => $t->payload['url'], 'liked' => true]])->assertOk();
        $this->assertSame('uncertain', $t->fresh()->status);
        $this->assertSame('uncertain', MetaEngagement::first()->status);
    }

    public function test_scheduled_unfollow_progresses_through_two_checks_and_a_fresh_profile_without_touching_manual_follows(): void
    {
        $this->instagram();
        foreach (['agent', 'manual'] as $source) {
            SocialContact::create(['social_account_id' => $this->a->id, 'handle' => $source === 'agent' ? 'friend' : 'manual',
                'followed_at' => now()->subDays(8), 'metadata' => ['follow_source' => $source]]);
        }
        $this->artisan('instagram:unfollow-due')->assertSuccessful();
        $this->assertSame(0, BrowserTask::count());
        $finishCheck = function () {
            $task = BrowserTask::latest('id')->first();
            $this->assertSame('friend', $task->payload['handle']);
            $task->update(['status' => 'done', 'finished_at' => now(), 'result' => ['account_id' => '123', 'handle' => 'friend', 'follow_back' => 'no', 'proof' => 'own_followers_search']]);
            app(MetaBrowserAgent::class)->finish($task);
        };
        $this->artisan('instagram:unfollow-due --commit')->assertSuccessful();
        $finishCheck();
        $this->travel(5)->minutes();
        $this->artisan('instagram:unfollow-due --commit')->assertSuccessful();
        $this->assertSame(1, BrowserTask::count());
        $this->travel(10)->minutes();
        $this->artisan('instagram:unfollow-due --commit')->assertSuccessful();
        $finishCheck();
        $this->travel(5)->minutes();
        $this->artisan('instagram:unfollow-due --commit')->assertSuccessful();
        $profile = BrowserTask::latest('id')->first();
        $this->assertSame('profile.read', $profile->payload['action']);
        $profile->update(['status' => 'done', 'finished_at' => now(), 'result' => ['account_id' => '123', 'target' => ['type' => 'profile', 'id' => 'friend', 'author' => 'friend', 'url' => 'https://www.instagram.com/friend/']]]);
        $this->travel(5)->minutes();
        $this->artisan('instagram:unfollow-due --commit')->assertSuccessful();
        $this->assertSame('unfollow', MetaEngagement::sole()->action);
        $this->assertSame(0, AccountActionEvent::count());
        $this->assertNull(SocialContact::where('handle', 'manual')->first()->follow_back_checked_at);
    }

    public function test_a_later_read_reconciles_an_uncertain_like_without_a_second_attempt(): void
    {
        $this->submit($this->read(), 'comment.like');
        $t = app(BrowserTaskQueue::class)->claim('vps-test');
        $this->postJson('/api/meta/tasks/'.$t->id.'/authorize')->assertOk();
        $this->postJson('/api/extension/tasks/'.$t->id.'/finish', ['status' => 'uncertain', 'result' => ['clicked' => true]])->assertOk();
        $this->travel(1)->seconds();
        $read = $this->read();
        $r = $read->result;
        $r['comments'][0]['liked'] = true;
        $read->update(['result' => $r]);
        $this->postJson('/api/meta/tasks/'.$t->id.'/reconcile', ['read_task_id' => $read->id])->assertOk()->assertJsonPath('sent', false)->assertJsonPath('confirmed', true);
        $this->assertSame(1, AccountActionEvent::count());
        $this->assertSame('confirmed', AccountActionEvent::first()->status);
        $this->assertSame('done', MetaEngagement::first()->status);
    }

    public function test_an_interrupted_local_launch_expires_without_blocking_the_next_read_or_replaying_a_write(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->a->users()->attach($user, ['is_active' => true]);
        $token = $user->createToken('Companion');
        $old = BrowserTask::create(['social_account_id' => $this->a->id, 'profile' => MetaBrowserAgent::LOCAL,
            'kind' => 'facebook.agent.act', 'status' => 'running', 'claimed_at' => now()->subMinutes(31), 'payload' => ['action' => 'comment.like']]);
        $this->app['auth']->forgetGuards();
        $this->withToken($token->plainTextToken);
        $id = $this->postJson('/api/extension/meta/facebook/agent/read', ['platform_account_id' => '123', 'action' => 'group.read',
            'url' => 'https://www.facebook.com/groups/456/'])->assertAccepted()->json('task_id');
        $this->postJson('/api/meta/tasks/'.$id.'/claim')->assertOk();
        $this->assertSame('uncertain', $old->fresh()->status);
        $this->assertSame('running', BrowserTask::findOrFail($id)->status);
        $this->assertSame(2, BrowserTask::count());
    }
}
