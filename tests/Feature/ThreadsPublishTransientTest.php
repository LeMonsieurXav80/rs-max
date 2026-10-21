<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Services\Adapters\ThreadsAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Couvre le trou qui coupait les fils en plein milieu sur Threads
 * (fils d'aout-septembre 2026, « Threads publish failed: Fatal (code=-1) »
 * suivi d'une cascade de « Previous segment failed »).
 *
 * Deux defauts distincts, tous deux exerces ici :
 *
 *  1. `code=-1` (« Fatal » / API Unknown de Graph) n'etait pas reconnu comme un
 *     alea transitoire. Servi en HTTP 4xx, il ne declenchait aucune tentative.
 *  2. Un alea sur la FINALISATION (`threads_publish`) ne declenchait pas de
 *     reconstruction du conteneur, alors que le meme alea sur sa CREATION en
 *     declenchait une. Un segment rate condamne tous les suivants du fil.
 *
 * @see ThreadsAdapter::isTransientError()
 * @see ThreadsAdapter::createAndPublishWithRetry()
 */
class ThreadsPublishTransientTest extends TestCase
{
    use RefreshDatabase;

    /** Adapter sans backoff : on exerce les boucles, pas les pauses reelles. */
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

    /**
     * Le « Fatal » de Graph, tel qu'il arrive en HTTP 400 — donc hors du filet 5xx.
     *
     * Sans type de retour : dans un Http::fake(), Http::response() rend une
     * promesse, pas une Response.
     */
    private function fatalResponse()
    {
        return Http::response([
            'error' => [
                'message' => 'Fatal',
                'type' => 'OAuthException',
                'code' => -1,
            ],
        ], 400);
    }

    public function test_fatal_at_publish_rebuilds_the_container_and_succeeds(): void
    {
        $containerPosts = 0;
        $publishPosts = 0;

        Http::fake(function (Request $request) use (&$containerPosts, &$publishPosts) {
            if ($request->method() === 'GET') {
                return Http::response(['status' => 'FINISHED', 'permalink' => 'https://threads.net/p/1']);
            }

            // Attention a l'ordre : '/threads' est contenu dans '/threads_publish'.
            if (str_contains($request->url(), '/threads_publish')) {
                $publishPosts++;

                // La panne couvre les 5 tentatives rapprochees de postWithRetry()
                // sur la 1re tentative ; elle est passee quand on reconstruit.
                return $publishPosts <= 5
                    ? $this->fatalResponse()
                    : Http::response(['id' => 'PUBLISHED_ID']);
            }

            $containerPosts++;

            return Http::response(['id' => 'CONTAINER_'.$containerPosts]);
        });

        $result = $this->adapter()->publish($this->account(), 'Mon segment');

        $this->assertTrue($result['success'], 'Un « Fatal (code=-1) » a la finalisation doit declencher une reconstruction du conteneur');
        $this->assertSame('PUBLISHED_ID', $result['external_id']);
        $this->assertSame(6, $publishPosts, '5 echecs rapproches, puis la finalisation du conteneur reconstruit');
        $this->assertSame(2, $containerPosts, 'Un conteneur neuf doit etre cree pour la 2e tentative');
    }

    public function test_fatal_is_retried_at_container_creation_despite_http_400(): void
    {
        $containerPosts = 0;

        Http::fake(function (Request $request) use (&$containerPosts) {
            if ($request->method() === 'GET') {
                return Http::response(['status' => 'FINISHED', 'permalink' => 'https://threads.net/p/1']);
            }

            if (str_contains($request->url(), '/threads_publish')) {
                return Http::response(['id' => 'PUBLISHED_ID']);
            }

            $containerPosts++;

            // Avant le correctif, ce 400 n'etait pas transitoire : zero tentative.
            return $containerPosts === 1
                ? $this->fatalResponse()
                : Http::response(['id' => 'CONTAINER_OK']);
        });

        $result = $this->adapter()->publish($this->account(), 'Mon segment');

        $this->assertTrue($result['success'], 'Un code=-1 en HTTP 400 doit etre retente comme un alea serveur');
        $this->assertSame(2, $containerPosts);
    }

    public function test_gives_up_cleanly_when_fatal_never_recovers(): void
    {
        $publishPosts = 0;

        Http::fake(function (Request $request) use (&$publishPosts) {
            if ($request->method() === 'GET') {
                return Http::response(['status' => 'FINISHED']);
            }

            if (str_contains($request->url(), '/threads_publish')) {
                $publishPosts++;

                return $this->fatalResponse();
            }

            return Http::response(['id' => 'CONTAINER_ID']);
        });

        $result = $this->adapter()->publish($this->account(), 'Mon segment');

        $this->assertFalse($result['success'], 'Une panne qui ne passe jamais doit finir en echec propre');
        $this->assertStringContainsString('code=-1', $result['error']);
        // 3 reconstructions × 5 tentatives rapprochees : borne, pas de boucle infinie.
        $this->assertSame(15, $publishPosts);
    }

    public function test_reply_rebuilds_its_container_after_fatal_at_publish(): void
    {
        $containerPosts = 0;
        $publishPosts = 0;

        Http::fake(function (Request $request) use (&$containerPosts, &$publishPosts) {
            if ($request->method() === 'GET') {
                return Http::response(['status' => 'FINISHED', 'permalink' => 'https://threads.net/p/2']);
            }

            if (str_contains($request->url(), '/threads_publish')) {
                $publishPosts++;

                return $publishPosts <= 5
                    ? $this->fatalResponse()
                    : Http::response(['id' => 'REPLY_ID']);
            }

            $containerPosts++;

            return Http::response(['id' => 'REPLY_CONTAINER_'.$containerPosts]);
        });

        $result = $this->adapter()->publishReply($this->account(), 'Segment 43', 'PARENT_ID');

        $this->assertTrue($result['success'], 'C\'est sur les reponses que le fil cassait : elles doivent etre reconstruites aussi');
        $this->assertSame('REPLY_ID', $result['external_id']);
        $this->assertSame(2, $containerPosts);
    }

    public function test_carousel_rebuilds_when_fatal_hits_the_final_publish(): void
    {
        $childPosts = 0;
        $parentPosts = 0;
        $publishPosts = 0;

        Http::fake(function (Request $request) use (&$childPosts, &$parentPosts, &$publishPosts) {
            if ($request->method() === 'GET') {
                return Http::response(['status' => 'FINISHED', 'permalink' => 'https://threads.net/p/3']);
            }

            if (str_contains($request->url(), '/threads_publish')) {
                $publishPosts++;

                return $publishPosts <= 5
                    ? $this->fatalResponse()
                    : Http::response(['id' => 'CAROUSEL_PUBLISHED']);
            }

            if (($request->data()['media_type'] ?? null) === 'CAROUSEL') {
                return Http::response(['id' => 'CAROUSEL_'.(++$parentPosts)]);
            }

            return Http::response(['id' => 'child_'.(++$childPosts)]);
        });

        $media = [
            ['type' => 'image', 'mimetype' => 'image/jpeg', 'url' => 'https://example.com/a.jpg'],
            ['type' => 'image', 'mimetype' => 'image/jpeg', 'url' => 'https://example.com/b.jpg'],
        ];

        $result = $this->adapter()->publish($this->account(), 'Legende', $media);

        $this->assertTrue($result['success'], 'Le carrousel doit etre reassemble apres un Fatal a la finalisation');
        $this->assertSame('CAROUSEL_PUBLISHED', $result['external_id']);
        $this->assertSame(2, $parentPosts, 'Un nouveau parent doit etre assemble');
        $this->assertSame(4, $childPosts, '2 images × 2 tentatives : rebuild complet');
    }
}
