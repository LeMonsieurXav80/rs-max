<?php

namespace Tests\Feature;

use App\Models\Persona;
use App\Models\Platform;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class XAssistedTest extends TestCase
{
    use RefreshDatabase;

    public function test_assiste_reserve_une_reponse_sans_creer_de_tache_et_bloque_les_doublons(): void
    {
        $platform = Platform::firstOrCreate(['slug' => 'twitter'], ['name' => 'X', 'auth_type' => 'oauth2']);
        $persona = Persona::create(['name' => 'Réponses', 'system_prompt' => 'Aide sans promotion.', 'is_active' => true]);
        $owner = User::factory()->create();
        $account = SocialAccount::create(['name' => 'X', 'platform_id' => $platform->id, 'platform_account_id' => '1000',
            'persona_id' => $persona->id, 'driver' => 'api', 'browser_username' => 'owner', 'languages' => ['fr'],
            'agent_strategy' => ['search_queries' => ['whey lang:fr'], 'exclusions' => ['concours']]]);
        $account->users()->attach($owner, ['is_active' => true]);
        $this->withToken($owner->createToken('extension')->plainTextToken);
        $identity = ['platform_account_id' => '1000', 'username' => 'owner'];
        $url = '/api/extension/x/assisted/';
        $this->getJson($url.'context?'.http_build_query($identity))->assertOk()
            ->assertJsonPath('queries.0', 'whey lang:fr')->assertJsonPath('search_language', 'fr');
        $this->postJson($url.'check', ['ids' => ['4100']] + $identity)->assertOk()->assertJsonPath('blocked', []);
        $this->postJson($url.'check', ['ids' => ['4100'], 'username' => 'wrong'] + $identity)->assertConflict();
        $this->postJson($url.'check', ['ids' => ['4100'], 'platform_account_id' => '999'] + $identity)->assertForbidden();

        Setting::setEncrypted('openai_api_key', 'test-key');
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'Belle progression !']]]])]);
        $target = ['target_id' => '4100', 'author' => 'visitor', 'text' => 'Mon record', 'origin' => 'Mon record'] + $identity;
        $key = $this->postJson($url.'preview', $target)->assertOk()->assertJsonPath('reply_text', 'Belle progression !')->json('draft_key');
        $this->assertDatabaseCount('extension_x_replies', 0);
        $this->assertDatabaseCount('browser_tasks', 0);

        $receipt = $this->postJson($url.'reserve', ['draft_key' => $key] + $identity)->assertOk()->json('id');
        $this->postJson($url.'reserve', ['draft_key' => $key] + $identity)->assertConflict();
        $this->postJson($url.'preview', $target)->assertConflict();
        $this->postJson($url.'check', ['ids' => ['4100']] + $identity)->assertOk()->assertJsonPath('blocked', ['4100']);
        $this->postJson($url."replies/{$receipt}/finish", ['reply_id' => '4100'] + $identity)->assertUnprocessable();
        $this->postJson($url."replies/{$receipt}/finish", ['reply_id' => '4101'] + $identity)->assertOk()->assertJsonPath('status', 'published');
        $this->postJson($url."replies/{$receipt}/finish", ['reply_id' => '4101'] + $identity)->assertOk();
        $this->assertDatabaseHas('extension_x_replies', ['id' => $receipt, 'proof' => 'manual:visible:4101']);
        $this->assertDatabaseCount('browser_tasks', 0);
    }
}
