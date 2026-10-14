<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Services\Adapters\InstagramAdapter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Publication vidéo Instagram : Meta doit venir lire l'URL lui-même.
 *
 * Aucune vidéo n'avait jamais été publiée sur Instagram : l'adapter poussait les
 * octets vers rupload.facebook.com, qui répond `ProcessingFailedError` sur nos
 * verticales 1080x1920 d'environ 80 s — le format Reels standard. Mesuré en
 * production : 1080x1920 passe à 40 s mais pas à 82 s, et 82 s passe en
 * 720x1280 ; ni le poids, ni le compte, ni le token, ni la version d'API
 * n'entrent en jeu.
 *
 * Le flux `video_url` avait été abandonné sur une lecture inversée de l'erreur
 * 2207076 : elle signalait que Meta n'arrivait pas à TÉLÉCHARGER le fichier (la
 * route média exige une URL signée), pas que l'API était en panne.
 *
 * On verrouille donc l'ordre : `video_url` d'abord, octets en secours.
 */
class InstagramVideoPublishTest extends TestCase
{
    private function adapter(): InstagramAdapter
    {
        return new class extends InstagramAdapter
        {
            // Le polling réel attend 10 s entre deux tours.
            protected function pause(int $seconds): void {}
        };
    }

    private function account(): SocialAccount
    {
        $account = new SocialAccount;
        $account->id = 1;
        $account->credentials = [
            'account_id' => 'IG123',
            'access_token' => 'PAGE_TOKEN',
            'user_access_token' => 'USER_TOKEN',
        ];

        return $account;
    }

    private function video(): array
    {
        return [[
            'type' => 'video',
            'mimetype' => 'video/mp4',
            'url' => 'https://exemple.test/media/reel.mp4?signature=abc',
        ]];
    }

    public function test_une_video_part_par_video_url_et_non_par_upload_d_octets(): void
    {
        $ruploadTouche = false;

        Http::fake(function (Request $request) use (&$ruploadTouche) {
            $url = $request->url();

            if (str_contains($url, 'rupload.facebook.com')) {
                $ruploadTouche = true;

                return Http::response(['success' => true], 200);
            }

            if (str_contains($url, '/media_publish')) {
                return Http::response(['id' => 'POST_PUBLIE'], 200);
            }

            if (str_contains($url, '/media')) {
                return Http::response(['id' => 'CONTENEUR1'], 200);
            }

            // Polling de l'état du conteneur.
            return Http::response(['status_code' => 'FINISHED'], 200);
        });

        $resultat = $this->adapter()->publish($this->account(), 'Légende', $this->video());

        $this->assertTrue($resultat['success'], 'La publication vidéo aurait dû réussir.');
        $this->assertSame('POST_PUBLIE', $resultat['external_id']);
        $this->assertFalse($ruploadTouche, 'rupload ne doit pas être sollicité quand video_url suffit.');

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/IG123/media')
                && ($request->data()['video_url'] ?? null) === 'https://exemple.test/media/reel.mp4?signature=abc'
                && ! isset($request->data()['upload_type']);
        });
    }

    public function test_l_upload_d_octets_reste_en_secours_si_meta_refuse_l_url(): void
    {
        $ruploadTouche = false;

        Http::fake(function (Request $request) use (&$ruploadTouche) {
            $url = $request->url();

            if (str_contains($url, 'rupload.facebook.com')) {
                $ruploadTouche = true;

                return Http::response(['success' => true], 200);
            }

            if (str_contains($url, '/media_publish')) {
                return Http::response(['id' => 'POST_SECOURS'], 200);
            }

            if (str_contains($url, '/media')) {
                $data = $request->data();

                // Meta refuse la création par URL, accepte la session resumable.
                if (isset($data['video_url'])) {
                    return Http::response([
                        'error' => ['message' => 'Media download failed', 'code' => 2207076],
                    ], 400);
                }

                return Http::response([
                    'id' => 'CONTENEUR2',
                    'uri' => 'https://rupload.facebook.com/ig-api-upload/v26.0/CONTENEUR2',
                ], 200);
            }

            return Http::response(['status_code' => 'FINISHED'], 200);
        });

        $resultat = $this->adapter()->publish($this->account(), 'Légende', $this->video());

        $this->assertTrue($resultat['success'], 'Le secours par octets aurait dû aboutir.');
        $this->assertSame('POST_SECOURS', $resultat['external_id']);
        $this->assertTrue($ruploadTouche, 'Le secours par octets doit être emprunté quand video_url est refusé.');
    }

    public function test_une_url_relative_ne_part_pas_en_video_url(): void
    {
        // Meta ne peut rien télécharger d'un chemin relatif : c'est exactement
        // ce qui avait produit les 2207076 et fait condamner ce flux à tort.
        $urlEnvoyeeAMeta = null;

        Http::fake(function (Request $request) use (&$urlEnvoyeeAMeta) {
            $url = $request->url();

            if (str_contains($url, 'rupload.facebook.com')) {
                return Http::response(['success' => true], 200);
            }

            if (str_contains($url, '/media_publish')) {
                return Http::response(['id' => 'POST_RELATIF'], 200);
            }

            if (str_contains($url, '/media')) {
                if (isset($request->data()['video_url'])) {
                    $urlEnvoyeeAMeta = $request->data()['video_url'];
                }

                return Http::response([
                    'id' => 'CONTENEUR3',
                    'uri' => 'https://rupload.facebook.com/ig-api-upload/v26.0/CONTENEUR3',
                ], 200);
            }

            return Http::response(['status_code' => 'FINISHED'], 200);
        });

        $media = [[
            'type' => 'video',
            'mimetype' => 'video/mp4',
            'url' => '/media/reel.mp4',
        ]];

        $this->adapter()->publish($this->account(), 'Légende', $media);

        $this->assertNull(
            $urlEnvoyeeAMeta,
            'Un chemin relatif ne doit jamais être proposé à Meta comme video_url.'
        );
    }
}
