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

/** Emojis seuls et simples mercis TikTok traités sans modèle ; le texte reste à Hermès. */
class TikTokScriptedInboxTest extends TestCase
{
    use RefreshDatabase;

    private function items(): array
    {
        $this->travelTo(Carbon::parse('2026-11-16 14:00', 'Europe/Lisbon'));
        $a = SocialAccount::create(['platform_id' => Platform::where('slug', 'tiktok')->firstOrFail()->id, 'name' => 'Compte',
            'driver' => 'browser', 'browser_profile' => 'android-honor', 'browser_username' => 'wildycaro']);
        $make = fn (string $id, string $content) => InboxItem::create(['social_account_id' => $a->id, 'platform_id' => $a->platform_id,
            'external_id' => $id, 'type' => 'comment', 'author_username' => 'ana'.$id, 'content' => $content,
            'post_content' => 'Légende', 'status' => 'unread', 'posted_at' => now()->subHour()]);

        return [$make('1', '😍😍 [Rood hart]'), $make('2', 'Merci beaucoup'), $make('3', 'Où as-tu trouvé cette robe ?')];
    }

    public function test_a_blanc_rien_ne_part(): void
    {
        Queue::fake();
        [$emoji] = $this->items();

        $this->artisan('tiktok:scripted-inbox')->expectsOutputToContain("#{$emoji->id} emoji")->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertNull($emoji->fresh()->reply_content);
    }

    public function test_emoji_repondu_merci_aime_et_texte_laisse_a_hermes(): void
    {
        Queue::fake();
        [$emoji, $thanks, $text] = $this->items();

        $this->artisan('tiktok:scripted-inbox --commit')->assertSuccessful();

        $this->assertContains($emoji->fresh()->reply_content, InboxFollowup::SMILES);
        Queue::assertPushed(SendAutonomousInboxReply::class, fn ($job) => $job->itemId === $emoji->id);
        Queue::assertPushed(LikeInboxItem::class, fn ($job) => $job->itemId === $thanks->id);
        $this->assertSame('not_relevant_liked', $thanks->fresh()->agent_review_reason);
        $this->assertNull($thanks->fresh()->reply_content);
        $this->assertNull($text->fresh()->reply_content);
        Queue::assertNotPushed(SendAutonomousInboxReply::class, fn ($job) => $job->itemId === $text->id);
    }

    public function test_un_report_pour_indecision_dhermes_nempeche_pas_la_reponse_emoji(): void
    {
        Queue::fake();
        [$emoji, $thanks] = $this->items();
        $emoji->update(['agent_review_reason' => 'agent_no_decision', 'agent_retry_at' => now()->addHours(6)]);
        $thanks->update(['agent_review_reason' => 'not_relevant', 'agent_retry_at' => now()->addDays(7)]);

        $this->artisan('tiktok:scripted-inbox --commit')->assertSuccessful();

        $this->assertContains($emoji->fresh()->reply_content, InboxFollowup::SMILES);
        Queue::assertPushed(LikeInboxItem::class, fn ($job) => $job->itemId === $thanks->id); // écarté ⇒ like, jamais de réponse
        $this->assertNull($thanks->fresh()->reply_content);
    }

    public function test_un_compliment_ecarte_par_hermes_est_aime_une_seule_fois(): void
    {
        Queue::fake();
        [, , $text] = $this->items();
        $text->update(['agent_review_reason' => 'not_relevant', 'agent_retry_at' => now()->addDays(7)]);

        $this->artisan('tiktok:scripted-inbox --commit')->assertSuccessful();
        $this->artisan('tiktok:scripted-inbox --commit')->assertSuccessful();

        Queue::assertPushed(LikeInboxItem::class, fn ($job) => $job->itemId === $text->id);
        Queue::assertPushed(LikeInboxItem::class, 2); // le merci et le compliment, une fois chacun
        $this->assertNull($text->fresh()->reply_content);
    }

    public function test_hors_de_la_plage_de_journee_rien_ne_part(): void
    {
        Queue::fake();
        $this->items();
        $this->travelTo(Carbon::parse('2026-11-16 23:30', 'Europe/Lisbon'));

        $this->artisan('tiktok:scripted-inbox --commit')->assertSuccessful();

        Queue::assertNothingPushed();
    }
}
