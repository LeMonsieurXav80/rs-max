<?php

namespace Tests\Feature;

use App\Models\BotActionLog;
use App\Models\BrowserTask;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Services\Agent\Feed\FeedEngagementPlanner;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Fil « Pour toi » de Threads trié par script. Voir docs/flux-engagement-scripte.md. */
class ThreadsFeedEngagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-14 12:00', 'Europe/Lisbon'));
    }

    private function account(array $feed = []): SocialAccount
    {
        return SocialAccount::create(['name' => 'Wildy Threads', 'platform_id' => Platform::firstOrCreate(['slug' => 'threads'], ['name' => 'Threads'])->id,
            'driver' => 'api', 'browser_profile' => 'nas-wildycaro', 'browser_username' => 'wildycaro', 'browser_session_status' => 'connected',
            'agent_strategy' => ['search_queries' => ['squat', 'fitness'], 'exclusions' => ['onlyfans'],
                'feed_engagement' => $feed + ['spin_templates' => ['{Belle|Super} séance {💪|🔥}'], 'emoji_templates' => ['{💪|🔥}{👏|🙌}']]]]);
    }

    private function threadsPost(string $id, string $author, string $text, array $extra = []): array
    {
        return $extra + ['id' => $id, 'author' => $author, 'url' => "https://www.threads.com/@$author/post/$id", 'text' => $text,
            'published_at' => now()->subHour()->toIso8601String(), 'liked' => false, 'translatable' => true, 'ui_lang' => 'en'];
    }

    public function test_le_plan_threads_ecarte_reponses_et_publicites_et_lit_le_sujet_et_la_langue(): void
    {
        $account = $this->account(['spin_percent' => 0]);
        $plan = app(FeedEngagementPlanner::class)->plan($account, [
            $this->threadsPost('DfA0001', 'alice', 'Grosse séance de squat ce matin'),
            $this->threadsPost('DfA0002', 'bob', 'Leg day', ['topic' => 'Fitness Threads', 'translatable' => false]),
            $this->threadsPost('DfA0003', 'carol', 'Bravo pour le squat', ['reply_hint' => true, 'reply_to' => 'alice']),
            $this->threadsPost('DfA0004', 'brand', 'Squat parfait avec nos leggings', ['promoted' => true]),
            $this->threadsPost('DfA0005', 'dana', 'Une séance de squat avec le club', ['ui_lang' => 'fr']),
            $this->threadsPost('DfA0006', 'wildycaro', 'Ma séance de squat'),
            $this->threadsPost('ab', 'eve', 'squat'),
        ]);
        $by = collect($plan['items'])->keyBy('id');

        $this->assertTrue($by['DfA0001']['like']);
        $this->assertSame('fr', $by['DfA0001']['language'], 'le lien « Translate » sous une interface anglaise ne dit rien du français');
        $this->assertSame('llm', $by['DfA0001']['comment']);
        $this->assertSame(['fitness'], $by['DfA0002']['hits'], 'le sujet choisi par l’auteur compte comme son texte');
        $this->assertSame('other', $by['DfA0002']['language']);
        $this->assertSame(['réponse'], $by['DfA0003']['reasons']);
        $this->assertSame(['publicité'], $by['DfA0004']['reasons']);
        $this->assertSame('other', $by['DfA0005']['language'], 'sous une interface française, « Traduire » signale une autre langue');
        $this->assertSame(['compte lui-même'], $by['DfA0006']['reasons']);
        $this->assertSame(['illisible'], $by['ab']['reasons']);
    }

    public function test_un_geste_threads_deja_demande_bloque_le_like_le_commentaire_et_l_auteur(): void
    {
        $account = $this->account(['spin_percent' => 100]);
        foreach ([['like', 'DfB0001', 'alice', 'done'], ['comment', 'DfB0002', 'bob', 'uncertain'], ['like', 'DfB0003', 'carol', 'failed']] as [$action, $id, $author, $status]) {
            BrowserTask::create(['social_account_id' => $account->id, 'profile' => 'nas-wildycaro', 'kind' => 'threads.agent.act', 'status' => $status,
                'payload' => ['action' => $action, 'target' => ['id' => $id, 'author' => $author, 'url' => "https://www.threads.com/@$author/post/$id"]]]);
        }
        $plan = app(FeedEngagementPlanner::class)->plan($account, [
            $this->threadsPost('DfB0001', 'alice', 'Squat du jour, belle séance'),
            $this->threadsPost('DfB0004', 'bob', 'Encore une séance de squat'),
            $this->threadsPost('DfB0003', 'carol', 'Squat léger pour la reprise'),
        ]);
        $by = collect($plan['items'])->keyBy('id');

        $this->assertFalse($by['DfB0001']['like']);
        $this->assertContains('déjà aimée', $by['DfB0001']['reasons']);
        $this->assertNull($by['DfB0004']['comment']);
        $this->assertContains('auteur commenté depuis moins de 7 jours', $by['DfB0004']['reasons']);
        $this->assertTrue($by['DfB0003']['like'], 'un échec franc laisse réessayer');
        $this->assertSame('spin', $by['DfB0003']['comment']);
        $this->assertMatchesRegularExpression('/^(Belle|Super) séance (💪|🔥)$/u', $by['DfB0003']['comment_text']);
    }

    public function test_le_releve_a_blanc_lit_pour_toi_sans_rien_envoyer_et_le_commit_reste_refuse(): void
    {
        $account = $this->account();
        $account->agentActions()->create(['action' => 'feed.read', 'enabled' => true, 'daily_limit' => 100]);
        $queue = $this->createMock(BrowserTaskQueue::class);
        $queue->expects($this->once())->method('run')->with($this->anything(), 'threads.agent.read',
            $this->callback(fn ($p) => $p['url'] === 'https://www.threads.com/' && $p['feed'] === 'foryou' && $p['limit'] === 25 && $p['username'] === 'wildycaro'))
            ->willReturn(['items' => [$this->threadsPost('DfC0001', 'alice', 'Grosse séance de squat')]]);
        $this->app->instance(BrowserTaskQueue::class, $queue);

        $this->artisan('threads:feed-engage', ['account' => $account->id])
            ->expectsOutputToContain('Relevé à blanc, rien n\'a été envoyé : 1 lues, 1 retenues, 1 likes')->assertSuccessful();
        $log = BotActionLog::where('action_type', 'agent.feed.read')->sole();
        $this->assertTrue($log->metadata['dry_run']);
        $this->assertSame('https://www.threads.com/', $log->target_uri);
        $this->assertSame(0, BrowserTask::count());

        $this->artisan('threads:feed-engage', ['account' => $account->id, '--commit' => true])
            ->expectsOutputToContain('Engagement du fil coupé')->assertFailed();
    }
}
