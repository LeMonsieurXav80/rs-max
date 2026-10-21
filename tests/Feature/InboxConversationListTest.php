<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La liste /inbox regroupe les messages en conversations, filtre et trie en PHP
 * — sur la conversation entiere, pas sur une ligne. Elle chargeait donc TOUS les
 * messages des comptes selectionnes, en modeles Eloquent avec leurs relations,
 * avant d'en afficher quinze : 46 779 lignes pour 8 comptes le 18/10/2026, et
 * un `Allowed memory size of 268435456 bytes exhausted` dans
 * `InboxController.php` — l'ecran tombait en 500 par intermittence.
 *
 * Le chargement se fait desormais en deux passes (projection legere pour
 * regrouper, modeles complets pour la seule page affichee). Ces tests figent le
 * comportement visible, qui lui ne doit pas bouger : regroupement, fusion des
 * chaines de reponse, filtres « new » / « followup », pagination.
 */
class InboxConversationListTest extends TestCase
{
    use RefreshDatabase;

    private Platform $platform;

    private SocialAccount $compte;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform = Platform::create([
            'slug' => 'facebook',
            'name' => 'Facebook',
            'auth_type' => 'oauth2',
        ]);

        $this->compte = SocialAccount::create([
            'platform_id' => $this->platform->id,
            'platform_account_id' => 'page-1',
            'name' => 'Ma Page',
            'credentials' => ['access_token' => 't'],
        ]);

        $this->user = User::factory()->create(['role' => 'admin']);
        $this->user->socialAccounts()->attach($this->compte->id, ['is_active' => true]);
    }

    private function message(array $attrs = []): InboxItem
    {
        static $n = 0;
        $n++;

        return InboxItem::create(array_merge([
            'social_account_id' => $this->compte->id,
            'platform_id' => $this->platform->id,
            'type' => 'comment',
            'external_id' => 'ext-'.$n,
            'conversation_key' => 'convo-'.$n,
            'author_name' => 'Visiteur '.$n,
            'author_external_id' => 'visiteur-'.$n,
            'content' => 'Message numero '.$n,
            'posted_at' => now()->subMinutes(100 - $n),
            'status' => 'unread',
        ], $attrs));
    }

    private function url(string $query = ''): string
    {
        return '/inbox?accounts[]='.$this->compte->id.($query ? '&'.$query : '');
    }

    public function test_les_messages_d_une_meme_conversation_sont_regroupes(): void
    {
        $this->message(['conversation_key' => 'fil-a', 'content' => 'Premier du fil A']);
        $this->message(['conversation_key' => 'fil-a', 'content' => 'Second du fil A']);
        $this->message(['conversation_key' => 'fil-b', 'content' => 'Seul du fil B']);

        $reponse = $this->actingAs($this->user)->get($this->url())->assertOk();

        $conversations = $reponse->viewData('conversations');
        $this->assertCount(2, $conversations->items());

        $filA = collect($conversations->items())->first(fn ($c) => $c->total_count === 2);
        $this->assertNotNull($filA, 'Le fil A devait porter ses deux messages.');

        // La page affiche bien le contenu : les modeles sont hydrates, pas seulement comptes.
        $reponse->assertSee('Premier du fil A')->assertSee('Seul du fil B');
    }

    public function test_une_reponse_a_notre_reponse_rejoint_la_conversation_d_origine(): void
    {
        // On a repondu au message d'origine ; notre reponse porte l'id externe R1.
        $this->message([
            'conversation_key' => 'fil-origine',
            'content' => 'Message initial',
            'status' => 'replied',
            'reply_content' => 'Merci !',
            'reply_external_id' => 'R1',
        ]);

        // Le visiteur repond a NOTRE reponse : parent_id = R1, et une autre cle.
        $this->message([
            'conversation_key' => 'fil-detache',
            'parent_id' => 'R1',
            'content' => 'Reponse a votre reponse',
        ]);

        $conversations = $this->actingAs($this->user)->get($this->url())
            ->assertOk()
            ->viewData('conversations');

        $this->assertCount(1, $conversations->items(), 'Les deux messages forment une seule conversation.');
        $this->assertSame(2, $conversations->items()[0]->total_count);
    }

    public function test_le_filtre_new_ecarte_les_conversations_deja_repondues(): void
    {
        $this->message(['conversation_key' => 'jamais-repondu', 'content' => 'Question fraiche']);
        $this->message([
            'conversation_key' => 'deja-repondu',
            'content' => 'Question ancienne',
            'reply_content' => 'Voila la reponse',
        ]);

        $this->actingAs($this->user)->get($this->url('status=new'))
            ->assertOk()
            ->assertSee('Question fraiche')
            ->assertDontSee('Question ancienne');

        $this->actingAs($this->user)->get($this->url('status=followup'))
            ->assertOk()
            ->assertSee('Question ancienne')
            ->assertDontSee('Question fraiche');
    }

    public function test_une_conversation_dont_le_dernier_message_est_le_notre_sort_du_filtre_new(): void
    {
        // `platform_account_id` du compte = 'page-1' : ce message est le notre.
        $this->message([
            'conversation_key' => 'fil-clos',
            'content' => 'Notre propre mot de la fin',
            'author_external_id' => 'page-1',
        ]);

        $this->actingAs($this->user)->get($this->url('status=new'))
            ->assertOk()
            ->assertDontSee('Notre propre mot de la fin');
    }

    public function test_la_pagination_compte_les_conversations_et_n_affiche_que_la_page(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->message(['conversation_key' => 'fil-'.$i, 'content' => 'Contenu du fil '.$i]);
        }

        $conversations = $this->actingAs($this->user)->get($this->url('per_page=2'))
            ->assertOk()
            ->viewData('conversations');

        $this->assertSame(5, $conversations->total(), 'Le total porte sur toutes les conversations.');
        $this->assertCount(2, $conversations->items(), 'Seule la page demandee est rendue.');
        $this->assertSame(3, $conversations->lastPage());

        // Page 2 : des conversations differentes, et toujours hydratees.
        $page2 = $this->actingAs($this->user)->get($this->url('per_page=2&page=2'))
            ->assertOk()
            ->viewData('conversations');

        $this->assertCount(2, $page2->items());
        $this->assertNotEquals(
            collect($conversations->items())->pluck('key')->all(),
            collect($page2->items())->pluck('key')->all()
        );
    }

    public function test_les_conversations_sont_triees_du_plus_recent_au_plus_ancien(): void
    {
        $this->message(['conversation_key' => 'vieux', 'posted_at' => now()->subDays(3)]);
        $this->message(['conversation_key' => 'recent', 'posted_at' => now()->subMinutes(5)]);
        $this->message(['conversation_key' => 'median', 'posted_at' => now()->subDay()]);

        $conversations = $this->actingAs($this->user)->get($this->url())
            ->assertOk()
            ->viewData('conversations');

        $cles = collect($conversations->items())->map(fn ($c) => explode(':', $c->key)[1])->all();
        $this->assertSame(['recent', 'median', 'vieux'], $cles);
    }

    public function test_les_messages_archives_restent_hors_de_la_liste(): void
    {
        $this->message(['content' => 'Visible ici']);
        $this->message(['content' => 'Range aux archives', 'status' => 'archived']);

        $this->actingAs($this->user)->get($this->url())
            ->assertOk()
            ->assertSee('Visible ici')
            ->assertDontSee('Range aux archives');
    }
}
