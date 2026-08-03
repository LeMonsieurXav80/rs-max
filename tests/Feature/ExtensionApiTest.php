<?php

namespace Tests\Feature;

use App\Models\BotActionLog;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Couvre la remontée des actions de l'extension Chrome (RS-Max Companion) :
 * POST /api/extension/actions et GET /api/extension/summary.
 *
 * Le point sensible est le cloisonnement : le navigateur envoie un
 * social_account_id, on ne doit jamais le croire sur parole.
 */
class ExtensionApiTest extends TestCase
{
    use RefreshDatabase;

    private function account(?User $user = null, string $slug = 'facebook'): SocialAccount
    {
        $platform = Platform::firstOrCreate(
            ['slug' => $slug],
            ['name' => ucfirst($slug), 'is_active' => true]
        );

        $account = SocialAccount::create([
            'platform_id' => $platform->id,
            'platform_account_id' => 'test-'.uniqid(),
            'name' => 'Page de test',
            'is_active' => true,
        ]);

        if ($user) {
            $account->users()->attach($user->id, ['is_active' => true]);
        }

        return $account;
    }

    public function test_les_actions_sont_enregistrees_avec_la_source_extension(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $account = $this->account($user);

        $response = $this->postJson('/api/extension/actions', [
            'actions' => [
                [
                    'social_account_id' => $account->id,
                    'action_type' => 'fb_invite_to_like',
                    'target_uri' => 'https://facebook.com/page',
                    'target_author' => 'Jean Dupont',
                    'success' => true,
                    'performed_at' => '2026-08-03T10:00:00Z',
                ],
                [
                    'social_account_id' => $account->id,
                    'action_type' => 'fb_invite_to_like',
                    'target_uri' => 'https://facebook.com/page',
                    'target_author' => 'Marie Martin',
                    'success' => false,
                    'error' => 'Invitation non confirmée',
                    'metadata' => ['streak' => 2],
                ],
            ],
        ]);

        $response->assertCreated()->assertJson(['stored' => 2, 'rejected' => 0]);

        $this->assertSame(2, BotActionLog::where('source', 'extension')->count());

        $echec = BotActionLog::where('target_author', 'Marie Martin')->first();
        $this->assertFalse($echec->success);
        $this->assertSame(['streak' => 2], $echec->metadata);

        $ok = BotActionLog::where('target_author', 'Jean Dupont')->first();
        $this->assertSame('2026-08-03 10:00:00', $ok->performed_at->toDateTimeString());
    }

    public function test_un_compte_non_rattache_est_rejete_sans_etre_enregistre(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $mien = $this->account($user);
        $autrui = $this->account(User::factory()->create());

        $response = $this->postJson('/api/extension/actions', [
            'actions' => [
                ['social_account_id' => $mien->id, 'action_type' => 'fb_invite_to_like', 'target_uri' => 'https://facebook.com/a'],
                ['social_account_id' => $autrui->id, 'action_type' => 'fb_invite_to_like', 'target_uri' => 'https://facebook.com/b'],
            ],
        ]);

        $response->assertCreated()->assertJson(['stored' => 1, 'rejected' => 1]);
        $this->assertSame(0, BotActionLog::where('social_account_id', $autrui->id)->count());
    }

    public function test_le_resume_agrege_succes_et_echecs_par_type_daction(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $account = $this->account($user);

        $this->postJson('/api/extension/actions', [
            'actions' => [
                ['social_account_id' => $account->id, 'action_type' => 'fb_invite_to_like', 'target_uri' => 'https://facebook.com/a', 'success' => true],
                ['social_account_id' => $account->id, 'action_type' => 'fb_invite_to_like', 'target_uri' => 'https://facebook.com/b', 'success' => true],
                ['social_account_id' => $account->id, 'action_type' => 'fb_invite_to_like', 'target_uri' => 'https://facebook.com/c', 'success' => false],
                ['social_account_id' => $account->id, 'action_type' => 'fb_compose_prefill', 'target_uri' => 'https://facebook.com/d', 'success' => true],
            ],
        ])->assertCreated();

        $response = $this->getJson('/api/extension/summary?days=7');

        $response->assertOk()->assertJson(['days' => 7, 'total' => 4]);

        $actions = collect($response->json('actions'))->keyBy('action_type');
        $this->assertSame(2, $actions['fb_invite_to_like']['success']);
        $this->assertSame(1, $actions['fb_invite_to_like']['failed']);
        $this->assertSame(1, $actions['fb_compose_prefill']['success']);
    }

    /**
     * L'extension rattache toute seule la page consultée au bon compte en
     * comparant l'ID Facebook détecté au platform_account_id. Sans ce champ
     * dans la réponse, elle retomberait sur un choix manuel — la porte ouverte
     * aux actions comptabilisées sur le mauvais compte.
     */
    public function test_la_liste_des_comptes_expose_le_platform_account_id(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $account = $this->account($user);

        $this->getJson('/api/accounts')
            ->assertOk()
            ->assertJsonPath('accounts.0.platform_account_id', $account->platform_account_id);
    }

    public function test_un_token_est_obligatoire(): void
    {
        $this->postJson('/api/extension/actions', ['actions' => []])->assertUnauthorized();
    }
}
