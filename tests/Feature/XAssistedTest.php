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
            'browser_username' => 'owner', 'languages' => ['fr'], 'agent_strategy' => [
                'reply_language' => 'es',
                'search_queries' => ['Mode durable', 'mode durable lang:fr', '#Tenue', '(style LANG:en)', str_repeat('x', 255)],
                'hashtags' => ['tenue', '#élégance', '#123', '#deux mots'],
                'target_accounts' => ['@ModePro', 'https://x.com/modepro', '@retirer', 'https://x.com/pro/status/123'],
                'exclusions' => ['@RETIRER', 'spam'],
            ]]);
        $account->users()->attach($owner, ['is_active' => true]);
        $this->withToken($owner->createToken('extension')->plainTextToken);
        $identity = ['platform_account_id' => '1000', 'username' => 'owner'];
        $queries = ['Mode durable lang:fr', '#Tenue lang:fr', '(style LANG:en)', str_repeat('x', 255).' lang:fr', '#élégance lang:fr', 'from:modepro lang:fr'];
        $local = '/api/extension/x/assisted/context?'.http_build_query($identity);
        $catalog = '/api/community-manager/accounts';

        $this->getJson($local)->assertOk()->assertJsonPath('queries', $queries)
            ->assertJsonPath('search_language', 'fr')->assertJsonPath('reply_language', 'es');
        $this->getJson($catalog)->assertOk()->assertJsonPath('accounts.0.discovery_queries', $queries)
            ->assertJsonPath('accounts.0.search_language', 'fr');

        $account->update(['languages' => ['fr', 'en']]);
        $multilingual = ['Mode durable', 'mode durable lang:fr', '#Tenue', '(style LANG:en)', str_repeat('x', 255), '#élégance', 'from:modepro'];
        $this->getJson($local)->assertOk()->assertJsonPath('queries', $multilingual)->assertJsonPath('search_language', null);
        $this->getJson($catalog)->assertOk()->assertJsonPath('accounts.0.discovery_queries', $multilingual)
            ->assertJsonPath('accounts.0.search_language', null);
    }

    public function test_le_signet_local_partage_la_reservation_et_exige_une_preuve_apres_rechargement(): void
    {
        $platform = Platform::firstOrCreate(['slug' => 'twitter'], ['name' => 'X', 'auth_type' => 'oauth2']);
        $owner = User::factory()->create(['role' => 'manager']);
        $account = SocialAccount::create(['name' => 'X', 'platform_id' => $platform->id, 'platform_account_id' => '1000',
            'driver' => 'browser', 'browser_profile' => 'test', 'browser_username' => 'owner', 'browser_session_status' => 'connected']);
        $account->users()->attach($owner, ['is_active' => true]);
        $this->withToken($owner->createToken('extension')->plainTextToken);
        $identity = ['platform_account_id' => '1000', 'username' => 'owner'];
        $target = ['target_id' => '4100', 'author' => 'visitor', 'action' => 'bookmark'] + $identity;
        $url = '/api/extension/x/assisted/reactions/';
        $receipt = $this->postJson($url.'reserve', $target)->assertCreated()->assertJsonPath('status', 'running')->json('id');
        $this->postJson($url.'reserve', $target)->assertConflict();
        $confirmation = ['status' => 'confirmed', 'target_id' => '4100'] + $identity;
        $this->postJson($url.$receipt.'/finish', ['proof' => 'visible'] + $confirmation)->assertUnprocessable();
        $this->postJson($url.$receipt.'/finish', ['proof' => 'after_reload', 'target_id' => '4101'] + $confirmation)->assertUnprocessable();
        $this->postJson($url.$receipt.'/finish', ['proof' => 'after_reload'] + $confirmation)->assertOk()->assertJsonPath('status', 'confirmed');
        $this->postJson($url.$receipt.'/finish', ['proof' => 'after_reload'] + $confirmation)->assertOk();
        $this->getJson('/api/community-manager/x/engagements/'.$receipt)
            ->assertOk()->assertJsonPath('confirmed', true)->assertJsonPath('result.proof', 'after_reload');
        $this->assertDatabaseHas('bot_action_logs', ['social_account_id' => $account->id, 'action_type' => 'x.bookmark',
            'source' => 'extension', 'success' => true]);
        $this->assertDatabaseCount('x_engagements', 1);
        $this->assertDatabaseCount('browser_tasks', 0);
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
        $this->freezeSecond();
        $freshId = (now()->subHour()->getTimestampMs() - 1288834974657) << 22;
        $targetId = (string) ($freshId + 1);
        $branchTargetId = (string) ($freshId + 2);
        $offTopicId = (string) ($freshId + 3);
        $this->getJson($url.'context?'.http_build_query($identity))->assertOk()
            ->assertJsonPath('queries.0', 'whey lang:fr')->assertJsonPath('search_language', 'fr');
        $this->postJson($url.'check', ['ids' => [$targetId]] + $identity)->assertOk()->assertJsonPath('blocked', []);
        $this->postJson($url.'check', ['ids' => [$targetId], 'username' => 'wrong'] + $identity)->assertConflict();
        $this->postJson($url.'check', ['ids' => [$targetId], 'platform_account_id' => '999'] + $identity)->assertForbidden();

        Setting::setEncrypted('openai_api_key', 'test-key');
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => fn ($request) => Http::response(['choices' => [['message' => [
            'content' => str_contains($request['messages'][1]['content'], 'parfum')
                ? '{"pertinent":false}'
                : json_encode(['pertinent' => true, 'texte' => str_contains($request['messages'][0]['content'], 'Le premier brouillon dépassait')
                    ? 'Belle progression !' : str_repeat('進', 141)]),
        ]]]])]);
        $branch = [
            'target_id' => $branchTargetId, 'author' => 'visitor', 'text' => 'Merci pour la précision',
            'thread' => [
                ['id' => '4101', 'author' => 'visitor', 'text' => 'Une question sur le squat.'],
                ['id' => '4102', 'author' => 'owner', 'text' => 'Ma réponse.'],
                ['id' => $branchTargetId, 'author' => 'visitor', 'text' => 'Merci pour la précision'],
            ],
        ] + $identity;
        $this->postJson($url.'preview', $branch)->assertConflict();
        $tooOld = (string) ((now()->subRealDays(14)->subSecond()->getTimestampMs() - 1288834974657) << 22);
        $this->postJson($url.'preview', ['target_id' => $tooOld, 'author' => 'visitor', 'text' => 'Quelle charge choisir ?'] + $identity)
            ->assertConflict()->assertJsonPath('message', \App\Services\Agent\CommentFreshness::REJECTION);
        Http::assertNothingSent();

        $this->postJson($url.'preview', ['target_id' => $offTopicId, 'author' => 'visitor', 'text' => 'Mon nouveau parfum'] + $identity)
            ->assertUnprocessable()->assertJsonMissingPath('draft_key');
        $target = ['target_id' => $targetId, 'author' => 'visitor', 'text' => 'New deadlift record', 'origin' => 'Training log'] + $identity;
        $key = $this->postJson($url.'preview', $target)->assertOk()->assertJsonPath('reply_text', 'Belle progression !')->json('draft_key');
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request['response_format']['type'] === 'json_object'
            && str_contains($request['messages'][0]['content'], 'Rédige en français')
            && str_contains($request['messages'][0]['content'], 'Le ton vient du persona du compte')
            && ! str_contains($request['messages'][1]['content'], 'proportions similaires')
            && str_contains($request['messages'][1]['content'], 'Pratiquants de musculation'));
        $branch['text'] = $branch['thread'][2]['text'] = 'Quelle charge pour commencer ?';
        $this->postJson($url.'preview', $branch)->assertOk()->assertJsonPath('thread_root_id', '4101');
        $branch['text'] = $branch['thread'][2]['text'] = null;
        $branch['thread'][2]['quote'] = 'Un exercice présenté dans une publication citée.';
        $this->postJson($url.'preview', $branch)->assertOk()->assertJsonPath('thread_root_id', '4101');
        $this->assertDatabaseCount('extension_x_replies', 0);
        $this->assertDatabaseCount('browser_tasks', 0);

        $receipt = $this->postJson($url.'reserve', ['draft_key' => $key] + $identity)->assertOk()->json('id');
        $this->postJson($url.'reserve', ['draft_key' => $key] + $identity)->assertConflict();
        $this->postJson($url.'preview', $target)->assertConflict();
        $this->postJson($url.'check', ['ids' => [$targetId]] + $identity)->assertOk()->assertJsonPath('blocked', [$targetId]);
        $this->postJson($url."replies/{$receipt}/finish", ['reply_id' => $targetId] + $identity)->assertUnprocessable();
        $this->postJson($url."replies/{$receipt}/finish", ['reply_id' => '4101'] + $identity)->assertOk()->assertJsonPath('status', 'published');
        $this->postJson($url."replies/{$receipt}/finish", ['reply_id' => '4101'] + $identity)->assertOk();
        $this->assertDatabaseHas('extension_x_replies', ['id' => $receipt, 'proof' => 'manual:visible:4101']);

        $edge = (string) ((now()->subRealDays(14)->getTimestampMs() - 1288834974657) << 22);
        $key = $this->postJson($url.'preview', ['target_id' => $edge] + $target)->assertOk()->json('draft_key');
        $this->travel(1)->second();
        $this->postJson($url.'reserve', ['draft_key' => $key] + $identity)->assertConflict()
            ->assertJsonPath('message', \App\Services\Agent\CommentFreshness::REJECTION);
        $this->assertDatabaseCount('extension_x_replies', 1);
        $this->assertDatabaseCount('browser_tasks', 0);
    }
}
