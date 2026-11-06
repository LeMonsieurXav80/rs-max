<?php

namespace Tests\Feature;

use App\Jobs\LikeInboxItem;
use App\Jobs\PrepareInboxDrafts;
use App\Models\ExternalPost;
use App\Models\InboxItem;
use App\Models\Persona;
use App\Models\Platform;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Agent\AgentCatalog;
use App\Services\Browser\BrowserException;
use App\Services\Browser\BrowserTaskQueue;
use App\Services\Browser\BrowserUncertainException;
use App\Services\Browser\TikTokSessionService;
use App\Services\Inbox\InboxDraftService;
use App\Services\Inbox\InboxReplyService;
use App\Services\Inbox\InboxSyncService;
use App\Services\Inbox\TikTokInboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InboxDraftsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Persona $persona;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create(['role' => 'manager']);
        Setting::setEncrypted('openai_api_key', 'cle');
        $this->persona = Persona::create(['name' => 'Caro', 'system_prompt' => 'Tu es Caro.', 'is_active' => true]);
        $platform = Platform::firstOrCreate(['slug' => 'facebook'], ['name' => 'Facebook']);
        $this->account = SocialAccount::create(['platform_id' => $platform->id, 'name' => 'Page', 'persona_id' => $this->persona->id, 'languages' => ['fr']]);
        app(AgentCatalog::class)->configure($this->account, 'reply', true, 5);
    }

    private function item(string $id, string $text, array $extra = []): InboxItem
    {
        return InboxItem::create($extra + ['social_account_id' => $this->account->id, 'platform_id' => $this->account->platform_id, 'type' => 'comment', 'external_id' => $id,
            'author_name' => 'Fan', 'author_username' => 'fan', 'content' => $text, 'status' => 'unread', 'posted_at' => now()]);
    }

    private function openai(array $replies): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode(['reponses' => $replies])]]], 'usage' => ['total_tokens' => 321],
        ])]);
    }

    private function prepare(): array
    {
        return app(InboxDraftService::class)->prepare($this->account->fresh(['platform', 'persona']), 10);
    }

    private function tiktok(array $comments = [], bool $confirmed = false): SocialAccount
    {
        $platform = Platform::firstOrCreate(['slug' => 'tiktok'], ['name' => 'TikTok']);
        $account = SocialAccount::create(['platform_id' => $platform->id, 'name' => 'Caro', 'persona_id' => $this->persona->id, 'languages' => ['fr'],
            'driver' => 'browser', 'browser_profile' => 'wildycaro', 'browser_username' => 'wildycaro']);

        $sessions = $this->createMock(TikTokSessionService::class);
        $sessions->method('check')->willReturn(true);
        $this->app->instance(TikTokSessionService::class, $sessions);

        $queue = $this->createMock(BrowserTaskQueue::class);
        $queue->method('run')->willReturnCallback(fn ($account, string $kind) => $kind === 'tiktok.comments'
            ? ['items' => $comments]
            : ['confirmed' => $confirmed]);
        $this->app->instance(BrowserTaskQueue::class, $queue);

        return $account;
    }

    private function comment(string $author, string $content, array $extra = []): array
    {
        return $extra + ['author' => $author, 'content' => $content, 'caption' => 'Ma vidéo', 'replied' => false, 'liked' => false];
    }

    public function test_un_seul_prompt_redige_tout_avec_le_persona_et_rien_ne_part(): void
    {
        $this->openai(['1' => 'Merci !', '2' => 'Avec plaisir']);
        $one = $this->item('a', 'Bravo');
        $two = $this->item('b', 'Super');

        $this->assertSame(['created' => 2, 'tokens' => 321], $this->prepare());

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r['messages'][0]['content'], 'Tu es Caro.') && str_contains($r['messages'][1]['content'], 'Bravo') && str_contains($r['messages'][1]['content'], 'Super'));
        $this->assertSame(['Avec plaisir', 'Merci !'], collect([$one, $two])->map(fn ($i) => $i->fresh()->reply_draft)->sort()->values()->all());
        $this->assertNull($one->fresh()->reply_content);
        $this->assertSame('unread', $one->fresh()->status);
    }

    public function test_le_contexte_va_du_message_dorigine_a_la_conversation_en_cours(): void
    {
        $this->openai(['1' => 'Oui']);
        ExternalPost::create(['social_account_id' => $this->account->id, 'platform_id' => $this->account->platform_id, 'external_id' => 'post1', 'content' => 'Mon entraînement du jour', 'posted_at' => now()]);
        $this->item('root', 'Quel programme ?', ['external_post_id' => 'post1', 'reply_content' => 'Le push', 'status' => 'replied', 'replied_at' => now(), 'posted_at' => now()->subHour()]);
        $this->item('child', 'Combien de séries ?', ['external_post_id' => 'post1', 'parent_id' => 'root']);

        $this->prepare();

        Http::assertSent(function ($r) {
            $sent = $r['messages'][1]['content'];

            return strpos($sent, 'Mon entraînement du jour') < strpos($sent, 'Quel programme ?')
                && strpos($sent, 'Quel programme ?') < strpos($sent, 'Le push')
                && strpos($sent, 'Le push') < strpos($sent, 'Combien de séries ?');
        });
    }

    public function test_preparer_ne_reprend_pas_un_message_deja_redige(): void
    {
        $this->openai(['1' => 'Merci']);
        $this->item('a', 'Bravo', ['reply_draft' => 'Déjà prêt']);

        $this->assertSame(['created' => 0, 'tokens' => 0], $this->prepare());
        Http::assertNothingSent();
    }

    public function test_le_quota_du_jour_borne_les_brouillons_de_lagent(): void
    {
        app(AgentCatalog::class)->configure($this->account, 'reply', true, 1);
        $this->openai(['1' => 'Merci']);
        $this->item('a', 'Bravo');
        $this->item('b', 'Super');

        $this->assertSame(1, $this->prepare()['created']);
        $this->assertSame(0, $this->prepare()['created']);
    }

    public function test_lenvoi_efface_le_brouillon_et_garde_la_reponse(): void
    {
        $item = $this->item('a', 'Bravo', ['reply_draft' => 'Merci']);
        $sync = $this->createMock(InboxSyncService::class);
        $sync->method('getServiceForAccount')->willReturn(new class implements \App\Services\Inbox\PlatformInboxInterface
        {
            public function fetchInbox(SocialAccount $account, ?\Carbon\Carbon $since = null): \Illuminate\Support\Collection
            {
                return collect();
            }

            public function sendReply(SocialAccount $account, InboxItem $item, string $replyText): array
            {
                return ['success' => true, 'external_id' => 'r1'];
            }
        });
        $this->app->instance(InboxSyncService::class, $sync);

        app(InboxReplyService::class)->send($item, 'Merci, corrigé');

        $this->assertNull($item->fresh()->reply_draft);
        $this->assertSame('Merci, corrigé', $item->fresh()->reply_content);
    }

    public function test_lapi_lance_la_preparation_et_liste_ce_qui_attend(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->manager);
        $item = $this->item('a', 'Bravo', ['reply_draft' => 'Merci']);

        $this->postJson("/api/agent/accounts/{$this->account->id}/drafts", ['limit' => 5])->assertStatus(202);
        Queue::assertPushed(PrepareInboxDrafts::class);

        $this->getJson('/api/agent/drafts')->assertOk()->assertJsonPath('drafts.0.id', $item->id)->assertJsonPath('drafts.0.reply', 'Merci');
    }

    public function test_sans_persona_lapi_refuse_de_preparer(): void
    {
        Sanctum::actingAs($this->manager);
        $this->account->update(['persona_id' => null]);

        $this->postJson("/api/agent/accounts/{$this->account->id}/drafts")->assertStatus(422);
    }

    public function test_une_reponse_trop_longue_est_ecartee(): void
    {
        $this->openai(['1' => str_repeat('a', 600)]);
        $item = $this->item('a', 'Bravo');

        $this->assertSame(0, $this->prepare()['created']);
        $this->assertNull($item->fresh()->reply_draft);
    }

    public function test_longlet_a_valider_montre_le_brouillon_et_le_menu_son_compteur(): void
    {
        $this->item('a', 'Bravo', ['reply_draft' => 'Merci beaucoup', 'post_content' => 'Ma vidéo']);
        $this->item('b', 'Sans brouillon');

        $this->actingAs($this->manager)->get('/inbox?status=draft')->assertOk()
            ->assertSee('Merci beaucoup')->assertSee('Sur : Ma vidéo')->assertDontSee('Sans brouillon');
    }

    public function test_longlet_a_valider_ignore_les_comptes_par_defaut(): void
    {
        $this->item('a', 'Bravo', ['reply_draft' => 'Merci beaucoup']);
        $this->manager->update(['default_accounts' => [999]]);

        $this->actingAs($this->manager)->get('/inbox?status=draft')->assertOk()->assertSee('Merci beaucoup');
        $this->actingAs($this->manager)->get('/inbox')->assertOk()->assertDontSee('Merci beaucoup');
    }

    public function test_le_lot_de_la_page_rend_les_textes_par_message(): void
    {
        $this->openai(['1' => 'Merci']);
        $item = $this->item('a', 'Bravo');

        $this->actingAs($this->manager)->postJson('/inbox/bulk-ai-generate', ['ids' => [$item->id]])
            ->assertOk()->assertJsonPath('replies.'.$item->id, 'Merci');
        Http::assertSentCount(1);
    }

    public function test_un_brouillon_nest_pas_regenere_par_le_lot(): void
    {
        $item = $this->item('a', 'Bravo', ['reply_draft' => 'Merci']);

        $this->actingAs($this->manager)->postJson('/inbox/bulk-ai-prepare', ['ids' => [$item->id]])
            ->assertOk()->assertJsonPath('items.0.reply', 'Merci')->assertJsonPath('items.0.loading', false);
    }

    public function test_tiktok_entre_dans_la_messagerie_comme_les_autres_reseaux(): void
    {
        $account = $this->tiktok([$this->comment('fan', 'Bravo'), $this->comment('fan', 'Déjà vu', ['replied' => true]), $this->comment('wildycaro', 'Moi')]);

        $this->assertSame(1, app(InboxSyncService::class)->syncAccount($account->fresh('platform'))['fetched']);
        app(InboxSyncService::class)->syncAccount($account->fresh('platform'));

        $item = InboxItem::where('social_account_id', $account->id)->sole();
        $this->assertSame(['fan', 'Bravo', 'Ma vidéo'], [$item->author_username, $item->content, $item->post_content]);
    }

    public function test_la_date_relative_de_studio_devient_une_date(): void
    {
        $this->freezeSecond();
        $account = $this->tiktok([$this->comment('fan', 'Bravo', ['date' => '3h ago']), $this->comment('ami', 'Super', ['date' => 'il y a 2 j']), $this->comment('zoe', 'Top', ['date' => 'inconnu'])]);

        app(InboxSyncService::class)->syncAccount($account->fresh('platform'));

        $dates = InboxItem::where('social_account_id', $account->id)->pluck('posted_at', 'author_username');
        $this->assertTrue($dates['fan']->equalTo(now()->subHours(3)));
        $this->assertTrue($dates['ami']->equalTo(now()->subDays(2)));
        $this->assertTrue($dates['zoe']->equalTo(now()));
    }

    public function test_tiktok_repond_avec_la_legende_comme_contexte(): void
    {
        $this->openai(['1' => 'Merci']);
        $account = $this->tiktok();
        $item = InboxItem::create(['social_account_id' => $account->id, 'platform_id' => $account->platform_id, 'type' => 'comment', 'external_id' => 'h', 'author_username' => 'fan',
            'content' => 'Bravo', 'post_content' => 'Ma vidéo', 'status' => 'unread', 'posted_at' => now()]);

        app(InboxDraftService::class)->batch($account->fresh(['platform', 'persona']), collect([$item]));

        Http::assertSent(fn ($r) => str_contains($r['messages'][1]['content'], 'Ma vidéo'));
        $this->assertSame('Merci', $item->fresh()->reply_draft);
    }

    public function test_lenvoi_tiktok_cible_le_commentaire_exact(): void
    {
        Queue::fake();
        $account = $this->tiktok();
        $queue = $this->createMock(BrowserTaskQueue::class);
        $sent = [];
        $queue->expects($this->once())->method('run')->willReturnCallback(function ($a, string $kind, array $p) use (&$sent) {
            $sent[$kind] = $p;

            return ['confirmed' => true];
        });
        $this->app->instance(BrowserTaskQueue::class, $queue);
        $item = InboxItem::create(['social_account_id' => $account->id, 'platform_id' => $account->platform_id, 'type' => 'comment', 'external_id' => 'h', 'author_username' => 'fan',
            'content' => 'Bravo', 'post_content' => 'Ma vidéo', 'status' => 'unread', 'posted_at' => now()]);

        $this->assertTrue(app(InboxReplyService::class)->send($item, 'Merci')['success']);
        $this->assertSame('replied', $item->fresh()->status);
        $this->assertSame(['author' => 'fan', 'content' => 'Bravo', 'caption' => 'Ma vidéo'], $sent['tiktok.reply']['target']);
        $this->assertSame('Merci', $sent['tiktok.reply']['text']);
        $this->assertTrue($sent['tiktok.reply']['confirm_after_reload']);
    }

    public function test_une_reponse_que_tiktok_ne_garde_pas_nest_pas_marquee_repondue(): void
    {
        $account = $this->tiktok([$this->comment('fan', 'Bravo')]);
        $item = InboxItem::create(['social_account_id' => $account->id, 'platform_id' => $account->platform_id, 'type' => 'comment', 'external_id' => 'h', 'author_username' => 'fan',
            'content' => 'Bravo', 'post_content' => 'Ma vidéo', 'status' => 'read', 'posted_at' => now()]);

        $this->assertFalse(app(InboxReplyService::class)->send($item, 'Merci')['success']);

        $this->assertSame('reply_failed', $item->fresh()->status);
        $this->assertNull($item->fresh()->replied_at);
    }

    public function test_le_like_nest_retenu_que_si_tiktok_laffiche(): void
    {
        $account = $this->tiktok([$this->comment('fan', 'Bravo')]);
        $item = InboxItem::create(['social_account_id' => $account->id, 'platform_id' => $account->platform_id, 'type' => 'comment', 'external_id' => 'h', 'author_username' => 'fan',
            'content' => 'Bravo', 'post_content' => 'Ma vidéo', 'status' => 'read', 'posted_at' => now()]);

        (new LikeInboxItem($item->id))->handle(app(TikTokInboxService::class));
        $this->assertNull($item->fresh()->liked_at);

        $queue = $this->createMock(BrowserTaskQueue::class);
        $queue->expects($this->once())->method('run')->willReturnCallback(function ($account, string $kind, array $payload) {
            $this->assertSame('tiktok.like', $kind);
            $this->assertTrue($payload['confirm_after_reload']);

            return ['confirmed' => true];
        });
        $this->app->instance(BrowserTaskQueue::class, $queue);
        $this->app->forgetInstance(TikTokInboxService::class);

        (new LikeInboxItem($item->id))->handle(app(TikTokInboxService::class));
        $this->assertNotNull($item->fresh()->liked_at);
    }

    public function test_une_reponse_tiktok_confirmee_est_likee(): void
    {
        Queue::fake();
        $account = $this->tiktok(confirmed: true);
        $item = InboxItem::create(['social_account_id' => $account->id, 'platform_id' => $account->platform_id, 'type' => 'comment', 'external_id' => 'h', 'author_username' => 'fan',
            'content' => 'Bravo', 'post_content' => 'Ma vidéo', 'status' => 'read', 'posted_at' => now()]);

        app(InboxReplyService::class)->send($item, 'Merci');

        Queue::assertPushed(LikeInboxItem::class, fn ($job) => $job->itemId === $item->id);
    }

    public function test_lenvoi_tiktok_immediat_attend_le_planificateur_avant_de_liker(): void
    {
        Queue::fake();
        $account = $this->tiktok();
        $account->users()->attach($this->manager->id, ['is_active' => true]);
        Sanctum::actingAs($this->manager);
        $item = $this->item('tiktok-async', 'Bravo', ['social_account_id' => $account->id, 'platform_id' => $account->platform_id]);
        $queue = $this->createMock(BrowserTaskQueue::class);
        $queue->expects($this->once())->method('run')->willReturnCallback(function ($account, $kind, $payload, $timeout) {
            $this->assertSame('tiktok.reply', $kind);
            $this->assertGreaterThan(105, $timeout);

            return ['confirmed' => true];
        });
        $this->app->instance(BrowserTaskQueue::class, $queue);

        $this->postJson('/api/inbox/'.$item->id.'/reply', ['reply_text' => 'Merci', 'dry_run' => false])
            ->assertAccepted()->assertJsonPath('success', true)->assertJsonStructure(['scheduled_at']);
        $this->assertNull($item->fresh()->replied_at);
        Queue::assertNotPushed(LikeInboxItem::class);

        $this->artisan('inbox:send-scheduled')->assertSuccessful();
        $this->assertSame('replied', $item->fresh()->status);
        Queue::assertPushed(LikeInboxItem::class, fn ($job) => $job->itemId === $item->id);
    }

    public function test_une_tentative_tiktok_interrompue_nest_pas_rejouee(): void
    {
        $account = $this->tiktok();
        $item = $this->item('tiktok-interrupted', 'Bravo', [
            'social_account_id' => $account->id, 'platform_id' => $account->platform_id,
            'reply_scheduled_at' => now(), 'reply_content' => 'Merci', 'reply_attempts' => 1,
        ]);
        $queue = $this->createMock(BrowserTaskQueue::class);
        $queue->expects($this->never())->method('run');
        $this->app->instance(BrowserTaskQueue::class, $queue);

        $this->assertFalse(app(InboxReplyService::class)->send($item, 'Merci', scheduled: true)['success']);
        $this->assertSame('reply_failed', $item->fresh()->status);
        $this->assertFalse(app(InboxReplyService::class)->submit($item->fresh(), 'Merci')['success']);
    }

    public function test_une_reponse_non_gardee_nest_pas_likee(): void
    {
        Queue::fake();
        $account = $this->tiktok([$this->comment('fan', 'Bravo')]);
        $item = InboxItem::create(['social_account_id' => $account->id, 'platform_id' => $account->platform_id, 'type' => 'comment', 'external_id' => 'h', 'author_username' => 'fan',
            'content' => 'Bravo', 'post_content' => 'Ma vidéo', 'status' => 'read', 'posted_at' => now()]);

        app(InboxReplyService::class)->send($item, 'Merci');

        Queue::assertNotPushed(LikeInboxItem::class);
    }

    public function test_un_envoi_non_confirme_nest_jamais_relance(): void
    {
        $account = $this->tiktok();
        $queue = $this->createMock(BrowserTaskQueue::class);
        $queue->expects($this->once())->method('run')->willThrowException(new BrowserUncertainException('Réponse non confirmée : vérifier sur TikTok.'));
        $this->app->instance(BrowserTaskQueue::class, $queue);
        $item = InboxItem::create(['social_account_id' => $account->id, 'platform_id' => $account->platform_id, 'type' => 'comment', 'external_id' => 'h', 'author_username' => 'fan',
            'content' => 'Bravo', 'post_content' => 'Ma vidéo', 'status' => 'read', 'posted_at' => now(), 'reply_scheduled_at' => now()->subMinute(), 'reply_content' => 'Merci']);

        $this->artisan('inbox:send-scheduled');
        $this->artisan('inbox:send-scheduled');

        $this->assertSame('reply_failed', $item->fresh()->status);
        $this->assertNull($item->fresh()->replied_at);
        $this->assertNull($item->fresh()->reply_scheduled_at);
    }

    public function test_un_refus_avant_envoi_reste_relancable(): void
    {
        $account = $this->tiktok();
        $queue = $this->createMock(BrowserTaskQueue::class);
        $queue->method('run')->willThrowException(new BrowserException('Commentaire introuvable : rien n’a été envoyé.'));
        $this->app->instance(BrowserTaskQueue::class, $queue);
        $item = InboxItem::create(['social_account_id' => $account->id, 'platform_id' => $account->platform_id, 'type' => 'comment', 'external_id' => 'h', 'author_username' => 'fan',
            'content' => 'Bravo', 'post_content' => 'Ma vidéo', 'status' => 'read', 'posted_at' => now()]);

        app(InboxReplyService::class)->send($item, 'Merci');

        $this->assertSame('read', $item->fresh()->status);
    }

    public function test_le_lot_par_le_navigateur_part_par_la_planification(): void
    {
        $account = $this->tiktok();
        $item = InboxItem::create(['social_account_id' => $account->id, 'platform_id' => $account->platform_id, 'type' => 'comment', 'external_id' => 'h', 'author_username' => 'fan',
            'content' => 'Bravo', 'post_content' => 'Ma vidéo', 'status' => 'read', 'posted_at' => now(), 'reply_draft' => 'Merci']);

        $this->actingAs($this->manager)->postJson('/inbox/bulk-send', ['items' => [['id' => $item->id, 'reply_text' => 'Merci !']]])->assertOk();

        $this->assertNotNull($item->fresh()->reply_scheduled_at);
        $this->assertSame('Merci !', $item->fresh()->reply_content);
        $this->assertNull($item->fresh()->reply_draft);
    }
}
