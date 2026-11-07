<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Services\Browser\BrowserException;
use App\Services\Browser\BrowserTaskQueue;
use App\Services\Browser\XSessionService;
use App\Services\TelegramNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BrowserSessionTelegramTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        Setting::setEncrypted('notify_telegram_bot_token', 'fake-test-only');
        Setting::set('notify_telegram_chat_id', 'test-chat');
    }

    private function account(string $slug = 'twitter'): SocialAccount
    {
        $platform = Platform::firstOrCreate(['slug' => $slug], ['name' => $slug === 'twitter' ? 'X' : 'TikTok']);

        return SocialAccount::create(['name' => 'Compte dynamique', 'platform_id' => $platform->id,
            'driver' => 'browser', 'browser_profile' => 'nas-test', 'browser_username' => 'owner', 'browser_session_status' => 'connected',
            'browser_session' => [['name' => 'test-cookie', 'value' => 'fake']], 'browser_session_received_at' => now()]);
    }

    public function test_x_expire_envoie_compte_reseau_et_site_une_seule_fois(): void
    {
        $a = $this->account();
        $this->mock(BrowserTaskQueue::class)->shouldReceive('run')->andReturn(['username' => null]);
        $this->assertFalse(app(XSessionService::class)->check($a));
        $this->assertFalse(app(XSessionService::class)->check($a));
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['chat_id'] === 'test-chat' && str_contains($r['text'], $a->name)
            && str_contains($r['text'], 'X') && str_contains($r['text'], 'https://x.com/')
            && ! isset($r['parse_mode']));
    }

    public function test_restauration_x_reussie_ne_declenche_aucune_fausse_alerte(): void
    {
        $a = $this->account();
        $read = 0;
        $this->mock(BrowserTaskQueue::class)->shouldReceive('run')->andReturnUsing(function ($a, $kind) use (&$read) {
            return $kind === 'session.apply' ? [] : ['username' => ++$read === 1 ? null : 'owner'];
        });
        $this->assertTrue(app(XSessionService::class)->check($a));
        Http::assertNothingSent();
    }

    public function test_x_sans_session_enregistree_signale_le_reseau_correct(): void
    {
        $a = $this->account('twitter');
        $a->update(['browser_session' => null]);
        $this->mock(BrowserTaskQueue::class)->shouldReceive('run')->once()->andReturn(['username' => null]);
        $this->assertFalse(app(XSessionService::class)->check($a));
        Http::assertSent(fn ($r) => str_contains($r['text'], $a->name) && str_contains($r['text'], 'https://x.com/'));
    }

    public function test_connexion_retablie_permet_une_alerte_lors_dun_nouvel_episode(): void
    {
        $a = $this->account();
        TelegramNotificationService::notifyExpiredSession($a, 'https://x.com/');
        TelegramNotificationService::notifyExpiredSession($a, 'https://x.com/');
        TelegramNotificationService::clearSessionAlert($a);
        TelegramNotificationService::notifyExpiredSession($a, 'https://x.com/');
        Http::assertSentCount(2);
    }

    public function test_restauration_interrompue_apres_constat_de_deconnexion_est_signalee(): void
    {
        $a = $this->account();
        $queue = $this->mock(BrowserTaskQueue::class);
        $queue->shouldReceive('run')->withArgs(fn ($a, $kind) => $kind === 'x.whoami')->once()->andReturn(['username' => null]);
        $queue->shouldReceive('run')->withArgs(fn ($a, $kind) => $kind === 'session.apply')->once()->andThrow(new BrowserException('Navigateur indisponible'));
        try {
            app(XSessionService::class)->check($a);
            $this->fail('Interruption attendue');
        } catch (BrowserException $e) {
            $this->assertSame('Navigateur indisponible', $e->getMessage());
        }
        Http::assertSentCount(1);
    }
}
