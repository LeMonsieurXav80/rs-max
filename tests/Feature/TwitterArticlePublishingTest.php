<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\SocialAccount;
use App\Services\Adapters\TwitterAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Le chemin Article passe par TwitterAdapter::publish() : un `article_title`
 * non vide bascule sur /2/articles au lieu de /2/tweets. Tout le reste de la
 * chaîne (external_id, statut, tracking media) est inchangé, puisque publish
 * renvoie un post_id de même nature qu'un tweet.
 */
class TwitterArticlePublishingTest extends TestCase
{
    use RefreshDatabase;

    private function account(?string $subscription = 'Premium'): SocialAccount
    {
        $platform = new Platform;
        $platform->id = 1;
        $platform->slug = 'twitter';

        $account = new SocialAccount;
        $account->id = 1;
        $account->name = 'Compte X';
        $account->subscription_type = $subscription;
        $account->credentials = [
            'api_key' => 'ck',
            'api_secret' => 'cs',
            'access_token' => 'at',
            'access_token_secret' => 'ats',
        ];
        $account->setRelation('platform', $platform);

        return $account;
    }

    public function test_sans_titre_on_publie_un_tweet_normal(): void
    {
        Http::fake([
            'api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '111']]),
        ]);

        $result = (new TwitterAdapter)->publish($this->account(), 'Un tweet court.', null, [
            'article_title' => null,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('111', $result['external_id']);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.twitter.com/2/tweets');
    }

    public function test_un_titre_rempli_bascule_sur_les_endpoints_articles(): void
    {
        Http::fake([
            'api.x.com/2/articles/draft' => Http::response(['data' => ['id' => '777']]),
            'api.x.com/2/articles/777/publish' => Http::response(['data' => ['post_id' => '888']]),
        ]);

        $result = (new TwitterAdapter)->publish($this->account(), "Corps.\n\nSuite.", null, [
            'article_title' => 'Mon article',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('888', $result['external_id']);

        // Aucun appel à /2/tweets : c'est bien l'un OU l'autre.
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/2/tweets'));
    }

    public function test_un_titre_vide_ou_blanc_ne_bascule_pas(): void
    {
        Http::fake([
            'api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '111']]),
        ]);

        (new TwitterAdapter)->publish($this->account(), 'Texte.', null, ['article_title' => '   ']);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/articles'));
    }

    public function test_un_compte_non_abonne_est_refuse_avant_tout_appel(): void
    {
        Http::fake();

        $result = (new TwitterAdapter)->publish($this->account('Basic'), 'Corps.', null, [
            'article_title' => 'Mon article',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('abonné', $result['error']);
        Http::assertNothingSent();
    }

    public function test_un_brouillon_non_publie_est_signale_avec_son_id(): void
    {
        // Le brouillon existe côté X : il doit rester récupérable à la main,
        // donc son id figure dans le message d'erreur.
        Http::fake([
            'api.x.com/2/articles/draft' => Http::response(['data' => ['id' => '777']]),
            'api.x.com/2/articles/777/publish' => Http::response(['detail' => 'Rate limited'], 429),
        ]);

        $result = (new TwitterAdapter)->publish($this->account(), 'Corps.', null, [
            'article_title' => 'Mon article',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('777', $result['error']);
        $this->assertStringContainsString('Rate limited', $result['error']);
    }
}
