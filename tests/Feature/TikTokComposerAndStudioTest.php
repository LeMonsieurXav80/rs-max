<?php

namespace Tests\Feature;

use App\Jobs\PublishToPlatformJob;
use App\Models\BrowserTask;
use App\Models\ExternalPost;
use App\Models\MediaFile;
use App\Models\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\SocialAccountSnapshot;
use App\Models\User;
use App\Services\Browser\BrowserTaskQueue;
use App\Services\PublishingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TikTokComposerAndStudioTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create(['role' => 'admin']);
        $this->account = SocialAccount::create([
            'platform_id' => Platform::where('slug', 'tiktok')->firstOrFail()->id,
            'name' => 'Compte TikTok de test', 'driver' => 'browser', 'browser_profile' => 'nas-test',
            'browser_username' => 'owner', 'browser_session_status' => 'connected', 'languages' => ['fr'],
        ]);
        $this->account->users()->attach($this->manager, ['is_active' => true]);
    }

    public function test_composer_et_modifier_une_planification_puis_livrer_par_companion(): void
    {
        $this->actingAs($this->manager);
        $this->get(route('posts.create'))->assertOk()->assertSee('Compte TikTok de test');
        $files = ['fixture-photo.png' => 'image/png', 'fixture-video.mp4' => 'video/mp4'];
        $paths = [];
        foreach ($files as $filename => $mime) {
            $path = storage_path('app/private/media/'.$filename);
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, 'test');
            $paths[] = $path;
            MediaFile::create(['filename' => $filename, 'mime_type' => $mime, 'size' => 4, 'intimacy_level' => 'public']);
        }

        try {
            $data = ['accounts' => [$this->account->id], 'content_fr' => 'Texte initial', 'status' => 'scheduled',
                'scheduled_at' => now()->addDay()->toDateTimeString()];
            $this->post(route('posts.store'), $data)->assertSessionHasErrors('media');
            $data['media'] = [json_encode(['url' => '/media/fixture-photo.png', 'type' => 'image'])];
            $this->post(route('posts.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
            $post = Post::firstOrFail();
            $this->get(route('posts.edit', $post))->assertOk()->assertSee('Compte TikTok de test');

            $data['content_fr'] = 'Vidéo modifiée';
            $data['media'] = [json_encode(['url' => '/media/fixture-video.mp4', 'type' => 'video'])];
            $this->put(route('posts.update', $post), $data)->assertRedirect()->assertSessionHasNoErrors();
            $delivery = $post->postPlatforms()->firstOrFail();
            $delivery->update(['status' => 'publishing']);
            $id = '7705777869812731158';
            $this->mock(BrowserTaskQueue::class)->shouldReceive('run')->once()
                ->withArgs(fn ($account, $kind, $payload, $timeout) => $account->is($this->account)
                    && $kind === 'tiktok.publish' && $payload['media_type'] === 'video'
                    && $payload['caption'] === 'Vidéo modifiée'
                    && $payload['publication_key'] === 'post-platform:'.$delivery->id
                    && $payload['media'][0]['sha256'] === hash('sha256', 'test'))
                ->andReturn(['id' => $id, 'url' => "https://www.tiktok.com/@owner/video/{$id}",
                    'confirmed' => true, 'proof' => 'after_reload']);
            (new PublishToPlatformJob($delivery))->handle(app(PublishingService::class));
            $this->assertSame('published', $delivery->fresh()->status);
            $this->assertSame($id, $delivery->fresh()->external_id);
        } finally {
            foreach ($paths as $path) {
                unlink($path);
            }
        }
    }

    public function test_ajouter_tiktok_depuis_la_fiche_conserve_la_planification_et_les_autres_diffusions(): void
    {
        $this->actingAs($this->manager);
        $post = Post::create([
            'user_id' => $this->manager->id, 'content_fr' => 'Contenu', 'status' => 'scheduled',
            'scheduled_at' => now()->addDay()->startOfMinute(),
        ]);
        $platform = Platform::create(['slug' => 'instagram', 'name' => 'Instagram', 'auth_type' => 'oauth2']);
        $other = SocialAccount::create(['name' => 'Instagram de test', 'platform_id' => $platform->id]);
        $original = $post->postPlatforms()->create(['platform_id' => $platform->id,
            'social_account_id' => $other->id, 'status' => 'pending']);

        $this->get(route('posts.show', $post))->assertOk()->assertSee('Compte TikTok de test');
        $url = route('posts.addPlatforms', $post);
        $data = ['accounts' => [$this->account->id]];
        $this->post($url, $data)->assertSessionHasErrors('accounts');
        $this->assertSame(1, $post->postPlatforms()->count());

        MediaFile::create(['filename' => 'fiche-photo.png', 'mime_type' => 'image/png', 'size' => 4, 'intimacy_level' => 'public']);
        $post->update(['media' => [['url' => '/media/fiche-photo.png', 'type' => 'image']]]);
        $this->post($url, $data)->assertRedirect(route('posts.show', $post))->assertSessionHasNoErrors();
        $this->post($url, $data)->assertRedirect(route('posts.show', $post))->assertSessionHasNoErrors();

        $this->assertSame(2, $post->postPlatforms()->count());
        $this->assertSame('pending', $original->fresh()->status);
        $this->assertSame('pending', $post->postPlatforms()->where('social_account_id', $this->account->id)->firstOrFail()->status);
        $this->assertSame('scheduled', $post->fresh()->status);
        $this->assertTrue($post->scheduled_at->equalTo($post->fresh()->scheduled_at));
    }

    public function test_autorisation_du_clic_liee_a_la_diffusion_et_a_usage_unique(): void
    {
        Sanctum::actingAs($this->manager);
        $post = Post::create(['user_id' => $this->manager->id, 'content_fr' => 'Contenu', 'status' => 'publishing']);
        $delivery = $post->postPlatforms()->create(['platform_id' => $this->account->platform_id,
            'social_account_id' => $this->account->id, 'status' => 'publishing']);
        MediaFile::create(['filename' => 'fixture.png', 'mime_type' => 'image/png', 'size' => 4, 'intimacy_level' => 'public']);
        $task = BrowserTask::create([
            'social_account_id' => $this->account->id, 'kind' => 'tiktok.publish', 'profile' => 'nas-test',
            'idempotency_key' => 'post-platform:'.$delivery->id, 'status' => 'running',
            'payload' => ['deadline' => now()->addMinutes(5)->toIso8601String(),
                'media' => [['name' => 'fixture.png', 'mimetype' => 'image/png', 'size' => 4]]],
        ]);

        $url = '/api/extension/tasks/'.$task->id.'/authorize-tiktok-publication';
        $this->postJson($url)->assertOk();
        $this->assertTrue($task->fresh()->result['commit_authorized']);
        $this->postJson($url)->assertConflict();
    }

    public function test_import_humain_alimente_les_publications_natives_et_les_statistiques(): void
    {
        Storage::fake('local');
        $avatar = 'https://p16-common-sign.tiktokcdn-eu.com/avatar.webp';
        Http::fake([$avatar => Http::response('image', 200, ['Content-Type' => 'image/webp'])]);
        $id = '7705777869812731158';
        $this->mock(BrowserTaskQueue::class)->shouldReceive('run')->once()
            ->withArgs(fn ($account, $kind, $payload) => $account->is($this->account)
                && $kind === 'tiktok.studio.read' && $payload['username'] === 'owner')
            ->andReturn(['username' => 'owner', 'avatar' => $avatar, 'followers_count' => 56031,
                'posts' => [['id' => $id, 'type' => 'photo', 'published_at' => now()->subDay()->timestamp,
                    'caption' => 'Photo native', 'metrics' => ['views' => 636, 'likes' => 67, 'comments' => 35, 'shares' => 4, 'bookmarks' => 12]]]]);

        $token = $this->manager->createToken('agent', ['community-manager'])->plainTextToken;
        $this->withToken($token)->postJson('/external/refresh')->assertUnauthorized();
        $this->withToken($token)->getJson('/api/stats/top-posts')->assertForbidden();
        $this->actingAs($this->manager)->postJson(route('external.refresh'), ['accounts' => [$this->account->id]])
            ->assertOk()->assertJsonPath('imported', 1);
        $this->assertSame(636, ExternalPost::where('external_id', $id)->firstOrFail()->metrics['views']);
        $this->assertSame(56031, (int) SocialAccountSnapshot::firstOrFail()->followers_count);
        $this->assertStringStartsWith('/media/pp_tiktok_', $this->account->fresh()->profile_picture_url);
        $this->get(route('external.index', ['accounts' => [$this->account->id]]))->assertOk()->assertSee('Photo native');
        $this->get(route('stats.overview', ['accounts' => [$this->account->id]]))->assertOk()->assertViewHas('stats', fn ($stats) => $stats['total_views'] === 636);
    }
}
