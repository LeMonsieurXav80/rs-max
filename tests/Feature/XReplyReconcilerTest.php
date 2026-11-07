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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class XReplyReconcilerTest extends TestCase
{
    use RefreshDatabase;

    private InboxItem $item;

    private BrowserTask $task;

    private string $snapshot;

    private array $data;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-07T14:42:00Z'));
        Bus::fake([LikeInboxItem::class]);
        $u = User::factory()->create(['role' => 'manager']);
        $p = Platform::firstOrCreate(['slug' => 'twitter'], ['name' => 'X', 'auth_type' => 'oauth2']);
        $a = SocialAccount::create(['platform_id' => $p->id, 'name' => 'Compte attribué', 'driver' => 'browser', 'browser_profile' => 'nas-test', 'browser_username' => 'owner']);
        $a->users()->attach($u, ['is_active' => true]);
        Sanctum::actingAs($u);
        $this->item = InboxItem::create(['social_account_id' => $a->id, 'platform_id' => $p->id, 'external_id' => '987', 'type' => 'comment', 'author_username' => 'visitor',
            'content' => 'Très jolie tenue', 'reply_content' => 'Merci à vous 🙂', 'status' => 'reply_failed', 'reply_attempts' => 3]);
        $this->task = BrowserTask::create(['social_account_id' => $a->id, 'profile' => 'nas-test', 'kind' => 'x.post', 'status' => 'uncertain',
            'idempotency_key' => 'inbox-reply:'.$this->item->id, 'payload' => ['url' => 'https://x.com/compose/post?in_reply_to=987', 'text' => 'Merci à vous 🙂', 'username' => 'owner'],
            'claimed_at' => '2026-11-07T14:41:25Z', 'finished_at' => '2026-11-07T14:41:31Z', 'error' => 'Parent non encore monté']);
        AccountActionEvent::create(['social_account_id' => $a->id, 'browser_task_id' => $this->task->id, 'action' => 'x.reply', 'executor' => 'browser', 'status' => 'uncertain']);
        $id = '2119077783154397329';
        $this->data = ['account_id' => $a->id, 'read_at' => now()->toIso8601String(), 'source' => ['action' => 'thread.read', 'target_id' => $id],
            'result' => ['context_complete' => true, 'target' => ['id' => $id, 'author' => 'owner', 'text' => 'Merci à vous 🙂', 'url' => 'https://x.com/owner/status/'.$id, 'stats' => ['likes' => 0]],
                'conversation' => [['id' => '900', 'author' => 'owner', 'text' => 'Mon post'], ['id' => '987', 'author' => 'visitor', 'text' => 'Très jolie tenue'], ['id' => $id, 'author' => 'owner', 'text' => 'Merci à vous 🙂']]]];
        $this->snapshot = (string) Str::uuid();
        Cache::put('x-agent-snapshot:'.$this->snapshot, $this->data, now()->addHour());
    }

    private function url(): string
    {
        return '/api/community-manager/inbox/'.$this->item->id.'/reconcile';
    }

    public function test_la_relecture_exacte_resout_une_incertitude_sans_renvoi_et_relance_seulement_le_like(): void
    {
        $this->postJson($this->url(), ['snapshot_id' => $this->snapshot])->assertOk()->assertJsonPath('dry_run', true);
        $this->assertSame('uncertain', $this->task->fresh()->status);
        Bus::assertNothingDispatched();
        $this->postJson($this->url(), ['snapshot_id' => $this->snapshot, 'dry_run' => false])->assertOk()->assertJsonPath('proof.confirmed', true);
        $this->assertSame('done', $this->task->fresh()->status);
        $this->assertSame('replied', $this->item->fresh()->status);
        $this->assertSame('2119077783154397329', $this->item->fresh()->reply_external_id);
        $this->assertSame(1, BrowserTask::count());
        $this->assertSame('confirmed', AccountActionEvent::first()->status);
        Bus::assertDispatchedTimes(LikeInboxItem::class, 1);
        $this->postJson($this->url(), ['snapshot_id' => $this->snapshot, 'dry_run' => false])->assertOk()->assertJsonPath('already_replied', true);
        Bus::assertDispatchedTimes(LikeInboxItem::class, 1);
    }

    public function test_un_autre_auteur_ou_un_parent_absent_ne_resout_pas_le_geste(): void
    {
        $this->data['result']['target']['author'] = 'other';
        Cache::put('x-agent-snapshot:'.$this->snapshot, $this->data, now()->addHour());
        $this->postJson($this->url(), ['snapshot_id' => $this->snapshot, 'dry_run' => false])->assertConflict();
        $this->data['result']['target']['author'] = 'owner';
        $this->data['result']['conversation'] = array_slice($this->data['result']['conversation'], -1);
        Cache::put('x-agent-snapshot:'.$this->snapshot, $this->data, now()->addHour());
        $this->postJson($this->url(), ['snapshot_id' => $this->snapshot, 'dry_run' => false])->assertConflict();
        $this->assertSame('uncertain', $this->task->fresh()->status);
        Bus::assertNothingDispatched();
    }

    public function test_une_lecture_anterieure_a_la_fin_du_geste_est_refusee(): void
    {
        $this->data['read_at'] = '2026-11-07T14:41:30Z';
        Cache::put('x-agent-snapshot:'.$this->snapshot, $this->data, now()->addHour());
        $this->postJson($this->url(), ['snapshot_id' => $this->snapshot, 'dry_run' => false])->assertConflict();
        Bus::assertNothingDispatched();
    }
}
