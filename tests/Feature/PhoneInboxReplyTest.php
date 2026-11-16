<?php

namespace Tests\Feature;

use App\Jobs\LikeInboxItem;
use App\Jobs\VerifyTikTokInboxReply;
use App\Models\BrowserTask;
use App\Models\InboxItem;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Browser\BrowserTaskQueue;
use App\Services\Inbox\InboxReplyService;
use App\Services\Inbox\PhoneInboxReplyResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Réponses TikTok d'un compte sur téléphone : mises en file sans occuper l'unique worker, conclues en fin de tâche. */
class PhoneInboxReplyTest extends TestCase
{
    use RefreshDatabase;

    private function item(string $profile = 'android-honor'): InboxItem
    {
        $a = SocialAccount::create(['platform_id' => Platform::where('slug', 'tiktok')->firstOrFail()->id, 'name' => 'Compte',
            'driver' => 'browser', 'browser_profile' => $profile, 'browser_username' => 'wildycaro']);

        return InboxItem::create(['social_account_id' => $a->id, 'platform_id' => $a->platform_id, 'external_id' => 'empreinte',
            'type' => 'comment', 'author_username' => 'ana', 'content' => 'Jolie tenue', 'post_content' => 'Légende',
            'reply_content' => 'Merci 😊', 'status' => 'unread', 'reply_attempts' => 0, 'reply_scheduled_at' => now()->subSecond(),
            'posted_at' => now()->subHour()]);
    }

    private function finish(BrowserTask $task, string $status, array $result = []): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/extension/tasks/next?profile=android-honor')->assertJsonPath('id', $task->id);
        $this->postJson("/api/extension/tasks/{$task->id}/finish", ['status' => $status, 'result' => $result])->assertOk();
    }

    public function test_la_reponse_part_en_file_sans_attendre_et_le_like_prouve_evite_un_second_geste(): void
    {
        Queue::fake();
        $item = $this->item();

        $sent = app(InboxReplyService::class)->send($item, $item->reply_content, scheduled: true);

        $task = BrowserTask::firstOrFail();
        $this->assertTrue($sent['queued']);
        $this->assertSame(['tiktok.reply', BrowserTask::PENDING, $item->id], [$task->kind, $task->status, $task->payload['inbox_item_id']]);
        $this->assertSame(['author' => 'ana', 'content' => 'Jolie tenue', 'caption' => 'Légende',
            'posted_at' => $item->posted_at->toIso8601String()], $task->payload['target']);
        $item->refresh();
        $this->assertSame(1, $item->reply_attempts);
        $this->assertNull($item->reply_scheduled_at);
        $this->assertNull($item->replied_at);

        $this->finish($task, 'done', ['confirmed' => true, 'liked' => true, 'proof' => 'after_reload']);

        $item->refresh();
        $this->assertSame('replied', $item->status);
        $this->assertNotNull($item->replied_at);
        $this->assertNotNull($item->liked_at);
        Queue::assertNotPushed(LikeInboxItem::class);
    }

    public function test_sans_like_prouve_le_like_suit_et_une_incertitude_nest_jamais_renvoyee(): void
    {
        Queue::fake();
        $item = $this->item();
        app(InboxReplyService::class)->send($item, $item->reply_content, scheduled: true);
        $this->finish(BrowserTask::firstOrFail(), 'done', ['confirmed' => true]);
        Queue::assertPushed(LikeInboxItem::class, fn ($job) => $job->itemId === $item->id);

        $other = InboxItem::create(['social_account_id' => $item->social_account_id, 'platform_id' => $item->platform_id,
            'external_id' => 'autre', 'type' => 'comment', 'author_username' => 'bea', 'content' => 'Superbe', 'post_content' => 'Légende',
            'reply_content' => 'Merci', 'status' => 'unread', 'reply_attempts' => 0, 'reply_scheduled_at' => now()->subSecond(), 'posted_at' => now()->subHour()]);
        app(InboxReplyService::class)->send($other, $other->reply_content, scheduled: true);
        $this->finish(BrowserTask::where('payload->inbox_item_id', $other->id)->firstOrFail(), 'uncertain');

        $other->refresh();
        $this->assertSame(['reply_failed', 3], [$other->status, $other->reply_attempts]);
        Queue::assertPushed(VerifyTikTokInboxReply::class, fn ($job) => $job->itemId === $other->id);
        $this->assertFalse(app(InboxReplyService::class)->send($other, $other->reply_content, scheduled: true)['success'] ?? false);
    }

    public function test_une_reponse_jamais_prise_a_temps_redevient_a_traiter_sans_geste(): void
    {
        Queue::fake();
        $item = $this->item();
        app(InboxReplyService::class)->send($item, $item->reply_content, scheduled: true);
        $this->travel(31)->minutes();

        $this->assertNull(app(BrowserTaskQueue::class)->claim('android-honor'));

        $this->assertSame(BrowserTask::CANCELLED, BrowserTask::firstOrFail()->status);
        $item->refresh();
        $this->assertSame(['read', 0, null], [$item->status, $item->reply_attempts, $item->reply_content]);
    }

    public function test_le_like_dun_compte_sur_telephone_part_en_file_et_se_conclut_en_fin_de_tache(): void
    {
        $item = $this->item();

        (new LikeInboxItem($item->id))->handle(app(\App\Services\Inbox\TikTokInboxService::class));

        $task = BrowserTask::firstOrFail();
        $this->assertSame(['tiktok.like', BrowserTask::PENDING, $item->id], [$task->kind, $task->status, $task->payload['inbox_item_id']]);
        $this->assertNull($item->fresh()->liked_at);
        $this->finish($task, 'done', ['confirmed' => true, 'proof' => 'after_reload']);
        $this->assertNotNull($item->fresh()->liked_at);
    }

    public function test_un_compte_sur_navigateur_garde_lenvoi_synchrone(): void
    {
        Queue::fake();
        $item = $this->item('nas-wildycaro');
        $platform = $this->mock(\App\Services\Inbox\TikTokInboxService::class);
        $platform->shouldReceive('sendReply')->once()->andReturn(['success' => true, 'external_id' => null]);
        $this->mock(\App\Services\Inbox\InboxSyncService::class)->shouldReceive('getServiceForAccount')->once()->andReturn($platform);

        $this->assertTrue(app(InboxReplyService::class)->send($item, $item->reply_content, scheduled: true)['success']);
        $this->assertSame(0, BrowserTask::count());
        $this->assertSame('replied', $item->fresh()->status);
    }

    public function test_la_file_du_telephone_est_plafonnee(): void
    {
        $item = $this->item();
        foreach (range(1, PhoneInboxReplyResult::MAX_IN_FLIGHT) as $i) {
            BrowserTask::create(['social_account_id' => $item->social_account_id, 'profile' => 'android-honor', 'kind' => 'tiktok.reply',
                'payload' => ['inbox_item_id' => 1000 + $i]]);
        }
        $item->update(['reply_scheduled_at' => null, 'reply_content' => null]);
        request()->attributes->set('community_manager', true);

        $result = app(InboxReplyService::class)->submit($item, 'Merci');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('File du téléphone pleine', $result['error']);
    }
}
