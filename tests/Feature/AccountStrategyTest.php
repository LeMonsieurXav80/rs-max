<?php

namespace Tests\Feature;

use App\Models\AccountActionEvent;
use App\Models\BrowserTask;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Agent\AccountActionBudget;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountStrategyTest extends TestCase
{
    use RefreshDatabase;

    private function account(): SocialAccount
    {
        return SocialAccount::create(['name' => 'Test', 'platform_id' => Platform::where('slug', 'tiktok')->firstOrFail()->id, 'driver' => 'browser', 'browser_profile' => 'test', 'agent_hourly_limit' => 2]);
    }

    public function test_reglages_api_scopes_partiels_et_historique_durable(): void
    {
        $a = $this->account();
        $u = User::factory()->create(['role' => 'manager']);
        $a->users()->attach($u, ['is_active' => true]);
        $token = $u->createToken('CM', ['community-manager'])->plainTextToken;
        $path = '/api/community-manager/accounts/'.$a->id.'/strategy';
        $this->withToken($token)->patchJson($path, ['agent_strategy' => ['audience' => 'Public francophone', 'hashtags' => ['#mode'], 'target_accounts' => ['@exemple']], 'agent_hourly_limit' => 2, 'agent_min_interval_seconds' => 60])->assertOk()->assertJsonPath('cadence.limit', 2);
        $this->withToken($token)->patchJson($path, ['agent_strategy' => ['search_queries' => ['tenue du jour']]])->assertOk()->assertJsonPath('strategy.hashtags.0', '#mode');
        $this->withToken($token)->patchJson($path, ['agent_strategy' => ['reply_language' => 'es']])->assertOk()
            ->assertJsonPath('reply_language', 'es')->assertJsonPath('strategy.hashtags.0', '#mode');
        $this->withToken($token)->patchJson($path, ['agent_strategy' => ['reply_language' => 'xx']])->assertUnprocessable();
        $this->withToken($token)->patchJson($path, ['agent_strategy' => ['reply_language' => null]])->assertOk()->assertJsonPath('reply_language', 'fr');
        $this->withToken($token)->getJson('/api/community-manager/accounts/'.$this->account()->id.'/strategy')->assertNotFound();
        $this->withToken($token)->patchJson($path, ['agent_strategy' => ['credentials' => ['secret']]])->assertUnprocessable();
        $this->withToken($token)->patchJson($path, ['agent_hourly_limit' => -1])->assertUnprocessable();
        $this->assertSame(['#mode'], $a->fresh()->agent_strategy['hashtags']);
    }

    public function test_delai_de_decouverte_reglable_par_compte_dans_la_fiche_et_l_api(): void
    {
        $platform = Platform::firstOrCreate(['slug' => 'twitter'], ['name' => 'X']);
        $first = SocialAccount::create(['platform_id' => $platform->id, 'name' => 'Compte X A', 'driver' => 'browser']);
        $second = SocialAccount::create(['platform_id' => $platform->id, 'name' => 'Compte X B', 'driver' => 'browser']);
        $manager = User::factory()->create(['role' => 'manager']);
        $manager->socialAccounts()->attach([$first->id => ['is_active' => true], $second->id => ['is_active' => true]]);
        $token = $manager->createToken('CM', ['community-manager'])->plainTextToken;
        $path = '/api/community-manager/accounts/'.$first->id.'/strategy';
        $this->withToken($token)->patchJson($path, ['agent_strategy' => ['discovery_interval_minutes' => 5]])
            ->assertOk()->assertJsonPath('strategy.discovery_interval_minutes', 5);
        $this->withToken($token)->patchJson($path, ['agent_strategy' => ['discovery_interval_minutes' => 0]])->assertUnprocessable();
        $this->assertNull($second->fresh()->agent_strategy);
        $this->actingAs($manager)->get('/accounts/'.$first->id.'/edit')->assertOk()->assertSee('Intervalle entre explorations');
        $this->actingAs($manager)->put('/accounts/'.$first->id, ['name' => 'Compte X A', 'languages' => ['fr'],
            'agent_strategy' => ['discovery_interval_minutes' => '8']])->assertRedirect('/accounts/'.$first->id.'/edit');
        $this->assertSame(8, $first->fresh()->agent_strategy['discovery_interval_minutes']);
        $this->withToken($token)->patchJson($path, ['agent_strategy' => ['discovery_interval_minutes' => null]])
            ->assertOk()->assertJsonPath('strategy.discovery_interval_minutes', null);
    }

    public function test_limite_glissante_et_delai_avant_prise_de_tache(): void
    {
        $this->freezeTime();
        $a = $this->account();
        $a->update(['agent_min_interval_seconds' => 60]);
        $budget = app(AccountActionBudget::class);
        $queue = app(BrowserTaskQueue::class);
        $make = fn () => BrowserTask::create(['social_account_id' => $a->id, 'profile' => 'test', 'kind' => 'tiktok.like', 'payload' => []]);
        $t = $make();
        $this->assertSame($t->id, $queue->claim('test')->id);
        $t->update(['status' => 'uncertain']);
        $budget->finish($t);
        $blocked = $make();
        $this->assertNull($queue->claim('test'));
        $this->assertSame('cancelled', $blocked->fresh()->status);
        $this->travel(61)->seconds();
        $second = $make();
        $this->assertSame($second->id, $queue->claim('test')->id);
        $second->update(['status' => 'done', 'result' => ['confirmed' => true]]);
        $budget->finish($second);
        $this->travel(61)->seconds();
        $third = $make();
        $this->assertNull($queue->claim('test'));
        $this->assertSame('cancelled', $third->fresh()->status);
        $this->assertSame(2, $budget->usage($a)['attempted']);
        $this->assertSame(1, $budget->usage($a)['confirmed']);
        $this->travel(61)->minutes();
        $fourth = $make();
        $this->assertSame($fourth->id, $queue->claim('test')->id);
        $this->assertCount(3, AccountActionEvent::all());
    }

    public function test_lectures_non_comptees_et_compteur_independant_par_compte(): void
    {
        $a = $this->account();
        $a->update(['agent_hourly_limit' => 0]);
        $q = app(BrowserTaskQueue::class);
        $t = BrowserTask::create(['social_account_id' => $a->id, 'profile' => 'test', 'kind' => 'tiktok.comments', 'payload' => []]);
        $this->assertSame($t->id, $q->claim('test')->id);
        $t->update(['status' => 'done']);
        $this->assertSame(0, AccountActionEvent::count());
        $b = $this->account();
        app(AccountActionBudget::class)->reserve($b, 'inbox.reply', 'api', 'item');
        $this->assertSame(0, app(AccountActionBudget::class)->usage($a)['attempted']);
    }

    public function test_reponses_tiktok_comptent_les_tentatives_du_jour_et_bloquent_au_plafond(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-08T12:00:00Z'));
        $a = $this->account();
        $a->agentActions()->create(['action' => 'reply', 'enabled' => true, 'daily_limit' => 1]);
        $budget = app(AccountActionBudget::class);
        $old = $budget->reserve($a, 'tiktok.reply', 'browser', 'ancien');
        $old->update(['created_at' => now()->subDay()]);
        $this->assertSame(0, app(\App\Services\Agent\AgentCatalog::class)->usedToday($a, 'reply'));
        $budget->reserve($a, 'tiktok.reply', 'browser', 'nouveau')->update(['status' => 'uncertain']);
        $this->assertSame(1, app(\App\Services\Agent\AgentCatalog::class)->usedToday($a, 'reply'));
        $this->assertSame(0, app(\App\Services\Agent\AgentCatalog::class)->usedToday($this->account(), 'reply'));
        // Le like automatique reste possible quand le quota de réponses est consommé.
        $budget->reserve($a, 'tiktok.like', 'browser', 'nouveau');
        $this->expectException(\App\Services\Browser\BrowserException::class);
        $this->expectExceptionMessage('Quota quotidien de réponses TikTok atteint');
        $budget->reserve($a, 'tiktok.reply', 'browser', 'autre');
    }

    public function test_formulaire_tous_reseaux_normalise_listes(): void
    {
        $u = User::factory()->create(['role' => 'manager']);
        $a = $this->account();
        $a->users()->attach($u, ['is_active' => true]);
        $this->actingAs($u)->get('/accounts/'.$a->id.'/edit')->assertOk()->assertSee('Maximum d’actions sur 60 minutes')->assertSee('Companion · NAS');
        $this->actingAs($u)->put('/accounts/'.$a->id, ['name' => 'Test', 'languages' => ['fr'], 'agent_strategy' => ['hashtags' => "#mode\n\n#style", 'search_queries' => null, 'target_accounts' => '', 'exclusions' => null], 'agent_hourly_limit' => 2, 'agent_min_interval_seconds' => 60])->assertRedirect('/accounts/'.$a->id.'/edit');
        $this->assertSame(['#mode', '#style'], $a->fresh()->agent_strategy['hashtags']);
    }
}
