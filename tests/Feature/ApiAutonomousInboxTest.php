<?php

namespace Tests\Feature;

use App\Jobs\LikeInboxItem;
use App\Jobs\SendAutonomousInboxReply;
use App\Models\AccountActionEvent;
use App\Models\InboxItem;
use App\Models\Persona;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Agent\AgentCatalog;
use App\Services\Inbox\InboxReplyService;
use App\Services\Inbox\InboxSyncService;
use App\Services\Inbox\PlatformInboxInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiAutonomousInboxTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $slug = 'facebook'): array
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-08T12:00:00Z'));
        $user = User::factory()->create(['role' => 'manager']);
        $persona = Persona::create(['name' => 'Voix', 'system_prompt' => 'Répondre chaleureusement sans inventer.', 'is_active' => true]);
        $account = SocialAccount::create(['name' => 'Compte attribué', 'platform_id' => Platform::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)])->id, 'platform_account_id' => 'owner', 'persona_id' => $persona->id, 'driver' => 'api', 'credentials' => ['access_token' => 'test'], 'agent_hourly_limit' => 20, 'agent_daily_limit' => 100]);
        $account->users()->attach($user, ['is_active' => true]);
        $account->agentActions()->create(['action' => 'reply', 'enabled' => true, 'daily_limit' => 2]);
        if ($slug === 'facebook') {
            $account->agentActions()->create(['action' => 'comment.like', 'enabled' => true, 'daily_limit' => 2]);
        }
        $item = InboxItem::create(['social_account_id' => $account->id, 'platform_id' => $account->platform_id, 'type' => 'comment', 'external_id' => 'visitor-comment', 'content' => 'Très jolie tenue !', 'post_content' => 'La tenue du jour', 'status' => 'unread', 'posted_at' => now()]);
        Sanctum::actingAs($user, ['community-manager']);
        Queue::fake([SendAutonomousInboxReply::class, LikeInboxItem::class]);

        return [$account, $item];
    }

    public function test_les_quatre_api_sont_mises_en_file_sans_validation_et_comptees_une_seule_fois(): void
    {
        foreach (['facebook', 'instagram', 'threads', 'youtube'] as $slug) {
            [$account, $item] = $this->fixture($slug);
            $api = \Mockery::mock(PlatformInboxInterface::class);
            $api->shouldReceive('sendReply')->once()->andReturn(['success' => true, 'external_id' => 'receipt-'.$slug]);
            $this->mock(InboxSyncService::class)->shouldReceive('getServiceForAccount')->once()->andReturn($api);
            $context = $this->getJson('/api/community-manager/inbox/'.$item->id.'/context')->assertOk()->assertJsonPath('conversation.0.text', 'La tenue du jour')->json('context_id');
            $url = '/api/community-manager/inbox/'.$item->id.'/reply';
            $body = ['context_id' => $context, 'reply_text' => 'Merci beaucoup !', 'dry_run' => false];
            $this->postJson($url, $body)->assertAccepted()->assertJsonPath('validation_required', false);
            $this->postJson($url, $body)->assertAccepted();
            (new SendAutonomousInboxReply($item->id))->handle(app(InboxReplyService::class));
            $this->assertNotNull($item->fresh()->replied_at);
            $this->assertSame(1, AccountActionEvent::where('social_account_id', $account->id)->count());
            $this->assertSame(1, app(AgentCatalog::class)->usedToday($account, 'reply'));
            (new SendAutonomousInboxReply($item->id))->handle(app(InboxReplyService::class));
            if ($slug === 'facebook') {
                Queue::assertPushed(LikeInboxItem::class, fn ($job) => $job->itemId === $item->id);
            }
        }
    }

    public function test_desactivation_et_quota_refusent_avant_envoi(): void
    {
        [$a, $i] = $this->fixture('instagram');
        $context = $this->getJson('/api/community-manager/inbox/'.$i->id.'/context')->json('context_id');
        $body = ['context_id' => $context, 'reply_text' => 'Merci', 'dry_run' => false];
        $a->agentActions()->where('action', 'reply')->update(['enabled' => false]);
        $this->postJson('/api/community-manager/inbox/'.$i->id.'/reply', $body)->assertForbidden();
        $a->agentActions()->where('action', 'reply')->update(['enabled' => true, 'daily_limit' => 1]);
        AccountActionEvent::create(['social_account_id' => $a->id, 'action' => 'instagram.reply', 'executor' => 'api', 'target' => 'prior', 'status' => 'uncertain']);
        \App\Models\BotActionLog::query()->delete();
        $this->postJson('/api/community-manager/inbox/'.$i->id.'/reply', $body)->assertStatus(429);
        Queue::assertNotPushed(SendAutonomousInboxReply::class);
    }

    public function test_exception_api_interdit_un_second_envoi_et_conserve_le_texte(): void
    {
        [$a,$i] = $this->fixture('threads');
        $api = \Mockery::mock(PlatformInboxInterface::class);
        $api->shouldReceive('sendReply')->once()->andThrow(new \RuntimeException('Délai réseau'));
        $this->mock(InboxSyncService::class)->shouldReceive('getServiceForAccount')->once()->andReturn($api);
        $context = $this->getJson('/api/community-manager/inbox/'.$i->id.'/context')->json('context_id');
        $this->postJson('/api/community-manager/inbox/'.$i->id.'/reply', ['context_id' => $context, 'reply_text' => 'Merci !', 'dry_run' => false])->assertAccepted();
        $job = new SendAutonomousInboxReply($i->id);
        $job->handle(app(InboxReplyService::class));
        $job->handle(app(InboxReplyService::class));
        $this->assertSame(3, (int) $i->fresh()->reply_attempts);
        $this->assertNull($i->fresh()->reply_scheduled_at);
        $this->assertSame('Merci !', $i->fresh()->reply_content);
        $this->assertSame('uncertain', AccountActionEvent::first()->status);
    }

    public function test_like_facebook_relecture_et_aucun_second_post(): void
    {
        [$a,$i] = $this->fixture();
        Http::fake(['*' => Http::sequence()->push(['id' => 'visitor-comment', 'user_likes' => false])->push(['success' => true])->push(['id' => 'visitor-comment', 'user_likes' => true])]);
        $url = '/api/community-manager/inbox/'.$i->id.'/like';
        $this->postJson($url)->assertOk()->assertJsonPath('dry_run', true);
        Http::assertNothingSent();
        $this->postJson($url, ['dry_run' => false])->assertOk()->assertJsonPath('confirmed', true);
        $this->postJson($url, ['dry_run' => false])->assertOk()->assertJsonPath('unchanged', true);
        Http::assertSentCount(3);
        $this->assertNotNull($i->fresh()->liked_at);
        $this->assertSame(1, AccountActionEvent::where('action', 'facebook.comment.like')->count());
    }

    public function test_like_facebook_incertain_ne_se_relance_pas(): void
    {
        [$a,$i] = $this->fixture();
        Http::fake(['*' => Http::sequence()->push(['id' => 'visitor-comment', 'user_likes' => false])->push(['success' => true])->push(['id' => 'visitor-comment', 'user_likes' => false])]);
        $url = '/api/community-manager/inbox/'.$i->id.'/like';
        $this->postJson($url, ['dry_run' => false])->assertOk()->assertJsonPath('uncertain', true);
        $this->postJson($url, ['dry_run' => false])->assertConflict();
        Http::assertSentCount(3);
    }
}
