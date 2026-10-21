<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\Thread;
use App\Models\ThreadSegment;
use App\Models\ThreadSegmentPlatform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Un fil qui s'interrompt au milieu ne levait aucune exception : le statut
 * `partial` partait en base et personne n'etait prevenu. C'est comme ca que
 * 14 fils sur 16 ont pu perdre leurs derniers segments sur Threads pendant des
 * semaines sans un signal (constate le 06/10/2026).
 *
 * Le fil de test sort sur Bluesky, pas sur Threads : l'alerte est la meme et
 * BlueskyAdapter echoue en un appel, la ou l'adapter Threads imposerait ses
 * vraies pauses de reconstruction (plusieurs dizaines de secondes).
 *
 * @see \App\Console\Commands\PublishScheduledThreadsCommand::notifyIncomplete()
 */
class ThreadIncompleteAlertTest extends TestCase
{
    use RefreshDatabase;

    private function scheduledThread(): array
    {
        $platform = Platform::firstOrCreate(
            ['slug' => 'bluesky'],
            ['name' => 'Bluesky', 'auth_type' => 'oauth2'],
        );

        $account = SocialAccount::create([
            'platform_id' => $platform->id,
            'platform_account_id' => 'acc-bluesky',
            'name' => 'Compte Bluesky',
            'credentials' => ['identifier' => 'moi.bsky.social', 'password' => 'secret'],
        ]);

        $thread = Thread::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Fil de test',
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(),
        ]);

        $segments = collect([1, 2])->map(fn (int $position) => ThreadSegment::create([
            'thread_id' => $thread->id,
            'position' => $position,
            'content_fr' => "segment {$position}",
        ]));

        $thread->socialAccounts()->attach($account->id, [
            'platform_id' => $platform->id,
            'publish_mode' => 'thread',
            'status' => 'pending',
        ]);

        // Le 1er segment est deja en ligne : la reprise le saute, ce qui evite
        // aussi la pause inter-segments. Seul le 2e sera tente — et echouera.
        ThreadSegmentPlatform::create([
            'thread_segment_id' => $segments[0]->id,
            'social_account_id' => $account->id,
            'platform_id' => $platform->id,
            'status' => 'published',
            'external_id' => 'DEJA_EN_LIGNE',
            'published_at' => now(),
        ]);

        ThreadSegmentPlatform::create([
            'thread_segment_id' => $segments[1]->id,
            'social_account_id' => $account->id,
            'platform_id' => $platform->id,
            'status' => 'pending',
        ]);

        return [$thread, $account];
    }

    public function test_un_fil_interrompu_declenche_une_alerte_telegram(): void
    {
        Setting::set('notify_publish_error', true);
        Setting::set('notify_telegram_chat_id', '424242');
        Setting::setEncrypted('notify_telegram_bot_token', 'BOT:TOKEN');

        [$thread] = $this->scheduledThread();

        $alerts = [];

        Http::fake(function (Request $request) use (&$alerts) {
            if (str_contains($request->url(), 'api.telegram.org')) {
                $alerts[] = $request->data();

                return Http::response(['ok' => true]);
            }

            // Bluesky refuse la session : le segment 2 echoue en un appel.
            return Http::response(['error' => 'AuthenticationRequired'], 401);
        });

        $this->artisan('threads:publish-scheduled')->assertSuccessful();

        $thread->refresh();
        $this->assertSame('partial', $thread->status, 'Le fil doit bien finir en partial');

        $this->assertCount(1, $alerts, 'Une alerte, et une seule, doit partir pour le compte fautif');

        $text = $alerts[0]['text'];
        $this->assertStringContainsString('Fil incomplet', $text);
        $this->assertStringContainsString('bluesky', $text);
        $this->assertStringContainsString('Compte Bluesky', $text);
        // Le message doit dire OU ca s'est arrete, sinon on ne sait pas s'il
        // faut reprendre le fil ou le refaire en entier.
        $this->assertStringContainsString('segment 2', $text);
        $this->assertStringContainsString('1/2 publies', $text);
        $this->assertStringContainsString("/threads/{$thread->id}", $text);
    }

    public function test_aucune_alerte_quand_les_notifications_sont_coupees(): void
    {
        Setting::set('notify_publish_error', false);
        Setting::set('notify_telegram_chat_id', '424242');
        Setting::setEncrypted('notify_telegram_bot_token', 'BOT:TOKEN');

        $this->scheduledThread();

        $alerts = [];

        Http::fake(function (Request $request) use (&$alerts) {
            if (str_contains($request->url(), 'api.telegram.org')) {
                $alerts[] = $request->data();

                return Http::response(['ok' => true]);
            }

            return Http::response(['error' => 'AuthenticationRequired'], 401);
        });

        $this->artisan('threads:publish-scheduled')->assertSuccessful();

        $this->assertSame([], $alerts, 'Le reglage notify_publish_error doit continuer a faire foi');
    }
}
