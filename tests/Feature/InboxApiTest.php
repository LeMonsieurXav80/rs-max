<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Models\Persona;
use App\Models\Platform;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Inbox\InboxSyncService;
use App\Services\Inbox\PlatformInboxInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class InboxApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->user = User::factory()->create();
        $this->account = $this->account('instagram', 'Caroline');
        Sanctum::actingAs($this->user);
    }

    private function account(string $slug, string $name, bool $writable = true): SocialAccount
    {
        $platform = Platform::firstOrCreate(['slug' => $slug], ['name' => $slug, 'auth_type' => 'oauth2']);
        $persona = Persona::create(['name' => $name, 'system_prompt' => 'Tu es '.$name, 'is_active' => true]);
        $account = SocialAccount::create([
            'platform_id' => $platform->id, 'platform_account_id' => uniqid('account-'),
            'name' => $name, 'persona_id' => $persona->id,
        ]);
        $account->users()->attach($this->user->id, ['is_active' => $writable]);

        return $account;
    }

    private function item(array $attrs = [], ?SocialAccount $account = null): InboxItem
    {
        $account ??= $this->account;

        return InboxItem::create($attrs + [
            'social_account_id' => $account->id, 'platform_id' => $account->platform_id,
            'type' => 'comment', 'external_id' => uniqid('message-'), 'content' => 'Quel bel endroit !',
            'author_name' => 'Visiteur', 'author_external_id' => 'visitor', 'status' => 'unread',
            'posted_at' => now(),
        ]);
    }

    private function sendingService(int $calls = 1, bool $success = true): void
    {
        $platform = Mockery::mock(PlatformInboxInterface::class);
        $platform->shouldReceive('sendReply')->times($calls)
            ->andReturn(['success' => $success, 'external_id' => $success ? 'sent-123' : null, 'error' => $success ? null : 'Refus du réseau']);
        $this->mock(InboxSyncService::class)->shouldReceive('getServiceForPlatform')->times($calls)->andReturn($platform);
    }

    public function test_token_authentication_is_required(): void
    {
        auth()->forgetGuards();
        $this->getJson('/api/inbox')->assertUnauthorized();
    }

    public function test_real_bearer_token_can_select_a_lot(): void
    {
        $token = $this->user->createToken('inbox-test')->plainTextToken;
        auth()->forgetGuards();
        $this->item();
        $this->withToken($token)->postJson('/api/inbox/ai-prepare', ['platforms' => ['instagram'], 'limit' => 1])
            ->assertOk()->assertJsonPath('selected', 1);
    }

    public function test_selection_intersects_platform_account_and_global_limit(): void
    {
        $otherInstagram = $this->account('instagram', 'Xavier');
        $facebook = $this->account('facebook', 'Caroline Facebook');
        $oldest = $this->item(['posted_at' => now()->subDays(2)]);
        $this->item(['posted_at' => now()->subDay()]);
        $this->item();
        $this->item([], $otherInstagram);
        $this->item([], $facebook);

        $this->postJson('/api/inbox/ai-prepare', [
            'platforms' => ['instagram', 'facebook'], 'accounts' => [$this->account->id], 'limit' => 2,
        ])->assertOk()->assertJsonPath('selected', 2)->assertJsonPath('eligible', 3)
            ->assertJsonPath('items.0.id', $oldest->id)->assertJsonPath('items.0.persona.name', 'Caroline');
        Http::assertNothingSent();
    }

    public function test_limit_is_total_across_multiple_networks(): void
    {
        $this->item();
        $facebook = $this->account('facebook', 'Xavier');
        $this->item([], $facebook);
        $this->postJson('/api/inbox/ai-prepare', ['platforms' => ['instagram', 'facebook'], 'limit' => 1])
            ->assertOk()->assertJsonPath('eligible', 2)->assertJsonCount(1, 'items');
    }

    public function test_selection_excludes_treated_scheduled_failed_own_and_private_messages(): void
    {
        $eligible = $this->item(['status' => 'read']);
        foreach (['replied', 'archived', 'ignored', 'reply_failed'] as $status) {
            $this->item(['status' => $status]);
        }
        $this->item(['reply_scheduled_at' => now()->addHour()]);
        $this->item(['reply_content' => 'Déjà préparée']);
        $this->item(['replied_at' => now()]);
        $this->item(['reply_external_id' => 'external-reply']);
        $this->item(['reply_attempts' => 1]);
        $this->item(['author_external_id' => $this->account->platform_account_id]);
        $this->item(['type' => 'dm']);

        $this->postJson('/api/inbox/ai-prepare', ['platforms' => ['instagram'], 'limit' => 100])
            ->assertOk()->assertJsonPath('selected', 1)->assertJsonPath('items.0.id', $eligible->id);
    }

    public function test_private_messages_and_newest_order_are_explicit_choices(): void
    {
        $this->item(['type' => 'dm', 'posted_at' => now()->subDays(2)]);
        $newest = $this->item(['type' => 'dm']);
        $this->postJson('/api/inbox/ai-prepare', [
            'platforms' => ['instagram'], 'types' => ['dm'], 'limit' => 1, 'order' => 'newest',
        ])->assertOk()->assertJsonPath('items.0.id', $newest->id);
    }

    public function test_missing_inactive_or_empty_personas_are_reported(): void
    {
        $this->account->update(['persona_id' => null]);
        $this->item();
        $inactive = $this->account('facebook', 'Inactive');
        $inactive->persona->update(['is_active' => false]);
        $this->item([], $inactive);
        $blank = $this->account('youtube', 'Vide');
        $blank->persona->update(['system_prompt' => '   ']);
        $this->item([], $blank);
        $this->postJson('/api/inbox/ai-prepare', ['platforms' => ['instagram', 'facebook', 'youtube'], 'limit' => 10])
            ->assertOk()->assertJsonPath('available', 3)->assertJsonPath('selected', 0)->assertJsonCount(3, 'skipped_accounts');
    }

    public function test_invalid_or_unbounded_lots_are_rejected(): void
    {
        foreach ([[], ['limit' => 1], ['platforms' => []], ['platforms' => ['pinterest'], 'limit' => 1],
            ['platforms' => ['instagram'], 'limit' => 0], ['platforms' => ['instagram'], 'limit' => 251],
            ['platforms' => ['instagram'], 'limit' => 1, 'status' => 'all']] as $payload) {
            $this->postJson('/api/inbox/ai-prepare', $payload)->assertUnprocessable();
        }
    }

    public function test_disabled_network_is_not_selected_or_answered(): void
    {
        $item = $this->item();
        Setting::set('inbox_platform_instagram_enabled', '0');
        $this->postJson('/api/inbox/ai-prepare', ['platforms' => ['instagram'], 'limit' => 1])
            ->assertOk()->assertJsonPath('selected', 0);
        $this->postJson('/api/inbox/'.$item->id.'/ai-suggest')->assertUnprocessable();
        $this->postJson('/api/inbox/'.$item->id.'/reply', ['reply_text' => 'Merci'])->assertUnprocessable();
    }

    public function test_read_visibility_does_not_grant_reply_or_selection_rights(): void
    {
        $foreign = $this->account('facebook', 'Autre compte', writable: false);
        $item = $this->item([], $foreign);
        $this->getJson('/api/inbox')->assertOk()->assertJsonPath('items.0.id', $item->id)->assertJsonPath('items.0.can_reply', false);
        $this->postJson('/api/inbox/ai-prepare', ['accounts' => [$foreign->id], 'limit' => 1])->assertForbidden();
        $this->postJson('/api/inbox/'.$item->id.'/ai-suggest')->assertForbidden();
        $this->postJson('/api/inbox/'.$item->id.'/reply', ['reply_text' => 'Merci', 'dry_run' => false])->assertForbidden();
        $this->postJson('/api/inbox/ai-prepare', ['platforms' => ['facebook'], 'limit' => 1])->assertOk()->assertJsonPath('selected', 0);
        Http::assertNothingSent();
    }

    public function test_generation_uses_account_persona_despite_web_toggle_and_preserves_thread_context(): void
    {
        Setting::setEncrypted('openai_api_key', 'fake-key');
        Setting::set('inbox_use_persona', '0');
        Setting::set('inbox_reply_prompt', 'Réponds brièvement.');
        $previous = $this->item(['content' => 'Hello from London', 'reply_content' => 'Welcome!', 'status' => 'replied']);
        $item = $this->item(['parent_id' => $previous->external_id, 'content' => 'Where is this?']);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'It is in Faro.']]]])]);
        $this->postJson('/api/inbox/'.$item->id.'/ai-suggest')->assertOk()
            ->assertJsonPath('reply_text', 'It is in Faro.')->assertJsonPath('persona.name', 'Caroline');
        Http::assertSent(fn ($r) => str_contains($r['messages'][0]['content'], 'Tu es Caroline')
            && str_contains($r['messages'][0]['content'], 'Réponds brièvement.')
            && str_contains($r['messages'][1]['content'], 'Welcome!')
            && str_contains($r['messages'][1]['content'], 'same language'));
        $this->assertNull($item->fresh()->reply_content);
    }

    public function test_missing_persona_and_ai_failure_do_not_write_or_send(): void
    {
        $item = $this->item();
        $this->account->update(['persona_id' => null]);
        $this->postJson('/api/inbox/'.$item->id.'/ai-suggest')->assertUnprocessable();
        Http::assertNothingSent();
        $this->account->update(['persona_id' => Persona::first()->id]);
        Setting::setEncrypted('openai_api_key', 'fake-key');
        Http::fake(['api.openai.com/*' => Http::response(['error' => 'unavailable'], 503)]);
        $this->postJson('/api/inbox/'.$item->id.'/ai-suggest')->assertUnprocessable();
        $this->assertNull($item->fresh()->reply_content);
    }

    public function test_preview_is_default_and_does_not_send_or_schedule(): void
    {
        $item = $this->item();
        $this->postJson('/api/inbox/'.$item->id.'/reply', ['reply_text' => 'Merci !', 'scheduled_at' => now()->addHour()->toIso8601String()])
            ->assertOk()->assertJsonPath('dry_run', true)->assertJsonPath('sent', false);
        $this->assertNull($item->fresh()->reply_content);
        $this->assertNull($item->fresh()->reply_scheduled_at);
        Http::assertNothingSent();
    }

    public function test_explicit_send_updates_state_and_same_text_retry_does_not_send_twice(): void
    {
        $item = $this->item();
        $this->sendingService();
        $payload = ['reply_text' => 'Merci !', 'dry_run' => false];
        $this->postJson('/api/inbox/'.$item->id.'/reply', $payload)->assertOk()->assertJsonPath('success', true);
        $this->assertSame('replied', $item->fresh()->status);
        $this->assertSame('sent-123', $item->fresh()->reply_external_id);
        $this->postJson('/api/inbox/'.$item->id.'/reply', $payload)->assertOk()->assertJsonPath('already_replied', true);
        $this->postJson('/api/inbox/'.$item->id.'/reply', ['reply_text' => 'Autre texte', 'dry_run' => false])->assertConflict();
    }

    public function test_network_failure_is_returned_without_marking_replied(): void
    {
        $item = $this->item();
        $this->sendingService(success: false);
        $this->postJson('/api/inbox/'.$item->id.'/reply', ['reply_text' => 'Merci', 'dry_run' => false])
            ->assertStatus(502)->assertJsonPath('success', false);
        $this->assertNull($item->fresh()->replied_at);
    }

    public function test_lock_prevents_api_send_during_web_or_scheduler_send(): void
    {
        $item = $this->item();
        $lock = Cache::lock('inbox-reply:'.$item->id, 120);
        $lock->get();
        $this->postJson('/api/inbox/'.$item->id.'/reply', ['reply_text' => 'Merci', 'dry_run' => false])->assertConflict();
        Http::assertNothingSent();
        $lock->release();
    }

    public function test_scheduled_reply_is_sent_by_existing_scheduler_only_once(): void
    {
        $item = $this->item();
        $at = now()->addMinutes(10);
        $payload = ['reply_text' => 'Merci !', 'dry_run' => false, 'scheduled_at' => $at->toIso8601String()];
        $this->postJson('/api/inbox/'.$item->id.'/reply', $payload)->assertOk()->assertJsonPath('success', true);
        $this->postJson('/api/inbox/'.$item->id.'/reply', $payload)->assertOk()->assertJsonPath('already_scheduled', true);
        $this->postJson('/api/inbox/'.$item->id.'/reply', ['reply_text' => 'Autre texte', 'dry_run' => false])->assertConflict();
        Http::assertNothingSent();
        $this->travel(11)->minutes();
        $this->sendingService();
        $this->artisan('inbox:send-scheduled')->assertSuccessful();
        $this->artisan('inbox:send-scheduled')->assertSuccessful();
        $this->assertSame('replied', $item->fresh()->status);
        $this->assertNull($item->fresh()->reply_scheduled_at);
    }

    public function test_empty_text_and_own_or_archived_messages_cannot_be_answered(): void
    {
        $item = $this->item();
        $this->postJson('/api/inbox/'.$item->id.'/reply', ['reply_text' => '   ', 'dry_run' => false])->assertUnprocessable();
        $own = $this->item(['author_external_id' => $this->account->platform_account_id]);
        $this->postJson('/api/inbox/'.$own->id.'/reply', ['reply_text' => 'Merci', 'dry_run' => false])->assertConflict();
        $archived = $this->item(['status' => 'archived']);
        $this->postJson('/api/inbox/'.$archived->id.'/ai-suggest')->assertConflict();
        $this->postJson('/api/inbox/'.$archived->id.'/reply', ['reply_text' => 'Merci', 'dry_run' => false])->assertConflict();
    }

    public function test_web_inbox_uses_the_same_sender_and_respects_its_lock(): void
    {
        $item = $this->item();
        $this->actingAs($this->user, 'web');
        $lock = Cache::lock('inbox-reply:'.$item->id, 120);
        $lock->get();
        $this->postJson('/inbox/'.$item->id.'/reply', ['reply_text' => 'Merci !'])
            ->assertOk()->assertJsonPath('success', false);
        $lock->release();
        $this->sendingService();
        $this->postJson('/inbox/'.$item->id.'/reply', ['reply_text' => 'Merci !'])
            ->assertOk()->assertJsonPath('success', true);
        $this->assertSame('Merci !', $item->fresh()->reply_content);
    }

    public function test_scheduler_stops_after_three_failures_and_releases_the_reservation(): void
    {
        $item = $this->item(['reply_scheduled_at' => now()->subMinute(), 'reply_content' => 'Merci !']);
        $this->sendingService(calls: 3, success: false);
        for ($i = 0; $i < 4; $i++) {
            $this->artisan('inbox:send-scheduled')->assertSuccessful();
        }
        $this->assertSame('reply_failed', $item->fresh()->status);
        $this->assertSame(3, (int) $item->fresh()->reply_attempts);
        $this->assertNull($item->fresh()->reply_scheduled_at);
    }

    public function test_a_lot_can_contain_250_messages_but_never_251(): void
    {
        for ($i = 0; $i < 251; $i++) {
            $this->item();
        }
        $this->postJson('/api/inbox/ai-prepare', ['platforms' => ['instagram'], 'limit' => 250])
            ->assertOk()->assertJsonPath('selected', 250)->assertJsonPath('eligible', 251);
    }

    public function test_new_comments_exclude_nested_replies_and_conversations_already_handled(): void
    {
        $eligible = $this->item(['conversation_key' => 'fresh']);
        $this->item(['type' => 'reply']);
        $this->item(['parent_id' => 'parent', 'type' => 'comment']);
        $this->item(['conversation_key' => 'answered']);
        $this->item(['conversation_key' => 'answered', 'type' => 'reply', 'reply_content' => 'Merci', 'status' => 'replied']);
        $this->item(['conversation_key' => 'pending']);
        $this->item(['conversation_key' => 'pending', 'type' => 'reply', 'reply_scheduled_at' => now()->addHour()]);
        $this->postJson('/api/inbox/ai-prepare', ['accounts' => [$this->account->id], 'limit' => 250, 'new_only' => true])
            ->assertOk()->assertJsonPath('selected', 1)->assertJsonPath('items.0.id', $eligible->id);
    }

    public function test_new_comments_exclude_own_answers_even_without_reply_metadata(): void
    {
        $root = $this->item(['conversation_key' => 'own-answer']);
        $this->item(['conversation_key' => 'own-answer', 'parent_id' => $root->external_id,
            'author_external_id' => $this->account->platform_account_id]);
        $this->postJson('/api/inbox/ai-prepare', ['accounts' => [$this->account->id], 'limit' => 1, 'new_only' => true])
            ->assertOk()->assertJsonPath('selected', 0);
    }

    public function test_exact_cycle_counts_include_sent_scheduled_prepared_failed_and_excluded(): void
    {
        $items = [
            $this->item(),
            $this->item(['reply_content' => 'Brouillon']),
            $this->item(['reply_content' => 'Programmée', 'reply_scheduled_at' => now()->addHour()]),
            $this->item(['reply_content' => 'Envoyée', 'status' => 'replied', 'replied_at' => now()]),
            $this->item(['reply_content' => 'Échec', 'status' => 'reply_failed', 'reply_attempts' => 3]),
            $this->item(['status' => 'ignored']),
        ];
        $this->item(); // Another cycle must not change these counts.
        $this->postJson('/api/inbox/status', ['ids' => array_map(fn ($i) => $i->id, $items), 'new_only' => true])
            ->assertOk()->assertJsonPath('counts.total', 6)->assertJsonPath('counts.unprepared', 1)
            ->assertJsonPath('counts.prepared', 1)->assertJsonPath('counts.scheduled', 1)
            ->assertJsonPath('counts.sent', 1)->assertJsonPath('counts.failed', 1)
            ->assertJsonPath('counts.excluded', 1)->assertJsonPath('counts.pending', 3)
            ->assertJsonPath('counts.complete', false)->assertJsonPath('by_platform.instagram.total', 6);
        Http::assertNothingSent();
    }

    public function test_cycle_status_can_be_scoped_by_account_and_includes_disabled_network_history(): void
    {
        $item = $this->item(['status' => 'replied', 'replied_at' => now()]);
        Setting::set('inbox_platform_instagram_enabled', '0');
        $this->getJson('/api/inbox/status?accounts[]='.$this->account->id)->assertOk()
            ->assertJsonPath('counts.sent', 1)->assertJsonPath('counts.complete', true);
        $this->postJson('/api/inbox/status', ['ids' => [$item->id]])->assertOk()->assertJsonPath('counts.total', 1);
    }

    public function test_missing_cycle_items_prevent_a_false_complete_and_unbounded_status_is_refused(): void
    {
        $this->postJson('/api/inbox/status', ['ids' => [99999]])->assertOk()
            ->assertJsonPath('missing_or_inaccessible', 1)->assertJsonPath('counts.complete', false);
        $this->getJson('/api/inbox/status')->assertUnprocessable();
    }

    public function test_a_last_scheduled_attempt_remains_pending_until_the_network_result(): void
    {
        $item = $this->item(['reply_content' => 'Merci', 'reply_attempts' => 3, 'reply_scheduled_at' => now()->subSecond()]);
        $this->postJson('/api/inbox/status', ['ids' => [$item->id]])->assertOk()
            ->assertJsonPath('counts.pending', 1)->assertJsonPath('counts.failed', 0)->assertJsonPath('counts.scheduled', 1);
    }

    public function test_new_comments_detect_a_handled_child_without_a_shared_conversation_key(): void
    {
        $root = $this->item(['conversation_key' => null]);
        $this->item(['conversation_key' => null, 'parent_id' => $root->external_id,
            'type' => 'reply', 'reply_content' => 'Déjà traité']);
        $this->postJson('/api/inbox/ai-prepare', ['accounts' => [$this->account->id], 'limit' => 250, 'new_only' => true])
            ->assertOk()->assertJsonPath('selected', 0);
    }
}
