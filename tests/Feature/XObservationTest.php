<?php

namespace Tests\Feature;

use App\Models\BotActionLog;
use App\Models\ExternalPost;
use App\Models\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\FollowersService;
use App\Services\Stats\StatsSyncService;
use App\Services\Stats\XObservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class XObservationTest extends TestCase
{
    use RefreshDatabase;

    private SocialAccount $account;

    private User $user;

    private Platform $platform;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
        $this->platform = Platform::firstOrCreate(['slug' => 'twitter'], ['name' => 'X']);
        $this->account = SocialAccount::create(['platform_id' => $this->platform->id, 'platform_account_id' => '77', 'name' => 'Xav', 'driver' => 'browser', 'languages' => ['fr']]);
        $this->account->users()->attach($this->user, ['is_active' => true]);
    }

    private function publishedTweet(string $id): PostPlatform
    {
        $post = Post::create(['user_id' => $this->user->id, 'content_fr' => 'Bonjour', 'status' => 'published']);

        return PostPlatform::create(['post_id' => $post->id, 'social_account_id' => $this->account->id, 'platform_id' => $this->platform->id,
            'status' => 'published', 'external_id' => $id, 'published_at' => now()->subHours(3)]);
    }

    private function send(array $tweets, ?int $followers = 1500): array
    {
        return app(XObservationService::class)->record($this->account, $followers, $tweets);
    }

    public function test_un_releve_alimente_les_stats_de_la_publication_et_les_abonnes(): void
    {
        $postPlatform = $this->publishedTweet('500');

        $result = $this->send([['id' => '500', 'views' => 900, 'likes' => 12, 'replies' => 3, 'reposts' => 2, 'bookmarks' => 1]]);
        $this->assertTrue($result['followers']);
        $this->assertSame(1, $result['published']);

        $postPlatform->refresh();
        $this->assertSame(900, $postPlatform->metrics['views']);
        $this->assertSame(3, $postPlatform->metrics['comments']);
        $this->assertSame(1, $postPlatform->snapshots()->count());
        $this->assertSame(1500, $this->account->fresh()->followers_count);
        $this->assertSame(1, $this->account->snapshots()->where('followers_count', 1500)->count());
        $this->assertSame(1, BotActionLog::where('action_type', 'x.observation')->where('source', 'browser')->count());
    }

    public function test_des_vues_masquees_ne_font_pas_perdre_les_precedentes(): void
    {
        $postPlatform = $this->publishedTweet('500');
        $this->send([['id' => '500', 'views' => 900, 'likes' => 12]]);
        $this->send([['id' => '500', 'views' => null, 'likes' => 13]], 1600);

        $this->assertSame(900, $postPlatform->fresh()->metrics['views']);
        $this->assertSame(13, $postPlatform->fresh()->metrics['likes']);
        $this->assertSame(1, $this->account->snapshots()->count());
        $this->assertSame(1600, $this->account->snapshots()->first()->followers_count);
    }

    public function test_un_tweet_inconnu_devient_une_publication_a_adopter_et_le_bruit_est_ecarte(): void
    {
        $this->send([
            ['id' => '600', 'text' => 'Publié à la main', 'published_at' => '2026-11-05T10:00:00+00:00', 'views' => 40],
            ['id' => '601', 'text' => '@ami merci', 'is_reply' => true],
        ], null);

        $this->assertNull(ExternalPost::where('external_id', '600')->first()->ignored_at);
        $this->assertSame('auto_noise', ExternalPost::where('external_id', '601')->first()->ignored_reason);
    }

    public function test_un_compte_en_mode_navigateur_napelle_plus_lapi_x(): void
    {
        $postPlatform = $this->publishedTweet('500');

        $this->assertFalse(app(StatsSyncService::class)->shouldSync($postPlatform));
        $this->assertFalse(app(StatsSyncService::class)->syncPostPlatform($postPlatform));
        $this->assertNull(app(FollowersService::class)->syncFollowers($this->account));
        Http::assertNothingSent();
    }
}
