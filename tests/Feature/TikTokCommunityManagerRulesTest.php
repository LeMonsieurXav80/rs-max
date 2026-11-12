<?php

namespace Tests\Feature;

use App\Jobs\LikeInboxItem;
use App\Models\InboxItem;
use App\Models\Persona;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Inbox\InboxFollowup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Règles du community manager X appliquées aux commentaires reçus sur TikTok (12/11/2026). */
class TikTokCommunityManagerRulesTest extends TestCase
{
    use RefreshDatabase;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-12T12:00:00Z')->setTimezone(config('app.timezone')));
        $user = User::factory()->create(['role' => 'manager']);
        $persona = Persona::create(['name' => 'Persona', 'system_prompt' => 'Ton sobre', 'is_active' => true]);
        $this->account = SocialAccount::create(['name' => 'Compte TikTok', 'platform_id' => Platform::where('slug', 'tiktok')->firstOrFail()->id,
            'persona_id' => $persona->id, 'driver' => 'browser', 'browser_profile' => 'nas-test', 'browser_username' => 'owner']);
        $this->account->users()->attach($user, ['is_active' => true]);
        Sanctum::actingAs($user);
    }

    private function item(string $content, array $extra = []): InboxItem
    {
        return InboxItem::create($extra + ['social_account_id' => $this->account->id, 'platform_id' => $this->account->platform_id, 'type' => 'comment',
            'external_id' => sha1($content.json_encode($extra)), 'author_username' => 'visitor', 'content' => $content,
            'post_content' => 'Tenue du jour', 'status' => 'unread', 'posted_at' => now()->subHours(2)]);
    }

    public function test_merci_seul_et_emojis_seuls_sont_distingues(): void
    {
        $policy = app(InboxFollowup::class);
        foreach (['Merci beaucoup !', 'thank you 🙏', 'Obrigada'] as $text) {
            $this->assertTrue($policy->isThanks($text), $text);
            $this->assertFalse($policy->isEmojiOnly($text), $text);
        }
        foreach (['😍😍😍😍😍', '🥰[Réconfortant][Réconfortant][Yeux en cœur]', '😚😚😚😚[J\'aime]', '🤪', ''] as $text) {
            $this->assertTrue($policy->isEmojiOnly($text), $text);
            $this->assertFalse($policy->isThanks($text), $text);
        }
        foreach (['J\'adooooore[Yeux en cœur]', 'The best 💐', 'je confirme, jolie dame élégante 🥰', 'Merci, tu la trouves où ?', '😍 ?'] as $text) {
            $this->assertFalse($policy->isThanks($text) || $policy->isEmojiOnly($text), $text);
        }
    }

    public function test_un_merci_recoit_un_like_sans_lecture_studio_ni_reponse(): void
    {
        Queue::fake([LikeInboxItem::class]);
        $this->mock(\App\Services\Browser\BrowserTaskQueue::class)->shouldNotReceive('run');
        $item = $this->item('Merci beaucoup !', ['posted_at' => now()->subDays(3)]);

        $this->getJson('/api/community-manager/inbox/'.$item->id.'/context')
            ->assertConflict()->assertJsonPath('skip_item', true)->assertJsonPath('review_reason', 'not_relevant');
        Queue::assertPushed(LikeInboxItem::class, fn ($job) => $job->itemId === $item->id);
        $this->assertSame(0, (int) $item->fresh()->reply_attempts);
    }

    public function test_des_emojis_un_autocollant_ou_un_gif_recoivent_seulement_un_sourire(): void
    {
        Queue::fake();
        foreach (['😍😍😍', ''] as $content) {
            $item = $this->item($content, ['media_type' => $content === '' ? 'sticker' : null]);
            $context = $this->getJson('/api/community-manager/inbox/'.$item->id.'/context')->assertOk()
                ->assertJsonPath('reply_options', InboxFollowup::SMILES)->json('context_id');
            $url = '/api/community-manager/inbox/'.$item->id.'/reply';
            $this->postJson($url, ['context_id' => $context, 'reply_text' => 'Merci pour ces cœurs'])->assertStatus(422);
            $this->postJson($url, ['context_id' => $context, 'reply_text' => '😊'])->assertOk()->assertJsonPath('dry_run', true);
        }
    }

    public function test_une_relance_sans_question_sous_notre_reponse_recoit_un_like(): void
    {
        Queue::fake([LikeInboxItem::class]);
        $item = $this->item('Avec plaisir, belle soirée à toi', ['reply_to_username' => 'owner']);

        $this->getJson('/api/community-manager/inbox/'.$item->id.'/context')->assertConflict()->assertJsonPath('review_reason', 'not_relevant');
        Queue::assertPushed(LikeInboxItem::class);
    }

    public function test_adressage_et_style_avant_toute_reponse(): void
    {
        Queue::fake();
        $direct = $this->item('Très jolie robe, bravo');
        $this->getJson('/api/community-manager/inbox/'.$direct->id.'/context')->assertOk()
            ->assertJsonPath('addressing.role', 'interpelle')->assertJsonPath('publication.text', 'Tenue du jour');

        $other = $this->item('Tu as raison, elle est superbe', ['reply_to_username' => 'amie.de.passage']);
        $context = $this->getJson('/api/community-manager/inbox/'.$other->id.'/context')->assertOk()
            ->assertJsonPath('addressing.role', 'participant')->assertJsonPath('addressing.repond_a', '@amie.de.passage')->json('context_id');
        $this->postJson('/api/community-manager/inbox/'.$other->id.'/reply', ['context_id' => $context, 'reply_text' => 'Merci, c’est gentil'])
            ->assertStatus(422);

        // Le style X ne s’applique pas à TikTok : cœur et question finale passent.
        $context = $this->getJson('/api/community-manager/inbox/'.$direct->id.'/context')->json('context_id');
        foreach (['Merci, elle vient d’une friperie 🥰', 'Une friperie de Lisbonne, tu connais ?'] as $text) {
            $this->postJson('/api/community-manager/inbox/'.$direct->id.'/reply', ['context_id' => $context, 'reply_text' => $text])
                ->assertOk()->assertJsonPath('dry_run', true);
        }
    }

    public function test_un_commentaire_introuvable_deux_fois_est_ecarte_une_semaine(): void
    {
        $this->mock(\App\Services\Browser\BrowserTaskQueue::class)->shouldReceive('run')->twice()->andReturn(['target_found' => false]);
        $item = $this->item('Bonjour la miss', ['posted_at' => now()->subDays(3)]);
        $this->getJson('/api/community-manager/inbox/'.$item->id.'/context')->assertConflict();
        $this->assertSame(360, (int) $item->fresh()->agent_reviewed_at->diffInMinutes($item->fresh()->agent_retry_at));
        $this->travel(7)->hours();
        $this->getJson('/api/community-manager/inbox/'.$item->id.'/context')->assertConflict();
        $this->assertSame(10080, (int) $item->fresh()->agent_reviewed_at->diffInMinutes($item->fresh()->agent_retry_at));
    }

    public function test_la_lecture_studio_garde_le_destinataire_d_une_reponse(): void
    {
        $this->mock(\App\Services\Browser\TikTokSessionService::class)->shouldReceive('check')->andReturnTrue();
        $this->mock(\App\Services\Browser\BrowserTaskQueue::class)->shouldReceive('run')->andReturn(['items' => [
            ['author' => 'visitor', 'content' => 'Elle est superbe', 'caption' => 'Tenue du jour', 'replied' => false, 'reply_to' => 'Amie.De.Passage', 'date' => '2h'],
            ['author' => 'other', 'content' => 'Bravo', 'caption' => 'Tenue du jour', 'replied' => false, 'reply_to' => null, 'date' => '2h'],
        ]]);
        $items = app(\App\Services\Inbox\TikTokInboxService::class)->fetchInbox($this->account);
        $this->assertSame(['amie.de.passage', null], $items->pluck('reply_to_username')->all());
    }

    public function test_les_autres_reseaux_suivent_les_memes_regles(): void
    {
        Queue::fake();
        $fb = SocialAccount::create(['name' => 'Page', 'platform_id' => Platform::firstOrCreate(['slug' => 'facebook'], ['name' => 'Facebook'])->id,
            'persona_id' => $this->account->persona_id, 'platform_account_id' => 'page-1']);
        $fb->users()->attach(User::first(), ['is_active' => true]);
        $item = fn (string $content) => InboxItem::create(['social_account_id' => $fb->id, 'platform_id' => $fb->platform_id, 'type' => 'comment',
            'external_id' => sha1($content), 'author_username' => 'visitor', 'content' => $content, 'post_content' => 'Publication', 'status' => 'unread', 'posted_at' => now()->subHour()]);

        $this->getJson('/api/community-manager/inbox/'.$item('Merci !')->id.'/context')->assertConflict()->assertJsonPath('review_reason', 'not_relevant');
        Queue::assertNotPushed(LikeInboxItem::class);
        $this->getJson('/api/community-manager/inbox/'.$item('👏👏👏')->id.'/context')->assertOk()->assertJsonPath('reply_options', InboxFollowup::SMILES);
    }
}
