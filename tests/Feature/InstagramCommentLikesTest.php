<?php

namespace Tests\Feature;

use App\Models\AccountActionEvent;
use App\Models\BrowserTask;
use App\Models\MetaEngagement;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Agent\AgentCatalog;
use App\Services\Agent\InstagramCommentLikes;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class InstagramCommentLikesTest extends TestCase
{
    use RefreshDatabase;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-21T10:00:00Z'));
        $this->account = SocialAccount::create(['name' => 'Nina', 'platform_id' => Platform::firstOrCreate(['slug' => 'instagram'], ['name' => 'Instagram'])->id,
            'platform_account_id' => '123', 'browser_username' => 'nina', 'driver' => 'browser', 'browser_profile' => 'vps-test', 'browser_session_status' => 'connected',
            'agent_daily_limit' => 50, 'agent_strategy' => ['instagram_comment_likes_enabled' => true, 'hashtags' => ['#musculation', '#running'], 'exclusions' => ['concours']]]);
        foreach (['search.read' => 100, 'thread.read' => 200, 'comment.like' => 50] as $key => $limit) {
            app(AgentCatalog::class)->configure($this->account, $key, true, $limit);
        }
    }

    private function tick(bool $commit = true): array
    {
        return app(InstagramCommentLikes::class)->tick($this->account, $commit);
    }

    private function snapshot(array $comments): BrowserTask
    {
        return BrowserTask::create(['social_account_id' => $this->account->id, 'profile' => 'vps-test', 'kind' => 'instagram.agent.read', 'status' => 'done', 'finished_at' => now(),
            'payload' => ['action' => 'thread.read', 'origin' => InstagramCommentLikes::ORIGIN, 'url' => 'https://www.instagram.com/p/ABC/'],
            'result' => ['account_id' => '123', 'target' => ['id' => 'ABC', 'type' => 'post', 'author' => 'owner', 'url' => 'https://www.instagram.com/p/ABC/', 'text' => 'Séance musculation'], 'comments' => $comments]]);
    }

    private function comment(string $id, string $author = 'visitor', array $extra = []): array
    {
        return $extra + ['type' => 'comment', 'id' => 'ABC:'.$id, 'author' => $author, 'text' => 'Belle séance !', 'url' => 'https://www.instagram.com/p/ABC/', 'liked' => false];
    }

    public function test_opt_in_and_dry_run_are_required_before_any_order(): void
    {
        $this->assertSame('search.read', $this->tick(false)['action']);
        $this->assertSame(0, BrowserTask::count());
        $this->account->update(['agent_strategy' => ['hashtags' => ['musculation']]]);
        $this->assertSame('disabled', $this->tick()['status']);
        $this->assertSame(0, BrowserTask::count());
        $this->assertSame(0, AccountActionEvent::count());
    }

    public function test_discovery_then_post_read_then_like_uses_the_common_worker_and_budget(): void
    {
        $search = BrowserTask::findOrFail($this->tick()['task_id']);
        $this->assertSame('musculation', $search->payload['hashtag']);
        $search->update(['status' => 'done', 'finished_at' => now(), 'result' => ['account_id' => '123', 'items' => [['id' => 'ABC', 'url' => 'https://www.instagram.com/p/ABC/']]]]);
        $read = BrowserTask::findOrFail($this->tick()['task_id']);
        $this->assertSame('thread.read', $read->payload['action']);
        $this->assertSame(0, MetaEngagement::count());
        $result = $this->snapshot([$this->comment('1')])->result;
        BrowserTask::latest('id')->first()->delete();
        $read->update(['status' => 'done', 'finished_at' => now(), 'result' => $result]);
        $like = BrowserTask::findOrFail($this->tick()['task_id']);
        $this->assertSame('comment.like', $like->payload['action']);
        $this->assertSame('vps-test', $like->profile);
        $this->assertSame(InstagramCommentLikes::ORIGIN, $like->payload['origin']);
        $this->assertSame('busy', $this->tick()['status']);
        $claimed = app(BrowserTaskQueue::class)->claim('vps-test');
        $this->assertSame($like->id, $claimed->id);
        $this->assertSame('instagram.comment.like', AccountActionEvent::first()->action);
        $this->assertSame(1, app(AgentCatalog::class)->usedToday($this->account, 'comment.like'));
    }

    public function test_self_author_already_liked_excluded_and_uncertain_targets_are_skipped(): void
    {
        $read = $this->snapshot([$this->comment('1', 'nina'), $this->comment('2', 'owner'), $this->comment('3', extra: ['liked' => true]),
            $this->comment('4', extra: ['text' => 'Un concours']), $this->comment('5'), $this->comment('6')]);
        MetaEngagement::create(['social_account_id' => $this->account->id, 'action' => 'comment.like', 'target_key' => hash('sha256', 'comment:ABC:5'), 'request_key' => (string) Str::uuid(), 'payload' => [], 'status' => 'uncertain']);
        $plan = $this->tick(false);
        $this->assertSame('ABC:6', $plan['target_id']);
        $this->assertSame($read->id, $plan['read_task_id']);
        $this->assertSame(1, BrowserTask::count());
    }

    public function test_fifty_attempts_shared_with_local_exhaust_the_daily_quota(): void
    {
        $this->snapshot([$this->comment('1')]);
        foreach (range(1, 50) as $n) {
            AccountActionEvent::create(['social_account_id' => $this->account->id, 'action' => 'instagram.comment.like', 'executor' => $n % 2 ? 'local' : 'browser', 'status' => $n === 50 ? 'uncertain' : 'confirmed']);
        }
        $this->assertSame('quota_or_disabled', $this->tick()['status']);
        $this->assertSame(0, MetaEngagement::count());
        $this->assertSame(0, app(AgentCatalog::class)->forAccount($this->account)->get('comment.like')['left']);
        $this->travel(1)->days();
        $this->assertSame(50, app(AgentCatalog::class)->forAccount($this->account)->get('comment.like')['left']);
    }

    public function test_five_comments_per_post_and_worker_occupancy_limit_each_pass(): void
    {
        $this->snapshot([$this->comment('9')]);
        foreach (range(1, 5) as $n) {
            MetaEngagement::create(['social_account_id' => $this->account->id, 'action' => 'comment.like', 'target_key' => hash('sha256', 'comment:ABC:'.$n), 'request_key' => (string) Str::uuid(), 'payload' => ['url' => 'https://www.instagram.com/p/ABC/'], 'status' => 'done']);
        }
        $this->assertSame('search.read', $this->tick(false)['action']);
        BrowserTask::create(['social_account_id' => $this->account->id, 'profile' => 'mac-local', 'kind' => 'instagram.agent.read', 'status' => 'running', 'payload' => []]);
        $this->assertSame('busy', $this->tick()['status']);
    }

    public function test_explicit_platform_limit_pauses_other_accounts_on_the_same_worker_and_local_actions(): void
    {
        $other = SocialAccount::create(['platform_id' => $this->account->platform_id, 'platform_account_id' => '999', 'name' => 'Autre compte']);
        BrowserTask::create(['social_account_id' => $other->id, 'profile' => 'vps-test', 'kind' => 'instagram.agent.act', 'status' => 'failed', 'finished_at' => now(), 'payload' => [],
            'result' => ['platform_limit' => ['source' => 'platform_ui', 'scope' => 'temporary', 'message' => 'HTTP ERROR 429']]]);
        $this->assertSame('platform_pause', $this->tick()['status']);
        $user = User::factory()->create(['role' => 'admin']);
        $this->account->users()->attach($user, ['is_active' => true]);
        $this->actingAs($user)->postJson('/api/agent/accounts/'.$this->account->id.'/meta/read', ['action' => 'thread.read', 'url' => 'https://www.instagram.com/p/ABC/'])->assertStatus(429);
        $this->travel(61)->minutes();
        $this->assertSame('search.read', $this->tick(false)['action']);
    }

    public function test_only_full_manager_access_can_enable_the_pass_and_partial_changes_keep_hashtags(): void
    {
        $user = User::factory()->create(['role' => 'manager']);
        $this->account->users()->attach($user, ['is_active' => true]);
        $path = '/api/community-manager/accounts/'.$this->account->id.'/strategy';
        $this->withToken($user->createToken('CM', ['community-manager'])->plainTextToken)
            ->patchJson($path, ['agent_strategy' => ['instagram_comment_likes_enabled' => true]])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('Gestionnaire')->plainTextToken)
            ->patchJson($path, ['agent_strategy' => ['instagram_comment_likes_enabled' => false]])->assertOk();
        $this->assertFalse((bool) $this->account->fresh()->agent_strategy['instagram_comment_likes_enabled']);
        $this->assertSame(['#musculation', '#running'], $this->account->fresh()->agent_strategy['hashtags']);
        $this->assertSame('disabled', $this->tick()['status']);
    }

    public function test_session_is_stored_encrypted_without_contacting_instagram_during_the_pause(): void
    {
        $task = BrowserTask::create(['social_account_id' => $this->account->id, 'profile' => 'vps-test', 'kind' => 'instagram.agent.act', 'status' => 'failed', 'finished_at' => now(), 'payload' => [],
            'result' => ['platform_limit' => ['source' => 'platform_ui', 'scope' => 'temporary']]]);
        $user = User::factory()->create(['role' => 'admin']);
        $this->account->users()->attach($user, ['is_active' => true]);
        $this->mock(BrowserTaskQueue::class)->shouldNotReceive('run');
        $cookies = array_map(fn ($key, $value) => ['name' => $key, 'value' => $value, 'domain' => '.instagram.com'], ['ds_user_id', 'sessionid'], ['123', 'test-secret']);
        $this->actingAs($user)->postJson('/api/extension/meta/instagram/session', ['platform_account_id' => '123', 'cookies' => $cookies])
            ->assertStatus(202)->assertJsonPath('status', 'pending')->assertDontSee('test-secret');
        $this->assertStringNotContainsString('test-secret', $this->account->fresh()->getRawOriginal('browser_session'));
        $this->assertSame(1, BrowserTask::count());
        $this->artisan('instagram:like-comments --commit')->assertSuccessful();
        $this->assertSame(1, BrowserTask::count());
    }

    public function test_pending_session_is_verified_after_the_pause_before_any_new_social_action(): void
    {
        $this->account->update(['browser_session_status' => 'pending', 'browser_session' => [['name' => 'ds_user_id', 'value' => '123', 'domain' => '.instagram.com']]]);
        $this->mock(BrowserTaskQueue::class)->shouldReceive('run')->once()->withArgs(fn ($a, $kind) => $a->id === $this->account->id && $kind === 'instagram.whoami')
            ->andReturn(['account_id' => '123', 'username' => 'nina']);
        $this->artisan('instagram:like-comments --commit')->assertSuccessful();
        $this->assertSame('connected', $this->account->fresh()->browser_session_status);
        $this->assertSame(0, MetaEngagement::count());
        $this->assertSame(0, AccountActionEvent::count());
    }
}
