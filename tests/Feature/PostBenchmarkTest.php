<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Stats\PostBenchmarkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Comparaison d'un brouillon à l'historique du MÊME compte.
 *
 * Le service doit rester muet quand l'échantillon ne permet pas de conclure :
 * c'est la moitié de son intérêt. On vérifie donc autant ce qu'il tait que ce
 * qu'il affirme.
 */
class PostBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $platform = Platform::create(['slug' => 'twitter', 'name' => 'Twitter', 'auth_type' => 'oauth1']);

        $this->account = SocialAccount::create([
            'platform_id' => $platform->id,
            'platform_account_id' => '42',
            'name' => 'Compte de test',
        ]);

        $this->user = User::factory()->create();
        $this->user->socialAccounts()->attach($this->account->id, ['is_active' => true]);
    }

    /**
     * Crée $count publications mesurées avec les caractéristiques données.
     */
    private function publish(int $count, int $views, array $attrs = []): void
    {
        foreach (range(1, $count) as $i) {
            $post = Post::create([
                'user_id' => $this->user->id,
                'content_fr' => $attrs['content'] ?? 'Texte court',
                'media' => ($attrs['has_media'] ?? false) ? [['url' => 'a.jpg']] : null,
                'link_url' => ($attrs['has_link'] ?? false) ? 'https://example.com' : null,
                'status' => 'published',
            ]);

            PostPlatform::create([
                'post_id' => $post->id,
                'social_account_id' => $this->account->id,
                'platform_id' => $this->account->platform_id,
                'status' => 'published',
                'external_id' => 'x'.$post->id,
                'published_at' => now()->subDays($i)->setHour($attrs['hour'] ?? 10),
                'metrics' => ['views' => $views, 'likes' => 1],
            ]);
        }
    }

    private function service(): PostBenchmarkService
    {
        return app(PostBenchmarkService::class);
    }

    public function test_il_se_tait_tant_que_l_echantillon_est_trop_maigre(): void
    {
        $this->publish(5, 100);

        $result = $this->service()->forDraft($this->account, ['has_media' => true]);

        $this->assertTrue($result['insufficient']);
        $this->assertSame(5, $result['sample']);
        $this->assertSame([], $result['signals']);
        $this->assertNull($result['median']);
    }

    public function test_il_compare_le_brouillon_a_la_cohorte_correspondante(): void
    {
        // 12 posts avec média à 2000 vues, 12 sans média à 500.
        $this->publish(12, 2000, ['has_media' => true]);
        $this->publish(12, 500, ['has_media' => false]);

        $result = $this->service()->forDraft($this->account, ['has_media' => true]);

        $this->assertFalse($result['insufficient']);
        $this->assertSame(24, $result['sample']);

        $media = collect($result['signals'])->firstWhere('key', 'media');
        $this->assertNotNull($media);
        $this->assertSame('Avec média', $media['label']);
        $this->assertSame(2000, $media['median']);
        $this->assertSame(500, $media['other_median']);
        $this->assertSame(4.0, $media['ratio']);
        $this->assertSame(12, $media['n']);
    }

    public function test_un_groupe_trop_petit_est_omis_plutot_qu_affiche(): void
    {
        // 22 sans lien, seulement 3 avec : la comparaison n'est pas crédible.
        $this->publish(22, 500, ['has_link' => false]);
        $this->publish(3, 5000, ['has_link' => true]);

        $result = $this->service()->forDraft($this->account, ['has_link' => true, 'has_media' => false]);

        $this->assertFalse($result['insufficient']);
        $this->assertNull(collect($result['signals'])->firstWhere('key', 'link'));
    }

    public function test_la_mediane_resiste_a_un_post_viral(): void
    {
        $this->publish(11, 100, ['has_media' => true]);
        $this->publish(1, 900000, ['has_media' => true]);   // le coup de chance
        $this->publish(12, 100, ['has_media' => false]);

        $result = $this->service()->forDraft($this->account, ['has_media' => true]);
        $media = collect($result['signals'])->firstWhere('key', 'media');

        // Une moyenne donnerait ~75 000 : la médiane tient bon.
        $this->assertSame(100, $media['median']);
        $this->assertSame(1.0, $media['ratio']);
    }

    public function test_l_horaire_est_compare_par_tranche(): void
    {
        $this->publish(12, 3000, ['hour' => 20]);   // le soir
        $this->publish(12, 800, ['hour' => 9]);     // le matin

        $result = $this->service()->forDraft($this->account, ['hour' => 21]);
        $hour = collect($result['signals'])->firstWhere('key', 'hour');

        $this->assertSame('Publié le soir', $hour['label']);
        $this->assertSame(3000, $hour['median']);
        $this->assertSame(800, $hour['other_median']);
    }

    public function test_l_endpoint_repond_pour_les_comptes_accessibles(): void
    {
        $this->publish(12, 2000, ['has_media' => true]);
        $this->publish(12, 500, ['has_media' => false]);

        $response = $this->actingAs($this->user)->postJson(route('posts.benchmark'), [
            'accounts' => [$this->account->id],
            'content_fr' => 'Un brouillon',
            'has_media' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('accounts.0.account_id', $this->account->id);
        $response->assertJsonPath('accounts.0.insufficient', false);
    }

    public function test_le_composer_affiche_le_panneau_de_reperes(): void
    {
        $response = $this->actingAs($this->user)->get(route('posts.create'));

        $response->assertOk();
        $response->assertSee('Repères');
        $response->assertSee('postBenchmark()', false);
    }

    public function test_l_endpoint_ignore_un_compte_non_rattache(): void
    {
        $autre = SocialAccount::create([
            'platform_id' => $this->account->platform_id,
            'platform_account_id' => '99',
            'name' => 'Compte d\'un autre',
        ]);

        $response = $this->actingAs($this->user)->postJson(route('posts.benchmark'), [
            'accounts' => [$autre->id],
        ]);

        $response->assertOk();
        $response->assertJsonCount(0, 'accounts');
    }
}
