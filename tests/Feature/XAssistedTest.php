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

    public function test_companion_releve_audience_et_publications_du_compte_sans_effacer_les_metriques_masquees(): void
    {
        $platform = Platform::firstOrCreate(['slug' => 'twitter'], ['name' => 'X', 'auth_type' => 'oauth2']);
        $owner = User::factory()->create();
        $account = SocialAccount::create(['name' => 'X', 'platform_id' => $platform->id, 'platform_account_id' => '1000',
            'browser_username' => 'owner', 'followers_count' => 22598]);
        $account->users()->attach($owner, ['is_active' => true]);
        $this->withToken($owner->createToken('extension')->plainTextToken);
        $identity = ['platform_account_id' => '1000', 'username' => 'owner'];
        $url = '/api/extension/x/assisted/observations';
        $tweet = ['id' => '4100', 'author' => 'owner', 'text' => 'Un post', 'published_at' => now()->toIso8601String(),
            'reply' => false, 'reposted' => false, 'classification_known' => true, 'stats' => "10 J'aime, 18 vues"];
        $other = ['id' => '4101', 'author' => 'other'] + $tweet;
        $this->postJson($url, [
            'followers_observation' => ['value' => 22600, 'precision' => 'rounded', 'display' => '22,6 k Abonnés'],
            'tweets' => [$tweet, $other],
        ] + $identity)->assertOk()->assertJsonPath('followers', false)->assertJsonPath('external_created', 1);
        $this->assertSame(22598, $account->fresh()->followers_count);
        $this->assertDatabaseMissing('external_posts', ['external_id' => '4101']);
        $this->postJson($url, [
            'followers_observation' => ['value' => 22610, 'precision' => 'exact', 'display' => '22 610 Abonnés'],
            'tweets' => [['stats' => '20 vues'] + $tweet],
        ] + $identity)->assertOk()->assertJsonPath('followers', true)->assertJsonPath('external_updated', 1);
        $this->assertSame(22610, $account->fresh()->followers_count);
        $post = \App\Models\ExternalPost::where('external_id', '4100')->firstOrFail();
        $this->assertSame(10, $post->metrics['likes']);
        $this->assertSame(20, $post->metrics['views']);
        $this->postJson($url, ['tweets' => [], 'username' => 'other'] + $identity)->assertConflict();
        $this->assertDatabaseCount('browser_tasks', 0);
    }

    public function test_companion_local_et_hermes_partagent_les_recherches_du_compte(): void
    {
        $platform = Platform::firstOrCreate(['slug' => 'twitter'], ['name' => 'X', 'auth_type' => 'oauth2']);
        $owner = User::factory()->create(['role' => 'manager']);
        $account = SocialAccount::create(['name' => 'X', 'platform_id' => $platform->id, 'platform_account_id' => '1000',
            'browser_username' => 'owner', 'agent_strategy' => [
                'search_queries' => ['Mode durable', 'mode durable', '#Tenue'],
                'hashtags' => ['tenue', '#élégance', '#123', '#deux mots'],
                'target_accounts' => ['@ModePro', 'https://x.com/modepro', '@retirer', 'https://x.com/pro/status/123'],
                'exclusions' => ['@RETIRER', 'spam'],
            ]]);
        $account->users()->attach($owner, ['is_active' => true]);
        $this->withToken($owner->createToken('extension')->plainTextToken);
        $identity = ['platform_account_id' => '1000', 'username' => 'owner'];
        $queries = ['Mode durable', '#Tenue', '#élégance', 'from:modepro'];

        $this->getJson('/api/extension/x/assisted/context?'.http_build_query($identity))
            ->assertOk()->assertJsonPath('queries', $queries);
        $this->getJson('/api/community-manager/accounts')
            ->assertOk()->assertJsonPath('accounts.0.discovery_queries', $queries);
    }

    public function test_assiste_reserve_une_reponse_sans_creer_de_tache_et_bloque_les_doublons(): void
    {
        $platform = Platform::firstOrCreate(['slug' => 'twitter'], ['name' => 'X', 'auth_type' => 'oauth2']);
        $persona = Persona::create(['name' => 'Réponses', 'system_prompt' => 'Aide sans promotion. Réponds dans la langue du message.', 'is_active' => true]);
        $owner = User::factory()->create();
        $account = SocialAccount::create(['name' => 'X', 'platform_id' => $platform->id, 'platform_account_id' => '1000',
            'persona_id' => $persona->id, 'driver' => 'api', 'browser_username' => 'owner', 'languages' => ['fr'],
            'agent_strategy' => ['audience' => 'Pratiquants de musculation', 'search_queries' => ['whey lang:fr'], 'exclusions' => ['concours']]]);
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
        Http::fake(['api.openai.com/*' => fn ($request) => Http::response(['choices' => [['message' => [
            'content' => str_contains($request['messages'][1]['content'], 'parfum')
                ? '{"pertinent":false}'
                : json_encode(['pertinent' => true, 'texte' => str_contains($request['messages'][0]['content'], 'Le premier brouillon dépassait')
                    ? 'Belle progression !' : str_repeat('進', 141)]),
        ]]]])]);
        $this->postJson($url.'preview', ['target_id' => '4102', 'author' => 'visitor', 'text' => 'Mon nouveau parfum'] + $identity)
            ->assertUnprocessable()->assertJsonMissingPath('draft_key');
        $target = ['target_id' => '4100', 'author' => 'visitor', 'text' => 'New deadlift record', 'origin' => 'Training log'] + $identity;
        $key = $this->postJson($url.'preview', $target)->assertOk()->assertJsonPath('reply_text', 'Belle progression !')->json('draft_key');
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request['response_format']['type'] === 'json_object'
            && str_contains($request['messages'][0]['content'], 'Rédige en français')
            && str_contains($request['messages'][0]['content'], 'Le ton vient du persona du compte')
            && ! str_contains($request['messages'][1]['content'], 'proportions similaires')
            && str_contains($request['messages'][1]['content'], 'Pratiquants de musculation'));
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
