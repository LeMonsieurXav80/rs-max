<?php

namespace Tests\Feature;

use App\Models\Persona;
use App\Models\Platform;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class XExtensionTest extends TestCase
{
    use RefreshDatabase;

    private SocialAccount $account;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
        $p = Platform::firstOrCreate(['slug' => 'twitter'], ['name' => 'X']);
        $persona = Persona::create(['name' => 'Xavier', 'system_prompt' => 'Tu es Xavier.', 'is_active' => true]);
        $this->account = SocialAccount::create(['platform_id' => $p->id, 'platform_account_id' => '77', 'name' => 'Xavier', 'persona_id' => $persona->id]);
        $this->account->users()->attach($this->user, ['is_active' => true]);
        Setting::setEncrypted('openai_api_key', 'test-only');
        Setting::set('inbox_use_persona', false);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'Merci pour ton retour !']]]])]);
    }

    private function bind(): void
    {
        $this->putJson('/api/extension/x/context', ['username' => 'test_xav', 'platform_account_id' => '77', 'social_account_id' => $this->account->id])->assertOk();
    }

    private function data(): array
    {
        return ['username' => 'test_xav', 'platform_account_id' => '77', 'tweet_id' => '300', 'author' => 'visiteur', 'content' => 'Bien trouvé !', 'root_id' => '100', 'context_complete' => true,
            'conversation' => [['id' => '100', 'author' => 'original', 'text' => 'Un sujet précis qui donne le contexte.'],
                ['id' => '200', 'author' => 'test_xav', 'text' => 'Ma réponse intermédiaire.'],
                ['id' => '300', 'author' => 'visiteur', 'text' => 'Bien trouvé !']]];
    }

    public function test_native_identity_resolves_only_an_authorized_x_account_without_writing(): void
    {
        $this->getJson('/api/extension/x/context?username=test_xav&platform_account_id=77')->assertOk()->assertJsonPath('account_id', $this->account->id)->assertJsonPath('bound', false)->assertDontSee('Tu es Xavier');
        $this->assertDatabaseCount('extension_x_profiles', 0);
        $this->account->users()->updateExistingPivot($this->user->id, ['is_active' => false]);
        $this->getJson('/api/extension/x/context?username=test_xav&platform_account_id=77')->assertOk()->assertJsonPath('account_id', null);
        $this->putJson('/api/extension/x/context', ['username' => 'test_xav', 'social_account_id' => $this->account->id])->assertForbidden();
    }

    public function test_wrong_identity_or_other_platform_cannot_bind(): void
    {
        $this->putJson('/api/extension/x/context', ['username' => 'test_xav', 'platform_account_id' => '99', 'social_account_id' => $this->account->id])->assertConflict();
        $other = Platform::firstOrCreate(['slug' => 'instagram'], ['name' => 'Instagram']);
        $this->account->update(['platform_id' => $other->id]);
        $this->putJson('/api/extension/x/context', ['username' => 'test_xav', 'social_account_id' => $this->account->id])->assertForbidden();
    }

    public function test_original_intermediate_tweet_and_persona_are_in_the_generation(): void
    {
        $this->bind();
        $this->postJson('/api/extension/x/suggest', $this->data())->assertOk()->assertJsonPath('persona.name', 'Xavier');
        Http::assertSent(fn ($r) => str_contains($r['messages'][0]['content'], 'Tu es Xavier') && str_contains($r['messages'][0]['content'], 'Réponse X') && str_contains($r['messages'][1]['content'], 'sujet précis') && str_contains($r['messages'][1]['content'], 'intermédiaire'));
    }

    public function test_missing_original_or_inconsistent_target_cannot_generate(): void
    {
        $this->bind();
        $d = $this->data();
        $d['context_complete'] = false;
        $this->postJson('/api/extension/x/suggest', $d)->assertUnprocessable();
        $d = $this->data();
        $d['root_id'] = '99';
        $this->postJson('/api/extension/x/suggest', $d)->assertUnprocessable();
        $d = $this->data();
        $d['conversation'][2]['text'] = 'Autre mention';
        $this->postJson('/api/extension/x/suggest', $d)->assertUnprocessable();
        $d = $this->data();
        $d['conversation'][0]['text'] = '';
        $this->postJson('/api/extension/x/suggest', $d)->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_draft_reuse_and_single_claim_survive_a_different_handle_alias(): void
    {
        $this->bind();
        $draft = $this->postJson('/api/extension/x/suggest', $this->data())->assertOk()->json();
        $this->postJson('/api/extension/x/suggest', $this->data())->assertOk()->assertJsonPath('id', $draft['id']);
        Http::assertSentCount(1);
        $b = ['username' => 'test_xav', 'reply_text' => $draft['reply_text']];
        $this->postJson('/api/extension/x/'.$draft['id'].'/begin', $b)->assertOk();
        $this->postJson('/api/extension/x/'.$draft['id'].'/begin', $b)->assertConflict();
        $this->putJson('/api/extension/x/context', ['username' => 'alias_xav', 'social_account_id' => $this->account->id])->assertOk();
        $d = $this->data();
        $d['username'] = 'alias_xav';
        $this->postJson('/api/extension/x/suggest', $d)->assertConflict();
        $this->postJson('/api/extension/x/'.$draft['id'].'/finish', ['username' => 'test_xav', 'status' => 'uncertain', 'liked' => true, 'proof' => 'Non confirmé'])->assertOk();
        $this->postJson('/api/extension/x/suggest', $this->data())->assertConflict();
    }

    public function test_changed_context_or_persona_cannot_send_stale_reply(): void
    {
        $this->bind();
        $draft = $this->postJson('/api/extension/x/suggest', $this->data())->json();
        $d = $this->data();
        $d['conversation'][0]['text'] = 'Contexte corrigé';
        $this->postJson('/api/extension/x/suggest', $d)->assertConflict();
        $this->account->persona->update(['system_prompt' => 'Autre persona']);
        $this->postJson('/api/extension/x/'.$draft['id'].'/begin', ['username' => 'test_xav', 'reply_text' => $draft['reply_text']])->assertConflict();
    }
}
