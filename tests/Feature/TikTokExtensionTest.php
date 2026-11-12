<?php

namespace Tests\Feature;

use App\Models\BotActionLog;
use App\Models\BrowserTask;
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
        $this->assertDatabaseCount('inbox_items', 0);
    }

    public function test_inbox_task_uses_the_same_reservation_with_the_approved_text(): void
    {
        $this->bind();
        $platform = Platform::firstOrCreate(['slug' => 'tiktok'], ['name' => 'TikTok']);
        $account = SocialAccount::create(['platform_id' => $platform->id, 'name' => 'TikTok', 'driver' => 'browser', 'browser_username' => 'test.xav']);
        $account->users()->attach($this->user, ['is_active' => true]);
        $task = BrowserTask::create(['social_account_id' => $account->id, 'kind' => 'tiktok.reply', 'profile' => 'nas', 'status' => BrowserTask::RUNNING,
            'payload' => ['username' => 'test.xav', 'text' => 'Merci !', 'target' => ['author' => 'visiteur', 'content' => 'Belle vidéo !', 'caption' => 'Une randonnée à Lisbonne.']]]);
        $payload = $this->payload() + ['browser_task_id' => $task->id];

        $this->postJson('/api/extension/tiktok/suggest', $payload)->assertForbidden();
        $this->user->update(['role' => 'admin']);
        $this->postJson('/api/extension/tiktok/suggest', array_replace($payload, ['content' => 'Autre commentaire']))->assertConflict();
        $reply = $this->postJson('/api/extension/tiktok/suggest', $payload)->assertOk()->assertJsonPath('reply_text', 'Merci !')->json();
        Http::assertNothingSent();

        $begin = ['username' => 'test.xav', 'reply_text' => $reply['reply_text']];
        $this->postJson('/api/extension/tiktok/'.$reply['id'].'/begin', $begin)->assertOk();
        $this->postJson('/api/extension/tiktok/'.$reply['id'].'/begin', $begin)->assertConflict();
        $this->postJson('/api/extension/tiktok/'.$reply['id'].'/finish', $begin + ['status' => 'published', 'liked' => false, 'proof' => 'Texte et auteur vérifiés après rechargement'])->assertOk();
        $this->assertDatabaseHas('extension_tiktok_comments', ['id' => $reply['id'], 'status' => 'published']);
        $this->assertDatabaseMissing('bot_action_logs', ['source' => 'extension', 'action_type' => 'tiktok.reply']);
    }

    public function test_a_local_reply_is_recorded_in_the_tiktok_inbox_even_before_import(): void
    {
        $this->user->update(['role' => 'admin']);
        $this->bind();
        $platform = Platform::firstOrCreate(['slug' => 'tiktok'], ['name' => 'TikTok']);
        $account = SocialAccount::create(['platform_id' => $platform->id, 'name' => 'TikTok', 'browser_username' => 'test.xav']);
        $account->users()->attach($this->user, ['is_active' => true]);

        $data = $this->payload();
        $draft = $this->postJson('/api/extension/tiktok/suggest', $data)->assertOk()->json();
        $begin = ['username' => 'test.xav', 'reply_text' => $draft['reply_text']];
        $this->postJson('/api/extension/tiktok/'.$draft['id'].'/begin', $begin)->assertOk();

        $other = $this->postJson('/api/extension/tiktok/suggest', array_replace($data, ['fingerprint' => hash('sha256', 'different-image')]))->assertOk()->json();
        $this->postJson('/api/extension/tiktok/'.$other['id'].'/begin', $begin)->assertConflict();
        $this->postJson('/api/extension/tiktok/'.$draft['id'].'/finish', $begin + [
            'channel' => 'local', 'status' => 'published', 'liked' => true, 'proof' => 'Réponse exacte visible',
        ])->assertOk();
        $this->postJson('/api/extension/tiktok/'.$other['id'].'/begin', $begin)->assertConflict();

        $item = \App\Models\InboxItem::sole();
        $this->assertSame($account->id, $item->social_account_id);
        $this->assertSame('replied', $item->status);
        $this->assertSame($draft['reply_text'], $item->reply_content);
        $this->assertNotNull($item->replied_at);
        $this->assertNotNull($item->liked_at);
        $log = BotActionLog::where('action_type', 'tiktok.reply')->sole();
        $this->assertSame($account->id, $log->social_account_id);
        $this->assertSame('extension', $log->source);
        $this->assertSame('confirmed', $log->metadata['status']);
        $this->assertTrue($log->metadata['proof']['liked']);
        $this->actingAs($this->user)->get('/agent/tiktok?source=extension')->assertOk()
            ->assertSee('Companion · local')->assertSee($draft['reply_text']);
        $this->getJson('/api/community-manager/accounts/'.$account->id.'/inbox?agent_queue=1')->assertOk()->assertJsonPath('pagination.total', 0);
    }

    public function test_local_reply_prompt_uses_the_caption_thread_target_and_persona(): void
    {
        $this->bind();
        $data = $this->payload();
        $this->postJson('/api/extension/tiktok/suggest', $data)->assertOk();
        $data['content'] = 'Où exactement ?';
        $data['fingerprint'] = hash('sha256', 'followup');
        $data['conversation'] = [
            ['author' => 'visiteur', 'content' => 'Belle vidéo !'],
            ['author' => 'test.xav', 'content' => 'C’est au Portugal.', 'reply_to' => 'visiteur'],
            ['author' => 'visiteur', 'content' => $data['content'], 'reply_to' => 'test.xav'],
        ];
        $draft = $this->postJson('/api/extension/tiktok/suggest', $data)->assertOk()->json();
        $this->aiReply = 'À Lisbonne !';
        $this->postJson('/api/extension/tiktok/suggest', $data)->assertOk()
            ->assertJsonPath('id', $draft['id'])->assertJsonPath('reply_text', $this->aiReply);
        Http::assertSent(fn ($request) => str_contains($request['messages'][0]['content'], 'Tu es Xavier')
            && str_contains($request['messages'][0]['content'], 'Réponds uniquement à ce dernier')
            && str_contains($request['messages'][1]['content'], 'publication_origine')
            && str_contains($request['messages'][1]['content'], 'C’est au Portugal.')
            && str_contains($request['messages'][1]['content'], 'Où exactement ?'));

        $data['content'] = 'Merci !';
        $this->postJson('/api/extension/tiktok/suggest', $data)->assertUnprocessable();
        $data['conversation'][2]['content'] = $data['content'];
        $this->postJson('/api/extension/tiktok/suggest', $data)->assertConflict();
    }

    public function test_local_likes_are_journaled_once_on_the_tiktok_account_without_a_persona(): void
    {
        $this->user->update(['role' => 'admin']);
        $platform = Platform::firstOrCreate(['slug' => 'tiktok'], ['name' => 'TikTok']);
        $account = SocialAccount::create(['platform_id' => $platform->id, 'name' => 'TikTok', 'browser_username' => 'test.xav']);
        $payload = ['username' => 'test.xav', 'author' => 'visiteur', 'fingerprint' => hash('sha256', 'commentaire')];
        $this->postJson('/api/extension/tiktok/like', $payload)->assertUnprocessable();
        $account->users()->attach($this->user, ['is_active' => true]);
        $this->postJson('/api/extension/tiktok/like', $payload)->assertOk()->assertJsonPath('status', 'confirmed');
        $this->postJson('/api/extension/tiktok/like', $payload)->assertOk();
        $log = BotActionLog::sole();
        $this->assertSame($account->id, $log->social_account_id);
        $this->assertSame('tiktok.like', $log->action_type);
        $this->assertSame('extension', $log->source);
        $this->assertSame('confirmed', $log->metadata['status']);
        $this->assertTrue($log->metadata['proof']['liked']);
        $this->assertDatabaseCount('extension_tiktok_comments', 0);
        Http::assertNothingSent();
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

    public function test_un_remerciement_local_est_reserve_au_like_et_le_style_x_s_applique(): void
    {
        $this->bind();
        $data = ['content' => 'Merci beaucoup !'] + $this->payload();
        $this->postJson('/api/extension/tiktok/suggest', $data)->assertConflict()->assertJsonPath('like_only', true);
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('extension_tiktok_comments')->count());
        foreach (['😍😍😍[J\'aime]', ''] as $n => $content) {
            $smile = $this->postJson('/api/extension/tiktok/suggest', ['content' => $content, 'fingerprint' => hash('sha256', 'emoji'.$n)] + $this->payload())->assertOk()->json('reply_text');
            $this->assertContains($smile, \App\Services\Inbox\InboxFollowup::SMILES);
        }
        Http::assertNothingSent();
        \Illuminate\Support\Facades\DB::table('extension_tiktok_comments')->delete();

        $this->aiReply = 'Merci, tu es adorable 🥰';
        $data = ['fingerprint' => hash('sha256', 'style')] + $this->payload();
        $this->postJson('/api/extension/tiktok/suggest', $data)->assertUnprocessable();
        Http::assertSentCount(2);
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('extension_tiktok_comments')->count());
    }

    public function test_en_local_une_reponse_confirmee_est_sautee_mais_une_reponse_retenue_reste_ouverte(): void
    {
        $tiktok = SocialAccount::create(['platform_id' => Platform::firstOrCreate(['slug' => 'tiktok'], ['name' => 'TikTok'])->id,
            'name' => 'Compte TikTok', 'browser_username' => 'test.xav', 'persona_id' => $this->account->persona_id]);
        $tiktok->users()->attach($this->user, ['is_active' => true]);
        $this->bind();
        $data = $this->payload();
        $item = \App\Models\InboxItem::create(['social_account_id' => $tiktok->id, 'platform_id' => $tiktok->platform_id, 'type' => 'comment',
            'external_id' => \App\Services\Inbox\TikTokInboxService::commentId($tiktok, $data['author'], $data['content'], $data['video_context']),
            'author_username' => $data['author'], 'content' => $data['content'], 'post_content' => $data['video_context'],
            'status' => 'reply_failed', 'reply_attempts' => 3, 'reply_content' => 'Merci !']);
        $this->postJson('/api/extension/tiktok/suggest', $data)->assertOk();

        $item->update(['status' => 'replied', 'replied_at' => now()]);
        $this->postJson('/api/extension/tiktok/suggest', ['fingerprint' => hash('sha256', 'autre')] + $data)->assertConflict();
    }
}
