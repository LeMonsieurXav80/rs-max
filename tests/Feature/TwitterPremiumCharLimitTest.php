<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\Setting;
use App\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La limite de caracteres sur X depend du COMPTE, pas de la plateforme :
 * POST /2/tweets accepte 25 000 caracteres depuis aout 2024, mais uniquement
 * si le compte qui publie est abonne (sinon erreur 111 « Tweet text is too
 * long »). `subscription_type` (Basic | Premium | PremiumPlus), renvoye par
 * GET /2/users/me en contexte utilisateur, est ce qui tranche.
 */
class TwitterPremiumCharLimitTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $slug, ?string $subscription): SocialAccount
    {
        $platform = new Platform;
        $platform->id = 1;
        $platform->slug = $slug;

        $account = new SocialAccount;
        $account->id = 1;
        $account->subscription_type = $subscription;
        $account->setRelation('platform', $platform);

        return $account;
    }

    public function test_un_compte_x_gratuit_reste_plafonne_a_280(): void
    {
        $this->assertSame(280, $this->account('twitter', null)->charLimit(280));
        $this->assertSame(280, $this->account('twitter', 'Basic')->charLimit(280));
    }

    public function test_un_compte_x_premium_debloque_la_limite_longue(): void
    {
        $this->assertSame(25000, $this->account('twitter', 'Premium')->charLimit(280));
        $this->assertSame(25000, $this->account('twitter', 'PremiumPlus')->charLimit(280));
    }

    public function test_la_limite_premium_reste_configurable(): void
    {
        Setting::set('platform_char_limit_twitter_premium', 4000);

        $this->assertSame(4000, $this->account('twitter', 'Premium')->charLimit(280));
    }

    public function test_les_autres_plateformes_ne_sont_pas_affectees(): void
    {
        // Un abonnement payant ailleurs ne doit pas retomber sur la cle twitter_premium.
        $this->assertSame(500, $this->account('threads', 'Premium')->charLimit(500));
    }
}
