<?php

namespace Tests\Feature;

use App\Jobs\LikeInboxItem;
use App\Models\BrowserTask;
use App\Models\InboxItem;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Réponses TikTok en échec tranchées par TikTok Studio, sans renvoi d'une réponse qui a pu partir. */
class TikTokReconcileFailedTest extends TestCase
{
    use RefreshDatabase;

    public function test_studio_tranche_chaque_echec_sans_jamais_renvoyer(): void
    {
        Queue::fake();
        $a = SocialAccount::create(['platform_id' => Platform::where('slug', 'tiktok')->firstOrFail()->id, 'name' => 'Compte',
            'driver' => 'browser', 'browser_profile' => 'android-honor', 'browser_username' => 'wildycaro']);
        $make = fn (string $id) => InboxItem::create(['social_account_id' => $a->id, 'platform_id' => $a->platform_id, 'external_id' => $id,
            'type' => 'comment', 'author_username' => 'ana'.$id, 'content' => 'Texte '.$id, 'reply_content' => 'Merci',
            'status' => 'reply_failed', 'reply_attempts' => 3, 'posted_at' => now()->subDay()]);
        [$answered, $neverSent, $maybeSent, $absent] = [$make('1'), $make('2'), $make('3'), $make('4')];
        BrowserTask::create(['social_account_id' => $a->id, 'profile' => 'android-honor', 'kind' => 'tiktok.reply', 'status' => BrowserTask::UNCERTAIN,
            'payload' => ['target' => ['author' => 'ana3', 'content' => 'Texte 3']]]);
        BrowserTask::create(['social_account_id' => $a->id, 'profile' => 'android-honor', 'kind' => 'tiktok.reply', 'status' => BrowserTask::FAILED,
            'payload' => ['target' => ['author' => 'ana2', 'content' => 'Texte 2']]]);
        $this->mock(BrowserTaskQueue::class)->shouldReceive('run')->twice()
            ->withArgs(fn ($account, $kind, $payload) => $kind === 'tiktok.inbox.reconcile' && count($payload['items']) === 4)
            ->andReturn(['statuses' => [(string) $answered->id => 'answered', (string) $neverSent->id => 'pending',
                (string) $maybeSent->id => 'pending', (string) $absent->id => 'absent'], 'pending' => 2, 'answered' => 1]);

        $this->artisan('tiktok:reconcile-failed')->assertSuccessful();
        $this->assertSame(4, InboxItem::where('status', 'reply_failed')->count());

        $this->artisan('tiktok:reconcile-failed --commit')->assertSuccessful();

        $this->assertSame('replied', $answered->fresh()->status);
        Queue::assertPushed(LikeInboxItem::class, fn ($job) => $job->itemId === $answered->id);
        $this->assertSame(['read', 0, null], [$neverSent->fresh()->status, $neverSent->fresh()->reply_attempts, $neverSent->fresh()->reply_content]);
        $this->assertSame(['ignored', 'reconciled_withheld'], [$maybeSent->fresh()->status, $maybeSent->fresh()->agent_review_reason]);
        $this->assertSame(['ignored', 'reconciled_absent'], [$absent->fresh()->status, $absent->fresh()->agent_review_reason]);
    }
}
