<?php

namespace Tests\Feature;

use App\Jobs\PrepareAgentProposals;
use App\Jobs\SendAgentProposal;
use App\Models\AgentProposal;
use App\Models\ExternalPost;
use App\Models\InboxItem;
use App\Models\Persona;
use App\Models\Platform;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Agent\AgentCatalog;
use App\Services\Agent\AgentProposalService;
use App\Services\Browser\BrowserException;
use App\Services\Browser\BrowserTaskQueue;
use App\Services\Inbox\InboxReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AgentProposalsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create(['role' => 'manager']);
        Setting::setEncrypted('openai_api_key', 'cle');
        $persona = Persona::create(['name' => 'Caro', 'system_prompt' => 'Tu es Caro.', 'is_active' => true]);
        $platform = Platform::firstOrCreate(['slug' => 'facebook'], ['name' => 'Facebook']);
        $this->account = SocialAccount::create(['platform_id' => $platform->id, 'name' => 'Page', 'persona_id' => $persona->id, 'languages' => ['fr']]);
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
        return app(AgentProposalService::class)->prepare($this->account->fresh(['platform', 'persona']), 10);
    }

    public function test_un_seul_prompt_prepare_toutes_les_conversations_avec_le_persona(): void
    {
        ExternalPost::create(['social_account_id' => $this->account->id, 'platform_id' => $this->account->platform_id, 'external_id' => 'p1', 'content' => 'Mon voyage au Portugal']);
        $this->item('c1', 'Quelle ville ?', ['external_post_id' => 'p1']);
        $this->item('c2', 'Bravo');
        $this->openai(['1' => 'Lisbonne !', '2' => 'Merci']);

        $result = $this->prepare();

        $this->assertSame(['created' => 2, 'tokens' => 321], $result);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r['messages'][0]['content'], 'Tu es Caro.') && str_contains($r['messages'][1]['content'], 'Mon voyage au Portugal'));
        $this->assertSame(0, InboxItem::whereNotNull('replied_at')->count());
    }

    public function test_le_contexte_va_du_message_dorigine_a_la_conversation_en_cours(): void
    {
        ExternalPost::create(['social_account_id' => $this->account->id, 'platform_id' => $this->account->platform_id, 'external_id' => 'p1', 'content' => 'Message d origine']);
        $this->item('c1', 'Premier', ['external_post_id' => 'p1', 'reply_content' => 'Notre reponse', 'replied_at' => now(), 'status' => 'replied', 'posted_at' => now()->subHour()]);
        $this->item('c2', 'Second', ['external_post_id' => 'p1', 'parent_id' => 'c1']);
        $this->openai(['1' => 'Suite']);

        $this->prepare();

        $this->assertSame(['Message d origine', 'Premier', 'Notre reponse', 'Second'], array_column(AgentProposal::first()->conversation, 'text'));
    }

    public function test_une_proposition_nest_jamais_envoyee_sans_validation(): void
    {
        Queue::fake();
        $this->item('c1', 'Bravo');
        $this->openai(['1' => 'Merci']);

        $this->prepare();

        Queue::assertNothingPushed();
        $this->assertSame(AgentProposal::PENDING, AgentProposal::first()->status);
    }

    public function test_lagent_ne_peut_pas_valider_avec_son_jeton(): void
    {
        $this->item('c1', 'Bravo');
        $this->openai(['1' => 'Merci']);
        $this->prepare();
        $proposal = AgentProposal::first();
        $token = $this->manager->createToken('agent')->plainTextToken;

        $this->withToken($token)->postJson("/api/agent/proposals/{$proposal->id}/approve")->assertNotFound();
        $this->withToken($token)->post(route('agent.validations.approve', $proposal), ['reply_text' => 'x'])->assertRedirect(route('login'));

        $this->assertSame(AgentProposal::PENDING, $proposal->fresh()->status);
    }

    public function test_valider_envoie_le_texte_corrige_une_seule_fois(): void
    {
        Queue::fake();
        $this->item('c1', 'Bravo');
        $this->openai(['1' => 'Merci']);
        $this->prepare();
        $proposal = AgentProposal::first();

        $this->actingAs($this->manager)->post(route('agent.validations.approve', $proposal), ['reply_text' => 'Merci beaucoup'])->assertRedirect();
        $this->actingAs($this->manager)->post(route('agent.validations.approve', $proposal), ['reply_text' => 'Autre'])->assertRedirect();

        Queue::assertPushed(SendAgentProposal::class, 1);
        $this->assertSame('Merci beaucoup', $proposal->fresh()->reply_text);
    }

    public function test_lenvoi_passe_par_la_boite_de_reception_et_decompte_le_quota(): void
    {
        $this->item('c1', 'Bravo');
        $this->openai(['1' => 'Merci']);
        $this->prepare();
        $proposal = AgentProposal::first();
        $this->app->instance(InboxReplyService::class, new class extends InboxReplyService
        {
            public function send(InboxItem $item, string $text, bool $scheduled = false): array
            {
                return ['success' => true, 'external_id' => 'r1'];
            }
        });
        app()->forgetInstance(AgentProposalService::class);
        $proposal->update(['status' => AgentProposal::APPROVED]);

        app(AgentProposalService::class)->send($proposal);

        $this->assertSame(AgentProposal::SENT, $proposal->fresh()->status);
        $this->assertSame(1, app(AgentCatalog::class)->usedToday($this->account, 'reply'));
    }

    public function test_une_proposition_refusee_nest_pas_reproposee(): void
    {
        $this->item('c1', 'Bravo');
        $this->openai(['1' => 'Merci']);
        $this->prepare();
        $this->actingAs($this->manager)->post(route('agent.validations.reject', AgentProposal::first()))->assertRedirect();

        $this->assertSame(0, $this->prepare()['created']);
        Http::assertSentCount(1);
    }

    public function test_sans_persona_lapi_refuse_de_preparer(): void
    {
        Queue::fake();
        $this->account->update(['persona_id' => null]);
        Sanctum::actingAs($this->manager);

        $this->postJson("/api/agent/accounts/{$this->account->id}/proposals")->assertStatus(422);
        Queue::assertNotPushed(PrepareAgentProposals::class);
    }

    public function test_lapi_lance_la_preparation_et_liste_lattente(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->manager);

        $this->postJson("/api/agent/accounts/{$this->account->id}/proposals", ['limit' => 5])->assertStatus(202);

        Queue::assertPushed(PrepareAgentProposals::class, 1);
        $this->getJson('/api/agent/proposals')->assertOk()->assertExactJson(['proposals' => []]);
    }

    public function test_une_reponse_trop_longue_est_ecartee(): void
    {
        $this->item('c1', 'Bravo');
        $this->openai(['1' => str_repeat('a', 600)]);

        $this->assertSame(0, $this->prepare()['created']);
    }

    public function test_la_page_montre_le_contexte_et_le_menu_le_compteur(): void
    {
        $this->item('c1', 'Quelle ville ?');
        $this->openai(['1' => 'Lisbonne']);
        $this->prepare();

        $this->actingAs($this->manager)->get(route('agent.validations'))->assertOk()
            ->assertSee('Quelle ville ?')->assertSee('Lisbonne')->assertSee('Valider et envoyer');
    }

    public function test_tiktok_se_prepare_sans_boite_de_reception_et_ecarte_le_deja_repondu(): void
    {
        $tiktok = SocialAccount::create(['platform_id' => Platform::where('slug', 'tiktok')->first()->id, 'name' => 'Wildy', 'driver' => 'browser', 'browser_profile' => 'p',
            'browser_username' => 'wildycaro', 'persona_id' => $this->account->persona_id, 'languages' => ['fr']]);
        app(AgentCatalog::class)->configure($tiktok, 'reply', true, 5);
        $this->app->instance(BrowserTaskQueue::class, new class extends BrowserTaskQueue
        {
            public function __construct() {}

            public function run(SocialAccount $account, string $kind, array $payload, int $timeout = 120): array
            {
                return ['items' => [
                    ['author' => 'fan', 'content' => 'Top', 'caption' => 'Ma vidéo', 'replied' => false],
                    ['author' => 'autre', 'content' => 'Deja', 'caption' => 'Ma vidéo', 'replied' => true],
                ]];
            }
        });
        $this->openai(['1' => 'Merci']);

        $result = app(AgentProposalService::class)->prepare($tiktok->fresh(['platform', 'persona']), 10);

        $this->assertSame(1, $result['created']);
        $this->assertSame(['Ma vidéo', 'Top'], array_column(AgentProposal::first()->conversation, 'text'));
    }

    public function test_un_envoi_tiktok_non_confirme_reste_a_verifier(): void
    {
        $tiktok = SocialAccount::create(['platform_id' => Platform::where('slug', 'tiktok')->first()->id, 'name' => 'Wildy', 'driver' => 'browser', 'browser_profile' => 'p', 'browser_username' => 'wildycaro', 'languages' => ['fr']]);
        app(AgentCatalog::class)->configure($tiktok, 'reply', true, 5);
        $proposal = AgentProposal::create(['social_account_id' => $tiktok->id, 'target_key' => 'k', 'target' => ['author' => 'fan'], 'conversation' => [], 'reply_text' => 'Merci', 'status' => AgentProposal::APPROVED]);
        $this->app->instance(BrowserTaskQueue::class, new class extends BrowserTaskQueue
        {
            public function __construct() {}

            public function run(SocialAccount $account, string $kind, array $payload, int $timeout = 120): array
            {
                throw new BrowserException('Réponse non confirmée : vérifier sur TikTok.');
            }
        });

        app(AgentProposalService::class)->send($proposal);

        $this->assertSame(AgentProposal::UNCERTAIN, $proposal->fresh()->status);
    }
}
