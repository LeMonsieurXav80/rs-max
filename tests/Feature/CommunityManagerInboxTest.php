<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Models\Persona;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommunityManagerInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_contexte_persona_scope_aperçu_puis_planification_sans_appel_ia(): void
    {
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\SendAutonomousInboxReply::class]);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-07T12:00:00Z'));
        Http::preventStrayRequests();
        $u = User::factory()->create(['role' => 'manager']);
        $p = Persona::create(['name' => 'Persona associé', 'system_prompt' => 'Ton chaleureux et sobre', 'is_active' => true]);
        $a = SocialAccount::create(['name' => 'Compte attribué', 'platform_id' => Platform::where('slug', 'tiktok')->firstOrFail()->id, 'persona_id' => $p->id, 'driver' => 'browser']);
        $a->users()->attach($u, ['is_active' => true]);
        Sanctum::actingAs($u);
        $i = InboxItem::create(['social_account_id' => $a->id, 'platform_id' => $a->platform_id, 'type' => 'comment', 'external_id' => 'comment-1', 'content' => 'Très jolie paire !', 'post_content' => 'Nouvelle vidéo', 'status' => 'unread', 'posted_at' => now()]);
        $this->getJson('/api/community-manager/accounts/'.$a->id.'/inbox')->assertOk()->assertJsonPath('items.0.post_content', 'Nouvelle vidéo');
        $ctx = $this->getJson('/api/community-manager/inbox/'.$i->id.'/context')->assertOk()->assertJsonPath('persona.instructions', 'Ton chaleureux et sobre')->json('context_id');
        $data = ['context_id' => $ctx, 'reply_text' => 'Merci beaucoup 🖤'];
        $url = '/api/community-manager/inbox/'.$i->id.'/reply';
        $this->postJson($url, $data)->assertOk()->assertJsonPath('dry_run', true);
        $this->assertNull($i->fresh()->reply_scheduled_at);
        $p->update(['system_prompt' => 'Nouveau ton']);
        $this->postJson($url, $data + ['dry_run' => false])->assertConflict();
        $data['context_id'] = $this->getJson('/api/community-manager/inbox/'.$i->id.'/context')->json('context_id');
        $this->postJson($url, $data + ['dry_run' => false])->assertAccepted()->assertJsonPath('validation_required', false)->assertJsonPath('queued', true);
        $this->assertNotNull($i->fresh()->reply_activity_window);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SendAutonomousInboxReply::class);
        $this->assertSame('Merci beaucoup 🖤', $i->fresh()->reply_content);
        $this->assertNull($i->fresh()->reply_draft);
        Http::assertNothingSent();
        $a->users()->detach($u);
        $this->getJson('/api/community-manager/inbox/'.$i->id.'/context')->assertNotFound();
    }

    public function test_budget_api_bloque_avant_envoi(): void
    {
        $u = User::factory()->create();
        $a = SocialAccount::create(['name' => 'Compte API', 'platform_id' => Platform::firstOrCreate(['slug' => 'facebook'], ['name' => 'Facebook', 'auth_type' => 'oauth2'])->id, 'agent_hourly_limit' => 0]);
        $a->users()->attach($u, ['is_active' => true]);
        Sanctum::actingAs($u);
        $i = InboxItem::create(['social_account_id' => $a->id, 'platform_id' => $a->platform_id, 'type' => 'comment', 'external_id' => 'comment-2', 'content' => 'Bonjour', 'status' => 'unread']);
        $network = \Mockery::mock(\App\Services\Inbox\PlatformInboxInterface::class);
        $network->shouldNotReceive('sendReply');
        $this->mock(\App\Services\Inbox\InboxSyncService::class)->shouldReceive('getServiceForAccount')->andReturn($network);
        $this->postJson('/api/inbox/'.$i->id.'/reply', ['reply_text' => 'Bonjour !', 'dry_run' => false])->assertStatus(429);
        $this->assertNull($i->fresh()->replied_at);
    }

    public function test_le_contexte_x_contient_la_publication_origine_et_refuse_un_fil_deja_traite(): void
    {
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\SendAutonomousInboxReply::class]);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-07T12:00:00Z'));
        Http::preventStrayRequests();
        $u = User::factory()->create(['role' => 'manager']);
        $p = Persona::create(['name' => 'Voix associée', 'system_prompt' => 'Chaleureux', 'is_active' => true]);
        $a = SocialAccount::create(['name' => 'Compte attribué', 'platform_id' => Platform::firstOrCreate(['slug' => 'twitter'], ['name' => 'X', 'auth_type' => 'oauth2'])->id,
            'driver' => 'browser', 'browser_username' => 'owner', 'browser_profile' => 'nas-test', 'persona_id' => $p->id]);
        $a->users()->attach($u, ['is_active' => true]);
        Sanctum::actingAs($u);
        $i = InboxItem::create(['social_account_id' => $a->id, 'platform_id' => $a->platform_id, 'type' => 'comment', 'external_id' => '987',
            'author_username' => 'visitor', 'content' => 'Très jolie tenue', 'status' => 'unread']);
        $data = ['target' => ['id' => '987', 'author' => 'visitor', 'text' => 'Très jolie tenue'], 'context_complete' => true,
            'conversation' => [['author' => 'owner', 'text' => 'La robe du jour'], ['author' => 'visitor', 'text' => 'Très jolie tenue']], 'visible_replies' => []];
        $queue = $this->mock(\App\Services\Browser\BrowserTaskQueue::class);
        $queue->shouldReceive('run')->twice()->withArgs(fn ($account, $kind, $payload) => $account->id === $a->id && $kind === 'x.agent.read' && $payload['target_id'] === '987')
            ->andReturn($data, array_replace($data, ['visible_replies' => [['author' => 'owner', 'text' => 'Merci !']]]));
        $url = '/api/community-manager/inbox/'.$i->id.'/context';
        $context = $this->getJson($url)->assertOk()->assertJsonPath('conversation.0.text', 'La robe du jour')->assertJsonPath('persona.instructions', 'Chaleureux')->json('context_id');
        $this->postJson('/api/community-manager/inbox/'.$i->id.'/reply', ['context_id' => $context, 'reply_text' => 'Merci à vous !', 'dry_run' => false])->assertAccepted();
        $this->assertNotNull($i->fresh()->reply_scheduled_at);
        $this->assertSame(0, (int) $i->fresh()->reply_attempts);
        $this->assertSame(0, \App\Models\AccountActionEvent::count());
        $this->getJson($url)->assertConflict();
        Http::assertNothingSent();
    }
}
