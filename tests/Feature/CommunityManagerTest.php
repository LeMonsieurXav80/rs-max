<?php

namespace Tests\Feature;

use App\Models\BrowserTask;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Agent\AgentCatalog;
use App\Services\Agent\CommunityManagerWindow;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommunityManagerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-07T12:00:00Z'));
        $this->user = User::factory()->create(['role' => 'manager']);
        $this->account = SocialAccount::create(['name' => 'Compte attribué', 'platform_id' => Platform::where('slug', 'tiktok')->firstOrFail()->id,
            'driver' => 'browser', 'browser_profile' => 'nas-test', 'browser_username' => 'test', 'browser_session_status' => 'connected']);
        $this->account->users()->attach($this->user, ['is_active' => true]);
        foreach (['feed.read', 'video.comments', 'video.like', 'video.comment'] as $action) {
            app(AgentCatalog::class)->configure($this->account, $action, true, 5);
        }
    }

    public function test_jeton_dedie_ne_lit_ni_secrets_ni_reglages_ni_autres_api(): void
    {
        $token = $this->user->createToken('Community Manager', ['community-manager'])->plainTextToken;
        $this->withToken($token)->getJson('/api/community-manager/accounts')->assertOk()->assertJsonCount(1, 'accounts');
        foreach (['/api/accounts', '/api/meta-ads/campaigns', '/api/extension/tasks/next?profile=nas-test', '/api/agent/accounts'] as $path) {
            $this->withToken($token)->getJson($path)->assertForbidden();
        }
    }

    public function test_provisionnement_prive_idempotent_sans_afficher_le_jeton(): void
    {
        $file = sys_get_temp_dir().'/rsmax-cm-'.bin2hex(random_bytes(8)).'.json';
        try {
            $this->artisan('community-manager:provision', ['--account' => [$this->account->id], '--token-file' => $file])->assertSuccessful();
            $config = json_decode(file_get_contents($file), true);
            $token = \Laravel\Sanctum\PersonalAccessToken::findToken($config['token']);
            $this->assertSame(['community-manager'], $token->abilities);
            $this->assertSame(0600, fileperms($file) & 0777);
            $this->assertTrue($token->tokenable->connectedSocialAccounts()->whereKey($this->account->id)->exists());
            $this->artisan('community-manager:provision', ['--account' => [$this->account->id], '--token-file' => $file])->assertSuccessful();
            $this->assertSame($config, json_decode(file_get_contents($file), true));
            $this->assertSame(1, $token->tokenable->tokens()->count());
        } finally {
            @unlink($file);
        }
    }

    public function test_catalogue_limite_aux_comptes_attribues_et_absence_de_jeton_ou_session(): void
    {
        Sanctum::actingAs($this->user);
        SocialAccount::create(['name' => 'Autre compte', 'platform_id' => $this->account->platform_id]);
        $this->getJson('/api/community-manager/accounts')->assertJsonCount(1, 'accounts')->assertDontSee('browser_profile')->assertDontSee('browser_session');
    }

    public function test_aucune_lecture_sociale_la_nuit(): void
    {
        Sanctum::actingAs($this->user);
        $this->travelTo(Carbon::parse('2026-11-07T23:00:00Z'));
        $this->mock(BrowserTaskQueue::class)->shouldNotReceive('run');
        $this->postJson('/api/community-manager/read', ['account_id' => $this->account->id, 'action' => 'feed.read'])->assertStatus(423);
        $this->getJson('/api/community-manager/accounts')->assertOk()->assertJsonPath('daytime', false);
    }

    public function test_fenetre_tient_compte_de_lheure_dete_et_du_jour_de_preparation(): void
    {
        $window = app(CommunityManagerWindow::class);
        $this->travelTo(Carbon::parse('2026-07-07T15:59:00Z'));
        $this->assertTrue($window->isOpen());
        $spec = $window->specification();
        $this->assertTrue($window->isOpen(array_reverse($spec, true)));
        $this->travelTo(Carbon::parse('2026-07-07T16:00:00Z'));
        $this->assertFalse($window->isOpen($spec));
        $this->travelTo(Carbon::parse('2026-07-08T10:00:00Z'));
        $this->assertFalse($window->isOpen($spec));
    }

    public function test_tache_retardee_annulee_sans_priver_un_humain_de_sa_file(): void
    {
        $spec = app(CommunityManagerWindow::class)->specification();
        $bot = BrowserTask::create(['social_account_id' => $this->account->id, 'profile' => 'nas-test', 'kind' => 'tiktok.video.like', 'payload' => ['activity_window' => $spec]]);
        $human = BrowserTask::create(['social_account_id' => $this->account->id, 'profile' => 'nas-test', 'kind' => 'tiktok.comments', 'payload' => []]);
        $this->travelTo(Carbon::parse('2026-11-07T23:00:00Z'));
        $claimed = app(BrowserTaskQueue::class)->claim('nas-test');
        $this->assertSame($human->id, $claimed->id);
        $this->assertSame('cancelled', $bot->fresh()->status);
    }

    public function test_snapshot_reste_complet_cote_serveur_avec_reponse_compacte_pour_hermes(): void
    {
        Sanctum::actingAs($this->user);
        $url = 'https://www.tiktok.com/@creator/video/123';
        $comments = array_map(fn ($i) => ['key' => hash('sha256', (string) $i), 'text' => str_repeat('a', 300), 'author' => 'visitor'.$i, 'liked' => false], range(1, 50));
        $this->mock(BrowserTaskQueue::class)->shouldReceive('run')->once()->andReturn(['video' => ['url' => $url, 'caption' => 'Une tenue'], 'comments' => $comments, 'complete' => true, 'observed' => 50, 'expected' => 50]);
        $r = $this->postJson('/api/community-manager/read', ['account_id' => $this->account->id, 'action' => 'video.comments', 'url' => $url])
            ->assertOk()->assertJsonCount(3, 'sample')->assertJsonPath('observed', 50)->assertJsonPath('complete', true);
        $this->assertLessThan(1300, strlen($r->getContent()));
        $snapshot = \Illuminate\Support\Facades\Cache::get('tiktok-video:'.$r->json('snapshot_id'));
        $this->assertCount(50, $snapshot['comments']);
    }

    public function test_un_like_de_video_ne_charge_pas_de_commentaires(): void
    {
        Sanctum::actingAs($this->user);
        $this->mock(BrowserTaskQueue::class)->shouldReceive('run')->once()->withArgs(fn ($a, $kind) => $kind === 'tiktok.feed.read')->andReturn([
            'items' => [['url' => 'https://www.tiktok.com/@creator/video/123', 'caption' => 'Une tenue', 'liked' => false]],
        ]);
        $r = $this->postJson('/api/community-manager/read', ['account_id' => $this->account->id, 'action' => 'feed.read', 'limit' => 1])->assertOk();
        $id = $r->json('items.0.snapshot_id');
        $p = $this->postJson('/api/community-manager/prepare', ['account_id' => $this->account->id, 'action' => 'video.like', 'snapshot_id' => $id])
            ->assertCreated();
        $this->assertNotEmpty(\App\Models\TikTokEngagement::find($p->json('id'))->payload['activity_window']);
        $this->postJson('/api/community-manager/prepare', ['account_id' => $this->account->id, 'action' => 'video.comment', 'snapshot_id' => $id])->assertUnprocessable();
    }

    public function test_refus_des_actions_sur_un_compte_non_attribue(): void
    {
        Sanctum::actingAs($this->user);
        $other = SocialAccount::create(['name' => 'Autre', 'platform_id' => $this->account->platform_id]);
        $this->mock(BrowserTaskQueue::class)->shouldNotReceive('run');
        $this->postJson('/api/community-manager/read', ['account_id' => $other->id, 'action' => 'feed.read'])->assertForbidden();
    }
}
