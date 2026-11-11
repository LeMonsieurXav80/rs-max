<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Browser\BrowserException;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AgentActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create(['role' => 'manager']);
        $this->account = SocialAccount::create(['platform_id' => Platform::where('slug', 'tiktok')->first()->id, 'name' => 'Wildy', 'driver' => 'browser',
            'browser_profile' => 'wildycaro', 'browser_username' => 'wildycaro', 'browser_session_status' => 'connected', 'languages' => ['fr']]);
    }

    private function browser(?array $result, bool $fails = false): void
    {
        $this->app->instance(BrowserTaskQueue::class, new class($result, $fails) extends BrowserTaskQueue
        {
            public function __construct(private ?array $result, private bool $fails) {}

            public function run(SocialAccount $account, string $kind, array $payload, int $timeout = 120): array
            {
                if ($this->fails) {
                    throw new BrowserException('Navigateur du VPS injoignable.');
                }

                return $this->result;
            }
        });
    }

    private function enable(int $limit = 5): void
    {
        Sanctum::actingAs($this->manager);
        $this->putJson("/api/agent/accounts/{$this->account->id}/actions/comments.read", ['enabled' => true, 'daily_limit' => $limit])
            ->assertOk()->assertJson(['enabled' => true, 'daily_limit' => $limit]);
    }

    private function comment(string $text = 'Super'): array
    {
        return ['author' => 'fan', 'content' => $text, 'caption' => 'Ma vidéo', 'replied' => false, 'liked' => null, 'image' => 'inutile'];
    }

    public function test_une_action_est_desactivee_par_defaut(): void
    {
        Sanctum::actingAs($this->manager);

        $this->getJson('/api/agent/accounts')->assertOk()->assertExactJson(['accounts' => []]);
        $this->getJson("/api/agent/accounts/{$this->account->id}/read/comments.read")->assertForbidden();
    }

    public function test_seules_les_actions_activees_sont_annoncees_a_lagent(): void
    {
        $this->enable(5);

        $this->getJson('/api/agent/accounts')->assertOk()
            ->assertJsonPath('accounts.0.id', $this->account->id)
            ->assertJsonPath('accounts.0.actions', ['comments.read' => ['kind' => 'read', 'left' => 5]]);
    }

    public function test_la_lecture_ne_renvoie_que_lutile_et_decompte_le_quota(): void
    {
        $this->enable(2);
        $this->browser(['items' => [$this->comment(str_repeat('a', 400))]]);

        $response = $this->getJson("/api/agent/accounts/{$this->account->id}/read/comments.read")->assertOk()->assertJsonPath('left', 1);

        $this->assertSame(['author', 'text', 'video', 'replied', 'liked'], array_keys($response->json('items.0')));
        $this->assertLessThanOrEqual(281, mb_strlen($response->json('items.0.text')));
        $this->getJson('/api/agent/log')->assertJsonPath('log.0.action', 'agent.comments.read');
    }

    public function test_le_quota_du_jour_bloque_sans_toucher_au_navigateur(): void
    {
        $this->enable(1);
        $this->browser(['items' => []]);
        $this->getJson("/api/agent/accounts/{$this->account->id}/read/comments.read")->assertOk();
        $this->browser(null, fails: true);

        $this->getJson("/api/agent/accounts/{$this->account->id}/read/comments.read")->assertStatus(429);
    }

    public function test_une_panne_du_navigateur_ne_consomme_pas_le_quota(): void
    {
        $this->enable(1);
        $this->browser(null, fails: true);

        $this->getJson("/api/agent/accounts/{$this->account->id}/read/comments.read")->assertStatus(502);
        $this->assertSame(1, $this->getJson('/api/agent/accounts')->json('accounts.0.actions')['comments.read']['left']);
    }

    public function test_une_action_a_venir_ne_sactive_pas(): void
    {
        Sanctum::actingAs($this->manager);

        $this->putJson("/api/agent/accounts/{$this->account->id}/actions/follow", ['enabled' => true])->assertNotFound();
    }

    public function test_un_simple_utilisateur_na_pas_acces(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'user']));

        $this->getJson('/api/agent/accounts')->assertForbidden();
    }

    public function test_la_page_du_reseau_regle_chaque_compte(): void
    {
        $this->actingAs($this->manager)->get(route('agent.platform', 'tiktok'))->assertOk()
            ->assertSee('Lire les commentaires')->assertSee('Aimer une vidéo')->assertSee('Wildy');

        $this->actingAs($this->manager)->put(route('agent.update', ['account' => $this->account, 'action' => 'comments.read']), ['enabled' => 1, 'daily_limit' => 7])->assertRedirect();

        $this->assertDatabaseHas('agent_account_actions', ['social_account_id' => $this->account->id, 'action' => 'comments.read', 'enabled' => true, 'daily_limit' => 7]);
    }

    public function test_decocher_desactive_laction(): void
    {
        $this->enable();

        $this->actingAs($this->manager)->put(route('agent.update', ['account' => $this->account, 'action' => 'comments.read']), ['daily_limit' => 7])->assertRedirect();

        $this->assertDatabaseHas('agent_account_actions', ['social_account_id' => $this->account->id, 'enabled' => false]);
    }

    public function test_un_formulaire_par_compte_enregistre_ensemble_les_actions_de_chaque_reseau(): void
    {
        $twitter = Platform::firstOrCreate(['slug' => 'twitter'], ['name' => 'X', 'auth_type' => 'oauth2']);
        $a = SocialAccount::create(['platform_id' => $twitter->id, 'name' => 'X principal']);
        $b = SocialAccount::create(['platform_id' => $twitter->id, 'name' => 'X secondaire']);
        $tiktok = route('agent.platform', 'tiktok');
        $x = route('agent.platform', 'twitter');

        $this->actingAs($this->manager)->get($x)->assertOk()->assertSee(route('agent.actions.update', $a))
            ->assertSee(route('agent.actions.update', $b))->assertDontSee(route('agent.update', [$a, 'feed.read']));
        $this->get($tiktok)->assertOk()->assertSee(route('agent.actions.update', $this->account))
            ->assertDontSee(route('agent.update', [$this->account, 'comments.read']));

        $settings = [
            ['action' => 'feed.read', 'enabled' => 1, 'daily_limit' => 17],
            ['action' => 'like', 'daily_limit' => 3],
        ];
        $this->from($x)->put(route('agent.actions.update', $a), ['actions' => $settings])->assertRedirect($x);
        $this->assertDatabaseHas('agent_account_actions', ['social_account_id' => $a->id, 'action' => 'feed.read', 'enabled' => true, 'daily_limit' => 17]);
        $this->assertDatabaseHas('agent_account_actions', ['social_account_id' => $a->id, 'action' => 'like', 'enabled' => false, 'daily_limit' => 3]);
        $this->assertDatabaseMissing('agent_account_actions', ['social_account_id' => $b->id]);

        $this->from($tiktok)->put(route('agent.actions.update', $this->account), ['actions' => [
            ['action' => 'comments.read', 'enabled' => 1, 'daily_limit' => 7],
            ['action' => 'video.like', 'enabled' => 1, 'daily_limit' => 4],
        ]])->assertRedirect($tiktok);
        $this->assertDatabaseHas('agent_account_actions', ['social_account_id' => $this->account->id, 'action' => 'video.like', 'enabled' => true, 'daily_limit' => 4]);

        $settings[0]['daily_limit'] = 18;
        $settings[1]['action'] = 'comments.read';
        $this->from($x)->put(route('agent.actions.update', $a), ['actions' => $settings])->assertSessionHasErrors('actions.1.action');
        $this->assertDatabaseHas('agent_account_actions', ['social_account_id' => $a->id, 'action' => 'feed.read', 'daily_limit' => 17]);
    }

    public function test_le_menu_propose_chaque_reseau(): void
    {
        $this->actingAs($this->manager)->get(route('bot.index'))->assertOk()->assertSee(url('/agent/tiktok'))->assertSee(url('/agent/twitter'));
    }
}
