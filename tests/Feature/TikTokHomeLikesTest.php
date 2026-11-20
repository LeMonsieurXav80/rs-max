<?php

namespace Tests\Feature;

use App\Console\Commands\TikTokHomeLikesCommand;
use App\Models\AccountActionEvent;
use App\Models\BrowserTask;
use App\Models\Platform;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Agent\TikTokFeedEngagement;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TikTokHomeLikesTest extends TestCase
{
    use RefreshDatabase;

    private function account(): SocialAccount
    {
        $platform = Platform::firstOrCreate(['slug' => 'tiktok'], ['name' => 'TikTok', 'is_active' => true]);
        $account = SocialAccount::create([
            'platform_id' => $platform->id, 'platform_account_id' => 'test', 'name' => 'WildyCaro',
            'driver' => 'browser', 'browser_profile' => 'nas-example', 'browser_username' => 'wildycaro',
        ]);
        $account->agentActions()->create(['action' => 'comment.like', 'enabled' => true, 'daily_limit' => 300]);

        return $account;
    }

    private function like(string $video, string $key, string $status = 'sending'): array
    {
        return ['video' => $video, 'key' => str_repeat($key, 64), 'author' => 'auteur '.$key, 'status' => $status];
    }

    public function test_le_passage_enchaine_des_sessions_dans_le_fil_du_telephone_et_ne_rejoue_pas(): void
    {
        $this->travelTo(now('Europe/Lisbon')->setTime(12, 10));
        $account = $this->account();
        Setting::set(TikTokHomeLikesCommand::PROFILES_SETTING, json_encode([$account->id => 'android-honor']));

        $queue = $this->mock(BrowserTaskQueue::class);
        $queue->shouldReceive('run')->once()->ordered()->withArgs(fn ($a, $kind, $payload, $timeout, $profile) => $a->is($account)
            && $kind === TikTokFeedEngagement::KIND && $payload['url'] === 'https://www.tiktok.com/' && $payload['minutes'] === 20
            && $payload['per_video'] === 5 && $payload['max_likes'] === 60 && $timeout === 28 * 60 && $profile === 'android-honor')
            ->andReturn(['items' => [$this->like('Ana', 'a', 'confirmed'), $this->like('Ana', 'b', 'shown'), $this->like('Léa', 'c', 'uncertain')]]);
        $queue->shouldReceive('run')->twice()->ordered()->withArgs(fn ($a, $kind, $payload) => $kind === TikTokFeedEngagement::KIND
            && $payload['max_likes'] === 57)->andReturn(['items' => []]);

        $this->artisan('tiktok:home-likes')->expectsOutput('@wildycaro android-honor Pour toi, jusqu’à 60 likes')->assertSuccessful();
        $this->artisan('tiktok:home-likes --commit')->expectsOutput('2 likes affichés ou confirmés sur 3 gestes, 60 maximum, 3 session(s).')->assertSuccessful();
        $this->artisan('tiktok:home-likes --commit')->doesntExpectOutput('2 likes affichés ou confirmés sur 3 gestes, 60 maximum, 3 session(s).')->assertSuccessful();
        $this->assertSame('nas-example', $account->profileFor('tiktok.reply'));
    }

    public function test_chaque_like_est_reserve_avant_le_geste_et_conclu_selon_ce_que_le_telephone_a_vu(): void
    {
        $account = $this->account();
        $task = BrowserTask::create(['social_account_id' => $account->id, 'profile' => 'android-honor',
            'kind' => TikTokFeedEngagement::KIND, 'payload' => ['url' => 'https://www.tiktok.com/', 'max_likes' => 2]]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/extension/tasks/next?profile=android-honor')->assertJsonPath('id', $task->id);
        $this->assertSame(0, AccountActionEvent::count());  // la session elle-même ne consomme rien

        $checkpoint = fn (array $like) => $this->postJson("/api/extension/tasks/{$task->id}/checkpoint", ['result' => ['items' => [$like]]]);
        $checkpoint($this->like('Ana', 'a'))->assertOk();
        $checkpoint($this->like('Ana', 'a'))->assertOk();  // reçu répété : un seul événement
        $checkpoint($this->like('Léa', 'b'))->assertOk();
        $checkpoint($this->like('Léa', 'c'))->assertStatus(409);  // quota de la session atteint : aucun geste
        $this->assertSame(2, AccountActionEvent::where('action', 'tiktok.comment.like')->where('status', 'attempted')->count());

        // Le rapport final perd le second like : il reste réservé, donc incertain, jamais effacé.
        $this->postJson("/api/extension/tasks/{$task->id}/finish", ['status' => 'done',
            'result' => ['items' => [$this->like('Ana', 'a', 'confirmed')]]])->assertOk();

        $this->assertSame(['confirmed', 'uncertain'], AccountActionEvent::orderBy('id')->pluck('status')->all());
        $this->assertCount(2, $task->fresh()->result['items']);
        $this->assertNull(AccountActionEvent::first()->browser_task_id);
    }

    public function test_une_session_orpheline_rend_ses_likes_reserves_incertains(): void
    {
        $account = $this->account();
        $task = BrowserTask::create(['social_account_id' => $account->id, 'profile' => 'android-honor',
            'kind' => TikTokFeedEngagement::KIND, 'payload' => ['max_likes' => 5]]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/extension/tasks/next?profile=android-honor')->assertJsonPath('id', $task->id);
        $this->postJson("/api/extension/tasks/{$task->id}/checkpoint", ['result' => ['items' => [$this->like('Ana', 'a')]]])->assertOk();

        $this->travel(BrowserTaskQueue::ORPHAN_AFTER + 60)->seconds();
        $this->getJson('/api/extension/tasks/next?profile=android-honor')->assertJsonPath('id', null);

        $this->assertSame(BrowserTask::UNCERTAIN, $task->fresh()->status);
        $this->assertSame('uncertain', AccountActionEvent::first()->status);
    }
}
