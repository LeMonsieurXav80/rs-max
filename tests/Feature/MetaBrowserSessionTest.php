<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Browser\BrowserTaskQueue;
use App\Services\Browser\MetaSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MetaBrowserSessionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $platform): array
    {
        Http::preventStrayRequests();
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);
        $p = Platform::firstOrCreate(['slug' => $platform], ['name' => $platform]);
        $a = SocialAccount::create(['platform_id' => $p->id, 'platform_account_id' => '123', 'name' => 'Nina',
            'driver' => 'browser', 'browser_profile' => 'vps-dix', 'browser_username' => $platform === 'instagram' ? 'nina.bellori' : null]);
        $a->users()->attach($user, ['is_active' => true]);
        $values = $platform === 'facebook' ? ['c_user' => '123', 'xs' => 'fixture-secret'] : ['ds_user_id' => '123', 'sessionid' => 'fixture-secret'];
        $cookies = collect($values)->map(fn ($value, $name) => compact('name', 'value') + ['domain' => '.'.$platform.'.com', 'path' => '/', 'hostOnly' => false])->values()->all();

        return [$user, $a, $cookies];
    }

    public function test_facebook_session_is_encrypted_and_verified_by_actual_actor_id(): void
    {
        [$u, $a, $cookies] = $this->fixture('facebook');
        $this->mock(BrowserTaskQueue::class)->shouldReceive('run')->once()->withArgs(fn ($account, $kind, $payload) => $account->id === $a->id && $kind === 'facebook.session.apply' && ! isset($payload['cookies']))->andReturn(['account_id' => '123']);
        $this->postJson('/api/extension/meta/facebook/session', ['platform_account_id' => '123', 'cookies' => $cookies])->assertOk()->assertJsonPath('status', 'connected')->assertDontSee('fixture-secret');
        $this->assertSame($cookies, $a->fresh()->browser_session);
        $this->assertStringNotContainsString('fixture-secret', $a->fresh()->getRawOriginal('browser_session'));
        $this->assertSame('browser', $a->fresh()->driver);
        Http::assertNothingSent();
    }

    public function test_foreign_domain_or_another_account_is_rejected_without_storing_cookies(): void
    {
        [$u, $a, $cookies] = $this->fixture('facebook');
        $this->mock(BrowserTaskQueue::class)->shouldNotReceive('run');
        $cookies[0]['value'] = '999';
        $this->postJson('/api/extension/meta/facebook/session', ['platform_account_id' => '123', 'cookies' => $cookies])->assertConflict();
        $cookies[0]['value'] = '123';
        $cookies[0]['domain'] = '.facebook.com.evil.test';
        $this->postJson('/api/extension/meta/facebook/session', ['platform_account_id' => '123', 'cookies' => $cookies])->assertUnprocessable();
        $this->assertNull($a->fresh()->browser_session);
    }

    public function test_inactive_account_cannot_receive_session(): void
    {
        [$u, $a, $cookies] = $this->fixture('instagram');
        $a->users()->updateExistingPivot($u->id, ['is_active' => false]);
        $this->mock(BrowserTaskQueue::class)->shouldNotReceive('run');
        $this->postJson('/api/extension/meta/instagram/session', ['platform_account_id' => '123', 'cookies' => $cookies])->assertNotFound();
        $this->assertNull($a->fresh()->browser_session);
    }

    public function test_instagram_requires_actual_id_and_expected_handle_after_restore(): void
    {
        [$u, $a, $cookies] = $this->fixture('instagram');
        $this->mock(BrowserTaskQueue::class)->shouldReceive('run')->once()->andReturn(['account_id' => '123', 'username' => 'another.account']);
        $this->postJson('/api/extension/meta/instagram/session', ['platform_account_id' => '123', 'cookies' => $cookies])->assertConflict()->assertJsonPath('status', 'expired');
        $this->assertSame('nina.bellori', $a->fresh()->browser_username);
    }

    public function test_facebook_page_actor_takes_precedence_over_main_login_and_conflicting_cookies_are_refused(): void
    {
        [$u, $a, $cookies] = $this->fixture('facebook');
        $cookies[] = ['name' => 'i_user', 'value' => '456', 'domain' => '.facebook.com'];
        $this->assertSame('456', MetaSessionService::identity($cookies, 'facebook'));
        $cookies[] = ['name' => 'c_user', 'value' => '789', 'domain' => 'www.facebook.com'];
        $this->assertNull(MetaSessionService::identity($cookies, 'facebook'));
    }

    public function test_personal_account_can_be_created_through_normal_web_form_without_api_credentials(): void
    {
        [$u, $a] = $this->fixture('facebook');
        $this->actingAs($u)->post('/accounts', ['platform_id' => $a->platform_id, 'name' => 'Nina personnel', 'languages' => ['fr'], 'driver' => 'browser_vps', 'browser_profile' => 'vps-dix', 'platform_account_id' => '61596810534814'])->assertRedirect('/accounts');
        $new = SocialAccount::where('platform_account_id', '61596810534814')->firstOrFail();
        $this->assertSame('browser', $new->driver);
        $this->assertSame([], $new->credentials);
        $this->assertSame('vps-dix', $new->browser_profile);
        $this->assertTrue($new->users()->where('user_id', $u->id)->wherePivot('is_active', true)->exists());
    }

    public function test_browser_meta_does_not_fall_back_to_api_or_advertise_unimplemented_actions(): void
    {
        [$u, $a] = $this->fixture('facebook');
        $a->agentActions()->create(['action' => 'reply', 'enabled' => true, 'daily_limit' => 5]);
        $this->assertSame('planned', app(\App\Services\Agent\AgentCatalog::class)->forAccount($a)->get('reply')['status']);
        $this->assertNull(app(\App\Services\Inbox\InboxSyncService::class)->getServiceForAccount($a));
        $result = app(\App\Services\Adapters\FacebookAdapter::class)->publish($a, 'Texte de test');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Companion', $result['error']);
        $this->assertFalse(app(\App\Services\Import\ImportService::class)->import($a)['success']);
        Http::assertNothingSent();
    }

    public function test_api_accounts_keep_their_original_action_catalog(): void
    {
        [$u, $a] = $this->fixture('facebook');
        $a->update(['driver' => 'api']);
        $this->assertSame('ready', app(\App\Services\Agent\AgentCatalog::class)->forAccount($a)->get('reply')['status']);
        $this->assertInstanceOf(\App\Services\Inbox\FacebookInboxService::class, app(\App\Services\Inbox\InboxSyncService::class)->getServiceForAccount($a));
    }
}
