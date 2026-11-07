<?php

namespace Tests\Feature;

use App\Jobs\SendAutonomousInboxReply;
use App\Models\InboxItem;
use App\Models\Persona;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AutonomousInboxFlowTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-07T12:00:00Z'));
        $u = User::factory()->create(['role' => 'manager']);
        $p = Platform::firstOrCreate(['slug' => 'twitter'], ['name' => 'X']);
        $persona = Persona::create(['name' => 'Voix', 'system_prompt' => 'Bienveillant', 'is_active' => true]);
        $a = SocialAccount::create(['platform_id' => $p->id, 'name' => 'Compte', 'driver' => 'browser', 'browser_profile' => 'test', 'browser_username' => 'owner', 'persona_id' => $persona->id, 'agent_hourly_limit' => 120, 'agent_daily_limit' => 400]);
        $a->users()->attach($u, ['is_active' => true]);
        Sanctum::actingAs($u, ['community-manager']);

        return [$a, $u];
    }

    private function item(SocialAccount $a, string $id): InboxItem
    {
        return InboxItem::create(['social_account_id' => $a->id, 'platform_id' => $a->platform_id, 'type' => 'comment', 'external_id' => $id, 'author_username' => 'visitor', 'content' => 'Merci '.$id, 'status' => 'unread', 'posted_at' => now()]);
    }

    public function test_les_commentaires_ecartes_ne_bloquent_pas_les_suivants_et_redeviennent_lisibles(): void
    {
        [$a,$u] = $this->fixture();
        $first = $this->item($a, '123');
        $next = $this->item($a, '124');
        $this->postJson('/api/community-manager/inbox/'.$first->id.'/review', ['reason' => 'not_relevant'])->assertOk();
        $url = '/api/community-manager/accounts/'.$a->id.'/inbox?agent_queue=1';
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $next->id);
        $this->assertSame('unread', $first->fresh()->status);
        $this->assertNull($first->fresh()->replied_at);
        $this->getJson('/api/community-manager/accounts/'.$a->id.'/inbox')->assertOk()->assertJsonCount(2, 'items');
        $this->travel(8)->days();
        $this->getJson($url)->assertOk()->assertJsonCount(2, 'items');
        $other = SocialAccount::create(['name' => 'Autre', 'platform_id' => $a->platform_id]);
        $foreign = $this->item($other, '999');
        $this->postJson('/api/community-manager/inbox/'.$foreign->id.'/review', ['reason' => 'not_relevant'])->assertNotFound();
    }

    public function test_une_reponse_deja_visible_est_ecartee_sans_renvoi_et_sans_bloquer_le_lot(): void
    {
        [$a] = $this->fixture();
        $i = $this->item($a, '123');
        $next = $this->item($a, '124');
        $this->mock(\App\Services\Browser\BrowserTaskQueue::class)->shouldReceive('run')->once()->andReturn(['context_complete' => true, 'target' => ['id' => '123', 'author' => 'visitor', 'text' => $i->content],
            'conversation' => [['author' => 'owner', 'text' => 'Original'], ['author' => 'visitor', 'text' => $i->content]], 'visible_replies' => [['author' => 'owner', 'id' => '456', 'text' => 'Déjà répondu']]]);
        $this->getJson('/api/community-manager/inbox/'.$i->id.'/context')->assertConflict()->assertJsonPath('skip_item', true)->assertJsonPath('review_reason', 'already_handled');
        $this->getJson('/api/community-manager/accounts/'.$a->id.'/inbox?agent_queue=1')->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $next->id);
        $this->assertNull($i->fresh()->reply_scheduled_at);
        $this->assertSame(0, (int) $i->fresh()->reply_attempts);
    }

    public function test_reponse_autonome_sauvegardee_et_envoi_mis_en_file_sans_validation(): void
    {
        [$a] = $this->fixture();
        $i = $this->item($a, '123');
        Queue::fake([SendAutonomousInboxReply::class]);
        $this->mock(\App\Services\Browser\BrowserTaskQueue::class)->shouldReceive('run')->once()->andReturn(['context_complete' => true, 'target' => ['id' => '123', 'author' => 'visitor', 'text' => $i->content],
            'conversation' => [['author' => 'owner', 'text' => 'Original'], ['author' => 'visitor', 'text' => $i->content]], 'visible_replies' => []]);
        $context = $this->getJson('/api/community-manager/inbox/'.$i->id.'/context')->json('context_id');
        $data = ['context_id' => $context, 'reply_text' => 'Merci beaucoup 🖤', 'dry_run' => false];
        $this->postJson('/api/community-manager/inbox/'.$i->id.'/reply', $data)->assertAccepted()->assertJsonPath('queued', true)->assertJsonPath('validation_required', false);
        $this->assertSame('Merci beaucoup 🖤', $i->fresh()->reply_content);
        $this->assertNull($i->fresh()->reply_draft);
        Queue::assertPushed(SendAutonomousInboxReply::class, fn ($job) => $job->itemId === $i->id);
        Queue::assertPushed(SendAutonomousInboxReply::class, 1);
        $this->postJson('/api/community-manager/inbox/'.$i->id.'/reply', $data)->assertAccepted();
        Queue::assertPushed(SendAutonomousInboxReply::class,1);
    }
}
