<?php

namespace Tests\Feature;

use App\Models\ExternalPost;
use App\Models\Partner;
use App\Models\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Import\PostMergeService;
use App\Services\PartnerTagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fusion de deux publications qui n'en sont qu'une.
 *
 * Le cas reel : une publication Instagram, republiee le lendemain sur
 * Pinterest par la synchronisation automatique, adoptee separement faute de
 * rapprochement possible a l'epoque.
 */
class PostMergeTest extends TestCase
{
    use RefreshDatabase;

    private array $platforms = [];

    private array $accounts = [];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['instagram' => 'Instagram', 'pinterest' => 'Pinterest'] as $slug => $name) {
            $platform = Platform::firstOrCreate(['slug' => $slug], ['name' => $name, 'auth_type' => 'oauth2']);
            $this->platforms[$slug] = $platform;
            $this->accounts[$slug] = SocialAccount::create([
                'platform_id' => $platform->id,
                'platform_account_id' => 'acc-'.$slug,
                'name' => 'Compte '.$name,
                'credentials' => ['access_token' => 't'],
            ]);
        }

        $this->user = User::factory()->create(['role' => 'manager']);
    }

    private function nativePost(string $slug, string $content, \DateTimeInterface $at): Post
    {
        $post = Post::create([
            'user_id' => $this->user->id,
            'content_fr' => $content,
            'platform_contents' => [$slug => $content],
            'status' => 'published',
            'source_type' => 'native',
            'published_at' => $at,
        ]);

        $post->postPlatforms()->create([
            'social_account_id' => $this->accounts[$slug]->id,
            'platform_id' => $this->platforms[$slug]->id,
            'status' => 'published',
            'external_id' => $slug.'-'.uniqid(),
            'published_at' => $at,
        ]);

        return $post->fresh();
    }

    public function test_les_diffusions_rejoignent_la_publication_conservee(): void
    {
        $insta = $this->nativePost('instagram', 'La legende Instagram', now()->subDays(2));
        $pin = $this->nativePost('pinterest', 'La description Pinterest', now()->subDay());

        app(PostMergeService::class)->merge($insta, $pin);

        $this->assertSame(1, Post::count());
        $this->assertSame(2, $insta->fresh()->postPlatforms()->count());
        $this->assertNull(Post::find($pin->id));
    }

    public function test_les_textes_des_deux_reseaux_sont_conserves(): void
    {
        $insta = $this->nativePost('instagram', 'La legende Instagram', now()->subDays(2));
        $pin = $this->nativePost('pinterest', 'La description Pinterest', now()->subDay());

        app(PostMergeService::class)->merge($insta, $pin);

        $contents = $insta->fresh()->platform_contents;

        $this->assertSame('La legende Instagram', $contents['instagram']);
        $this->assertSame('La description Pinterest', $contents['pinterest']);
    }

    public function test_la_publication_native_absorbee_ne_redevient_pas_adoptable(): void
    {
        $insta = $this->nativePost('instagram', 'La legende Instagram', now()->subDays(2));
        $pin = $this->nativePost('pinterest', 'La description Pinterest', now()->subDay());

        $externe = ExternalPost::create([
            'social_account_id' => $this->accounts['pinterest']->id,
            'platform_id' => $this->platforms['pinterest']->id,
            'external_id' => 'pin-1',
            'content' => 'La description Pinterest',
            'published_at' => now()->subDay(),
            'adopted_post_id' => $pin->id,
            'adopted_at' => now(),
        ]);

        app(PostMergeService::class)->merge($insta, $pin);

        $this->assertSame($insta->id, $externe->fresh()->adopted_post_id);
        $this->assertSame(0, ExternalPost::adoptable()->count());
    }

    public function test_les_marques_de_l_absorbee_survivent(): void
    {
        $marque = Partner::create(['name' => 'Zara', 'slug' => Partner::slugFor('Zara')]);

        $insta = $this->nativePost('instagram', 'La legende Instagram', now()->subDays(2));
        $pin = $this->nativePost('pinterest', 'La description Pinterest', now()->subDay());

        $pin->partners()->attach($marque->id, ['source' => PartnerTagService::SOURCE_TEXT]);

        app(PostMergeService::class)->merge($insta, $pin);

        $this->assertSame(['Zara'], $insta->fresh()->partners->pluck('name')->all());
    }

    public function test_la_date_retenue_est_celle_de_la_publication_d_origine(): void
    {
        $origine = now()->subDays(2)->startOfMinute();

        $insta = $this->nativePost('instagram', 'La legende Instagram', $origine);
        $pin = $this->nativePost('pinterest', 'La description Pinterest', now()->subDay());

        app(PostMergeService::class)->merge($insta, $pin);

        $this->assertSame(
            $origine->format('Y-m-d H:i'),
            $insta->fresh()->published_at->format('Y-m-d H:i')
        );
    }

    public function test_deux_publications_du_meme_reseau_sont_refusees(): void
    {
        $a = $this->nativePost('instagram', 'Premiere', now()->subDays(2));
        $b = $this->nativePost('instagram', 'Seconde', now()->subDay());

        $this->expectException(\InvalidArgumentException::class);

        app(PostMergeService::class)->merge($a, $b);
    }

    public function test_la_plus_ancienne_est_choisie_comme_publication_conservee(): void
    {
        $recente = $this->nativePost('pinterest', 'Republiee', now()->subDay());
        $ancienne = $this->nativePost('instagram', 'Originale', now()->subDays(3));

        [$keep, $absorbed] = app(PostMergeService::class)->pick($recente, $ancienne);

        $this->assertSame($ancienne->id, $keep->id);
        $this->assertSame($recente->id, $absorbed->id);
    }
}
