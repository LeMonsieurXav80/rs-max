<?php

namespace Tests\Feature;

use App\Jobs\VerifyTikTokInboxReply;
use App\Models\InboxItem;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Services\Inbox\TikTokReplyReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VerifyTikTokInboxReplyTest extends TestCase
{
    use RefreshDatabase;

    private function item(): InboxItem
    {
        $a = SocialAccount::create(['platform_id' => Platform::where('slug', 'tiktok')->firstOrFail()->id, 'name' => 'Compte associé', 'driver' => 'browser', 'browser_profile' => 'test']);

        return InboxItem::create(['social_account_id' => $a->id, 'platform_id' => $a->platform_id, 'external_id' => 'empreinte', 'type' => 'comment', 'content' => 'Jolie tenue', 'reply_content' => 'Merci !', 'status' => 'reply_failed', 'reply_attempts' => 3]);
    }

    public function test_reponse_retrouvee_est_rapprochee_sans_second_envoi(): void
    {
        Queue::fake();
        $item = $this->item();
        $service = $this->mock(TikTokReplyReconciler::class);
        $service->shouldReceive('verify')->once()->withArgs(fn ($i) => $i->id === $item->id)->andReturn(['verification_task_id' => 42, 'result' => ['confirmed' => true]]);
        $service->shouldReceive('reconcile')->once()->withArgs(fn ($i, $proof, $dry) => $i->id === $item->id && $proof === 42 && $dry === false)->andReturn(['success' => true]);
        (new VerifyTikTokInboxReply($item->id))->handle($service);
        Queue::assertNothingPushed();
        $this->assertSame(3, $item->fresh()->reply_attempts);
        $this->assertNull($item->fresh()->reply_scheduled_at);
    }

    public function test_absence_declenche_une_seule_autre_lecture_et_ne_rearme_jamais_lenvoi(): void
    {
        Queue::fake();
        $item = $this->item();
        $service = $this->mock(TikTokReplyReconciler::class);
        $service->shouldReceive('verify')->twice()->andReturn(['verification_task_id' => 42, 'result' => ['confirmed' => false]]);
        $service->shouldNotReceive('reconcile');
        (new VerifyTikTokInboxReply($item->id))->handle($service);
        Queue::assertPushed(VerifyTikTokInboxReply::class, fn ($job) => $job->itemId === $item->id && $job->pass === 2 && $job->delay->greaterThan(now()->addMinutes(24)));
        (new VerifyTikTokInboxReply($item->id, 2))->handle($service);
        Queue::assertPushed(VerifyTikTokInboxReply::class, 1);
        $this->assertSame(3, $item->fresh()->reply_attempts);
        $this->assertSame('reply_failed', $item->fresh()->status);
        $this->assertNull($item->fresh()->reply_scheduled_at);
    }

    public function test_envoi_incertain_planifie_une_lecture_seule_et_pas_un_nouvel_envoi(): void
    {
        Queue::fake();
        $item = $this->item();
        $item->update(['status' => 'unread', 'reply_attempts' => 0, 'reply_scheduled_at' => now()->subSecond()]);
        $platform = $this->mock(\App\Services\Inbox\TikTokInboxService::class);
        $platform->shouldReceive('sendReply')->once()->andReturn(['success' => false, 'uncertain' => true, 'error' => 'Réponse absente après reload']);
        $this->mock(\App\Services\Inbox\InboxSyncService::class)->shouldReceive('getServiceForAccount')->once()->andReturn($platform);
        $result = app(\App\Services\Inbox\InboxReplyService::class)->send($item, $item->reply_content, scheduled: true);
        $this->assertFalse($result['success']);
        Queue::assertPushed(VerifyTikTokInboxReply::class, fn ($job) => $job->itemId === $item->id && $job->pass === 1 && $job->delay->greaterThan(now()->addMinutes(4)));
        Queue::assertPushed(VerifyTikTokInboxReply::class, 1);
        $item->refresh();
        $this->assertSame(3, $item->reply_attempts);
        $this->assertNull($item->reply_scheduled_at);
        app(\App\Services\Inbox\InboxReplyService::class)->send($item, $item->reply_content, scheduled: true);
        Queue::assertPushed(VerifyTikTokInboxReply::class, 1);
    }

    public function test_aucune_lecture_pour_une_reponse_confirmee_ou_jamais_tentee(): void
    {
        Queue::fake();
        $item = $this->item();
        $service = $this->mock(TikTokReplyReconciler::class);
        $service->shouldNotReceive('verify');
        $item->update(['replied_at' => now(), 'status' => 'replied']);
        (new VerifyTikTokInboxReply($item->id))->handle($service);
        $item->update(['replied_at' => null, 'status' => 'unread', 'reply_attempts' => 0]);
        (new VerifyTikTokInboxReply($item->id))->handle($service);
        Queue::assertNothingPushed();
    }
}
