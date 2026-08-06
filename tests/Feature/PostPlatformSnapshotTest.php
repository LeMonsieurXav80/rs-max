<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Stats\StatsSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Historique des métriques d'une publication.
 *
 * `post_platform.metrics` ne garde que le dernier relevé : les valeurs
 * intermédiaires étaient écrasées, donc la courbe de montée d'un post — le
 * signal le plus prédictif de sa portée finale — était perdue. On vérifie ici
 * qu'elle est conservée, sans gonfler la table de lignes identiques.
 */
class PostPlatformSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private ?User $owner = null;

    private function postPlatform(): PostPlatform
    {
        $platform = Platform::create([
            'slug' => 'twitter',
            'name' => 'Twitter',
            'auth_type' => 'oauth1',
        ]);

        $account = SocialAccount::create([
            'platform_id' => $platform->id,
            'platform_account_id' => '42',
            'name' => 'Compte de test',
            'credentials' => [
                'api_key' => 'k',
                'api_secret' => 's',
                'access_token' => 't',
                'access_token_secret' => 'ts',
            ],
        ]);

        $this->owner = User::factory()->create();
        $this->owner->socialAccounts()->attach($account->id, ['is_active' => true]);

        $post = Post::create([
            'user_id' => $this->owner->id,
            'content_fr' => 'Contenu de test',
            'status' => 'published',
        ]);

        return PostPlatform::create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'platform_id' => $platform->id,
            'status' => 'published',
            'external_id' => '1234567890',
            'published_at' => now(),
        ]);
    }

    /**
     * Réponse de l'API X pour des métriques données.
     */
    private function twitterResponse(int $views, int $likes, int $followers = 1000): array
    {
        return [
            'data' => [[
                'public_metrics' => [
                    'impression_count' => $views,
                    'like_count' => $likes,
                    'reply_count' => 0,
                    'retweet_count' => 0,
                    'bookmark_count' => 0,
                ],
            ]],
            'includes' => [
                'users' => [[
                    'public_metrics' => ['followers_count' => $followers],
                ]],
            ],
        ];
    }

    public function test_le_premier_releve_cree_un_snapshot(): void
    {
        Http::fake(['api.twitter.com/*' => Http::response($this->twitterResponse(100, 5))]);

        $pp = $this->postPlatform();
        app(StatsSyncService::class)->syncPostPlatform($pp);

        $this->assertCount(1, $pp->snapshots()->get());

        $snap = $pp->snapshots()->first();
        $this->assertSame(100, (int) $snap->views);
        $this->assertSame(5, (int) $snap->likes);
        $this->assertSame(1000, (int) $snap->followers);
    }

    public function test_les_releves_successifs_construisent_la_courbe(): void
    {
        Http::fakeSequence()
            ->push($this->twitterResponse(100, 5))
            ->push($this->twitterResponse(450, 12))
            ->push($this->twitterResponse(900, 30));

        $pp = $this->postPlatform();
        $service = app(StatsSyncService::class);

        foreach (range(1, 3) as $_) {
            $service->syncPostPlatform($pp->fresh());
        }

        $views = $pp->snapshots()->pluck('views')->map(fn ($v) => (int) $v)->all();
        $this->assertSame([100, 450, 900], $views);
    }

    public function test_un_releve_identique_ne_cree_pas_de_ligne(): void
    {
        Http::fake(['api.twitter.com/*' => Http::response($this->twitterResponse(100, 5))]);

        $pp = $this->postPlatform();
        $service = app(StatsSyncService::class);

        $service->syncPostPlatform($pp);
        $service->syncPostPlatform($pp->fresh());
        $service->syncPostPlatform($pp->fresh());

        // Un post figé ne doit pas générer une ligne par synchro.
        $this->assertCount(1, $pp->snapshots()->get());
    }

    public function test_une_variation_de_followers_seule_ne_cree_pas_de_ligne(): void
    {
        Http::fakeSequence()
            ->push($this->twitterResponse(100, 5, followers: 1000))
            ->push($this->twitterResponse(100, 5, followers: 1050));

        $pp = $this->postPlatform();
        $service = app(StatsSyncService::class);

        $service->syncPostPlatform($pp);
        $service->syncPostPlatform($pp->fresh());

        // `followers` évolue indépendamment du post : il ne doit pas déclencher de snapshot.
        $this->assertCount(1, $pp->snapshots()->get());
    }

    public function test_le_sparkline_rend_une_courbe_a_partir_des_releves(): void
    {
        $svg = Blade::render(
            '<x-sparkline :points="$p" :labels="$l" label="Vues" />',
            ['p' => [10, 40, 90], 'l' => ['06/08 10h00', '06/08 11h00', '06/08 12h00']]
        );

        $this->assertStringContainsString('<polyline', $svg);
        $this->assertStringContainsString('stroke-width="2"', $svg);
        // Le point le plus haut (90) doit être en haut du viewBox, le plus bas (10) en bas.
        $this->assertMatchesRegularExpression('/points="3,21(\.\d+)? [\d.]+,[\d.]+ 93,3(\.\d+)?"/', $svg);
        $this->assertStringContainsString('06/08 12h00', $svg);
    }

    public function test_un_seul_releve_ne_trace_pas_de_polyline(): void
    {
        $svg = Blade::render('<x-sparkline :points="$p" label="Vues" />', ['p' => [42]]);

        $this->assertStringNotContainsString('<polyline', $svg);
        $this->assertStringContainsString('<circle', $svg);
    }

    public function test_une_serie_plate_ne_divise_pas_par_zero(): void
    {
        $svg = Blade::render('<x-sparkline :points="$p" label="Vues" />', ['p' => [50, 50, 50]]);

        $this->assertStringContainsString('<polyline', $svg);
        $this->assertStringNotContainsString('NAN', strtoupper($svg));
    }

    public function test_la_page_du_post_affiche_la_courbe_de_montee(): void
    {
        Http::fakeSequence()
            ->push($this->twitterResponse(100, 5))
            ->push($this->twitterResponse(450, 12));

        $pp = $this->postPlatform();
        $service = app(StatsSyncService::class);
        $service->syncPostPlatform($pp);
        $service->syncPostPlatform($pp->fresh());

        $response = $this->actingAs($this->owner)->get(route('posts.show', $pp->post_id));

        $response->assertOk();
        $response->assertSee('<polyline', false);
        // Progression depuis le relevé précédent : 450 - 100.
        $response->assertSee('+350');
    }

    public function test_la_page_reste_lisible_sans_aucun_releve(): void
    {
        $pp = $this->postPlatform();
        $pp->update(['metrics' => ['views' => 10, 'likes' => 1], 'metrics_synced_at' => now()]);

        $response = $this->actingAs($this->owner)->get(route('posts.show', $pp->post_id));

        $response->assertOk();
        $response->assertSee('courbe en cours');
    }

    public function test_les_snapshots_sont_supprimes_avec_la_publication(): void
    {
        Http::fake(['api.twitter.com/*' => Http::response($this->twitterResponse(100, 5))]);

        $pp = $this->postPlatform();
        app(StatsSyncService::class)->syncPostPlatform($pp);

        $id = $pp->id;
        $pp->delete();

        $this->assertDatabaseMissing('post_platform_snapshots', ['post_platform_id' => $id]);
    }
}
