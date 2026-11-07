<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TikTokPausedSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_pause_accepte_le_stockage_chiffre_sans_installer_ni_visiter_tiktok(): void
    {
        $user = User::factory()->create(['role' => 'manager']);
        Sanctum::actingAs($user);
        $account = SocialAccount::create(['name' => 'Compte test', 'platform_id' => Platform::where('slug', 'tiktok')->firstOrFail()->id,
            'driver' => 'browser', 'browser_profile' => null, 'browser_username' => 'owner', 'browser_session_status' => 'expired']);
        $account->users()->attach($user, ['is_active' => true]);
        $this->mock(BrowserTaskQueue::class)->shouldNotReceive('run');
        $cookies = [['name' => 'sessionid', 'value' => 'fake-cookie-test-only', 'domain' => '.tiktok.com']];
        $this->postJson('/api/extension/tiktok/session', ['username' => 'owner', 'cookies' => $cookies])->assertOk()
            ->assertJsonPath('status', 'stored')->assertJsonPath('stored', true)->assertJsonPath('browser_connected', false)
            ->assertDontSee('fake-cookie-test-only');
        $account->refresh();
        $this->assertNull($account->browser_profile);
        $this->assertNotNull($account->browser_session_received_at);
        $this->assertSame($cookies, $account->browser_session);
        $this->assertStringNotContainsString('fake-cookie-test-only', DB::table('social_accounts')->where('id', $account->id)->value('browser_session'));
        $this->assertDatabaseCount('browser_tasks', 0);
    }
}
