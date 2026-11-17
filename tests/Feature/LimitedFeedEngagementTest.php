<?php

namespace Tests\Feature;

use App\Models\AccountActionEvent;
use App\Models\Platform;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Services\Agent\AccountActionBudget;
use App\Services\Agent\AgentCatalog;
use App\Services\Agent\RepostCadence;
use App\Services\Bot\BlueskyBotService;
use App\Services\Browser\BrowserException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LimitedFeedEngagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_reposts_and_quotes_share_a_rolling_interval_from_the_last_outcome(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17T12:00:00Z'));
        $account = $this->account('twitter');
        $budget = app(AccountActionBudget::class);
        $reservation = $budget->reserve($account, 'x.repost', 'browser', 'https://x.com/i/status/100');
        $this->travel(4)->minutes();
        $reservation->update(['status' => 'uncertain']);
        $this->travelTo(Carbon::parse('2026-11-19T12:03:59Z'));
        $this->assertSame('2026-11-19T12:04:00+00:00', app(RepostCadence::class)->nextAllowedAt($account)->toIso8601String());
        try {
            $budget->reserve($account, 'x.quote', 'browser', 'https://x.com/i/status/101');
            $this->fail('A quote used the same rolling window.');
        } catch (BrowserException $e) {
            $this->assertStringContainsString('entre republications', $e->getMessage());
        }
        $this->travel(1)->second();
        $this->assertSame('x.quote', $budget->reserve($account, 'x.quote', 'browser')->action);
    }

    public function test_bluesky_feed_mode_counts_comments_with_the_same_like_quota_and_confirms_repost(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17T12:00:00Z'));
        config(['app.key' => 'base64:'.base64_encode(str_repeat('n', 32))]);
        $account = $this->account('bluesky');
        $account->update(['credentials' => ['handle' => 'example.test', 'app_password' => 'test-password']]);
        foreach (['like' => 2, 'repost' => 1, 'reply' => 1] as $action => $limit) {
            app(AgentCatalog::class)->configure($account, $action, true, $limit);
        }
        foreach (['feed_only' => '1', 'feed_likes' => '1', 'feed_likes_max' => '2'] as $key => $value) {
            Setting::set("bot_{$key}_bluesky_{$account->id}", $value);
        }
        $post = $this->blueskyPost('first', 'Une séance de musculation accessible. Voici les exercices que nous avons faits au club pour travailler les appuis sur une jambe.');
        $second = $this->blueskyPost('second', 'Musculation encore et quels exercices vous aimez faire en ce moment ?');
        $reply = $this->blueskyPost('comment', 'Avec une main en appui, la descente devient plus facile à contrôler.');
        $written = [];
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($account, $post, $second, $reply, &$written) {
            return match (basename(parse_url($request->url(), PHP_URL_PATH))) {
                'com.atproto.server.createSession' => Http::response([
                    'did' => $account->platform_account_id, 'accessJwt' => 'example.'.base64_encode(json_encode(['exp' => time() + 3600])).'.signature', 'refreshJwt' => 'refresh',
                ]),
                'app.bsky.feed.getTimeline' => Http::response(['feed' => [['post' => $post + ['replyCount' => 1]], ['post' => $second]]]),
                'app.bsky.feed.getPostThread' => Http::response(['thread' => ['replies' => [['post' => $reply]]]]),
                'com.atproto.repo.createRecord' => $this->record($request, $written),
                'com.atproto.repo.getRecord' => Http::response(['value' => $written[array_key_last($written)]['record']]),
                default => throw new \LogicException('Action étrangère au flux limité.'),
            };
        });

        $service = app(BlueskyBotService::class);
        $result = $service->runForAccount($account);
        $this->assertSame([1, 1, 1], [$result['feed_likes'], $result['comment_likes'], $result['reposts']]);
        $this->assertSame(['app.bsky.feed.like', 'app.bsky.feed.like', 'app.bsky.feed.repost'], array_column($written, 'collection'));
        $this->assertSame([$post['uri'], $reply['uri'], $post['uri']], array_map(fn ($entry) => $entry['record']['subject']['uri'], $written));
        $this->assertSame(3, AccountActionEvent::where('social_account_id', $account->id)->where('status', 'confirmed')->count());

        $service->runForAccount($account->fresh());
        $this->assertCount(3, $written);
        $budget = app(AccountActionBudget::class);
        $budget->reserve($account, 'bluesky.reply', 'api', '10');
        $this->expectException(BrowserException::class);
        $budget->reserve($account, 'bluesky.reply', 'api', '11');
    }

    private function account(string $network): SocialAccount
    {
        $platform = Platform::firstOrCreate(['slug' => $network], ['name' => ucfirst($network)]);

        return SocialAccount::create([
            'name' => 'Compte test', 'platform_id' => $platform->id,
            'platform_account_id' => "identity-{$network}", 'driver' => $network === 'twitter' ? 'browser' : 'api',
            'agent_strategy' => ['repost_interval_hours' => 48, 'search_queries' => ['musculation']],
        ]);
    }

    private function blueskyPost(string $id, string $text): array
    {
        return [
            'uri' => "at://did:plc:author/app.bsky.feed.post/{$id}", 'cid' => "cid_{$id}",
            'author' => ['did' => 'did:plc:author', 'handle' => 'author.bsky.social'],
            'record' => ['createdAt' => '2026-11-17T11:00:00Z', 'text' => $text],
        ];
    }

    private function record(Request $request, array &$written): \GuzzleHttp\Promise\PromiseInterface
    {
        $written[] = $request->data();

        return Http::response(['uri' => 'at://identity/'.$request['collection'].'/'.count($written)]);
    }
}
