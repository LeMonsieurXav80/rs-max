<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\XEngagement;
use App\Services\Agent\Feed\FeedEngagementPlanner;
use App\Services\Agent\Feed\FeedMatcher;
use App\Services\Agent\Feed\Spintax;
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
}
