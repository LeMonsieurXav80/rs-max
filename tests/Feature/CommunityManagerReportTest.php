<?php

namespace Tests\Feature;

use App\Models\AccountActionEvent;
use App\Models\Platform;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommunityManagerReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_rapport_est_scope_et_son_envoi_est_explicite_et_unique(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        Setting::setEncrypted('notify_telegram_bot_token', 'test-only');
        Setting::set('notify_telegram_chat_id', 'test-chat');
        $u = User::factory()->create(['role' => 'manager']);
        $p = Platform::firstOrCreate(['slug' => 'twitter'], ['name' => 'X', 'auth_type' => 'oauth2']);
        $a = SocialAccount::create(['platform_id' => $p->id, 'name' => 'Compte attribué', 'agent_hourly_limit' => 2]);
        $other = SocialAccount::create(['platform_id' => $p->id, 'name' => 'Compte non attribué']);
        $a->users()->attach($u, ['is_active' => true]);
        foreach ([$a, $other] as $account) {
            AccountActionEvent::create(['social_account_id' => $account->id, 'action' => 'x.like', 'executor' => 'browser', 'status' => 'confirmed']);
        }
        Sanctum::actingAs($u);
        $url = '/api/community-manager/report';
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'accounts')->assertJsonPath('accounts.0.confirmed', 1)->assertDontSee('Compte non attribué');
        $this->postJson($url, [])->assertOk()->assertJsonPath('dry_run', true);
        Http::assertNothingSent();
        $this->postJson($url, ['dry_run' => false])->assertUnprocessable();
        $payload = ['dry_run' => false, 'report_key' => (string) \Illuminate\Support\Str::uuid()];
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('delivery.sent', true);
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('already_requested', true);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r['text'], 'Compte attribué') && ! isset($r['parse_mode']));
    }
}
