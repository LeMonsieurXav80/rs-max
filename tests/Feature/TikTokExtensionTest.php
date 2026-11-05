<?php

namespace Tests\Feature;

use App\Models\Persona;
use App\Models\Platform;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TikTokExtensionTest extends TestCase
{
    use RefreshDatabase;

    private SocialAccount $account;

    private User $user;

    private string $aiReply = 'Merci, je comprends ton point de vue.';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
        $p = Platform::firstOrCreate(['slug' => 'instagram'], ['name' => 'Instagram']);
        $persona = Persona::create(['name' => 'Xavier', 'system_prompt' => 'Tu es Xavier, très oral.', 'is_active' => true]);
        $this->account = SocialAccount::create(['platform_id' => $p->id, 'platform_account_id' => 'xav', 'name' => 'Xavier', 'persona_id' => $persona->id]);
        $this->account->users()->attach($this->user, ['is_active' => true]);
        Setting::setEncrypted('openai_api_key', 'test-only');
        Setting::set('inbox_use_persona', false);
        Http::fake(['api.openai.com/*' => fn () => Http::response(['choices' => [['message' => ['content' => $this->aiReply]]]])]);
    }

    private function bind(): void
    {
        $this->putJson('/api/extension/tiktok/context', ['username' => 'Test.Xav', 'social_account_id' => $this->account->id])->assertOk()->assertJsonPath('persona.name', 'Xavier');
    }

    private function payload(): array
    {
        return ['username' => 'test.xav', 'fingerprint' => hash('sha256', 'test'), 'author' => 'visiteur',
            'content' => 'Belle vidéo !', 'video_context' => 'Une randonnée à Lisbonne.', 'max_length' => 150];
    }

    public function test_context_requires_auth_and_never_exposes_prompt_or_credentials(): void
    {
        $this->getJson('/api/extension/tiktok/context?username=test.xav')->assertOk()->assertJsonCount(1, 'accounts')->assertDontSee('très oral');
        auth()->forgetGuards();
        $this->getJson('/api/extension/tiktok/context?username=test.xav')->assertUnauthorized();
    }

    public function test_profile_is_specific_to_handle_and_account_permissions(): void
    {
        $this->bind();
        $this->getJson('/api/extension/tiktok/context?username=other')->assertOk()->assertJsonPath('persona', null);
        $this->account->users()->updateExistingPivot($this->user->id, ['is_active' => false]);
        $this->getJson('/api/extension/tiktok/context?username=test.xav')->assertForbidden();
        $this->putJson('/api/extension/tiktok/context', ['username' => 'other', 'social_account_id' => $this->account->id])->assertForbidden();
    }

    public function test_persona_is_required_even_with_web_setting_disabled(): void
    {
        $this->bind();
        $this->postJson('/api/extension/tiktok/suggest', $this->payload())->assertOk()->assertJsonPath('persona.name', 'Xavier');
        Http::assertSent(fn ($r) => str_contains($r['messages'][0]['content'], 'Tu es Xavier') && str_contains($r['messages'][0]['content'], '150 caractères') && str_contains($r['messages'][1]['content'], 'randonnée'));
        $this->account->persona->update(['is_active' => false]);
        $this->postJson('/api/extension/tiktok/suggest', $this->payload())->assertUnprocessable();
    }

    public function test_draft_is_reused_but_attempt_is_claimed_once_and_never_retried(): void
    {
        $this->bind();
        $r = $this->postJson('/api/extension/tiktok/suggest', $this->payload())->assertOk()->json();
        $this->postJson('/api/extension/tiktok/suggest', $this->payload())->assertOk()->assertJsonPath('id', $r['id']);
        Http::assertSentCount(1);
        $begin = ['username' => 'test.xav', 'reply_text' => $r['reply_text']];
        $this->postJson('/api/extension/tiktok/'.$r['id'].'/begin', $begin)->assertOk();
        $this->postJson('/api/extension/tiktok/'.$r['id'].'/begin', $begin)->assertConflict();
        $this->postJson('/api/extension/tiktok/suggest', $this->payload())->assertConflict();
        $this->postJson('/api/extension/tiktok/'.$r['id'].'/finish', ['username' => 'test.xav', 'status' => 'uncertain', 'liked' => true, 'proof' => 'Délai dépassé'])->assertOk();
        $this->postJson('/api/extension/tiktok/suggest', $this->payload())->assertConflict();
        $this->assertDatabaseHas('extension_tiktok_comments', ['id' => $r['id'], 'status' => 'uncertain', 'liked' => true]);
    }

    public function test_changed_persona_or_wrong_text_cannot_send_old_draft(): void
    {
        $this->bind();
        $r = $this->postJson('/api/extension/tiktok/suggest', $this->payload())->json();
        $this->postJson('/api/extension/tiktok/'.$r['id'].'/begin', ['username' => 'test.xav', 'reply_text' => 'Autre texte'])->assertConflict();
        $this->account->persona->update(['system_prompt' => 'Nouvelles consignes']);
        $this->postJson('/api/extension/tiktok/'.$r['id'].'/begin', ['username' => 'test.xav', 'reply_text' => $r['reply_text']])->assertConflict();
    }

    public function test_cannot_finish_without_claim_or_for_another_profile(): void
    {
        $this->bind();
        $r = $this->postJson('/api/extension/tiktok/suggest', $this->payload())->json();
        $this->postJson('/api/extension/tiktok/'.$r['id'].'/finish', ['username' => 'test.xav', 'status' => 'published', 'liked' => true, 'proof' => 'Visible'])->assertConflict();
        $this->putJson('/api/extension/tiktok/context', ['username' => 'other', 'social_account_id' => $this->account->id])->assertOk();
        $this->postJson('/api/extension/tiktok/'.$r['id'].'/begin', ['username' => 'other', 'reply_text' => $r['reply_text']])->assertConflict();
    }

    public function test_empty_overlong_or_own_comments_cannot_create_attempt(): void
    {
        $this->bind();
        $data = $this->payload();
        $data['author'] = 'test.xav';
        $this->postJson('/api/extension/tiktok/suggest', $data)->assertConflict();
        $this->aiReply = str_repeat('😀', 76);
        $this->postJson('/api/extension/tiktok/suggest', $this->payload())->assertUnprocessable();
        $this->assertSame(0, DB::table('extension_tiktok_comments')->count());
    }
}
