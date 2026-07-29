<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Services\Adapters\ThreadsAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Couvre le durcissement de la publication carrousel Threads : l'API Meta
 * rejette parfois un enfant fraîchement créé comme « invalide/expiré »
 * (code=100, subcode=4279004). L'adapter doit alors reconstruire les enfants
 * et réessayer l'assemblage plutôt que d'échouer d'emblée.
 *
 * Voir le bug post 723 (02/07/2026) et ThreadsAdapter::publishCarousel().
 */
class ThreadsCarouselTest extends TestCase
{
    use RefreshDatabase;

    /**
     * SocialAccount non persisté : l'adapter lit seulement ->credentials.
     * On évite ainsi de dépendre du schéma complet de la table.
     */
    private function account(): SocialAccount
    {
        $account = new SocialAccount;
        $account->id = 1;
        $account->credentials = ['user_id' => 'USER123', 'access_token' => 'TOKEN123'];

        return $account;
    }

    private function media(): array
    {
        return [
            ['type' => 'image', 'mimetype' => 'image/jpeg', 'url' => 'https://example.com/a.jpg'],
            ['type' => 'image', 'mimetype' => 'image/jpeg', 'url' => 'https://example.com/b.jpg'],
        ];
    }

    public function test_carousel_retries_assembly_when_children_are_rejected_as_expired(): void
    {
        $childSeq = 0;
        $parentAttempts = 0;

        Http::fake(function (Request $request) use (&$childSeq, &$parentAttempts) {
            $url = $request->url();
            $data = $request->data();

            // Statuts / permalink : toujours prêts.
            if ($request->method() === 'GET') {
                return Http::response(['status' => 'FINISHED', 'permalink' => 'https://threads.net/p/1']);
            }

            // Publication finale du conteneur.
            if (str_contains($url, '/threads_publish')) {
                return Http::response(['id' => 'PUBLISHED_ID']);
            }

            // Assemblage du carrousel parent : 1er essai rejeté (4279004), 2e OK.
            if (($data['media_type'] ?? null) === 'CAROUSEL') {
                $parentAttempts++;

                if ($parentAttempts === 1) {
                    return Http::response([
                        'error' => [
                            'message' => 'Invalid parameter',
                            'type' => 'OAuthException',
                            'code' => 100,
                            'error_subcode' => 4279004,
                        ],
                    ], 400);
                }

                return Http::response(['id' => 'CAROUSEL_ID']);
            }

            // Création d'un conteneur enfant.
            return Http::response(['id' => 'child_'.(++$childSeq)]);
        });

        $result = (new ThreadsAdapter)->publish($this->account(), 'Légende', $this->media());

        $this->assertTrue($result['success'], 'Le carrousel doit réussir après reconstruction des enfants');
        $this->assertSame('PUBLISHED_ID', $result['external_id']);
        $this->assertSame(2, $parentAttempts, 'Le parent doit être réessayé exactement une fois');
        // 2 images × 2 tentatives d'assemblage = 4 enfants créés au total.
        $this->assertSame(4, $childSeq);
    }

    public function test_carousel_rebuilds_when_container_vanishes_at_publish(): void
    {
        $childSeq = 0;
        $parentAttempts = 0;
        $publishAttempts = 0;

        Http::fake(function (Request $request) use (&$childSeq, &$parentAttempts, &$publishAttempts) {
            $data = $request->data();

            if ($request->method() === 'GET') {
                return Http::response(['status' => 'FINISHED', 'permalink' => 'https://threads.net/p/1']);
            }

            // Publication finale : 1er conteneur « introuvable » (4279009), 2e OK.
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

            if (($data['media_type'] ?? null) === 'CAROUSEL') {
                $parentAttempts++;

                return Http::response(['id' => 'CAROUSEL_ID_'.$parentAttempts]);
            }

            return Http::response(['id' => 'child_'.(++$childSeq)]);
        });

        $result = (new ThreadsAdapter)->publish($this->account(), 'Légende', $this->media());

        $this->assertTrue($result['success'], 'Le carrousel doit être reconstruit puis republié après un conteneur introuvable (4279009)');
        $this->assertSame('PUBLISHED_ID', $result['external_id']);
        $this->assertSame(2, $publishAttempts, 'La publication doit être réessayée exactement une fois');
        $this->assertSame(2, $parentAttempts, 'Un nouveau conteneur parent doit être assemblé au 2e essai');
        // 2 images × 2 tentatives = 4 enfants recréés (rebuild complet).
        $this->assertSame(4, $childSeq);
    }

    public function test_carousel_retries_child_creation_on_transient_error(): void
    {
        $childPosts = 0;

        Http::fake(function (Request $request) use (&$childPosts) {
            $data = $request->data();

            if ($request->method() === 'GET') {
                return Http::response(['status' => 'FINISHED', 'permalink' => 'https://threads.net/p/1']);
            }

            if (str_contains($request->url(), '/threads_publish')) {
                return Http::response(['id' => 'PUBLISHED_ID']);
            }

            if (($data['media_type'] ?? null) === 'CAROUSEL') {
                return Http::response(['id' => 'CAROUSEL_ID']);
            }

            // 1er POST enfant : erreur transitoire (code=1 / HTTP 500), puis OK.
            $childPosts++;
            if ($childPosts === 1) {
                return Http::response(['error' => ['code' => 1, 'message' => 'An unknown error occurred']], 500);
            }

            return Http::response(['id' => 'child_'.$childPosts]);
        });

        $result = (new ThreadsAdapter)->publish($this->account(), 'Légende', $this->media());

        $this->assertTrue($result['success'], 'Une erreur transitoire code=1 doit être réessayée');
        $this->assertSame('PUBLISHED_ID', $result['external_id']);
        $this->assertGreaterThanOrEqual(3, $childPosts, 'Le 1er enfant doit être retenté puis les 2 enfants créés');
    }
}
