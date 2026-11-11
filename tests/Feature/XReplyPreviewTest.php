<?php

namespace Tests\Feature;

use App\Models\Persona;
use App\Models\Platform;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class XReplyPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_hermes_et_companion_lisent_le_meme_corpus_sans_creer_une_action(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $persona = Persona::create(['name' => 'Persona X', 'system_prompt' => 'Aide sans promotion DIX.', 'is_active' => true]);
        $platform = Platform::firstOrCreate(['slug' => 'twitter'], ['name' => 'X', 'auth_type' => 'oauth2']);
        $account = SocialAccount::create(['name' => 'X', 'platform_id' => $platform->id, 'platform_account_id' => '12345',
            'driver' => 'api', 'browser_username' => 'owner', 'persona_id' => $persona->id]);
        $other = SocialAccount::create(['name' => 'Autre', 'platform_id' => $platform->id, 'platform_account_id' => '12346',
            'driver' => 'api', 'browser_username' => 'other', 'persona_id' => $persona->id]);
        $account->users()->attach($manager, ['is_active' => true]);
        Setting::setEncrypted('openai_api_key', 'test-key');

        $this->artisan('x:configure-reply-vault', ['corpus' => 'test', '--account' => [$account->id], '--brand' => ['DIX']])->assertSuccessful();

        Http::preventStrayRequests();
        Http::fake([
            'http://localhost/lookup' => fn (Request $request) => Http::response([
                'revision' => '123abcd',
                'sources' => str_contains($request['target'], 'créatine')
                    ? [['pmid' => '40265319', 'title' => 'Does creatine cause hair loss?', 'excerpt' => 'Essai contrôlé sur la créatine et la chute des cheveux.']]
                    : [],
            ]),
            'api.openai.com/*' => fn (Request $request) => Http::response(['choices' => [['message' => [
                'content' => str_contains($request['messages'][1]['content'], 'Brouillon volontairement hors sujet')
                    ? 'Chez DIX, nous avons une solution.' : 'Cet essai n’a pas montré d’effet sur les cheveux.',
            ]]]]),
        ]);
        $agent = $manager->createToken('test-agent', ['community-manager'])->plainTextToken;
        $extension = $manager->createToken('test-extension')->plainTextToken;
        $target = ['text' => 'La créatine donne-t-elle une chute de cheveux ?'];
        $agentPath = "/api/community-manager/accounts/{$account->id}/x/reply-preview";
        $extensionPath = '/api/extension/x/reply-preview';
        $identity = ['platform_account_id' => '12345', 'username' => 'owner'];

        $this->withToken($agent)->postJson($agentPath, $target)->assertOk()
            ->assertJsonPath('status', 'preview')->assertJsonPath('sources.0.pmid', '40265319')->assertJsonCount(1, 'sources')
            ->assertJsonMissingPath('id');
        $this->withToken($agent)->postJson($extensionPath, $target + $identity)->assertForbidden();
        $this->withToken($agent)->postJson("/api/community-manager/accounts/{$other->id}/x/reply-preview", $target)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->withToken($extension)->postJson($extensionPath, $target + $identity)->assertOk()
            ->assertJsonPath('sources.0.url', 'https://pubmed.ncbi.nlm.nih.gov/40265319/')->assertJsonPath('vault_revision', '123abcd');
        $this->withToken($extension)->postJson($extensionPath, $target + ['username' => 'wrong'] + $identity)->assertConflict();
        $this->withToken($extension)->postJson($extensionPath, ['text' => 'Record perso au squat !'] + $identity)->assertOk()
            ->assertJsonCount(0, 'sources');

        $thread = [
            ['id' => '100', 'author' => 'coach', 'text' => 'Est-ce que la créatine change la force ?'],
            ['id' => '200', 'author' => 'athlete', 'text' => 'Pour moi la question concerne les cheveux.', 'quote' => 'Une étude ancienne.'],
            ['id' => '300', 'author' => 'visitor', 'text' => 'C’était à quelle dose ?'],
        ];
        $branch = ['text' => $thread[2]['text'], 'target_id' => '300', 'author' => 'visitor', 'thread' => $thread] + $identity;
        $this->withToken($extension)->postJson($extensionPath, $branch)->assertOk()->assertJsonPath('thread_root_id', '100');
        $this->withToken($extension)->postJson($extensionPath, ['target_id' => '100'] + $branch)->assertUnprocessable();
        $this->withToken($extension)->postJson($extensionPath, ['author' => 'coach'] + $branch)->assertUnprocessable();
        Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost/lookup'
            && str_contains($request['origin'] ?? '', 'les cheveux') && str_contains($request['origin'] ?? '', 'la force'));
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.openai.com/')
            && str_contains($request['messages'][1]['content'], '"publication_origine":{"id":"100","author":"coach"')
            && str_contains($request['messages'][1]['content'], 'Une étude ancienne')
            && str_contains($request['messages'][1]['content'], '"publication_cible":{"id":"300","author":"visitor"'));

        $this->withToken($extension)->postJson($extensionPath, ['text' => 'Brouillon volontairement hors sujet.'] + $identity)
            ->assertUnprocessable()->assertJsonPath('message', 'Mention de marque écartée par le filtre de relecture.');

        Http::assertSent(fn (Request $request) => $request->url() !== 'http://localhost/lookup' && str_contains($request['messages'][0]['content'], 'sans promotion')
            && str_contains($request['messages'][1]['content'], '40265319'));
        $this->assertDatabaseCount('browser_tasks', 0);
        $this->assertDatabaseCount('extension_x_replies', 0);
        $this->assertDatabaseCount('x_engagements', 0);
    }
}
