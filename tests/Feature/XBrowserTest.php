<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Adapters\AdapterFactory;
use App\Services\Adapters\TwitterAdapter;
use App\Services\Adapters\TwitterBrowserAdapter;
use App\Services\Browser\CamofoxClient;
use App\Services\Browser\XPage;
use App\Services\Browser\XSessionService;
use App\Services\Inbox\InboxSyncService;
use App\Services\Inbox\TwitterBrowserInboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Navigateur simulé : assez fidèle pour vérifier l'ordre des gestes et les refus. */
class FakeCamofox extends CamofoxClient
{
    public array $calls = [];

    public array $imported = [];

    public bool $loggedIn = true;

    public bool $sessionLostUntilImport = false;

    public string $editorText = '';

    public bool $altersText = false;

    public bool $submitted = false;

    public array $timeline = [];

    public function isConfigured(): bool
    {
        return true;
    }

    public function open(string $userId, string $url): string
    {
        $this->calls[] = ['open', $url];

        return 't1';
    }

    public function close(string $userId, string $tabId): void {}

    public function navigate(string $userId, string $tabId, string $url): void
    {
        $this->calls[] = ['navigate', $url];
    }

    public function typeKeys(string $userId, string $tabId, string $text): void
    {
        $this->calls[] = ['keys', $text];
        $this->editorText .= $this->altersText ? 'x' : $text;
    }

    public function upload(string $userId, string $tabId, array $paths, string $selector): void
    {
        $this->calls[] = ['upload', $paths];
    }

    public function importCookies(string $userId, array $cookies): void
    {
        $this->imported[] = [$userId, $cookies];
        $this->sessionLostUntilImport = false;
    }

    public function evaluate(string $userId, string $tabId, string $expression): mixed
    {
        $connected = $this->loggedIn && ! $this->sessionLostUntilImport;

        return match (true) {
            str_contains($expression, 'getAttribute("href")') => $connected ? '/wildycaro' : null,
            str_ends_with($expression, '.click()') => tap(null, function () use ($expression) {
                $this->calls[] = ['click', json_decode(substr($expression, strlen('document.querySelector('), -strlen(').click()')))];
                $this->submitted = $this->submitted || str_contains($expression, 'tweetButton');
            }),
            str_contains($expression, 'article[data-testid="tweet"]\')].map') => $this->timeline,
            str_contains($expression, 'innerText||\'\'') => $this->editorText,
            str_contains($expression, '!!document.querySelector') => match (true) {
                str_contains($expression, 'AppTabBar_Profile_Link') => true,
                str_contains($expression, 'tweetTextarea_0') => ! $this->submitted,
                default => true,
            },
            default => null,
        };
    }
}

class XBrowserTest extends TestCase
{
    use RefreshDatabase;

    private FakeCamofox $browser;

    private SocialAccount $account;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->browser = new FakeCamofox;
        $this->app->instance(CamofoxClient::class, $this->browser);
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
        $platform = Platform::firstOrCreate(['slug' => 'twitter'], ['name' => 'X']);
        $this->account = SocialAccount::create(['platform_id' => $platform->id, 'platform_account_id' => '77', 'name' => 'Wildy', 'driver' => 'browser', 'browser_username' => 'wildycaro', 'languages' => ['fr']]);
        $this->account->users()->attach($this->user, ['is_active' => true]);
    }

    private function cookies(string $id = '77'): array
    {
        return [
            ['name' => 'auth_token', 'value' => 'secret', 'domain' => '.x.com', 'sameSite' => 'no_restriction', 'expirationDate' => 1900000000.5],
            ['name' => 'ct0', 'value' => 'csrf', 'domain' => '.x.com'],
            ['name' => 'twid', 'value' => "u%3D{$id}", 'domain' => '.x.com'],
        ];
    }

    private function adapter(): TwitterBrowserAdapter
    {
        return app(TwitterBrowserAdapter::class);
    }

    private function tweet(string $id, string $text, array $extra = []): array
    {
        return $extra + ['id' => $id, 'author' => 'wildycaro', 'name' => 'Wildy', 'avatar' => null, 'text' => $text, 'published_at' => now()->toIso8601String(),
            'stats' => '', 'pinned' => false, 'reposted' => false, 'reply' => false];
    }

    public function test_la_session_recue_est_chiffree_importee_et_verifiee(): void
    {
        $this->postJson('/api/extension/x/session', ['cookies' => $this->cookies()])
            ->assertOk()->assertJson(['status' => 'connected', 'username' => 'wildycaro']);

        $this->account->refresh();
        $this->assertSame('rsmax-x-'.$this->account->id, $this->browser->imported[0][0]);
        $this->assertSame('None', $this->browser->imported[0][1][0]['sameSite']);
        $this->assertSame('secret', $this->account->browser_session[0]['value']);
        $this->assertStringNotContainsString('secret', (string) $this->account->getRawOriginal('browser_session'));
        $this->assertArrayNotHasKey('browser_session', $this->account->toArray());
    }

    public function test_les_cookies_dun_autre_compte_sont_refuses(): void
    {
        $this->postJson('/api/extension/x/session', ['cookies' => $this->cookies('88')])->assertStatus(422);

        $this->assertSame([], $this->browser->imported);
    }

    public function test_une_session_perdue_est_restauree_depuis_la_copie_chiffree(): void
    {
        $this->account->update(['browser_session' => $this->cookies()]);
        $this->browser->sessionLostUntilImport = true;

        $this->assertTrue(app(XSessionService::class)->check($this->account));
        $this->assertCount(1, $this->browser->imported);
        $this->assertSame('connected', $this->account->fresh()->browser_session_status);
    }

    public function test_une_session_expiree_nest_pas_masquee(): void
    {
        $this->account->update(['browser_session' => $this->cookies()]);
        $this->browser->loggedIn = false;

        $result = $this->adapter()->publish($this->account, 'Bonjour');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Session', $result['error']);
        $this->assertSame('expired', $this->account->fresh()->browser_session_status);
        $this->assertFalse($this->browser->submitted);
    }

    public function test_publier_renvoie_lid_du_tweet_reconnu_sur_le_profil(): void
    {
        $this->browser->timeline = [$this->tweet('1', 'Ancien épinglé', ['pinned' => true]), $this->tweet('500', 'Bonjour le monde')];

        $result = $this->adapter()->publish($this->account, 'Bonjour le monde');

        $this->assertTrue($result['success']);
        $this->assertSame('500', $result['external_id']);
        $this->assertSame('https://x.com/wildycaro/status/500', $result['permalink']);
    }

    public function test_un_texte_altere_annule_avant_le_clic(): void
    {
        $this->browser->altersText = true;

        $result = $this->adapter()->publish($this->account, 'Bonjour');

        $this->assertFalse($result['success']);
        $this->assertFalse($this->browser->submitted);
    }

    public function test_un_envoi_sans_confirmation_le_dit_sans_pretendre_reussir(): void
    {
        $result = $this->adapter()->publish($this->account, 'Bonjour');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('non confirmé', $result['error']);
        $this->assertSame(1, collect($this->browser->calls)->where(0, 'click')->where(1, XPage::SUBMIT)->count());
    }

    public function test_photos_et_video_passent_par_le_dossier_partage(): void
    {
        $dir = storage_path('app/private/media/');
        @mkdir($dir, 0777, true);
        touch($dir.'a.jpg');
        touch($dir.'b.mp4');
        $this->browser->timeline = [$this->tweet('500', 'Avec média')];
        $image = ['local_path' => $dir.'a.jpg', 'mimetype' => 'image/jpeg'];

        $this->adapter()->publish($this->account, 'Avec média', [$image]);
        $this->assertSame(['/uploads/a.jpg'], collect($this->browser->calls)->firstWhere(0, 'upload')[1]);

        $this->browser->calls = [];
        $this->browser->submitted = false;
        $this->adapter()->publish($this->account, 'Avec média', [$image, ['local_path' => $dir.'b.mp4', 'mimetype' => 'video/mp4']]);
        $this->assertSame(['/uploads/b.mp4'], collect($this->browser->calls)->firstWhere(0, 'upload')[1]);

        unlink($dir.'a.jpg');
        unlink($dir.'b.mp4');
    }

    public function test_repondre_a_un_commentaire_passe_par_le_navigateur(): void
    {
        $this->browser->timeline = [$this->tweet('600', 'Merci à toi')];
        $item = InboxItem::create(['platform_id' => $this->account->platform_id, 'social_account_id' => $this->account->id, 'type' => 'comment', 'external_id' => '555', 'status' => 'unread']);

        $service = app(InboxSyncService::class)->getServiceForAccount($this->account);
        $result = $service->sendReply($this->account, $item, 'Merci à toi');

        $this->assertInstanceOf(TwitterBrowserInboxService::class, $service);
        $this->assertSame('600', $result['external_id']);
        $this->assertContains(['open', 'https://x.com/intent/post?in_reply_to=555'], $this->browser->calls);
    }

    public function test_les_mentions_lues_excluent_le_compte_lui_meme(): void
    {
        $this->browser->timeline = [$this->tweet('600', 'Moi'), $this->tweet('601', '@wildycaro super', ['author' => 'visiteur'])];

        $items = app(TwitterBrowserInboxService::class)->fetchInbox($this->account);

        $this->assertSame(['601'], $items->pluck('external_id')->all());
        $this->assertSame('visiteur', $items->first()['author_username']);
    }

    public function test_le_mode_de_connexion_change_le_chemin_de_chaque_compte(): void
    {
        $this->assertInstanceOf(TwitterBrowserAdapter::class, AdapterFactory::forAccount($this->account));

        $this->account->update(['driver' => 'api']);

        $this->assertInstanceOf(TwitterAdapter::class, AdapterFactory::forAccount($this->account->fresh()));
    }

    public function test_les_stats_affichees_par_x_sont_lues_dans_les_deux_langues(): void
    {
        $this->assertSame(['replies' => 3, 'reposts' => 2, 'likes' => 1204, 'bookmarks' => 1, 'views' => 9000],
            XPage::parseStats("3 réponses, 2 reposts, 1 204 J''aime, 1 signet, 9 000 vues"));
        $this->assertSame(['replies' => 1, 'likes' => 12, 'views' => 345],
            XPage::parseStats('1 reply, 12 likes, 345 views'));
    }
}
