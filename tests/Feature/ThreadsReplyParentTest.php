<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Services\Adapters\ThreadsAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Threads accepte parfois un post (id + permalink renvoyés, quota consommé) puis
 * le supprime dans la minute. La réponse suivante échoue alors en code=24 /
 * subcode 4279009 en désignant SON conteneur — pourtant FINISHED — ce qui masque
 * la vraie cause : le parent a disparu.
 *
 * Voir le fil 73 (planetedecaro, 04/08/2026) et ThreadsAdapter::publishReply().
 */
class ThreadsReplyParentTest extends TestCase
{
    use RefreshDatabase;

    /** Adapter sans backoff : on exerce les boucles de retry sans attendre les pauses. */
    private function adapter(): ThreadsAdapter
    {
        return new class extends ThreadsAdapter
        {
            protected function pause(int $seconds): void {}
        };
    }

    private function account(): SocialAccount
    {
        $account = new SocialAccount;
        $account->id = 1;
        $account->credentials = ['user_id' => 'USER123', 'access_token' => 'TOKEN123'];

        return $account;
    }

    private function missingObjectResponse()
    {
        return Http::response([
            'error' => [
                'message' => "Unsupported get request. Object with ID 'PARENT' does not exist",
                'type' => 'THApiException',
                'code' => 100,
                'error_subcode' => 33,
            ],
        ], 400);
    }

    public function test_reply_fails_fast_when_parent_post_has_vanished(): void
    {
        $posts = 0;

        Http::fake(function (Request $request) use (&$posts) {
            if ($request->method() === 'POST') {
                $posts++;

                return Http::response(['id' => 'SHOULD_NOT_HAPPEN']);
            }

            return $this->missingObjectResponse();
        });

        $result = $this->adapter()->publishReply($this->account(), 'Suite du fil', 'PARENT');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['parent_missing']);
        $this->assertStringContainsString("n'existe plus", $result['error']);
        $this->assertSame(0, $posts, 'Aucun conteneur ne doit être créé si le parent a disparu');
    }

    public function test_reply_error_is_requalified_when_parent_vanishes_mid_flight(): void
    {
        $existenceChecks = 0;

        Http::fake(function (Request $request) use (&$existenceChecks) {
            $data = $request->data();

            if ($request->method() === 'GET') {
                // Vérification d'existence du parent : présent au départ, disparu ensuite.
                if (($data['fields'] ?? null) === 'id') {
                    $existenceChecks++;

                    return $existenceChecks === 1
                        ? Http::response(['id' => 'PARENT'])
                        : $this->missingObjectResponse();
                }

                return Http::response(['status' => 'FINISHED']);
            }

            if (str_contains($request->url(), '/threads_publish')) {
                return Http::response([
                    'error' => [
                        'message' => 'The requested resource does not exist',
                        'type' => 'OAuthException',
                        'code' => 24,
                        'error_subcode' => 4279009,
                        'is_transient' => false,
                    ],
                ], 400);
            }

            return Http::response(['id' => 'REPLY_CONTAINER']);
        });

        $result = $this->adapter()->publishReply($this->account(), 'Suite du fil', 'PARENT');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['parent_missing']);
        $this->assertStringContainsString("n'existe plus", $result['error']);
        $this->assertSame(2, $existenceChecks, 'Le parent doit être revérifié après un 4279009');
    }

    public function test_reply_publishes_normally_when_parent_still_exists(): void
    {
        Http::fake(function (Request $request) {
            $data = $request->data();

            if ($request->method() === 'GET') {
                if (($data['fields'] ?? null) === 'id') {
                    return Http::response(['id' => 'PARENT']);
                }

                return Http::response(['status' => 'FINISHED', 'permalink' => 'https://threads.net/p/2']);
            }

            if (str_contains($request->url(), '/threads_publish')) {
                return Http::response(['id' => 'REPLY_ID']);
            }

            return Http::response(['id' => 'REPLY_CONTAINER']);
        });

        $result = $this->adapter()->publishReply($this->account(), 'Suite du fil', 'PARENT');

        $this->assertTrue($result['success']);
        $this->assertSame('REPLY_ID', $result['external_id']);
    }

    public function test_single_image_post_rebuilds_its_container_when_it_vanishes_at_publish(): void
    {
        $containers = 0;
        $publishAttempts = 0;

        Http::fake(function (Request $request) use (&$containers, &$publishAttempts) {
            if ($request->method() === 'GET') {
                return Http::response(['status' => 'FINISHED', 'permalink' => 'https://threads.net/p/1']);
            }

            if (str_contains($request->url(), '/threads_publish')) {
                $publishAttempts++;

                if ($publishAttempts === 1) {
                    return Http::response([
                        'error' => [
                            'message' => 'The requested resource does not exist',
                            'type' => 'OAuthException',
                            'code' => 24,
                            'error_subcode' => 4279009,
                            'is_transient' => false,
                        ],
                    ], 400);
                }

                return Http::response(['id' => 'PUBLISHED_ID']);
            }

            return Http::response(['id' => 'CONTAINER_'.(++$containers)]);
        });

        $media = [['type' => 'image', 'mimetype' => 'image/jpeg', 'url' => 'https://example.com/a.jpg']];
        $result = $this->adapter()->publish($this->account(), 'Premier segment', $media);

        $this->assertTrue($result['success'], 'Un post mono-image doit être reconstruit puis republié après un 4279009');
        $this->assertSame('PUBLISHED_ID', $result['external_id']);
        $this->assertSame(2, $publishAttempts);
        $this->assertSame(2, $containers, 'Un nouveau conteneur doit être créé au 2e essai');
    }

    public function test_single_image_post_gives_up_after_max_rebuilds(): void
    {
        $publishAttempts = 0;

        Http::fake(function (Request $request) use (&$publishAttempts) {
            if ($request->method() === 'GET') {
                return Http::response(['status' => 'FINISHED']);
            }

            if (str_contains($request->url(), '/threads_publish')) {
                $publishAttempts++;

                return Http::response([
                    'error' => [
                        'message' => 'The requested resource does not exist',
                        'code' => 24,
                        'error_subcode' => 4279009,
                    ],
                ], 400);
            }

            return Http::response(['id' => 'CONTAINER']);
        });

        $media = [['type' => 'image', 'mimetype' => 'image/jpeg', 'url' => 'https://example.com/a.jpg']];
        $result = $this->adapter()->publish($this->account(), 'Premier segment', $media);

        $this->assertFalse($result['success']);
        $this->assertSame(3, $publishAttempts, 'La reconstruction doit être bornée à 3 tentatives');
    }

    public function test_reply_is_not_blocked_when_existence_check_fails_transiently(): void
    {
        Http::fake(function (Request $request) {
            $data = $request->data();

            if ($request->method() === 'GET') {
                // Erreur inattendue (5xx) sur la vérification : on ne doit pas bloquer.
                if (($data['fields'] ?? null) === 'id') {
                    return Http::response(['error' => ['code' => 1, 'message' => 'An unknown error occurred']], 500);
                }

                return Http::response(['status' => 'FINISHED', 'permalink' => 'https://threads.net/p/2']);
            }

            if (str_contains($request->url(), '/threads_publish')) {
                return Http::response(['id' => 'REPLY_ID']);
            }

            return Http::response(['id' => 'REPLY_CONTAINER']);
        });

        $result = $this->adapter()->publishReply($this->account(), 'Suite du fil', 'PARENT');

        $this->assertTrue($result['success'], 'Une lecture ratée ne doit jamais bloquer une publication valide');
    }

    /**
     * Pendant du cas carrousel du fil 82, côté réponse simple : une panne code=1
     * qui survit aux tentatives rapprochées de postWithRetry() doit déclencher
     * une reconstruction du conteneur, pas condamner le segment.
     */
    public function test_reply_rebuilds_container_when_creation_outage_outlasts_close_retries(): void
    {
        $containerPosts = 0;

        Http::fake(function (Request $request) use (&$containerPosts) {
            if ($request->method() === 'GET') {
                return Http::response(['id' => 'PARENT', 'status' => 'FINISHED', 'permalink' => 'https://threads.net/p/2']);
            }

            if (str_contains($request->url(), '/threads_publish')) {
                return Http::response(['id' => 'REPLY_ID']);
            }

            $containerPosts++;

            if ($containerPosts <= 5) {
                return Http::response([
                    'error' => ['message' => 'An unknown error has occurred.', 'type' => 'OAuthException', 'code' => 1],
                ], 500);
            }

            return Http::response(['id' => 'REPLY_CONTAINER']);
        });

        $result = $this->adapter()->publishReply($this->account(), 'Suite du fil', 'PARENT');

        $this->assertTrue($result['success'], 'Une panne code=1 prolongée doit déclencher une reconstruction du conteneur');
        $this->assertSame('REPLY_ID', $result['external_id']);
        $this->assertSame(6, $containerPosts, '5 échecs sur la 1re tentative, puis le conteneur créé à la 2e');
    }
}
