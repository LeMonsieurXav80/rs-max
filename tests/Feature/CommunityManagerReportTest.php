<?php

namespace Tests\Feature;

use App\Jobs\NotifyAccountLimitAdjustment;
use App\Models\AccountActionEvent;
use App\Models\AgentLimitAdjustment;
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

    public function test_le_bilan_ne_retient_que_la_periode_et_les_comptes_actifs(): void
    {
        $user = User::factory()->create(['role' => 'manager']);
        $platform = Platform::firstOrCreate(['slug' => 'twitter'], ['name' => 'X', 'auth_type' => 'oauth2']);
        $active = SocialAccount::create(['platform_id' => $platform->id, 'name' => 'Compte actif']);
        $inactive = SocialAccount::create(['platform_id' => $platform->id, 'name' => 'Compte inactif']);
        foreach ([$active, $inactive] as $account) {
            $account->users()->attach($user, ['is_active' => true]);
        }
        $this->travel(-13)->hours();
        AccountActionEvent::create(['social_account_id' => $inactive->id, 'action' => 'x.reply',
            'executor' => 'browser', 'status' => 'failed', 'error' => 'Ancien incident']);
        $this->travelBack();
        AccountActionEvent::create(['social_account_id' => $active->id, 'action' => 'x.reply',
            'executor' => 'browser', 'status' => 'uncertain', 'error' => 'Preuve manquante']);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/community-manager/report?hours=12')->assertOk()->assertJsonCount(2, 'accounts');
        $text = $response->json('text');
        $this->assertStringContainsString('Incertaines 1', $text);
        $this->assertStringContainsString('Preuve manquante', $text);
        $this->assertStringNotContainsString('Ancien incident', $text);
        $this->assertStringNotContainsString('Compte inactif', $text);
    }

    public function test_seule_une_limitation_explicite_declenche_une_alerte_immediate(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        Setting::setEncrypted('notify_telegram_bot_token', 'test-only');
        Setting::set('notify_telegram_chat_id', 'test-chat');
        $platform = Platform::firstOrCreate(['slug' => 'twitter'], ['name' => 'X', 'auth_type' => 'oauth2']);
        $account = SocialAccount::create(['platform_id' => $platform->id, 'name' => 'Compte attribué']);
        foreach (['technical', 'stable', 'platform_ui'] as $source) {
            $adjustment = AgentLimitAdjustment::create(['social_account_id' => $account->id,
                'old_hourly' => 20, 'new_hourly' => 10, 'old_daily' => 80, 'new_daily' => 40,
                'old_interval' => 180, 'new_interval' => 360, 'reason' => $source, 'context' => ['source' => $source]]);
            (new NotifyAccountLimitAdjustment($adjustment->id))->handle();
        }

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['text'], 'platform_ui'));
    }
}
