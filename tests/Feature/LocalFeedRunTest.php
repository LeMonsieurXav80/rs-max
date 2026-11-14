<?php

namespace Tests\Feature;

use App\Models\BotActionLog;
use App\Models\BrowserTask;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\ThreadsEngagement;
use App\Models\User;
use App\Models\XEngagement;
use App\Services\Agent\Feed\LocalFeedRun;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** « Engager mon fil » lancé depuis Companion sur le Mac. Voir docs/flux-engagement-scripte.md. */
class LocalFeedRunTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-14 12:00', 'Europe/Lisbon'));
        $this->admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($this->admin);
    }

    private function account(string $slug, array $feed = []): SocialAccount
    {
        $account = SocialAccount::create(['name' => 'Wildy', 'platform_id' => Platform::firstOrCreate(['slug' => $slug], ['name' => $slug])->id,
            'platform_account_id' => '4242', 'driver' => $slug === 'twitter' ? 'browser' : 'api', 'browser_profile' => 'nas-wildycaro',
            'browser_username' => 'wildycaro', 'browser_session_status' => 'connected',
            'agent_strategy' => ['search_queries' => ['squat'], 'feed_engagement' => $feed + ['enabled' => true, 'spin_percent' => 100,
                'spin_templates' => ['{Belle|Super} séance {💪|🔥}'], 'emoji_templates' => ['{💪|🔥}{👏|🙌}']]]]);
        $account->users()->attach($this->admin, ['is_active' => true]);
        foreach (['feed.read', 'like', 'comment', 'reply'] as $action) {
            $account->agentActions()->create(['action' => $action, 'enabled' => true, 'daily_limit' => 100]);
        }

        return $account;
    }

    private function threadsPost(string $id, string $text): array
    {
        return ['id' => $id, 'author' => 'alice', 'url' => "https://www.threads.com/@alice/post/$id", 'text' => $text,
            'published_at' => now()->subHour()->toIso8601String(), 'liked' => false, 'translatable' => false, 'ui_lang' => 'en'];
    }

    /** Ce que Companion renvoie au `finish` après la preuve sur la page rechargée. */
    private function finish(array $task): void
    {
        $p = $task['payload'];
        $proof = ['action' => $p['action'], 'username' => $p['username'], 'target_url' => $p['url'], 'proof' => 'after_reload', 'confirmed' => true]
            + ($p['action'] === 'like' ? ['liked' => true] : ['text' => $p['text'], 'post_url' => 'https://www.threads.com/@wildycaro/post/DfL'.$task['id'].'z']);
        $this->postJson('/api/extension/tasks/'.$task['id'].'/finish', ['status' => 'done', 'result' => $proof])->assertOk();
    }

    public function test_le_releve_a_blanc_local_decide_sans_rien_creer_et_le_commit_exige_l_interrupteur(): void
    {
        $this->account('threads', ['enabled' => false]);
        $body = ['platform' => 'threads', 'username' => '@WildyCaro', 'items' => [$this->threadsPost('DfK0001', 'Grosse séance de squat ce matin')]];

        $this->postJson('/api/extension/feed/runs', $body)->assertOk()->assertJsonPath('dry_run', true)
            ->assertJsonPath('plan.summary.likes', 1)->assertJsonPath('plan.items.0.comment', 'spin');
        $this->postJson('/api/extension/feed/runs', $body + ['commit' => true])->assertForbidden();
        $log = BotActionLog::where('action_type', 'agent.feed.read')->sole();
        $this->assertSame(['local', true], [$log->metadata['trigger'], $log->metadata['dry_run']]);
        $this->assertSame(0, BrowserTask::count());
    }

    public function test_le_passage_local_threads_cree_ses_gestes_un_par_un_pour_le_seul_mac(): void
    {
        $account = $this->account('threads');
        $start = $this->postJson('/api/extension/feed/runs', ['platform' => 'threads', 'username' => 'wildycaro', 'commit' => true,
            'items' => [$this->threadsPost('DfK0002', 'Grosse séance de squat ce matin')]])->assertOk()->json();
        $next = fn () => $this->postJson('/api/extension/feed/runs/'.$start['run'].'/next', ['platform' => 'threads', 'username' => 'wildycaro'])->assertOk()->json();

        $like = $next();
        $this->assertSame(['task', 'like'], [$like['state'], $like['action']]);
        $this->assertSame(LocalFeedRun::PROFILE, BrowserTask::find($like['task_id'])->profile);
        $this->assertNull(app(BrowserTaskQueue::class)->claim('nas-wildycaro'), 'le NAS ne prend jamais un geste du Mac');
        $this->assertSame('pending', $next()['state'], 'tant que le Mac n’a pas clos la tâche, rien de nouveau');

        $task = $this->postJson('/api/extension/feed/tasks/'.$like['task_id'].'/claim')->assertOk()->json();
        $this->postJson('/api/extension/feed/tasks/'.$like['task_id'].'/claim')->assertStatus(409);
        $this->finish($task);
        $spin = $next();
        $this->assertSame(['task', 'reply'], [$spin['state'], $spin['action']]);
        $this->assertMatchesRegularExpression('/^(Belle|Super) séance (💪|🔥)$/u', $spin['text']);
        $this->finish($this->postJson('/api/extension/feed/tasks/'.$spin['task_id'].'/claim')->assertOk()->json());
        $done = $next();

        $this->assertSame('done', $done['state']);
        $this->assertSame(['confirmed', 'confirmed'], array_column($done['done'], 'status'));
        $this->assertSame(['local', 'local'], ThreadsEngagement::orderBy('id')->get()->pluck('payload.trigger')->all());
        $this->assertSame(2, BotActionLog::whereIn('action_type', ['feed.like', 'feed.spin'])->where('metadata->trigger', 'local')->where('success', true)->count());
        $this->assertSame(2, BotActionLog::where('action_type', 'agent.feed.read')->sole()->metadata['done']);
        $this->assertSame(['likes' => 1, 'spin' => 1, 'llm' => 0], app(\App\Services\Agent\Feed\FeedEngagementExecutor::class)->usedToday($account),
            'mêmes plafonds que le passage du NAS');
        $this->postJson('/api/extension/feed/runs/'.$start['run'].'/next', ['platform' => 'threads', 'username' => 'wildycaro'])->assertStatus(410);
    }

    public function test_le_passage_local_x_passe_par_x_agent_act_et_les_recus_du_script(): void
    {
        $this->account('twitter', ['spin_percent' => 0]);
        $post = ['id' => '1901', 'author' => 'alice', 'url' => 'https://x.com/alice/status/1901', 'text' => 'Grosse séance de squat ce matin',
            'published_at' => now()->subHour()->toIso8601String(), 'liked' => false, 'lang' => 'fr'];
        $start = $this->postJson('/api/extension/feed/runs', ['platform' => 'x', 'username' => 'wildycaro', 'commit' => true, 'items' => [$post]])->json();
        $like = $this->postJson('/api/extension/feed/runs/'.$start['run'].'/next', ['platform' => 'x', 'username' => 'wildycaro'])->assertOk()->json();
        $task = $this->postJson('/api/extension/feed/tasks/'.$like['task_id'].'/claim')->assertOk()->assertJsonPath('account_id', '4242')->json();
        $this->assertSame('x.agent.act', $task['kind']);
        $this->postJson('/api/extension/tasks/'.$task['id'].'/finish', ['status' => 'done', 'result' => ['confirmed' => true, 'proof' => 'after_reload',
            'target' => ['id' => '1901', 'author' => 'alice', 'text' => 'Grosse séance de squat ce matin']]])->assertOk();
        $done = $this->postJson('/api/extension/feed/runs/'.$start['run'].'/next', ['platform' => 'x', 'username' => 'wildycaro'])->json();

        $this->assertSame('done', $done['state']);
        $this->assertSame(1, $done['requested'], 'la rédaction rejoint la file d’Hermès');
        $receipt = XEngagement::where('action', 'like')->sole();
        $this->assertSame(['confirmed', 'feed_script', 'local'], [$receipt->status, $receipt->payload['origin'], $receipt->payload['trigger']]);
        $this->assertSame('awaiting', XEngagement::where('action', 'reply')->sole()->payload['draft_state']);
    }

    public function test_stop_enregistre_le_geste_en_cours_et_n_en_cree_pas_d_autre(): void
    {
        $this->account('threads');
        $who = ['platform' => 'threads', 'username' => 'wildycaro'];
        $start = $this->postJson('/api/extension/feed/runs', $who + ['commit' => true, 'items' => [$this->threadsPost('DfM0001', 'Grosse séance de squat ce matin')]])->json();
        $like = $this->postJson('/api/extension/feed/runs/'.$start['run'].'/next', $who)->json();
        $this->finish($this->postJson('/api/extension/feed/tasks/'.$like['task_id'].'/claim')->json());

        $done = $this->postJson('/api/extension/feed/runs/'.$start['run'].'/next', $who + ['stop' => true])->assertOk()->json();
        $this->assertSame(['done', 'arrêt demandé depuis Companion'], [$done['state'], $done['stopped']]);
        $this->assertSame('confirmed', ThreadsEngagement::sole()->status);
        $this->assertSame(1, BrowserTask::count(), 'aucun commentaire créé après Stop');
    }
}
