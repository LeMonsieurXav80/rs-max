<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\SocialAccount;
use App\Services\Twitter\TwitterArticleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Articles X : POST /2/articles/draft puis POST /2/articles/{id}/publish.
 * Le corps est du DraftJS alors que rs-max ne manipule que du texte plat,
 * la conversion est donc le point sensible.
 */
class TwitterArticleServiceTest extends TestCase
{
    use RefreshDatabase;

    private function account(): SocialAccount
    {
        $platform = new Platform;
        $platform->id = 1;
        $platform->slug = 'twitter';

        $account = new SocialAccount;
        $account->id = 1;
        $account->name = 'Compte de test';
        $account->subscription_type = 'Premium';
        $account->credentials = [
            'api_key' => 'ck',
            'api_secret' => 'cs',
            'access_token' => 'at',
            'access_token_secret' => 'ats',
        ];
        $account->setRelation('platform', $platform);

        return $account;
    }

    private function service(): TwitterArticleService
    {
        return new TwitterArticleService;
    }

    public function test_le_texte_plat_devient_des_blocs_draftjs(): void
    {
        $state = $this->service()->toContentState("Premier paragraphe.\n\nSecond paragraphe.");

        $this->assertSame([], $state['entities']);
        $this->assertSame([
            ['text' => 'Premier paragraphe.', 'type' => 'unstyled'],
            ['text' => 'Second paragraphe.', 'type' => 'unstyled'],
        ], $state['blocks']);
    }

    public function test_le_markdown_produit_par_l_ia_est_reconnu(): void
    {
        $body = "# Titre\n## Sous-titre\n- point un\n2. point deux\n> citation";

        $this->assertSame([
            ['text' => 'Titre', 'type' => 'header-one'],
            ['text' => 'Sous-titre', 'type' => 'header-two'],
            ['text' => 'point un', 'type' => 'unordered-list-item'],
            ['text' => 'point deux', 'type' => 'ordered-list-item'],
            ['text' => 'citation', 'type' => 'blockquote'],
        ], $this->service()->toContentState($body)['blocks']);
    }

    public function test_un_corps_vide_garde_un_bloc(): void
    {
        // Un content_state sans bloc serait refusé par l'API.
        $this->assertSame(
            [['text' => '', 'type' => 'unstyled']],
            $this->service()->toContentState("\n \n")['blocks']
        );
    }

    public function test_le_brouillon_envoie_titre_et_content_state(): void
    {
        Http::fake([
            'api.x.com/2/articles/draft' => Http::response(['data' => ['id' => '999', 'title' => 'Mon titre']]),
        ]);

        $result = $this->service()->createDraft($this->account(), 'Mon titre', 'Corps.');

        $this->assertTrue($result['success']);
        $this->assertSame('999', $result['article_id']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.x.com/2/articles/draft'
                && $request['title'] === 'Mon titre'
                && $request['content_state']['blocks'][0]['text'] === 'Corps.'
                && str_starts_with($request->header('Authorization')[0], 'OAuth ');
        });
    }

    public function test_la_publication_renvoie_le_post_id(): void
    {
        Http::fake([
            'api.x.com/2/articles/999/publish' => Http::response(['data' => ['post_id' => '12345']]),
        ]);

        $result = $this->service()->publishDraft($this->account(), '999');

        $this->assertTrue($result['success']);
        $this->assertSame('12345', $result['post_id']);
    }

    public function test_un_refus_de_l_api_remonte_le_message(): void
    {
        // Le cas attendu si le palier d'API ne donne pas accès aux Articles.
        Http::fake([
            'api.x.com/2/articles/draft' => Http::response(['detail' => 'Client not enrolled'], 403),
        ]);

        $result = $this->service()->createDraft($this->account(), 'Titre', 'Corps.');

        $this->assertFalse($result['success']);
        $this->assertSame('Client not enrolled', $result['error']);
    }

    public function test_un_compte_sans_credentials_ne_declenche_aucun_appel(): void
    {
        Http::fake();

        $account = $this->account();
        $account->credentials = ['api_key' => 'ck'];

        $result = $this->service()->createDraft($account, 'Titre', 'Corps.');

        $this->assertFalse($result['success']);
        Http::assertNothingSent();
    }
}
