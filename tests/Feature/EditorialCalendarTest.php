<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EditorialCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_grille_affiche_tous_les_jours_du_mois_et_les_evenements(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create())
            ->get('/editorial-calendar?month=2026-01')
            ->assertOk()
            ->assertSee('Janvier 2026')
            ->assertSee('datetime="2026-01-01"', false)
            ->assertSee('datetime="2026-01-31"', false)
            ->assertSee('CES Las Vegas')
            ->assertSee('Journée des Community Managers');
    }

    public function test_api_filtres_et_evenements_en_cours(): void
    {
        $this->getJson('/api/editorial-calendar')->assertUnauthorized();
        $this->actingAs(User::factory()->create())
            ->getJson('/api/editorial-calendar?from=2027-01-08&to=2027-01-08&topic=tech&q=las%20vegas')
            ->assertOk()
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.starts_on', '2027-01-06')
            ->assertJsonPath('events.0.ends_on', '2027-01-09')
            ->assertJsonPath('events.0.title', 'CES Las Vegas');
        $this->getJson('/api/editorial-calendar?from=2027-01-08')->assertUnprocessable();
        $this->getJson('/api/editorial-calendar?month=2027-13')->assertUnprocessable();
    }

    public function test_hermes_consulte_les_themes_du_compte_attribue(): void
    {
        $user = User::factory()->create(['role' => 'manager']);
        $platform = Platform::where('slug', 'tiktok')->firstOrFail();
        $account = SocialAccount::create(['name' => 'Test', 'platform_id' => $platform->id, 'agent_strategy' => ['hashtags' => ['#exemple']]]);
        $other = SocialAccount::create(['name' => 'Autre', 'platform_id' => $platform->id]);
        $account->users()->attach($user, ['is_active' => true]);
        $this->withToken($user->createToken('CM', ['community-manager'])->plainTextToken);
        $base = '/api/community-manager/accounts/';
        $this->patchJson($base.$account->id.'/strategy', ['agent_strategy' => ['editorial_topics' => ['tech']]])
            ->assertOk()
            ->assertJsonPath('strategy.hashtags.0', '#exemple');

        $result = $this->getJson($base.$account->id.'/editorial-calendar?month=2027-01')->assertOk();
        $topics = array_column($result->json('events'), 'topics');
        $this->assertNotEmpty($topics);
        foreach ($topics as $eventTopics) {
            $this->assertContains('tech', $eventTopics);
        }

        $all = $this->getJson($base.$account->id.'/editorial-calendar?month=2027-01&topic=all')->assertOk();
        $this->assertGreaterThan(count($topics), count($all->json('events')));
        $this->getJson($base.$other->id.'/editorial-calendar?account_id='.$account->id)->assertNotFound();
        $this->getJson('/api/editorial-calendar')->assertForbidden();
    }
}
