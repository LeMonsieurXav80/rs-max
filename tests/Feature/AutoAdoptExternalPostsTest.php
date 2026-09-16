<?php

namespace Tests\Feature;

use App\Models\ExternalPost;
use App\Models\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adoption automatique : ce qui doit fusionner tout seul, et surtout ce qui ne
 * doit PAS l'etre. Une fusion a tort est le seul defaut qui se repare mal —
 * elle entraine les stats, les tags partenaires et le comptage d'usage des
 * photos avec elle.
 */
class AutoAdoptExternalPostsTest extends TestCase
{
    use RefreshDatabase;

    private array $platforms = [];

    private array $accounts = [];

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'bluesky' => 'Bluesky'] as $slug => $name) {
            // Certains reseaux arrivent deja par les migrations.
            $platform = Platform::firstOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'auth_type' => 'oauth2']
            );

            $this->platforms[$slug] = $platform;
            $this->accounts[$slug] = SocialAccount::create([
                'platform_id' => $platform->id,
                'platform_account_id' => 'acc-'.$slug,
                'name' => 'Compte '.$name,
                'credentials' => ['access_token' => 't'],
            ]);
        }

        $this->owner = User::factory()->create(['role' => 'manager']);

        foreach ($this->accounts as $account) {
            $this->owner->socialAccounts()->attach($account->id, ['is_active' => true]);
        }
    }

    private function externalPost(string $slug, string $content, ?\DateTimeInterface $at = null): ExternalPost
    {
        return ExternalPost::create([
            'social_account_id' => $this->accounts[$slug]->id,
            'platform_id' => $this->platforms[$slug]->id,
            'external_id' => $slug.'-'.uniqid(),
            'content' => $content,
            'published_at' => $at ?? now()->subDay(),
        ]);
    }

    public function test_les_jumelles_de_deux_reseaux_ne_font_qu_une_publication(): void
    {
        $texte = 'Le nouveau parcours en foret ouvre ce week-end, venez avec de bonnes chaussures';

        $this->externalPost('facebook', $texte, now()->subDay());
        $this->externalPost('instagram', $texte.' #foret #rando', now()->subDay()->addMinutes(4));

        $this->artisan('external:auto-adopt --commit')->assertSuccessful();

        $this->assertSame(1, Post::count());

        $post = Post::first();
        $this->assertSame('native', $post->source_type);
        $this->assertSame(2, $post->postPlatforms()->count());
        $this->assertSame(2, ExternalPost::whereNotNull('adopted_post_id')->count());
    }

    public function test_deux_sujets_differents_restent_deux_publications(): void
    {
        $this->externalPost('facebook', 'Le nouveau parcours en foret ouvre ce week-end prochain', now()->subDay());
        $this->externalPost('instagram', 'Recette de la tarte aux myrtilles de notre chef pour l ete', now()->subDay()->addMinutes(3));

        $this->artisan('external:auto-adopt --commit')->assertSuccessful();

        $this->assertSame(2, Post::count());
        $this->assertSame(1, Post::first()->postPlatforms()->count());
    }

    public function test_deux_textes_proches_sans_certitude_restent_au_flux_manuel(): void
    {
        // Meme ouverture, suite differente : de quoi soupconner une jumelle,
        // pas de quoi trancher.
        $this->externalPost('facebook', 'Nos horaires changent des lundi prochain, notez le bien', now()->subDay());
        $this->externalPost('instagram', 'Nos horaires changent des lundi, et la boutique ferme le dimanche entier desormais', now()->subDay()->addMinutes(2));

        $this->artisan('external:auto-adopt --commit')->assertSuccessful();

        $this->assertSame(0, Post::count());
        $this->assertSame(0, ExternalPost::whereNotNull('adopted_post_id')->count());

        // Le rapprochement est quand meme ecrit : il dit quelles cartes vont
        // ensemble, et servira a les presenter cote a cote dans le flux.
        $this->assertSame(2, ExternalPost::whereNotNull('group_key')->count());
    }

    public function test_une_publication_trop_fraiche_attend_ses_jumelles(): void
    {
        $this->externalPost('facebook', 'Publication toute fraiche qui vient a peine de partir en ligne', now()->subMinutes(10));

        $this->artisan('external:auto-adopt --commit')->assertSuccessful();

        $this->assertSame(0, Post::count());
    }

    public function test_sans_commit_rien_n_est_ecrit(): void
    {
        $texte = 'Le nouveau parcours en foret ouvre ce week-end, venez avec de bonnes chaussures';

        $this->externalPost('facebook', $texte, now()->subDay());
        $this->externalPost('instagram', $texte, now()->subDay()->addMinutes(4));

        $this->artisan('external:auto-adopt')->assertSuccessful();

        $this->assertSame(0, Post::count());
        $this->assertSame(0, ExternalPost::whereNotNull('group_key')->count());
    }

    public function test_une_publication_deja_emise_par_rs_max_n_est_pas_readoptee(): void
    {
        $externe = $this->externalPost('facebook', 'Texte publie depuis RS-Max il y a deux jours de cela', now()->subDays(2));

        $post = Post::create([
            'user_id' => $this->owner->id,
            'content_fr' => 'Texte publie depuis RS-Max il y a deux jours de cela',
            'status' => 'published',
            'published_at' => now()->subDays(2),
        ]);

        $post->postPlatforms()->create([
            'social_account_id' => $this->accounts['facebook']->id,
            'platform_id' => $this->platforms['facebook']->id,
            'status' => 'published',
            'external_id' => $externe->external_id,
            'published_at' => now()->subDays(2),
        ]);

        $this->artisan('external:auto-adopt --commit')->assertSuccessful();

        $this->assertSame(1, Post::count());
    }

    public function test_le_bruit_ecarte_a_l_import_ne_remonte_pas(): void
    {
        $this->externalPost('facebook', 'Un partage de la publication de quelqu un d autre, sans interet', now()->subDay())
            ->update([
                'ignored_at' => now(),
                'ignored_reason' => ExternalPost::IGNORED_AUTO_NOISE,
            ]);

        $this->artisan('external:auto-adopt --commit')->assertSuccessful();

        $this->assertSame(0, Post::count());
    }

    public function test_les_metriques_de_l_import_suivent_la_publication(): void
    {
        $this->externalPost('facebook', 'Une publication avec ses chiffres deja connus', now()->subDay())
            ->update([
                'metrics' => ['views' => 1200, 'likes' => 42, 'comments' => 3, 'shares' => 7],
                'metrics_synced_at' => now()->subHour(),
            ]);

        $this->artisan('external:auto-adopt --commit')->assertSuccessful();

        $platform = Post::first()->postPlatforms()->first();

        // Sans ce report, la publication s'afficherait vide alors que l'import
        // avait deja paye l'appel.
        $this->assertSame(1200, $platform->metrics['views']);
        $this->assertSame(42, $platform->metrics['likes']);
        $this->assertNotNull($platform->metrics_synced_at);
    }
}
