<?php

namespace Tests\Feature;

use App\Jobs\LikeInboxItem;
use App\Jobs\SendAutonomousInboxReply;
use App\Models\InboxItem;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Services\Inbox\InboxFollowup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Réseaux par API : emojis seuls et simples mercis traités sans modèle, comme sur TikTok. */
class InboxScriptedTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $slug, array $actions = ['reply']): SocialAccount
    {
        $this->travelTo(Carbon::parse('2026-11-17 14:00', 'Europe/Lisbon'));
        $a = SocialAccount::create(['name' => $slug, 'platform_id' => Platform::firstOrCreate(['slug' => $slug], ['name' => $slug])->id,
            'driver' => 'api', 'credentials' => ['access_token' => 'test']]);
        foreach ($actions as $action) {
            $a->agentActions()->create(['action' => $action, 'enabled' => true, 'daily_limit' => 50]);
        }

        return $a;
    }

    private function item(SocialAccount $a, string $id, string $content): InboxItem
    {
        return InboxItem::create(['social_account_id' => $a->id, 'platform_id' => $a->platform_id, 'external_id' => $id,
            'type' => 'comment', 'author_username' => 'lecteur'.$id, 'content' => $content, 'post_content' => 'Publication',
            'status' => 'unread', 'posted_at' => now()->subHour()]);
    }

    public function test_instagram_emoji_repondu_merci_retire_sans_like_texte_laisse_a_hermes(): void
    {
        Queue::fake();
        $ig = $this->account('instagram');
        [$emoji, $thanks, $text] = [$this->item($ig, '1', '🍀👌'), $this->item($ig, '2', 'Merci beaucoup'), $this->item($ig, '3', 'Stunning 🥰🥰')];

        $this->artisan('inbox:scripted --commit')->assertSuccessful();

        $this->assertContains($emoji->fresh()->reply_content, InboxFollowup::SMILES);
        Queue::assertPushed(SendAutonomousInboxReply::class, fn ($job) => $job->itemId === $emoji->id);
        Queue::assertNotPushed(LikeInboxItem::class);  // Instagram ne s'aime pas par l'API
        $this->assertSame('not_relevant', $thanks->fresh()->agent_review_reason);
        $this->assertNull($thanks->fresh()->reply_content);
        $this->assertNull($text->fresh()->reply_content);
        $this->assertNull($text->fresh()->agent_review_reason);
    }

    public function test_facebook_merci_aime_et_compte_sans_reponse_autorisee_ignore(): void
    {
        Queue::fake();
        $fb = $this->account('facebook', ['reply', 'comment.like']);
        $thanks = $this->item($fb, '1', 'Merci beaucoup');
        $off = $this->account('instagram', []);
        $emoji = $this->item($off, '2', '😍');

        $this->artisan('inbox:scripted --commit')->assertSuccessful();

        Queue::assertPushed(LikeInboxItem::class, fn ($job) => $job->itemId === $thanks->id);
        $this->assertSame('not_relevant_liked', $thanks->fresh()->agent_review_reason);
        $this->assertNull($emoji->fresh()->reply_content);  // action « reply » coupée : rien
    }
}
