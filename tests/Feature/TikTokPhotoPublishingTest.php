<?php

namespace Tests\Feature;

use App\Models\AccountActionEvent;
use App\Models\BrowserTask;
use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\MediaPublication;
use App\Models\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TikTokPhotoPublishingTest extends TestCase
{
    use RefreshDatabase;

    private SocialAccount $account;

    private array $photos;

    private array $paths = [];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-08T12:00:00Z'));
        $this->user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($this->user);
        $this->account = SocialAccount::create(['name' => 'Compte attribué', 'platform_id' => Platform::where('slug', 'tiktok')->firstOrFail()->id, 'driver' => 'browser', 'browser_profile' => 'nas-test', 'browser_username' => 'owner', 'browser_session_status' => 'connected', 'agent_hourly_limit' => 120]);
        $this->account->users()->attach($this->user, ['is_active' => true]);
        $this->account->agentActions()->create(['action' => 'photo.publish', 'enabled' => true, 'daily_limit' => 3]);
        $this->photos = [];
        foreach (range(1, 2) as $i) {
            $name = 'photo-test-'.Str::uuid().'.png';
            $path = storage_path('app/private/media/'.$name);
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a3ioAAAAASUVORK5CYII='));
            $this->paths[] = $path;
            $this->photos[] = MediaFile::create(['filename' => $name, 'mime_type' => 'image/png', 'intimacy_level' => 'public', 'size' => filesize($path)])->id;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $p) {
            @unlink($p);
        }parent::tearDown();
    }

    private function url(): string
    {
        return '/api/community-manager/accounts/'.$this->account->id.'/tiktok/photo-posts';
    }

    private function data(): array
    {
        return ['media_ids' => $this->photos, 'title' => 'Titre', 'caption' => 'Une jolie promenade.', 'visibility' => 'public', 'request_key' => (string) Str::uuid()];
    }

    public function test_apercu_sans_tache_ni_publication_et_ordre_conserve(): void
    {
        $data = $this->data();
        $data['media_ids'] = array_reverse($this->photos);
        $this->postJson($this->url(), $data)->assertOk()->assertJsonPath('dry_run', true)->assertJsonPath('media.0.id', $this->photos[1]);
        $this->assertSame(0, BrowserTask::count());
        $this->assertSame(0, Post::count());
        $this->postJson($this->url(), array_replace($data, ['dry_run' => false, 'request_key' => null]))->assertUnprocessable();
    }

    public function test_intention_idempotente_puis_preuve_native_alimente_posts_et_usage_une_seule_fois(): void
    {
        $data = $this->data() + ['dry_run' => false];
        $id = $this->postJson($this->url(), $data)->assertAccepted()->json('id');
        $this->postJson($this->url(), $data)->assertAccepted()->assertJsonPath('id', $id);
        $this->assertSame(1, Post::count());
        $this->postJson($this->url(), array_replace($data, ['media_ids' => array_reverse($this->photos)]))->assertConflict();
        $task = app(BrowserTaskQueue::class)->claim('nas-test');
        $this->assertSame($id, $task->id);
        $this->assertSame(1, AccountActionEvent::count());
        $result = ['confirmed' => true, 'proof' => 'after_reload', 'username' => 'owner', 'title' => 'Titre', 'caption' => $data['caption'], 'visibility' => 'public', 'media_count' => 2, 'url' => 'https://www.tiktok.com/@owner/photo/987', 'upload_order_verified' => true, 'source_hashes' => array_column($task->payload['media'], 'sha256')];
        $this->postJson('/api/extension/tasks/'.$id.'/finish', ['status' => 'done', 'result' => $result])->assertOk();
        $this->assertSame('published', Post::first()->status);
        $this->assertSame(2, MediaPublication::count());
        $this->getJson('/api/community-manager/tiktok/photo-posts/'.$id)->assertOk()->assertJsonPath('confirmed', true);
        $this->postJson($this->url(), $data)->assertAccepted()->assertJsonPath('id', $id);
        $this->assertSame(2, MediaPublication::count());
    }

    public function test_preuve_absente_ne_marque_pas_les_photos_publiees(): void
    {
        $id = $this->postJson($this->url(), $this->data() + ['dry_run' => false])->json('id');
        app(BrowserTaskQueue::class)->claim('nas-test');
        $this->postJson('/api/extension/tasks/'.$id.'/finish', ['status' => 'done', 'result' => ['confirmed' => true]])->assertOk();
        $this->assertSame('uncertain', BrowserTask::find($id)->status);
        $this->assertSame('failed', Post::first()->status);
        $this->assertSame(0, MediaPublication::count());
    }

    public function test_fichiers_prives_modifies_et_quota_sont_controles_avant_le_geste(): void
    {
        $folder = MediaFolder::create(['name' => 'Privé', 'slug' => 'prive', 'is_private' => true]);
        $child = MediaFolder::create(['name' => 'Enfant', 'slug' => 'enfant', 'parent_id' => $folder->id]);
        MediaFile::find($this->photos[0])->update(['folder_id' => $child->id]);
        $this->postJson($this->url(), $this->data())->assertUnprocessable();
        MediaFile::find($this->photos[0])->update(['folder_id' => null]);
        $id = $this->postJson($this->url(), $this->data() + ['dry_run' => false])->json('id');
        file_put_contents($this->paths[0], 'modifié');
        $this->assertNull(app(BrowserTaskQueue::class)->claim('nas-test'));
        $this->assertSame('cancelled', BrowserTask::find($id)->status);
        $this->assertSame(0, AccountActionEvent::count());
    }

    public function test_repetition_sans_activation_ne_publie_pas_et_compte_attribue_obligatoire(): void
    {
        $this->account->agentActions()->where('action', 'photo.publish')->update(['enabled' => false]);
        $this->postJson($this->url(), $this->data() + ['dry_run' => false])->assertForbidden();
        $id = $this->postJson($this->url(), $this->data() + ['dry_run' => false, 'rehearse' => true])->assertAccepted()->json('id');
        $task = app(BrowserTaskQueue::class)->claim('nas-test');
        $this->assertSame($id, $task->id);
        $this->assertSame(0, AccountActionEvent::count());
        $this->assertSame(0, Post::count());
        $this->account->users()->detach();
        $this->getJson('/api/community-manager/tiktok/photo-posts/'.$id)->assertNotFound();
    }

    public function test_demande_perimee_est_terminale_sans_relance(): void
    {
        $id = $this->postJson($this->url(), $this->data() + ['dry_run' => false])->json('id');
        $this->travel(21)->minutes();
        $this->getJson('/api/community-manager/tiktok/photo-posts/'.$id)->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertNull(app(BrowserTaskQueue::class)->claim('nas-test'));
        $this->assertSame(0, AccountActionEvent::count());
    }

    public function test_formulaire_medias_affiche_ordre_et_test_nas(): void
    {
        $this->actingAs($this->user)->get('/tiktok/photos?media_ids='.implode(',', $this->photos))->assertOk()->assertSee('Tester sur le NAS')->assertSee('Description');
    }

    public function test_verification_reconcilie_sans_second_envoi_ni_double_usage(): void
    {
        $data = $this->data() + ['dry_run' => false];
        $id = $this->postJson($this->url(), $data)->json('id');
        $task = app(BrowserTaskQueue::class)->claim('nas-test');
        $receipt = ['send_clicked' => true, 'started_at' => now()->getTimestampMs(), 'username' => 'owner', 'title' => 'Titre', 'caption' => $data['caption'], 'visibility' => 'public', 'media_count' => 2, 'source_hashes' => array_column($task->payload['media'], 'sha256'), 'upload_order_verified' => true];
        $this->postJson('/api/extension/tasks/'.$id.'/finish', ['status' => 'uncertain', 'result' => $receipt, 'error' => 'Lecture interrompue'])->assertOk();
        $proof = ['confirmed' => true, 'proof' => 'after_reload', 'username' => 'owner', 'title' => 'Titre', 'caption' => $data['caption'], 'visibility' => 'public', 'media_count' => 2, 'url' => 'https://www.tiktok.com/@owner/photo/987'];
        $this->mock(BrowserTaskQueue::class)->shouldReceive('run')->twice()->withArgs(fn ($a, $kind, $payload) => $a->id === $this->account->id && $kind === 'tiktok.photo.check' && $payload['dry'] === true)->andReturn($proof);
        $url = '/api/community-manager/tiktok/photo-posts/'.$id.'/verify';
        $this->postJson($url)->assertOk()->assertJsonPath('dry_run', true);
        $this->assertSame(0, MediaPublication::count());
        $this->postJson($url, ['dry_run' => false])->assertOk()->assertJsonPath('confirmed', true)->assertJsonPath('sent', false);
        $this->assertSame(1, BrowserTask::count());
        $this->assertSame(1, AccountActionEvent::count());
        $this->assertSame(2, MediaPublication::count());
    }

    public function test_checkpoint_ne_confirme_rien_et_refuse_une_autre_photo(): void
    {
        $data = $this->data() + ['dry_run' => false];
        $id = $this->postJson($this->url(), $data)->json('id');
        $task = app(BrowserTaskQueue::class)->claim('nas-test');
        $receipt = ['send_clicked' => true, 'started_at' => now()->getTimestampMs(), 'username' => 'owner', 'title' => 'Titre', 'caption' => $data['caption'], 'visibility' => 'public', 'media_count' => 2, 'source_hashes' => array_column($task->payload['media'], 'sha256'), 'upload_order_verified' => true];
        $url = '/api/extension/tasks/'.$id.'/checkpoint';
        $this->postJson($url, ['result' => array_replace($receipt, ['username' => 'other'])])->assertConflict();
        $this->postJson($url, ['result' => $receipt + ['confirmed' => true]])->assertOk();
        $this->assertArrayNotHasKey('confirmed', BrowserTask::find($id)->result);
        $this->assertSame(0, MediaPublication::count());
        $this->travel(21)->minutes();
        $this->getJson('/api/community-manager/tiktok/photo-posts/'.$id)->assertJsonPath('status', 'running');
        $this->travel(5)->minutes();
        $this->getJson('/api/community-manager/tiktok/photo-posts/'.$id)->assertJsonPath('status', 'uncertain');
        $this->assertTrue(BrowserTask::find($id)->result['send_clicked']);
    }

    public function test_quota_reserve_et_revocation_finale_ne_publient_rien(): void
    {
        $this->account->agentActions()->where('action', 'photo.publish')->update(['daily_limit' => 1]);
        $id = $this->postJson($this->url(), $this->data() + ['dry_run' => false])->assertAccepted()->json('id');
        $other = $this->data() + ['dry_run' => false];
        $other['caption'] = 'Autre contenu';
        $this->postJson($this->url(), $other)->assertStatus(429);
        $task = app(BrowserTaskQueue::class)->claim('nas-test');
        $this->assertSame($id, $task->id);
        MediaFile::find($this->photos[0])->update(['intimacy_level' => 'never_publish']);
        $this->postJson('/api/extension/tasks/'.$id.'/authorize-photo')->assertUnprocessable();
        $this->assertSame(0, MediaPublication::count());
    }
}
