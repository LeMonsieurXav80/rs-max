<?php

namespace Tests\Feature;

use App\Console\Commands\TikTokHomeLikesCommand;
use App\Models\Platform;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Services\Browser\BrowserException;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TikTokHomeLikesTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_passage_reste_sur_le_fil_pour_toi_du_telephone_et_ne_rejoue_pas(): void
    {
        $this->travelTo(now('Europe/Lisbon')->setTime(12, 10));
        $platform = Platform::firstOrCreate(['slug' => 'tiktok'], ['name' => 'TikTok', 'is_active' => true]);
        $account = SocialAccount::create([
            'platform_id' => $platform->id, 'platform_account_id' => 'test', 'name' => 'WildyCaro',
            'driver' => 'browser', 'browser_profile' => 'nas-example', 'browser_username' => 'wildycaro',
        ]);
        $account->agentActions()->create(['action' => 'comment.like', 'enabled' => true, 'daily_limit' => 300]);
        Setting::set(TikTokHomeLikesCommand::PROFILES_SETTING, json_encode([$account->id => 'android-honor']));

        $home = 'https://www.tiktok.com/';
        $video = $home.'@other/video/123';
        $missing = ['key' => str_repeat('a', 64), 'author' => 'auteur1', 'liked' => false];
        $valid = ['key' => str_repeat('b', 64), 'author' => 'auteur2', 'liked' => false];
        $self = ['key' => str_repeat('c', 64), 'author' => 'wildycaro', 'liked' => false];
        $queue = $this->mock(BrowserTaskQueue::class);
        $queue->shouldReceive('run')->once()->ordered()->withArgs(function ($a, $kind, $payload, $timeout, $profile) use ($account, $home) {
            return $a->is($account) && $kind === 'tiktok.feed.read' && $payload['url'] === $home
                && $payload['resume'] === false && $profile === 'android-honor';
        })->andReturn(['items' => [['url' => $video], ['url' => $home.'@wildycaro/video/456']]]);
        $queue->shouldReceive('run')->once()->ordered()->withArgs(function ($a, $kind, $payload, $timeout, $profile) use ($video) {
            return $kind === 'tiktok.video.comments' && $payload['url'] === $video && $profile === 'android-honor';
        })->andReturn(['video' => ['url' => $video], 'comments' => [$self, $missing, $valid]]);
        $queue->shouldReceive('run')->once()->ordered()->withArgs(fn ($a, $kind, $payload, $timeout, $profile) => $kind === 'tiktok.comment.like'
            && $payload['target'] === $missing && $profile === 'android-honor')
            ->andThrow(new BrowserException('Commentaire exact introuvable : aucun geste.'));
        $queue->shouldReceive('run')->once()->ordered()->withArgs(fn ($a, $kind, $payload, $timeout, $profile) => $kind === 'tiktok.comment.like'
            && $payload['target'] === $valid && $profile === 'android-honor' && str_starts_with($payload['publication_key'], 'tiktok-home-like:'))
            ->andReturn(['confirmed' => true, 'proof' => 'after_reload']);
        $queue->shouldReceive('run')->times(3)->ordered()->withArgs(fn ($a, $kind, $payload, $timeout, $profile) => $kind === 'tiktok.feed.read'
            && $payload['url'] === $home && $payload['resume'] === true && $profile === 'android-honor')->andReturn(['items' => []]);

        $this->artisan('tiktok:home-likes')->expectsOutput('@wildycaro android-honor Pour toi, jusqu’à 60 likes')->assertSuccessful();
        $this->artisan('tiktok:home-likes --commit')->expectsOutput('1 likes ajoutés et confirmés sur 2 demandes, 60 maximum.')->assertSuccessful();
        $this->artisan('tiktok:home-likes --commit')->doesntExpectOutput('1 likes ajoutés et confirmés sur 2 demandes, 60 maximum.')->assertSuccessful();
        $this->assertSame('nas-example', $account->profileFor('tiktok.reply'));
    }
}
