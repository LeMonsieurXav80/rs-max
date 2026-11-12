<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Models\Platform;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Adapters\AdapterFactory;
use App\Services\Adapters\TwitterAdapter;
use App\Services\Adapters\TwitterBrowserAdapter;
use App\Services\Browser\BrowserException;
use App\Services\Browser\BrowserTaskQueue;
use App\Services\Browser\XSessionService;
use App\Services\Inbox\InboxSyncService;
use App\Services\Inbox\TwitterBrowserInboxService;
use App\Services\Stats\XBrowserStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Companion simulé : répond aux tâches, sans navigateur. */
class FakeQueue extends BrowserTaskQueue
{
    public array $calls = [];

    public ?string $username = 'wildycaro';

    public bool $lostUntilApply = false;

    public array $results = [];

    public ?string $failure = null;

    public function run(SocialAccount $account, string $kind, array $payload, int $timeout = 120): array
    {
        $this->calls[] = [$kind, $payload];

        if ($kind === 'session.apply') {
            $this->lostUntilApply = false;

            return [];
        }

        if ($kind === 'x.whoami') {
            return ['username' => $this->lostUntilApply ? null : $this->username];
        }

        if ($this->failure) {
            throw new BrowserException($this->failure);
        }

        return $this->results[$kind] ?? [];
    }

    public function kinds(): array
    {
        return array_column($this->calls, 0);
    }
}

class XBrowserTest extends TestCase
{
    use RefreshDatabase;

    private FakeQueue $queue;

    private SocialAccount $account;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = new FakeQueue;
        $this->app->instance(BrowserTaskQueue::class, $this->queue);
        $this->user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($this->user);
        $platform = Platform::firstOrCreate(['slug' => 'twitter'], ['name' => 'X']);
        $this->account = SocialAccount::create(['platform_id' => $platform->id, 'platform_account_id' => '77', 'name' => 'Wildy', 'driver' => 'browser',
            'browser_profile' => 'wildycaro', 'browser_username' => 'wildycaro', 'languages' => ['fr']]);
        $this->account->users()->attach($this->user, ['is_active' => true]);
    }

    public function test_la_fenetre_autonome_suit_la_reponse_jusqu_a_la_tache_nas(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-07T12:00:00Z'));
        $window = app(\App\Services\Agent\CommunityManagerWindow::class)->specification();
        $item = InboxItem::create(['social_account_id' => $this->account->id, 'platform_id' => $this->account->platform_id,
            'type' => 'comment', 'external_id' => '555', 'content' => 'Bonjour', 'status' => 'unread', 'reply_activity_window' => $window]);
        $this->queue->results['x.post'] = ['id' => '666', 'confirmed' => true, 'proof' => 'after_reload'];
        $result = app(TwitterBrowserInboxService::class)->sendReply($this->account, $item, 'Bonjour !');
        $this->assertTrue($result['success']);
        $this->assertSame($window, collect($this->queue->calls)->firstWhere(0, 'x.post')[1]['activity_window']);
    }

    private function cookies(string $id = '77'): array
    {
        return [
            ['name' => 'auth_token', 'value' => 'secret', 'domain' => '.x.com'],
            ['name' => 'ct0', 'value' => 'csrf', 'domain' => '.x.com'],
            ['name' => 'twid', 'value' => "u%3D{$id}", 'domain' => '.x.com'],
        ];
    }

    private function adapter(): TwitterBrowserAdapter
    {
        return app(TwitterBrowserAdapter::class);
    }

    public function test_la_session_recue_est_chiffree_appliquee_et_verifiee(): void
    {
        $this->postJson('/api/extension/x/session', ['cookies' => $this->cookies()])
            ->assertOk()->assertJson(['status' => 'connected', 'username' => 'wildycaro']);

        $this->account->refresh();
        $this->assertSame(['session.apply', 'x.whoami'], $this->queue->kinds());
        $this->assertSame('secret', $this->account->browser_session[0]['value']);
        $this->assertStringNotContainsString('secret', (string) $this->account->getRawOriginal('browser_session'));
        $this->assertArrayNotHasKey('browser_session', $this->account->toArray());
    }

    public function test_les_cookies_dun_autre_compte_sont_refuses(): void
    {
        $this->postJson('/api/extension/x/session', ['cookies' => $this->cookies('88')])->assertStatus(422);

        $this->assertSame([], $this->queue->calls);
    }

    public function test_une_session_perdue_est_restauree_depuis_la_copie_chiffree(): void
    {
        $this->account->update(['browser_session' => $this->cookies()]);
        $this->queue->lostUntilApply = true;

        $this->assertTrue(app(XSessionService::class)->check($this->account));
        $this->assertSame(['x.whoami', 'session.apply', 'x.whoami'], $this->queue->kinds());
        $this->assertSame('connected', $this->account->fresh()->browser_session_status);
    }

    public function test_une_session_expiree_bloque_avant_toute_action(): void
    {
        $this->queue->username = null;

        $result = $this->adapter()->publish($this->account, 'Bonjour');

        $this->assertFalse($result['success']);
        $this->assertSame('expired', $this->account->fresh()->browser_session_status);
        $this->assertNotContains('x.post', $this->queue->kinds());
    }

    public function test_le_profil_dun_autre_compte_ne_publie_pas(): void
    {
        $this->queue->username = 'autrecompte';

        $this->assertFalse($this->adapter()->publish($this->account, 'Bonjour')['success']);
        $this->assertNotContains('x.post', $this->queue->kinds());
    }

    public function test_publier_renvoie_lid_et_le_lien_du_tweet(): void
    {
        $this->queue->results['x.post'] = ['id' => '500', 'confirmed' => true, 'proof' => 'after_reload'];

        $result = $this->adapter()->publish($this->account, 'Bonjour le monde');

        $this->assertTrue($result['success']);
        $this->assertSame('500', $result['external_id']);
        $this->assertSame('https://x.com/wildycaro/status/500', $result['permalink']);
    }

    public function test_une_video_part_seule_et_quatre_images_au_plus(): void
    {
        $this->queue->results['x.post'] = ['id' => '500', 'confirmed' => true, 'proof' => 'after_reload'];
        $image = ['url' => 'https://rs/a.jpg', 'mimetype' => 'image/jpeg'];

        $this->adapter()->publish($this->account, 'Texte', array_fill(0, 6, $image));
        $this->assertCount(4, collect($this->queue->calls)->firstWhere(0, 'x.post')[1]['media']);

        $this->queue->calls = [];
        $this->adapter()->publish($this->account, 'Texte', [$image, ['url' => 'https://rs/b.mp4', 'mimetype' => 'video/mp4']]);
        $media = collect($this->queue->calls)->firstWhere(0, 'x.post')[1]['media'];
        $this->assertCount(1, $media);
        $this->assertSame('video/mp4', $media[0]['mimetype']);
    }

    public function test_un_refus_de_x_remonte_son_motif(): void
    {
        $this->queue->failure = 'Vous avez atteint votre limite quotidienne';

        $result = $this->adapter()->publish($this->account, 'Bonjour');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('limite quotidienne', $result['error']);
    }

    public function test_repondre_a_un_commentaire_passe_par_le_navigateur(): void
    {
        $this->queue->results['x.post'] = ['id' => '600', 'confirmed' => true, 'proof' => 'after_reload'];
        $item = InboxItem::create(['platform_id' => $this->account->platform_id, 'social_account_id' => $this->account->id, 'type' => 'comment', 'external_id' => '555', 'status' => 'unread']);

        $service = app(InboxSyncService::class)->getServiceForAccount($this->account);
        $result = $service->sendReply($this->account, $item, 'Merci à toi');

        $this->assertInstanceOf(TwitterBrowserInboxService::class, $service);
        $this->assertSame('600', $result['external_id']);
        $this->assertSame('https://x.com/compose/post?in_reply_to=555', collect($this->queue->calls)->firstWhere(0, 'x.post')[1]['url']);
    }

    public function test_citer_ouvre_le_composer_avec_la_source_exacte(): void
    {
        $this->adapter()->nativeQuote($this->account, 'Mon avis', '555');

        $payload = collect($this->queue->calls)->firstWhere(0, 'x.post')[1];
        $this->assertSame('Mon avis', $payload['text']);
        $this->assertSame('https://x.com/compose/post?quote_tweet_id=555', $payload['url']);
    }

    public function test_les_mentions_lues_excluent_le_compte_lui_meme(): void
    {
        $tweet = fn ($id, $author) => ['id' => $id, 'author' => $author, 'name' => 'N', 'avatar' => null, 'text' => 'T', 'published_at' => null];
        $this->queue->results['x.tweets'] = ['tweets' => [$tweet('600', 'wildycaro'), $tweet('601', 'visiteur')]];

        $items = app(TwitterBrowserInboxService::class)->fetchInbox($this->account);

        $this->assertSame(['601'], $items->pluck('external_id')->all());
    }

    public function test_la_synchro_companion_x_selectionne_les_comptes_opt_in_si_x_est_desactive_globalement(): void
    {
        Setting::set('inbox_platform_twitter_enabled', '0');
        $this->account->agentActions()->create(['action' => 'mentions.read', 'enabled' => true, 'daily_limit' => 100]);
        foreach (['browser', 'api'] as $driver) {
            $other = SocialAccount::create(['platform_id' => $this->account->platform_id, 'platform_account_id' => $driver,
                'driver' => $driver, 'name' => $driver]);
            $other->agentActions()->create(['action' => 'mentions.read', 'enabled' => $driver === 'api', 'daily_limit' => 100]);
        }
        $this->queue->results['x.tweets'] = ['tweets' => [
            ['id' => '601', 'author' => 'visiteur', 'name' => 'N', 'avatar' => null, 'text' => 'T', 'published_at' => now()->toIso8601String()],
        ]];

        $this->artisan('inbox:sync --platform=twitter --driver=browser')->assertSuccessful();

        $this->assertSame(['x.whoami', 'x.tweets', 'x.tweets'], $this->queue->kinds());
        $this->assertSame($this->account->id, InboxItem::where('external_id', '601')->sole()->social_account_id);
    }

    public function test_les_reponses_absentes_de_l_onglet_mentions_viennent_de_la_recherche_recente(): void
    {
        $tweet = fn ($id) => ['id' => $id, 'author' => 'visiteur', 'name' => 'N', 'avatar' => null, 'text' => 'T', 'published_at' => now()->toIso8601String()];
        $queue = new class extends FakeQueue
        {
            public function run(SocialAccount $account, string $kind, array $payload, int $timeout = 120): array
            {
                if ($kind === 'x.tweets' && str_contains($payload['url'], '/search?')) {
                    $this->calls[] = [$kind, $payload];
                    if ($this->failure) {
                        throw new BrowserException($this->failure);
                    }

                    return $this->results['search'];
                }

                return parent::run($account, $kind, $payload, $timeout);
            }
        };
        $this->app->instance(BrowserTaskQueue::class, $queue);
        $queue->results['x.tweets'] = ['tweets' => [$tweet('601')]];
        $queue->results['search'] = ['tweets' => [$tweet('601'), $tweet('602')]];

        $items = app(TwitterBrowserInboxService::class)->fetchInbox($this->account);

        $this->assertSame(['601', '602'], $items->pluck('external_id')->all());
        $this->assertSame('https://x.com/search?q=to%3Awildycaro&src=typed_query&f=live', $queue->calls[2][1]['url']);
    }

    public function test_la_synchro_des_mentions_publiques_conserve_la_date_x_et_les_reponses_existantes(): void
    {
        $previousTimezone = date_default_timezone_get();
        config(['app.timezone' => 'Europe/Paris']);
        date_default_timezone_set('Europe/Paris');

        try {
            $tweet = ['id' => '2120555661050126802', 'author' => 'visiteur', 'name' => 'N', 'avatar' => null,
                'text' => 'T', 'published_at' => '2026-11-11T16:34:02.000Z'];
            $this->queue->results['x.tweets'] = ['tweets' => [$tweet]];
            $sync = app(InboxSyncService::class);

            $this->assertSame(1, $sync->syncAccount($this->account)['fetched']);
            $item = InboxItem::where('external_id', $tweet['id'])->sole();
            $this->assertSame('2026-11-11T16:34:02+00:00', $item->posted_at->utc()->toIso8601String());

            DB::table('inbox_items')->where('id', $item->id)->update([
                'posted_at' => '2026-11-11 16:34:02', 'status' => 'replied', 'reply_attempts' => 1,
                'replied_at' => '2026-11-11 18:00:00', 'reply_content' => 'Merci !', 'agent_review_reason' => 'already_handled',
            ]);

            $this->assertSame(0, $sync->syncAccount($this->account)['fetched']);
            $item->refresh();
            $this->assertSame('2026-11-11T16:34:02+00:00', $item->posted_at->utc()->toIso8601String());
            $this->assertSame('replied', $item->status);
            $this->assertSame(1, (int) $item->reply_attempts);
            $this->assertSame('2026-11-11 18:00:00', $item->replied_at->toDateTimeString());
            $this->assertSame('Merci !', $item->reply_content);
            $this->assertSame('already_handled', $item->agent_review_reason);
            $this->assertNotContains('x.post', $this->queue->kinds());
        } finally {
            date_default_timezone_set($previousTimezone);
        }
    }

    public function test_une_mention_publique_sans_horodatage_visible_utilise_son_identifiant_x(): void
    {
        $this->queue->results['x.tweets'] = ['tweets' => [
            ['id' => '2120555661050126802', 'author' => 'visiteur', 'name' => 'N', 'avatar' => null, 'text' => 'T'],
        ]];

        $item = app(TwitterBrowserInboxService::class)->fetchInbox($this->account)->sole();

        $this->assertSame('2026-11-11T16:34:02+00:00', $item['posted_at']->utc()->toIso8601String());
    }

    public function test_le_mode_de_connexion_change_le_chemin_de_chaque_compte(): void
    {
        $this->assertInstanceOf(TwitterBrowserAdapter::class, AdapterFactory::forAccount($this->account));

        $this->account->update(['driver' => 'api']);

        $this->assertInstanceOf(TwitterAdapter::class, AdapterFactory::forAccount($this->account->fresh()));
    }

    public function test_la_fiche_du_compte_ne_propose_plus_de_signature(): void
    {
        $this->actingAs($this->user)->get(route('accounts.edit', $this->account))->assertOk()->assertDontSee('Branding');
    }

    public function test_le_formulaire_du_compte_bascule_le_mode_et_revient_a_lapi(): void
    {
        $form = ['name' => 'Wildy', 'languages' => ['fr'], 'driver' => 'api'];

        $this->actingAs($this->user)->put(route('accounts.update', $this->account), $form)->assertRedirect();
        $this->assertSame('api', $this->account->fresh()->driver);

        $this->actingAs($this->user)->put(route('accounts.update', $this->account), ['driver' => 'browser', 'browser_profile' => 'planetedecaro'] + $form)->assertRedirect();
        $this->assertTrue($this->account->fresh()->usesBrowser());
        $this->assertSame('planetedecaro', $this->account->fresh()->browser_profile);
    }

    public function test_les_stats_affichees_par_x_sont_lues_dans_les_deux_langues(): void
    {
        $this->assertSame(['replies' => 3, 'reposts' => 2, 'likes' => 1204, 'bookmarks' => 1, 'views' => 9000],
            XBrowserStatsService::parseStats("3 réponses, 2 reposts, 1 204 J''aime, 1 signet, 9 000 vues"));
        $this->assertSame(['replies' => 1, 'likes' => 12, 'views' => 345],
            XBrowserStatsService::parseStats('1 reply, 12 likes, 345 views'));
    }

    public function test_un_article_en_mode_navigateur_ne_se_replie_jamais_sur_lapi(): void
    {
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $result = $this->adapter()->publish($this->account, 'Texte', null, ['article_title' => 'Article']);
        $this->assertFalse($result['success']);
        $this->assertSame([], $this->queue->calls);
    }

    public function test_la_livraison_planifiee_transmet_sa_cle_et_refuse_une_confirmation_sans_relecture(): void
    {
        $this->queue->results['x.post'] = ['id' => '500'];
        $result = $this->adapter()->publish($this->account, 'Texte', null, ['publication_key' => 'post-platform:32']);
        $this->assertFalse($result['success']);
        $this->assertTrue($result['uncertain']);
        $this->assertSame('post-platform:32', collect($this->queue->calls)->firstWhere(0, 'x.post')[1]['publication_key']);
    }

    public function test_le_nouveau_vocabulaire_des_metriques_x_est_reconnu(): void
    {
        $this->assertSame(['replies' => 1, 'likes' => 160, 'views' => 1200], XBrowserStatsService::parseStats('1 Répondre, 160 aimer, 1 200 vues'));
    }

    public function test_les_abonnes_arrondis_sont_exposes_sans_ecraser_le_compteur_exact(): void
    {
        $this->account->update(['followers_count' => 22598]);
        $this->queue->results['x.tweets'] = ['tweets' => [], 'followers' => null,
            'followers_observation' => ['value' => 22600, 'precision' => 'rounded', 'display' => '22,6 k Abonnés']];
        $result = app(XBrowserStatsService::class)->sync($this->account);
        $this->assertFalse($result['followers']);
        $this->assertSame(22598, $this->account->fresh()->followers_count);
        $this->assertSame('rounded', $this->account->fresh()->browser_followers_observation['precision']);
        $this->getJson('/api/accounts')->assertOk()->assertJsonPath('accounts.0.browser_followers_observation.value', 22600);
    }

    public function test_le_job_dune_publication_planifiee_utilise_companion_sans_appel_x_api(): void
    {
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $this->queue->results['x.post'] = ['id' => '500', 'confirmed' => true, 'proof' => 'after_reload'];
        $post = \App\Models\Post::create(['user_id' => $this->user->id, 'content_fr' => 'Une publication planifiée', 'status' => 'scheduled', 'scheduled_at' => now()->addHour()]);
        $pp = \App\Models\PostPlatform::create(['post_id' => $post->id, 'platform_id' => $this->account->platform_id, 'social_account_id' => $this->account->id, 'status' => 'pending']);
        (new \App\Jobs\PublishToPlatformJob($pp))->handle(app(\App\Services\PublishingService::class));
        $this->assertSame('published', $pp->fresh()->status);
        $this->assertSame('500', $pp->fresh()->external_id);
        $this->assertSame('post-platform:'.$pp->id, collect($this->queue->calls)->firstWhere(0, 'x.post')[1]['publication_key']);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_le_menu_choisit_le_serveur_par_profil_sans_changer_le_driver_interne(): void
    {
        $url = route('accounts.edit', $this->account);
        $update = route('accounts.update', $this->account);
        $form = ['name' => 'Mon compte', 'languages' => ['fr']];
        $this->account->update(['driver' => 'api', 'browser_profile' => 'vps-test']);

        $this->actingAs($this->user)->get($url)->assertOk()
            ->assertSee('value="api" selected', false)
            ->assertSee('Companion sur le VPS')
            ->assertSee('Companion sur le NAS');

        $this->put($update, ['driver' => 'browser_vps', 'browser_profile' => 'vps-test'] + $form)
            ->assertSessionHasNoErrors()->assertRedirect($url);
        $this->assertSame('browser', $this->account->refresh()->driver);
        $this->get($url)->assertSee('value="browser_vps" selected', false);

        $this->from($url)->put($update, ['driver' => 'browser_nas', 'browser_profile' => 'vps-test'] + $form)
            ->assertSessionHasErrors('browser_profile');
        $this->assertSame('vps-test', $this->account->refresh()->browser_profile);

        $this->put($update, ['driver' => 'browser_nas', 'browser_profile' => 'nas-test'] + $form)
            ->assertSessionHasNoErrors()->assertRedirect($url);
        $this->assertSame('browser', $this->account->refresh()->driver);
        $this->get($url)->assertSee('value="browser_nas" selected', false);
    }
}
