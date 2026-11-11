<?php

namespace Tests\Feature;

use App\Models\AccountActionEvent;
use App\Models\AgentLimitAdjustment;
use App\Models\BrowserTask;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Agent\AccountActionBudget;
use App\Services\Agent\AdaptiveAccountLimits;
use App\Services\Browser\BrowserException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdaptiveAccountLimitsTest extends TestCase
{
    use RefreshDatabase;

    private function account(): SocialAccount
    {
        return SocialAccount::create(['name' => 'Essai', 'platform_id' => Platform::firstOrCreate(['slug' => 'twitter'], ['name' => 'X', 'is_active' => true, 'config' => []])->id,
            'agent_hourly_limit' => 120, 'agent_daily_limit' => 400, 'agent_min_interval_seconds' => 30,
            'agent_adaptation' => ['enabled' => true, 'max_hourly' => 300, 'max_daily' => 1000],
            'agent_adaptation_state' => ['enabled_at' => now()->subHour()->toIso8601String(), 'window_started_at' => now()->subMinutes(31)->toIso8601String()]]);
    }

    private function stable(SocialAccount $a): void
    {
        for ($i = 0; $i < 10; $i++) {
            AccountActionEvent::create(['social_account_id' => $a->id, 'action' => 'x.like', 'executor' => 'browser', 'status' => 'confirmed', 'created_at' => now()->subMinutes(25 - $i)]);
        }
    }

    public function test_progression_independante_et_plafonds_humains(): void
    {
        $this->freezeTime();
        $a = $this->account();
        $other = $this->account();
        $limits = app(AdaptiveAccountLimits::class);
        $a->agentActions()->create(['action' => 'reply', 'enabled' => true, 'daily_limit' => 10]);
        $a->agentActions()->create(['action' => 'like', 'enabled' => false, 'daily_limit' => 2]);
        $a->agentActions()->create(['action' => 'bookmark', 'enabled' => true, 'daily_limit' => 5]);
        $this->stable($a);
        $this->assertNotNull($limits->evaluate($a));
        $this->assertSame(150, $a->fresh()->agent_hourly_limit);
        $this->assertSame(500, $a->fresh()->agent_daily_limit);
        $this->assertSame(24, $a->fresh()->agent_min_interval_seconds);
        $this->assertSame(250, $a->agentActions()->where('action', 'reply')->first()->daily_limit);
        $this->assertSame(2, $a->agentActions()->where('action', 'like')->first()->daily_limit);
        $this->assertSame(5, $a->agentActions()->where('action', 'bookmark')->first()->daily_limit);
        $this->assertSame(120, $other->fresh()->agent_hourly_limit);
        for ($i = 0; $i < 6; $i++) {
            $this->travel(35)->minutes();
            $this->stable($a);
            $limits->evaluate($a);
        }
        $this->assertSame(300, $a->fresh()->agent_hourly_limit);
        $this->assertSame(1000, $a->fresh()->agent_daily_limit);
        $this->assertSame(12, $a->fresh()->agent_min_interval_seconds);
        $this->assertNull($limits->evaluate($a));
    }

    public function test_aucune_hausse_sur_simple_temps_ecoule_ou_gestes_en_cours(): void
    {
        $this->freezeTime();
        $a = $this->account();
        $limits = app(AdaptiveAccountLimits::class);
        $this->assertNull($limits->evaluate($a));
        $this->stable($a);
        AccountActionEvent::create(['social_account_id' => $a->id, 'action' => 'x.reply', 'executor' => 'browser', 'status' => 'attempted']);
        $this->assertNull($limits->evaluate($a));
        $this->assertSame(120, $a->fresh()->agent_hourly_limit);
    }

    public function test_limite_reseau_explicite_reduit_et_bloque_sans_renvoi(): void
    {
        $this->freezeTime();
        $a = $this->account();
        $task = BrowserTask::create(['social_account_id' => $a->id, 'profile' => 'test', 'kind' => 'x.post', 'payload' => [], 'status' => 'uncertain',
            'result' => ['platform_limit' => ['source' => 'platform_ui', 'scope' => 'daily', 'message' => 'Daily limit exceeded']]]);
        AccountActionEvent::create(['social_account_id' => $a->id, 'browser_task_id' => $task->id, 'action' => 'x.reply', 'executor' => 'browser', 'status' => 'uncertain']);
        $limits = app(AdaptiveAccountLimits::class);
        $row = $limits->evaluate($a);
        $this->assertSame('platform_ui', $row->context['source']);
        $a->refresh();
        $this->assertSame(60, $a->agent_hourly_limit);
        $this->assertSame(200, $a->agent_daily_limit);
        $this->assertTrue(app(AccountActionBudget::class)->usage($a)['blocked']);
        $this->assertNull($limits->evaluate($a));
        $this->assertSame(1, AgentLimitAdjustment::count());
        $this->assertSame('uncertain', $task->fresh()->status);
        $this->expectException(BrowserException::class);
        app(AccountActionBudget::class)->reserve($a, 'x.like', 'browser');
    }

    public function test_echec_technique_ne_devient_pas_un_quota_du_reseau_et_ne_se_repete_pas(): void
    {
        $this->freezeTime();
        $a = $this->account();
        AccountActionEvent::create(['social_account_id' => $a->id, 'action' => 'x.like', 'executor' => 'browser',
            'status' => 'failed', 'error' => 'HTTP 429 interne RS-Max', 'created_at' => now()->subMinute()]);
        $row = app(AdaptiveAccountLimits::class)->evaluate($a);
        $this->assertSame('technical', $row->context['source']);
        $this->assertSame(120, $a->fresh()->agent_hourly_limit);
        $this->assertNull(app(AdaptiveAccountLimits::class)->evaluate($a));
        $this->assertFalse(app(AccountActionBudget::class)->usage($a->fresh())['blocked']);
        $this->assertSame('x.like', app(AccountActionBudget::class)->reserve($a, 'x.like', 'browser')->action);
    }

    public function test_un_echec_de_commentaire_ne_leve_pas_une_limite_explicite_du_reseau(): void
    {
        $this->freezeTime();
        $a = $this->account();
        $task = BrowserTask::create(['social_account_id' => $a->id, 'profile' => 'test', 'kind' => 'x.agent.act',
            'payload' => [], 'status' => 'uncertain', 'result' => ['platform_limit' => ['source' => 'platform_ui', 'scope' => 'temporary']]]);
        AccountActionEvent::create(['social_account_id' => $a->id, 'browser_task_id' => $task->id,
            'action' => 'x.reply', 'executor' => 'browser', 'status' => 'uncertain']);
        $limits = app(AdaptiveAccountLimits::class);
        $limits->evaluate($a);

        $this->travel(1)->minute();
        AccountActionEvent::create(['social_account_id' => $a->id, 'action' => 'x.reply',
            'executor' => 'browser', 'status' => 'failed']);
        $limits->evaluate($a);

        $this->travel(16)->minutes();
        $this->expectException(BrowserException::class);
        $this->expectExceptionMessage(AccountActionBudget::PLATFORM_PAUSE);
        app(AccountActionBudget::class)->reserve($a, 'x.post', 'browser');
    }

    public function test_quota_local_sans_evenement_ne_declenche_aucune_adaptation(): void
    {
        $a = $this->account();
        $a->update(['agent_hourly_limit' => 0]);
        try {
            app(AccountActionBudget::class)->reserve($a, 'x.like', 'browser');
            $this->fail('Devait être bloqué');
        } catch (BrowserException $e) {
        }
        $this->assertNull(app(AdaptiveAccountLimits::class)->evaluate($a));
        $this->assertSame(0, AccountActionEvent::count());
    }

    public function test_global_journalier_et_frontiere_de_lisbonne(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-08 00:30:00', 'Europe/Paris'));
        $a = $this->account();
        $a->update(['agent_daily_limit' => 1, 'agent_adaptation' => null]);
        AccountActionEvent::create(['social_account_id' => $a->id, 'action' => 'x.reply', 'executor' => 'browser', 'status' => 'uncertain', 'created_at' => now()->subHours(12)]);
        $budget = app(AccountActionBudget::class);
        $this->assertSame(1, $budget->usage($a)['daily']['attempted']);
        try {
            $budget->reserve($a, 'x.like', 'browser');
            $this->fail('Quota partagé');
        } catch (BrowserException $e) {
            $this->assertStringContainsString('quotidien', $e->getMessage());
        }
        $this->travel(31)->minutes();
        $this->assertSame(0, $budget->usage($a)['daily']['attempted']);
        $budget->reserve($a, 'x.like', 'browser');
        $this->assertSame(1, $budget->usage($a)['daily']['attempted']);
    }

    public function test_api_plafonds_modifiables_et_agent_scoped_ne_peut_les_relever(): void
    {
        $a = $this->account();
        $u = User::factory()->create(['role' => 'manager']);
        $a->users()->attach($u, ['is_active' => true]);
        $path = '/api/community-manager/accounts/'.$a->id.'/strategy';
        $scoped = $u->createToken('CM', ['community-manager'])->plainTextToken;
        $this->withToken($scoped)->patchJson($path, ['agent_adaptation' => ['max_hourly' => 500]])->assertForbidden();
        $this->withToken($scoped)->patchJson($path, ['agent_hourly_limit' => 500])->assertForbidden();
        $this->withToken($scoped)->patchJson($path, ['agent_strategy' => ['hashtags' => ['#style']]])->assertOk();
        $full = $u->createToken('Gestionnaire', ['*'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($full)->patchJson('/api/accounts/'.$a->id.'/strategy', ['agent_adaptation' => ['max_hourly' => 200, 'max_daily' => 600]])->assertOk()->assertJsonPath('cadence.adaptation.policy.max_hourly', 200);
        $this->assertSame(120, $a->fresh()->agent_hourly_limit);
        $this->withToken($full)->patchJson('/api/accounts/'.$a->id.'/strategy', ['agent_hourly_limit' => 500])->assertOk()->assertJsonPath('cadence.limit', 200);
        $this->withToken($full)->patchJson('/api/accounts/'.$a->id.'/strategy', ['agent_adaptation' => ['unexpected' => true]])->assertUnprocessable();
    }

    public function test_ancien_incident_ignore_mais_fin_tardive_d_un_geste_traitee(): void
    {
        $this->freezeTime();
        $a = $this->account();
        AccountActionEvent::create(['social_account_id' => $a->id, 'action' => 'x.like', 'executor' => 'browser', 'status' => 'failed', 'updated_at' => now()->subDays(2), 'created_at' => now()->subDays(2)]);
        $this->assertNull(app(AdaptiveAccountLimits::class)->evaluate($a));
        $late = AccountActionEvent::create(['social_account_id' => $a->id, 'action' => 'x.reply', 'executor' => 'browser', 'status' => 'attempted', 'created_at' => now()->subHours(2)]);
        $late->update(['status' => 'uncertain']);
        $this->assertNotNull(app(AdaptiveAccountLimits::class)->evaluate($a));
    }
}
