<?php

namespace Tests\Feature;

use App\Models\AccountActionEvent;
use App\Models\BotActionLog;
use App\Models\BrowserTask;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AutomationJournalTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $platform = 'twitter'): SocialAccount
    {
        $p = Platform::firstOrCreate(['slug' => $platform], ['name' => $platform]);

        return SocialAccount::create(['platform_id' => $p->id, 'name' => 'Compte '.$platform, 'browser_profile' => 'test', 'driver' => 'browser']);
    }

    public function test_un_journal_par_tache_du_depart_a_la_preuve_et_trace_dedoublonnee(): void
    {
        $a = $this->account();
        $t = BrowserTask::create(['social_account_id' => $a->id, 'profile' => 'test', 'kind' => 'x.like', 'payload' => ['url' => 'https://x.com/a/status/1', 'cookies' => [['value' => 'NEVER-COOKIE']]]]);
        $this->assertSame('pending', BotActionLog::first()->metadata['status']);
        $t->update(['status' => 'running', 'claimed_at' => now()]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $step = ['id' => 'one', 'stage' => 'x.before_click', 'elapsed_ms' => 12, 'details' => ['action' => 'like', 'token' => 'NEVER-TOKEN', 'url' => 'https://x.com/a/status/1?token=NEVER-URL']];
        $this->postJson('/api/extension/tasks/'.$t->id.'/progress', ['steps' => [$step]])->assertOk();
        $this->postJson('/api/extension/tasks/'.$t->id.'/finish', ['status' => 'done', 'result' => ['confirmed' => true, 'proof' => 'after_reload'], 'diagnostics' => [$step]])->assertOk();
        $log = BotActionLog::first();
        $this->assertSame(1, BotActionLog::count());
        $this->assertSame('confirmed', $log->metadata['status']);
        $this->assertCount(1, $log->metadata['steps']);
        $this->assertStringNotContainsString('NEVER', json_encode($log->toArray()));
        $this->assertArrayNotHasKey('diagnostics', $t->fresh()->result);
        $this->postJson('/api/extension/tasks/'.$t->id.'/progress', ['steps' => [$step]])->assertConflict();
    }

    public function test_recu_tiktok_ne_devient_pas_preuve_et_reste_expurge(): void
    {
        $a = $this->account('tiktok');
        $t = BrowserTask::create(['social_account_id' => $a->id, 'profile' => 'test', 'kind' => 'tiktok.reply', 'payload' => [], 'status' => 'uncertain', 'result' => ['initial_visible' => true, 'confirmed' => false, 'submission' => ['http_status' => 200, 'status_code' => 0, 'comment_status' => 2, 'comment_id' => '7705842946607711009', 'text_matched' => true, 'cookie' => 'NEVER']]]);
        $metadata = BotActionLog::where('browser_task_id', $t->id)->first()->metadata;
        $this->assertSame('uncertain', $metadata['status']);
        $this->assertFalse($metadata['proof']['confirmed']);
        $this->assertSame(2, $metadata['proof']['submission']['comment_status']);
        $this->assertSame('7705842946607711009', $metadata['proof']['submission']['comment_id']);
        $this->assertStringNotContainsString('NEVER', json_encode($metadata));
    }

    public function test_l_ordre_final_corrige_la_progression_recue_en_desordre(): void
    {
        $a = $this->account();
        $task = BrowserTask::create(['social_account_id' => $a->id, 'profile' => 'test', 'kind' => 'x.like', 'payload' => [], 'status' => 'running']);
        $journal = app(\App\Services\Agent\AutomationJournal::class);
        $one = ['id' => 'one', 'stage' => 'task.received'];
        $two = ['id' => 'two', 'stage' => 'identity.check'];
        $journal->append($task, [$two]);
        $journal->append($task, [$one]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/extension/tasks/'.$task->id.'/finish', ['status' => 'done', 'diagnostics' => [$one, $two]])->assertOk();
        $this->assertSame(['task.received', 'identity.check'], array_column(BotActionLog::where('browser_task_id', $task->id)->first()->metadata['steps'], 'stage'));
    }

    public function test_lecture_de_logs_scopee_filtres_curseur_et_absence_de_secrets(): void
    {
        $a = $this->account();
        $other = $this->account();
        $u = User::factory()->create(['role' => 'manager']);
        $a->users()->attach($u, ['is_active' => true]);
        foreach ([$a, $other] as $account) {
            for ($i = 0; $i < 3; $i++) {
                BotActionLog::create(['social_account_id' => $account->id, 'action_type' => 'x.like', 'source' => 'extension', 'target_uri' => 'javascript:alert(1)', 'success' => false, 'error' => 'Bearer NEVER-SECRET', 'metadata' => ['token' => 'NEVER-META', 'status' => 'uncertain']]);
            }
        }
        Sanctum::actingAs($u, ['community-manager']);
        $url = '/api/community-manager/logs?platform=twitter&status=uncertain&limit=2';
        $response = $this->getJson($url)->assertOk()->assertJsonCount(2, 'logs')->assertJsonPath('has_more', true)->assertJsonPath('logs.0.account_id', $a->id)->assertJsonPath('logs.0.target_url', '')->assertDontSee('NEVER');
        $this->getJson($url.'&before_id='.$response->json('next_cursor'))->assertOk()->assertJsonCount(1, 'logs')->assertJsonPath('has_more', false);
        $this->getJson('/api/community-manager/logs?account_id='.$other->id)->assertNotFound();
        $this->getJson('/api/community-manager/logs?limit=10000')->assertUnprocessable();
    }

    public function test_purge_apres_sept_jours_sans_perdre_la_memoire_anti_doublon(): void
    {
        $this->freezeTime();
        $a = $this->account();
        $t = BrowserTask::create(['social_account_id' => $a->id, 'profile' => 'test', 'kind' => 'x.post', 'idempotency_key' => 'never-resend', 'payload' => ['text' => 'Bonjour', 'url' => 'https://x.com/compose/post'], 'status' => 'uncertain']);
        $e = AccountActionEvent::create(['social_account_id' => $a->id, 'browser_task_id' => $t->id, 'action' => 'x.reply', 'executor' => 'browser', 'status' => 'uncertain']);
        $this->travel(8)->days();
        $this->artisan('agent:purge-logs')->assertSuccessful();
        $this->assertSame(0, BotActionLog::count());
        $this->assertDatabaseHas('browser_tasks', ['id' => $t->id, 'idempotency_key' => 'never-resend', 'status' => 'uncertain']);
        $this->assertDatabaseHas('account_action_events', ['id' => $e->id]);
        app(\App\Services\Agent\AutomationJournal::class)->task($t);
        $this->assertSame(0, BotActionLog::count());
    }

    public function test_journal_affiche_dans_le_bon_reseau_et_page_filtrable(): void
    {
        $a = $this->account();
        $b = $this->account('facebook');
        $u = User::factory()->create(['role' => 'manager']);
        foreach ([$a, $b] as $account) {
            BotActionLog::create(['social_account_id' => $account->id, 'action_type' => 'probe.'.$account->platform->slug, 'source' => 'extension', 'target_uri' => '', 'success' => true]);
        }
        $this->actingAs($u)->get('/agent/twitter')->assertOk()->assertSee('Journal récent')->assertSee('probe.twitter')->assertDontSee('probe.facebook');
        $this->actingAs($u)->get('/agent/twitter/logs')->assertOk()->assertSee('Suppression automatique après sept jours')->assertSee('probe.twitter')->assertDontSee('probe.facebook');
        $this->actingAs($u)->get('/agent/facebook/logs')->assertOk()->assertSee('probe.facebook')->assertDontSee('probe.twitter');
    }

    public function test_evenement_api_et_annulation_sont_journalises_sans_renvoi(): void
    {
        $a = $this->account();
        $event = AccountActionEvent::create(['social_account_id' => $a->id, 'action' => 'inbox.reply', 'executor' => 'api', 'target' => 'https://example.com/thread']);
        $this->assertSame('running', BotActionLog::first()->metadata['status']);
        $event->update(['status' => 'failed', 'error' => 'Erreur API']);
        $this->assertSame(1, BotActionLog::count());
        $this->assertSame('failed', BotActionLog::first()->metadata['status']);
        $t = BrowserTask::create(['social_account_id' => $a->id, 'profile' => 'test', 'kind' => 'x.post', 'payload' => []]);
        $t->update(['status' => 'cancelled', 'error' => 'Cadence interne']);
        $this->assertSame('cancelled', BotActionLog::where('browser_task_id', $t->id)->first()->metadata['status']);
    }
}
