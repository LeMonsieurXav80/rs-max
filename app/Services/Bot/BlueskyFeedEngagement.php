<?php

namespace App\Services\Bot;

use App\Models\AccountActionEvent;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Services\Adapters\BlueskyAdapter;
use App\Services\Agent\AccountActionBudget;
use App\Services\Agent\AgentCatalog;
use App\Services\Agent\CommentFreshness;
use App\Services\Agent\CommunityManagerWindow;
use App\Services\Agent\Feed\FeedMatcher;
use App\Services\Agent\RepostCadence;
use App\Services\Browser\BrowserException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;

/** Passage Bluesky limité au flux et aux commentaires associés. */
final class BlueskyFeedEngagement
{
    public function __construct(
        private AccountActionBudget $budget,
        private AgentCatalog $catalog,
        private CommunityManagerWindow $window,
        private RepostCadence $reposts,
    ) {}

    public static function enabled(SocialAccount $account): bool
    {
        return Setting::get("bot_feed_only_bluesky_{$account->id}") === '1';
    }

    public function run(SocialAccount $account, string $did, PendingRequest $api): array
    {
        $result = ['total_likes' => 0, 'terms_processed' => 0, 'likeback_likes' => 0,
            'feed_likes' => 0, 'comment_likes' => 0, 'reposts' => 0];
        if ($did !== $account->platform_account_id) {
            return $result + ['error' => 'Identité Bluesky divergente.'];
        }
        if (! $this->window->isOpen()) {
            return $result;
        }

        $actions = $this->catalog->forAccount($account);
        $likeEnabled = $actions['like']['enabled'] && Setting::get("bot_feed_likes_bluesky_{$account->id}") === '1';
        $likes = $likeEnabled ? min($actions['like']['left'], (int) Setting::get("bot_feed_likes_max_bluesky_{$account->id}", 2)) : 0;
        $repost = $actions['repost']['enabled'] && $actions['repost']['left'] > 0
            && $this->reposts->hours($account) > 0 && $this->reposts->nextAllowedAt($account) === null;
        if ($likes < 1 && ! $repost) {
            return $result;
        }

        $root = 'https://bsky.social/xrpc/';
        try {
            $response = $api->get($root.'app.bsky.feed.getTimeline', ['limit' => 35]);
        } catch (ConnectionException) {
            return $result + ['error' => 'Connexion interrompue pendant la lecture du flux Bluesky.'];
        }
        if (! $response->successful()) {
            return $result + ['error' => 'Lecture du flux Bluesky refusée.'];
        }

        $strategy = $account->agent_strategy ?? [];
        $matcher = new FeedMatcher($strategy);
        // Un commentaire reste dans le thème du fil, mais peut ajouter un détail sans répéter son mot-clé.
        $directComments = [];
        $posts = [];
        foreach ($response->json('feed', []) as $item) {
            $post = $item['post'] ?? [];
            $original = empty($item['reason']) && empty($post['record']['reply']);
            if (! $original || ! $this->eligible($post, $did, $matcher, true)) {
                continue;
            }
            $posts[$post['uri']] = $post;
        }

        if ($likes > 0) {
            foreach (array_slice(array_filter($posts, fn ($post) => ($post['replyCount'] ?? 0) > 0), 0, 3) as $post) {
                try {
                    $thread = $api->get($root.'app.bsky.feed.getPostThread', ['uri' => $post['uri'], 'depth' => 1, 'parentHeight' => 0]);
                } catch (ConnectionException) {
                    continue;
                }
                if (! $thread->successful()) {
                    continue;
                }
                foreach ($thread->json('thread.replies', []) as $item) {
                    $reply = $item['post'] ?? [];
                    if ($this->eligible($reply, $did, $matcher, false)) {
                        $directComments[$reply['uri']] = $reply;
                    }
                }
            }
            // Chercher une réaction dans chacun des deux types de contenu, avec un seul compteur.
            $firstPost = array_slice(array_filter($posts, fn ($p) => empty($p['viewer']['like'])), 0, 1, true);
            foreach ($firstPost + $directComments + $posts as $uri => $post) {
                if ($likes < 1 || $this->stopped($account)) {
                    break;
                }
                $event = $this->react($account, $api, $root, $did, 'like', $post);
                if ($event !== null) {
                    $likes--;
                    if ($event->status === 'confirmed') {
                        $result[isset($directComments[$uri]) ? 'comment_likes' : 'feed_likes']++;
                    } else {
                        $repost = false;
                        break;
                    }
                }
            }
        }

        if ($repost && ! $this->stopped($account)) {
            foreach ($posts as $post) {
                $record = $post['record'];
                // Un texte substantiel et autonome, sans citation, lien commercial ni média à interpréter.
                if (mb_strlen(trim($record['text'])) < 80 || ! empty($record['embed']) || ! empty($record['facets'])) {
                    continue;
                }
                $event = $this->react($account, $api, $root, $did, 'repost', $post);
                if ($event) {
                    $result['reposts'] = (int) ($event->status === 'confirmed');
                    break;
                }
            }
        }

        $result['total_likes'] = $result['feed_likes'] + $result['comment_likes'];

        return $result;
    }

    private function eligible(array $post, string $did, FeedMatcher $matcher, bool $requireKeyword): bool
    {
        $author = (string) ($post['author']['handle'] ?? '');
        $text = trim((string) ($post['record']['text'] ?? ''));

        return $author !== '' && ! empty($post['author']['did']) && $post['author']['did'] !== $did
            && str_starts_with((string) ($post['uri'] ?? ''), 'at://') && filled($post['cid'] ?? null)
            && empty($post['labels']) && empty($post['author']['labels'])
            && mb_strlen($text) >= 12 && CommentFreshness::allowsPublishedAt($post['record']['createdAt'] ?? null)
            && $matcher->excludedBy($text, $author) === null
            && (! $requireKeyword || $matcher->isTarget($author) || $matcher->keywordHits($text) !== []);
    }

    private function stopped(SocialAccount $account): bool
    {
        return ! $this->window->isOpen() || Cache::has("bot_stop_bluesky_{$account->id}");
    }

    private function react(SocialAccount $account, PendingRequest $api, string $root, string $did, string $action, array $post): ?AccountActionEvent
    {
        if (! empty($post['viewer'][$action])) {
            return null;
        }
        try {
            $url = BlueskyAdapter::buildPostUrl($post['author']['did'], $post['uri']);
            $event = $this->budget->reserve($account, 'bluesky.'.$action, 'api', $url);
        } catch (BrowserException) {
            return null;
        }

        $collection = 'app.bsky.feed.'.$action;
        $subject = ['uri' => $post['uri'], 'cid' => $post['cid']];
        try {
            $response = $api->post($root.'com.atproto.repo.createRecord', [
                'repo' => $did, 'collection' => $collection,
                'record' => ['$type' => $collection, 'subject' => $subject, 'createdAt' => now()->toIso8601ZuluString()],
            ]);
            if (! $response->successful()) {
                $event->update(['status' => $response->serverError() ? 'uncertain' : 'failed',
                    'error' => 'Bluesky HTTP '.$response->status()]);

                return $event;
            }
            $uri = (string) $response->json('uri', '');
            $proof = $uri !== '' ? $api->get($root.'com.atproto.repo.getRecord', [
                'repo' => $did, 'collection' => $collection, 'rkey' => basename($uri),
            ]) : null;
            $confirmed = $proof?->successful() && $proof->json('value.subject.uri') === $subject['uri']
                && $proof->json('value.subject.cid') === $subject['cid'];
            $event->update(['status' => $confirmed ? 'confirmed' : 'uncertain',
                'error' => $confirmed ? null : 'Geste Bluesky absent à la relecture.']);
        } catch (ConnectionException) {
            $event->update(['status' => 'uncertain', 'error' => 'Connexion interrompue pendant le geste Bluesky.']);
        }

        return $event;
    }
}
