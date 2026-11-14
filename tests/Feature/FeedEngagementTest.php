<?php

namespace Tests\Feature;

use App\Models\BotActionLog;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\XEngagement;
use App\Services\Agent\AccountActionBudget;
use App\Services\Agent\Feed\FeedEngagementExecutor;
use App\Services\Agent\Feed\FeedEngagementPlanner;
use App\Services\Agent\Feed\FeedMatcher;
use App\Services\Agent\Feed\Spintax;
use App\Services\Agent\XEngagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedEngagementTest extends TestCase
{
    use RefreshDatabase;

    private function account(array $feed = [], array $strategy = []): SocialAccount
    {
        return SocialAccount::create(['name' => 'Wildy', 'platform_id' => Platform::firstOrCreate(['slug' => 'twitter'], ['name' => 'X'])->id,
            'driver' => 'browser', 'browser_profile' => 'test', 'browser_username' => 'wildy',
            'agent_strategy' => $strategy + ['search_queries' => ['squat', 'wall ball', 'pull-up', 'créatine'], 'hashtags' => ['#legday'],
                'exclusions' => ['onlyfans', 'deuil', '@spammeur'], 'target_accounts' => ['@coach'],
                'feed_engagement' => $feed + ['spin_templates' => ['{Belle|Super} séance {💪|🔥}'], 'emoji_templates' => ['{💪|🔥}{👏|🙌}']]]]);
    }

    private function feedPost(string $id, string $author, string $text, array $extra = []): array
    {
        return $extra + ['id' => $id, 'author' => $author, 'url' => "https://x.com/$author/status/$id", 'text' => $text,
            'published_at' => now()->subHour()->toIso8601String(), 'liked' => false, 'lang' => 'fr'];
    }

    public function test_spintax_tire_une_variante_compte_les_combinaisons_et_refuse_un_modele_casse(): void
    {
        $text = Spintax::expand('{Belle|Super} {séance|perf} {💪|🔥}');
        $this->assertMatchesRegularExpression('/^(Belle|Super) (séance|perf) (💪|🔥)$/u', $text);
        $this->assertSame(8, Spintax::combinations('{Belle|Super} {séance|perf} {💪|🔥}'));
        $this->assertSame('Top 💪', Spintax::expand('Top {|} 💪'));
        $this->assertNotNull(Spintax::error('{Belle|Super séance'));
        $this->assertNotNull(Spintax::error('{Belle|{Super|Top}}'));
        $this->assertNotNull(Spintax::error('Belle}'));
        $this->assertNull(Spintax::error('{Bien joué|Joli} {💪|👏}{|🔥}'));
    }

    public function test_la_correspondance_ignore_casse_accents_pluriel_et_tirets(): void
    {
        $m = new FeedMatcher(['search_queries' => ['squat', 'wall ball', 'pull-up', 'créatine'], 'hashtags' => ['#legday'],
            'exclusions' => ['onlyfans', '@spammeur'], 'target_accounts' => ['@Coach']]);
        $this->assertSame(['squat'], $m->keywordHits('3 SQUATS ce matin'));
        $this->assertSame(['wall ball'], $m->keywordHits('100 wall-balls'));
        $this->assertSame(['pull-up'], $m->keywordHits('Mes premiers pullups'));
        $this->assertSame(['créatine'], $m->keywordHits('la CREATINE ça marche ?'));
        $this->assertSame(['#legday'], $m->keywordHits('#LegDay'));
        $this->assertSame([], $m->keywordHits('squatter le canapé'));
        $plural = new FeedMatcher(['search_queries' => ['haltères', 'wall balls', 'dos', 'abs', 'lunges']]);
        $this->assertSame(['haltères'], $plural->keywordHits("L'haltère de 20 kg"));
        $this->assertSame(['haltères'], $plural->keywordHits('Deux haltères'));
        $this->assertSame(['wall balls'], $plural->keywordHits('100 wall ball'));
        $this->assertSame(['lunges'], $plural->keywordHits('walking lunge'));
        $this->assertSame(['dos'], $plural->keywordHits('Mon dos'));
        $this->assertSame([], $plural->keywordHits('I do it, ab'));
        $this->assertSame('onlyfans', $m->excludedBy('lien OnlyFans en bio', 'x'));
        $this->assertSame('@spammeur', $m->excludedBy('Belle séance', '@SPAMMEUR'));
        $this->assertTrue($m->isTarget('coach'));
        $symbols = new FeedMatcher(['exclusions' => ['💦', 'xxx', 'sexy']]);
        $this->assertSame('💦', $symbols->excludedBy('Quelle puissance 💦', 'alice'));
        $this->assertSame('xxx', $symbols->excludedBy('Belle séance', 'mrfunkyxxx'));
        $this->assertSame('sexy', $symbols->excludedBy('Séance', 'SexyGymGirl'));
        $this->assertNull($symbols->excludedBy('Séance', 'maxence'));
    }

    public function test_le_plan_aime_commente_et_explique_chaque_publication_ecartee(): void
    {
        $account = $this->account(['spin_percent' => 100]);
        $plan = app(FeedEngagementPlanner::class)->plan($account, [
            $this->feedPost('101', 'alice', 'Grosse séance de squat'),
            $this->feedPost('102', 'wildy', 'Mon squat du jour'),
            $this->feedPost('103', 'bob', 'Squat et lien onlyfans'),
            $this->feedPost('104', 'carla', 'Café du matin'),
            $this->feedPost('105', 'dan', 'Squat', ['promoted' => true]),
            $this->feedPost('106', 'eve', 'Squat', ['reply_hint' => true]),
            $this->feedPost('107', 'fred', 'Squat', ['published_at' => now()->subDays(20)->toIso8601String()]),
            $this->feedPost('108', 'gina', 'Leg day squat', ['lang' => 'en']),
            $this->feedPost('109', 'coach', 'Échauffement du jour'),
        ]);
        $by = collect($plan['items'])->keyBy('id');
        $this->assertTrue($by['101']['like']);
        $this->assertSame('spin', $by['101']['comment']);
        $this->assertMatchesRegularExpression('/^(Belle|Super) séance (💪|🔥)$/u', $by['101']['comment_text']);
        $this->assertSame(['compte lui-même'], $by['102']['reasons']);
        $this->assertSame(['exclu : onlyfans'], $by['103']['reasons']);
        $this->assertSame(['aucun mot-clé'], $by['104']['reasons']);
        $this->assertSame(['publicité'], $by['105']['reasons']);
        $this->assertSame(['réponse'], $by['106']['reasons']);
        $this->assertSame(['plus de 14 jours'], $by['107']['reasons']);
        $this->assertMatchesRegularExpression('/^(💪|🔥)(👏|🙌)$/u', $by['108']['comment_text'], 'autre langue : emojis seuls');
        $this->assertTrue($by['109']['like'], 'compte à suivre retenu sans mot-clé');
        $this->assertSame(['read' => 9, 'retained' => 3, 'likes' => 3, 'spin' => 3, 'llm' => 0], $plan['summary']);
    }

    public function test_le_modele_redige_en_francais_jamais_deux_fois_le_meme_auteur_et_les_plafonds_tiennent(): void
    {
        $account = $this->account(['spin_percent' => 0, 'daily_likes' => 2, 'daily_llm_comments' => 1]);
        XEngagement::create(['social_account_id' => $account->id, 'action' => 'reply', 'target_key' => hash('sha256', '9'),
            'status' => 'confirmed', 'requested_at' => now()->subDays(2), 'payload' => ['target' => ['id' => '9', 'author' => 'bob']]]);
        $plan = app(FeedEngagementPlanner::class)->plan($account, [
            $this->feedPost('201', 'alice', 'Squat lourd ce matin'),
            $this->feedPost('202', 'bob', 'Squat encore'),
            $this->feedPost('203', 'carla', 'Squat avant le boulot'),
            $this->feedPost('204', 'dan', 'Squat in the morning', ['lang' => 'en']),
        ]);
        $by = collect($plan['items'])->keyBy('id');
        $this->assertSame('llm', $by['201']['comment']);
        $this->assertNull($by['202']['comment']);
        $this->assertContains('auteur commenté depuis moins de 7 jours', $by['202']['reasons']);
        $this->assertContains('plafond de commentaires rédigés atteint', $by['203']['reasons']);
        $this->assertContains('plafond de likes atteint', $by['203']['reasons']);
        $this->assertContains('autre langue ou sans texte', $by['204']['reasons']);
    }

    public function test_api_regle_l_engagement_mais_le_jeton_community_manager_ne_peut_ni_l_allumer_ni_relever_un_plafond(): void
    {
        $account = $this->account();
        $manager = User::factory()->create(['role' => 'manager']);
        $account->users()->attach($manager, ['is_active' => true]);
        $full = $manager->createToken('gestion')->plainTextToken;
        $agent = $manager->createToken('CM', ['community-manager'])->plainTextToken;
        $path = '/api/accounts/'.$account->id.'/strategy';

        $this->withToken($full)->patchJson($path, ['agent_strategy' => ['feed_engagement' => ['enabled' => true, 'daily_likes' => 200]]])
            ->assertOk()->assertJsonPath('feed_engagement.enabled', true)->assertJsonPath('feed_engagement.daily_likes', 200)
            ->assertJsonPath('feed_engagement.spin_templates_preview.0.combinations', 4)
            ->assertJsonPath('strategy.search_queries.0', 'squat');
        $this->withToken($full)->patchJson($path, ['agent_strategy' => ['feed_engagement' => ['spin_templates' => ['{Belle|Super séance']]]])
            ->assertUnprocessable();
        $this->withToken($full)->patchJson($path, ['agent_strategy' => ['feed_engagement' => ['secret' => 1]]])->assertUnprocessable();

        $cm = '/api/community-manager/accounts/'.$account->id.'/strategy';
        app('auth')->forgetGuards();
        // Le formulaire envoie un modèle par ligne ; un tableau porte un modèle par entrée.
        $this->withToken($agent)->patchJson($cm, ['agent_strategy' => ['feed_engagement' => ['spin_templates' => "{Top|Joli} {travail|boulot}\n{Bravo|Respect}"]]])
            ->assertOk()->assertJsonPath('feed_engagement.spin_templates.1', '{Bravo|Respect}');
        app('auth')->forgetGuards();
        $this->withToken($agent)->patchJson($cm, ['agent_strategy' => ['feed_engagement' => ['spin_templates' => ["{Top|Joli}\n{Bravo|Respect}"]]]])
            ->assertUnprocessable();
        foreach ([['enabled' => false], ['daily_likes' => 300], ['like_scope' => 'all'], ['spin_percent' => 90]] as $raise) {
            app('auth')->forgetGuards();
            $this->withToken($agent)->patchJson($cm, ['agent_strategy' => ['feed_engagement' => $raise]])->assertForbidden();
        }
        app('auth')->forgetGuards();
        $this->withToken($agent)->patchJson($cm, ['agent_strategy' => ['feed_engagement' => ['daily_likes' => 100]]])->assertOk();
        $this->assertSame(100, $account->fresh()->agent_strategy['feed_engagement']['daily_likes']);
        $this->assertTrue($account->fresh()->agent_strategy['feed_engagement']['enabled']);
    }

    /** Faux navigateur : chaque geste aboutit avec le statut demandé, sans tâche Companion. */
    private function executor(array $outcomes = []): FeedEngagementExecutor
    {
        $fake = new class($outcomes) extends XEngagementService
        {
            public function __construct(public array $outcomes) {}

            public int $busy = 0;

            public function execute(XEngagement $row): XEngagement
            {
                abort_if($this->busy-- > 0, 429, 'Délai minimal non écoulé. Prochaine action : maintenant');
                $row->update(['status' => 'queued', 'requested_at' => now()]);

                return $row;
            }

            public function result(XEngagement $row): XEngagement
            {
                $row->update(['status' => array_shift($this->outcomes) ?? 'confirmed']);

                return $row->fresh();
            }
        };

        return new FeedEngagementExecutor($fake, app(AccountActionBudget::class), new \App\Services\Agent\Feed\FeedDraftService($fake), fn () => null);
    }

    public function test_l_execution_aime_commente_journalise_et_ne_refait_rien_au_passage_suivant(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-14 12:00', 'Europe/Lisbon'));
        $account = $this->account(['enabled' => true, 'spin_percent' => 100]);
        $posts = [$this->feedPost('301', 'alice', 'Grosse séance de squat'), $this->feedPost('302', 'bob', 'Leg day squat', ['lang' => 'en'])];
        $executor = $this->executor();
        $plan = app(FeedEngagementPlanner::class)->plan($account, $posts, $executor->usedToday($account));
        $run = $executor->run($account, $plan, collect($posts)->keyBy('id')->all(), 'snap', 'server');

        $this->assertNull($run['stopped']);
        $this->assertSame(['like', 'reply', 'like', 'reply'], array_column($run['done'], 'action'));
        $this->assertSame(['likes' => 2, 'spin' => 2, 'llm' => 0], $executor->usedToday($account));
        $reply = XEngagement::where('action', 'reply')->where('target_key', hash('sha256', '301'))->first();
        $this->assertSame('feed_script', $reply->payload['origin']);
        $this->assertSame('script_spintax', $reply->payload['draft_source']);
        $this->assertMatchesRegularExpression('/^(Belle|Super) séance (💪|🔥)$/u', $reply->payload['text']);
        $this->assertSame(2, BotActionLog::where('action_type', 'feed.like')->where('success', true)->count());
        $this->assertSame(2, BotActionLog::where('action_type', 'feed.spin')->count());

        $again = app(FeedEngagementPlanner::class)->plan($account, $posts, $executor->usedToday($account));
        $this->assertSame(0, $again['summary']['likes'] + $again['summary']['spin'], 'déjà aimées et commentées');
    }

    public function test_un_geste_incertain_arrete_la_publication_et_le_plafond_du_passage_tient(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-14 12:00', 'Europe/Lisbon'));
        $account = $this->account(['enabled' => true, 'spin_percent' => 100, 'pass_max_actions' => 3]);
        $posts = [$this->feedPost('401', 'alice', 'Squat'), $this->feedPost('402', 'bob', 'Squat'), $this->feedPost('403', 'carla', 'Squat')];
        $executor = $this->executor(['uncertain']);
        $plan = app(FeedEngagementPlanner::class)->plan($account, $posts);
        $run = $executor->run($account, $plan, collect($posts)->keyBy('id')->all(), 'snap', 'server');

        $this->assertSame([['401', 'like', 'uncertain'], ['402', 'like', 'confirmed'], ['402', 'reply', 'confirmed']],
            array_map(fn ($d) => [$d['id'], $d['action'], $d['status']], $run['done']));
        $this->assertSame('plafond du passage (3 gestes)', $run['stopped']);
        $this->assertFalse(XEngagement::where('action', 'reply')->where('target_key', hash('sha256', '401'))->exists());
    }

    public function test_commit_refuse_tant_que_l_interrupteur_est_coupe(): void
    {
        $account = $this->account();
        $this->artisan('x:feed-engage', ['account' => $account->id, '--commit' => true])
            ->expectsOutputToContain('Engagement du fil coupé sur la fiche du compte')->assertFailed();
        $this->artisan('x:feed-engage', ['--all' => true, '--commit' => true])->assertSuccessful();
        $this->assertSame(0, XEngagement::count());
    }

    public function test_les_redactions_sont_reservees_lues_remises_par_l_api_puis_envoyees_au_passage_suivant(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-14 12:00', 'Europe/Lisbon'));
        $persona = \App\Models\Persona::create(['name' => 'Caro', 'system_prompt' => 'Ton chaleureux', 'is_active' => true]);
        $account = $this->account(['enabled' => true, 'spin_percent' => 0]);
        $account->update(['persona_id' => $persona->id, 'browser_session_status' => 'connected']);
        $posts = [$this->feedPost('501', 'alice', 'Squat lourd ce matin'), $this->feedPost('502', 'bob', 'Squat avant le travail')];
        $executor = $this->executor();
        $plan = app(FeedEngagementPlanner::class)->plan($account, $posts, $executor->usedToday($account));
        $run = $executor->run($account, $plan, collect($posts)->keyBy('id')->all(), 'snap', 'server');
        $this->assertSame(2, $run['requested']);
        $this->assertSame(['like', 'like'], array_column($run['done'], 'action'));
        $this->assertSame(['likes' => 2, 'spin' => 0, 'llm' => 2], $executor->usedToday($account), 'une demande compte dans le plafond');
        $this->assertSame(2, BotActionLog::where('action_type', 'feed.llm')->where('metadata->status', 'requested')->count());

        $manager = User::factory()->create(['role' => 'manager']);
        $account->users()->attach($manager, ['is_active' => true]);
        $token = $manager->createToken('CM', ['community-manager'])->plainTextToken;
        $path = '/api/community-manager/accounts/'.$account->id.'/feed-drafts';
        $batch = $this->withToken($token)->getJson($path)->assertOk()->assertJsonPath('persona.instructions', 'Ton chaleureux')->json();
        $this->assertSame(['alice', 'bob'], array_column($batch['drafts'], 'author'));
        [$first, $second] = array_column($batch['drafts'], 'id');
        $this->withToken($token)->postJson($path, ['drafts' => [['id' => $first, 'text' => 'Merci pour ce rappel'], ['id' => $second, 'skip' => 'hors sujet']]])
            ->assertOk()->assertJsonPath('results.0.status', 'refused')->assertJsonPath('results.1.status', 'skipped');
        $this->withToken($token)->postJson($path, ['drafts' => [['id' => $first, 'text' => 'Le squat lourd le matin, ça réveille 💪']]])
            ->assertOk()->assertJsonPath('results.0.status', 'accepted');
        $this->assertSame('cancelled', XEngagement::find($second)->status);

        $next = $executor->run($account, ['items' => []], [], 'snap2', 'server');
        $this->assertSame([['reply', 'llm', 'confirmed']], array_map(fn ($d) => [$d['action'], $d['source'], $d['status']], $next['done']));
        $this->assertSame('Le squat lourd le matin, ça réveille 💪', XEngagement::find($first)->payload['text']);
        $this->assertSame('hermes_subscription', XEngagement::find($first)->payload['draft_source']);
        $this->assertSame(1, BotActionLog::where('action_type', 'feed.llm')->where('success', true)->count());
    }

    public function test_un_delai_minimal_passager_est_attendu_au_lieu_d_arreter_le_passage(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-14 12:00', 'Europe/Lisbon'));
        $account = $this->account(['enabled' => true, 'spin_percent' => 0]);
        $executor = $this->executor();
        (fn () => $this->engagements->busy = 1)->call($executor);
        $posts = [$this->feedPost('701', 'alice', 'Squat')];
        $run = $executor->run($account, app(FeedEngagementPlanner::class)->plan($account, $posts), collect($posts)->keyBy('id')->all(), 'snap', 'server');
        $this->assertNull($run['stopped']);
        $this->assertSame('confirmed', $run['done'][0]['status']);
    }

    public function test_une_redaction_non_remise_expire_sans_geste(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-14 09:00', 'Europe/Lisbon'));
        $account = $this->account(['enabled' => true]);
        $drafts = app(\App\Services\Agent\Feed\FeedDraftService::class);
        $this->assertTrue($drafts->request($account, $this->feedPost('601', 'alice', 'Squat'), 'snap', 'server'));
        $this->assertFalse($drafts->request($account, $this->feedPost('601', 'alice', 'Squat'), 'snap', 'server'), 'une seule demande par publication');
        $this->assertSame(1, $drafts->pending($account)->count());
        $this->travel(181)->minutes();
        $this->assertSame(0, $drafts->pending($account)->count());
        $this->assertSame('cancelled', XEngagement::first()->status);
    }
}
