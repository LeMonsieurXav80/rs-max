<?php

namespace Tests\Feature;

use App\Models\BotActionLog;
use App\Models\BrowserTask;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\ThreadsEngagement;
use App\Services\Agent\AccountActionBudget;
use App\Services\Agent\Feed\FeedEngagementExecutor;
use App\Services\Agent\Feed\FeedEngagementPlanner;
use App\Services\Agent\Feed\FrenchText;
use App\Services\Browser\BrowserTaskQueue;
use App\Services\Threads\ThreadsAgent;
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

    private function enable(SocialAccount $account): SocialAccount
    {
        foreach (['feed.read', 'like', 'comment'] as $action) {
            $account->agentActions()->create(['action' => $action, 'enabled' => true, 'daily_limit' => 100]);
        }

        return $account;
    }

    /** Faux NAS : chaque tâche en attente reçoit l'issue demandée, avec la preuve que Companion renvoie après rechargement. */
    private function executor(array $outcomes = []): FeedEngagementExecutor
    {
        return new FeedEngagementExecutor(app(AccountActionBudget::class), function () use (&$outcomes) {
            foreach (BrowserTask::where('kind', 'threads.agent.act')->where('status', 'pending')->orderBy('id')->get() as $task) {
                $outcome = array_shift($outcomes) ?? 'confirmed';
                $p = $task->payload;
                $proof = ['action' => $p['action'], 'username' => $p['username'], 'target_url' => $p['url'], 'proof' => 'after_reload',
                    'confirmed' => $outcome === 'confirmed', 'clicked' => true]
                    + ($p['action'] === 'like' ? ['liked' => true] : ['text' => $p['text'], 'post_url' => 'https://www.threads.com/@wildycaro/post/DfR'.$task->id.'x']);
                $task->update(['status' => $outcome === 'failed' ? 'failed' : 'done', 'claimed_at' => now(), 'finished_at' => now(), 'result' => $proof]);
                app(ThreadsAgent::class)->finish($task);
            }
        });
    }

    public function test_les_likes_et_le_spintax_partent_par_threads_agent_act_et_le_script_attend_la_preuve(): void
    {
        $account = $this->enable($this->account(['enabled' => true, 'spin_percent' => 100]));
        $posts = [$this->threadsPost('DfD0001', 'alice', 'Grosse séance de squat ce matin'), $this->threadsPost('DfD0002', 'bob', 'Leg day squat')];
        $executor = $this->executor();
        $plan = app(FeedEngagementPlanner::class)->plan($account, $posts, $executor->usedToday($account));
        $run = $executor->run($account, $plan, collect($posts)->keyBy('id')->all(), 'snap', 'server');

        $this->assertNull($run['stopped']);
        $this->assertSame([['like', 'confirmed'], ['reply', 'confirmed'], ['like', 'confirmed'], ['reply', 'confirmed']],
            array_map(fn ($d) => [$d['action'], $d['status']], $run['done']));
        $this->assertSame(['likes' => 2, 'spin' => 2, 'llm' => 0], $executor->usedToday($account));
        $comment = ThreadsEngagement::where('action', 'comment')->where('target_key', hash('sha256', 'DfD0001'))->sole();
        $this->assertSame('feed_script', $comment->payload['origin']);
        $this->assertMatchesRegularExpression('/^(Belle|Super) séance (💪|🔥)$/u', $comment->payload['text']);
        $task = $comment->browserTask;
        $this->assertSame(['threads.agent.act', 'comment', 'https://www.threads.com/@alice/post/DfD0001', 'threads-feed:'.$comment->id],
            [$task->kind, $task->payload['action'], $task->payload['target']['url'], $task->idempotency_key]);
        $this->assertMatchesRegularExpression('/^(💪|🔥)(👏|🙌)$/u', ThreadsEngagement::where('action', 'comment')
            ->where('target_key', hash('sha256', 'DfD0002'))->sole()->payload['text'], 'emojis seuls sous une publication anglaise');
        $this->assertSame(2, BotActionLog::where('action_type', 'feed.like')->where('success', true)->count());
        $this->assertSame(2, BotActionLog::where('action_type', 'feed.spin')->where('success', true)->count());

        $again = app(FeedEngagementPlanner::class)->plan($account, $posts, $executor->usedToday($account));
        $this->assertSame(0, $again['summary']['likes'] + $again['summary']['spin'], 'déjà aimées et commentées');
        $this->assertSame(4, BrowserTask::count(), 'aucun second envoi');
    }

    public function test_un_like_threads_incertain_n_est_jamais_renvoye_et_le_commentaire_ne_suit_pas(): void
    {
        $account = $this->enable($this->account(['enabled' => true, 'spin_percent' => 100]));
        $posts = [$this->threadsPost('DfE0001', 'alice', 'Grosse séance de squat ce matin')];
        $executor = $this->executor(['uncertain']);
        $run = $executor->run($account, app(FeedEngagementPlanner::class)->plan($account, $posts), collect($posts)->keyBy('id')->all(), 'snap', 'server');

        $this->assertSame([['like', 'uncertain']], array_map(fn ($d) => [$d['action'], $d['status']], $run['done']));
        $this->assertSame('uncertain', BrowserTask::sole()->status);
        $this->assertFalse(ThreadsEngagement::where('action', 'comment')->exists());
        $again = app(FeedEngagementPlanner::class)->plan($account, $posts);
        $this->assertFalse($again['items'][0]['like']);
        $this->assertContains('déjà aimée', $again['items'][0]['reasons']);
    }

    public function test_une_publication_pour_le_modele_ne_recoit_que_le_like_avant_l_etape_3(): void
    {
        $account = $this->enable($this->account(['enabled' => true, 'spin_percent' => 0]));
        $posts = [$this->threadsPost('DfF0001', 'alice', 'Grosse séance de squat ce matin')];
        $run = $this->executor()->run($account, app(FeedEngagementPlanner::class)->plan($account, $posts), collect($posts)->keyBy('id')->all(), 'snap', 'server');

        $this->assertSame(0, $run['requested']);
        $this->assertSame(['like'], array_column($run['done'], 'action'));
    }

    public function test_le_passage_reel_threads_lit_execute_et_journalise(): void
    {
        $account = $this->enable($this->account(['enabled' => true, 'spin_percent' => 0]));
        $queue = $this->createMock(BrowserTaskQueue::class);
        $queue->method('run')->willReturn(['items' => [$this->threadsPost('DfG0001', 'alice', 'Grosse séance de squat ce matin')]]);
        $this->app->instance(BrowserTaskQueue::class, $queue);
        $this->app->instance(FeedEngagementExecutor::class, $this->executor());

        $this->artisan('threads:feed-engage', ['--all' => true, '--commit' => true])
            ->expectsOutputToContain('1 gestes demandés, 1 confirmés')->assertSuccessful();
        $log = BotActionLog::where('action_type', 'agent.feed.read')->sole();
        $this->assertFalse($log->metadata['dry_run']);
        $this->assertSame(1, $log->metadata['done']);
        $this->assertSame('confirmed', ThreadsEngagement::sole()->status);
    }

    public function test_l_espagnol_et_le_portugais_ne_passent_plus_pour_du_francais(): void
    {
        $this->assertFalse(FrenchText::looksFrench('Ni antes ni después, todo llega cuando tiene que llegar'));
        $this->assertFalse(FrenchText::looksFrench('Dia de futevôlei…bom dia!'));
        $this->assertTrue(FrenchText::looksFrench('Reprise du sport après 7 semaines sans entraînement'));
        $this->assertTrue(FrenchText::looksFrench('Le deadlift et moi 😬'));
    }
}
