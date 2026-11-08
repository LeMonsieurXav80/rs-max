<?php

namespace Tests\Feature;

use App\Jobs\LikeInboxItem;
use App\Models\AccountActionEvent;
use App\Models\BrowserTask;
use App\Models\InboxItem;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TikTokReplyReconcilerTest extends TestCase
{
    use RefreshDatabase;

    private InboxItem $item;

    private BrowserTask $task;

    private BrowserTask $verification;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-08T12:00:00Z'));
        Bus::fake([LikeInboxItem::class]);
        $u = User::factory()->create(['role' => 'manager']);
        $a = SocialAccount::create(['platform_id' => Platform::where('slug', 'tiktok')->firstOrFail()->id, 'name' => 'Compte attribué', 'driver' => 'browser', 'browser_profile' => 'nas-test', 'browser_username' => 'owner']);
        $a->users()->attach($u, ['is_active' => true]);
        Sanctum::actingAs($u);
        $this->item = InboxItem::create(['social_account_id' => $a->id, 'platform_id' => $a->platform_id, 'external_id' => 'empreinte', 'type' => 'comment', 'author_username' => 'visitor', 'content' => 'Très jolie !', 'post_content' => 'Tenue du jour', 'reply_content' => 'Merci 🌹', 'status' => 'reply_failed', 'reply_attempts' => 3]);
        $payload = ['url' => 'https://www.tiktok.com/tiktokstudio/comment', 'username' => 'owner', 'text' => 'Merci 🌹', 'target' => ['author' => 'visitor', 'content' => 'Très jolie !', 'caption' => 'Tenue du jour']];
        $this->task = BrowserTask::create(['social_account_id' => $a->id, 'profile' => 'nas-test', 'kind' => 'tiktok.reply', 'status' => 'uncertain', 'payload' => $payload, 'claimed_at' => now()->subMinutes(4), 'finished_at' => now()->subMinutes(2), 'result' => ['send_clicked' => true], 'error' => 'Filtre non chargé']);
        AccountActionEvent::create(['social_account_id' => $a->id, 'browser_task_id' => $this->task->id, 'action' => 'tiktok.reply', 'executor' => 'browser', 'status' => 'uncertain']);
        $this->verification = BrowserTask::create(['social_account_id' => $a->id, 'profile' => 'nas-test', 'kind' => 'tiktok.verify', 'status' => 'done', 'payload' => $payload + ['action' => 'tiktok.reply'], 'claimed_at' => now()->subMinute(), 'finished_at' => now(), 'result' => ['confirmed' => true, 'proof' => 'after_reload', 'verified_kind' => 'tiktok.reply']]);
    }

    private function url(): string
    {
        return '/api/community-manager/inbox/'.$this->item->id;
    }

    public function test_preuve_exacte_reconcilie_une_seule_fois_et_ne_declenche_que_le_like(): void
    {
        $data = ['verification_task_id' => $this->verification->id];
        $this->postJson($this->url().'/reconcile', $data)->assertOk()->assertJsonPath('dry_run', true);
        $this->assertSame('uncertain', $this->task->fresh()->status);
        Bus::assertNothingDispatched();
        $this->postJson($this->url().'/reconcile', $data + ['dry_run' => false])->assertOk()->assertJsonPath('proof.confirmed', true);
        $this->assertSame('done', $this->task->fresh()->status);
        $this->assertSame('replied', $this->item->fresh()->status);
        $this->assertSame('confirmed', AccountActionEvent::first()->status);
        $this->assertSame(2, BrowserTask::count());
        $this->assertSame(1, AccountActionEvent::count());
        Bus::assertDispatchedTimes(LikeInboxItem::class, 1);
        $this->postJson($this->url().'/reconcile', $data + ['dry_run' => false])->assertOk()->assertJsonPath('already_replied', true);
        Bus::assertDispatchedTimes(LikeInboxItem::class, 1);
    }

    public function test_relecture_ancienne_autre_cible_ou_preuve_manquante_refusees(): void
    {
        $payload = $this->verification->payload;
        foreach ([['claimed_at' => $this->task->claimed_at], ['payload' => array_merge($payload, ['text' => 'Un autre texte'])], ['payload' => array_merge($payload, ['username' => 'other'])], ['result' => ['confirmed' => true]]] as $change) {
            $saved = $this->verification->getAttributes();
            $this->verification->update($change);
            $this->postJson($this->url().'/reconcile', ['verification_task_id' => $this->verification->id, 'dry_run' => false])->assertConflict();
            $this->verification->setRawAttributes($saved)->save();
        }
        $this->assertNull($this->item->fresh()->replied_at);
        Bus::assertNothingDispatched();
    }

    public function test_verification_ne_fait_qu_une_lecture_et_reste_limitee_au_compte_attribue(): void
    {
        $this->mock(\App\Services\Browser\BrowserTaskQueue::class)->shouldReceive('run')->once()->withArgs(function ($account, $kind, $payload) {
            $this->assertSame('tiktok.verify', $kind);
            $this->assertSame('tiktok.reply', $payload['action']);
            $this->assertSame($this->task->id, $payload['source_task_id']);
            BrowserTask::create(['social_account_id' => $account->id, 'profile' => 'nas-test', 'kind' => $kind, 'payload' => $payload, 'status' => 'done']);

            return true;
        })->andReturn(['confirmed' => false]);
        $this->postJson($this->url().'/verify-reply')->assertOk()->assertJsonPath('sent', false)->assertJsonPath('result.confirmed', false);
        $this->assertSame(1, AccountActionEvent::count());
        $this->item->socialAccount->users()->detach();
        $this->postJson($this->url().'/verify-reply')->assertNotFound();
        Bus::assertNothingDispatched();
    }
}
