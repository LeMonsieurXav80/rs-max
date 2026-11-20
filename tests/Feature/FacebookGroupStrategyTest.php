<?php

namespace Tests\Feature;

use App\Models\BrowserTask;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Agent\AgentCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FacebookGroupStrategyTest extends TestCase
{
    use RefreshDatabase;

    private function setupAccount(string $platform = 'facebook'): array
    {
        $user = User::factory()->create(['role' => 'manager']);
        $account = SocialAccount::create(['name' => 'Profil test', 'platform_id' => Platform::firstOrCreate(['slug' => $platform], ['name' => ucfirst($platform)])->id,
            'driver' => 'browser', 'browser_profile' => 'vps-test']);
        $account->users()->attach($user, ['is_active' => true]);
        $this->actingAs($user);

        return [$account, '/api/accounts/'.$account->id.'/strategy'];
    }

    public function test_groups_are_normalized_deduplicated_and_kept_on_partial_updates(): void
    {
        [$account, $path] = $this->setupAccount();
        $other = SocialAccount::create(['name' => 'Autre profil', 'platform_id' => $account->platform_id]);
        $this->patchJson($path, ['agent_strategy' => ['facebook_groups' => [
            'https://web.facebook.com/groups/123?ref=share', 'https://www.facebook.com/groups/123/',
            'https://m.facebook.com/groups/MonGroupe/#discussion',
        ]]])->assertOk()->assertJsonPath('strategy.facebook_groups', [
            'https://www.facebook.com/groups/123/', 'https://www.facebook.com/groups/mongroupe/',
        ]);
        $this->patchJson($path, ['agent_strategy' => ['objective' => 'Échanges dans les groupes']])->assertOk()->assertJsonCount(2, 'strategy.facebook_groups');
        $this->assertNull($other->fresh()->agent_strategy);
        $this->assertSame(0, BrowserTask::count());
        $this->patchJson($path, ['agent_strategy' => ['facebook_groups' => []]])->assertOk()->assertJsonPath('strategy.facebook_groups', []);
    }

    public static function invalidGroups(): array
    {
        return array_map(fn ($value) => [$value], [
            'https://facebook.com.evil.test/groups/123', 'https://evil.test/groups/123',
            'http://www.facebook.com/groups/123', 'https://www.facebook.com@evil.test/groups/123',
            'https://user:pass@www.facebook.com/groups/123', 'https://www.facebook.com:8443/groups/123',
            'https://www.facebook.com/groups/123/posts/456', 'https://www.facebook.com/share/g/abcd/',
            'https://www.facebook.com/groups/', 'https://www.facebook.com/groups/%2e%2e/',
        ]);
    }

    #[DataProvider('invalidGroups')]
    public function test_invalid_group_links_do_not_modify_the_selection(string $url): void
    {
        [$account, $path] = $this->setupAccount();
        $account->update(['agent_strategy' => ['facebook_groups' => ['https://www.facebook.com/groups/123/']]]);
        $this->patchJson($path, ['agent_strategy' => ['facebook_groups' => [$url]]])->assertUnprocessable()->assertJsonValidationErrors('agent_strategy.facebook_groups.0');
        $this->assertSame(['https://www.facebook.com/groups/123/'], $account->fresh()->agent_strategy['facebook_groups']);
    }

    public function test_groups_are_not_accepted_on_instagram_or_an_unassigned_account(): void
    {
        [$account, $path] = $this->setupAccount('instagram');
        $this->patchJson($path, ['agent_strategy' => ['facebook_groups' => ['https://www.facebook.com/groups/123/']]])
            ->assertUnprocessable()->assertJsonValidationErrors('agent_strategy.facebook_groups');
        $this->assertNull($account->fresh()->agent_strategy);
        $unassigned = SocialAccount::create(['name' => 'Non attribué', 'platform_id' => Platform::firstOrCreate(['slug' => 'facebook'], ['name' => 'Facebook'])->id]);
        $this->patchJson('/api/accounts/'.$unassigned->id.'/strategy', ['agent_strategy' => ['facebook_groups' => []]])->assertNotFound();
    }

    public function test_facebook_form_uses_groups_and_preserves_other_strategy_fields(): void
    {
        [$account] = $this->setupAccount();
        $account->update(['agent_strategy' => ['objective' => 'Échanges', 'hashtags' => ['#ancien']]]);
        $this->get('/accounts/'.$account->id.'/edit')->assertOk()->assertSee('Groupes Facebook à explorer')
            ->assertDontSee('name="agent_strategy[hashtags]"', false)->assertDontSee('name="agent_strategy[unfollow_after_days]"', false);
        $this->put('/accounts/'.$account->id, ['name' => $account->name, 'languages' => ['fr'],
            'agent_strategy' => ['facebook_groups' => "https://web.facebook.com/groups/123\n\nhttps://facebook.com/groups/456/"]])
            ->assertRedirect('/accounts/'.$account->id.'/edit');
        $this->assertSame(['https://www.facebook.com/groups/123/', 'https://www.facebook.com/groups/456/'], $account->fresh()->agent_strategy['facebook_groups']);
        $this->assertSame(['#ancien'], $account->fresh()->agent_strategy['hashtags']);
        $this->assertSame('Échanges', $account->fresh()->agent_strategy['objective']);
        $this->assertSame(0, BrowserTask::count());
    }

    public function test_group_comments_and_comment_likes_are_separate_and_can_be_enabled_independently(): void
    {
        [$account] = $this->setupAccount();
        $catalog = app(AgentCatalog::class);
        $this->assertNotSame($catalog->action($account, 'comment')['label'], $catalog->action($account, 'comment.like')['label']);
        foreach (['comment', 'comment.like'] as $action) {
            $this->assertSame('ready', $catalog->action($account, $action)['status']);
            $this->assertFalse($catalog->forAccount($account)[$action]['enabled']);
            $this->putJson('/api/agent/accounts/'.$account->id.'/actions/'.$action, ['enabled' => true, 'daily_limit' => 2])->assertOk();
        }
        $this->assertSame('planned', $catalog->action($account, 'reply')['status']);
        $api = SocialAccount::create(['name' => 'Page API', 'platform_id' => $account->platform_id, 'driver' => 'api']);
        $this->assertSame('ready', $catalog->action($api, 'comment.like')['status']);
        $this->assertNull($catalog->action($api, 'comment'));
    }
}
