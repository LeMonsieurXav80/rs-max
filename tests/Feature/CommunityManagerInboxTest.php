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
        $this->postJson($url, $data + ['dry_run' => false])->assertAccepted();
        $this->assertNotNull($i->fresh()->reply_activity_window);
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
}
