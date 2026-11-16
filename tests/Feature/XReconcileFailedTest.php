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

/** Réponses X incertaines retrouvées dans le fil de la mention, jamais renvoyées. */
class XReconcileFailedTest extends TestCase
{
    use RefreshDatabase;

    private function snowflake(\DateTimeInterface $at): string
    {
        return (string) ((((int) $at->format('Uv')) - 1288834974657) << 22);
    }

    public function test_reponse_retrouvee_classee_ou_laissee_selon_lage(): void
    {
        Queue::fake();
        $a = SocialAccount::create(['platform_id' => Platform::firstOrCreate(['slug' => 'twitter'], ['name' => 'X'])->id, 'name' => 'Wildy',
            'platform_account_id' => '77', 'driver' => 'browser', 'browser_profile' => 'nas-x', 'browser_username' => 'wildycaro']);
        $make = function (string $id, int $daysAgo) use ($a) {
            $item = InboxItem::create(['social_account_id' => $a->id, 'platform_id' => $a->platform_id, 'external_id' => $id, 'type' => 'mention',
                'author_username' => 'ana'.$id, 'content' => 'Superbe', 'reply_content' => 'Merci 😊', 'status' => 'reply_failed',
                'reply_attempts' => 3, 'posted_at' => now()->subDays($daysAgo)]);
            BrowserTask::create(['social_account_id' => $a->id, 'profile' => 'nas-x', 'kind' => 'x.post', 'idempotency_key' => 'inbox-reply:'.$item->id,
                'status' => BrowserTask::UNCERTAIN, 'payload' => ['text' => 'Merci 😊'], 'claimed_at' => now()->subDays($daysAgo)->addMinute(),
                'finished_at' => now()->subDays($daysAgo)->addMinutes(3)]);

            return $item;
        };
        [$found, $old, $recent] = [$make('101', 2), $make('102', 9), $make('103', 1)];
        $ours = $this->snowflake(now()->subDays(2)->addMinutes(2));
        $this->mock(BrowserTaskQueue::class)->shouldReceive('run')->andReturnUsing(fn ($acc, $kind, $payload) => [
            'target' => ['id' => $payload['target_id']],
            'visible_replies' => $payload['target_id'] === '101' ? [['id' => $ours, 'author' => 'WildyCaro', 'text' => 'Merci 😊', 'url' => 'https://x.com/wildycaro/status/'.$ours]] : [],
        ]);

        $this->artisan('x:reconcile-failed')->assertSuccessful();
        $this->assertSame(3, InboxItem::where('status', 'reply_failed')->count());

        $this->artisan('x:reconcile-failed --commit')->assertSuccessful();

        $this->assertSame(['replied', $ours], [$found->fresh()->status, $found->fresh()->reply_external_id]);
        $this->assertSame(BrowserTask::DONE, BrowserTask::where('idempotency_key', 'inbox-reply:'.$found->id)->first()->status);
        Queue::assertPushed(LikeInboxItem::class, fn ($job) => $job->itemId === $found->id);
        $this->assertSame(['ignored', 'reconciled_unproven'], [$old->fresh()->status, $old->fresh()->agent_review_reason]);
        $this->assertSame('reply_failed', $recent->fresh()->status);
    }
}
