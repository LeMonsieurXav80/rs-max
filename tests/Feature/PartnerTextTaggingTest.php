<?php

namespace Tests\Feature;

use App\Models\ExternalPost;
use App\Models\MediaFile;
use App\Models\Partner;
use App\Models\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Import\PostAdoptionService;
use App\Services\PartnerTagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Taguage d'apres le TEXTE d'une publication.
 *
 * Comble le trou de l'heritage par les photos : une publication faite
 * nativement, avec une image inconnue de la mediatheque, n'a rien a heriter
 * alors que sa legende cite la marque.
 *
 * Le contrat tient en deux points : on tague ce qui est nomme, et RIEN
 * d'autre — une publication sans marque ne doit jamais en recevoir une.
 */
class PartnerTextTaggingTest extends TestCase
{
    use RefreshDatabase;

    private Partner $marque;

    private Platform $platform;

    private SocialAccount $account;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marque = Partner::create(['name' => 'Coca-Cola', 'slug' => Partner::slugFor('Coca-Cola')]);

        $this->platform = Platform::firstOrCreate(
            ['slug' => 'instagram'],
            ['name' => 'Instagram', 'auth_type' => 'oauth2']
        );

        $this->account = SocialAccount::create([
            'platform_id' => $this->platform->id,
            'platform_account_id' => 'acc-ig',
            'name' => 'Compte',
            'credentials' => ['access_token' => 't'],
        ]);

        $this->user = User::factory()->create(['role' => 'manager']);
        $this->user->socialAccounts()->attach($this->account->id, ['is_active' => true]);
    }

    private function tag(string $text): array
    {
        $post = Post::create([
            'user_id' => $this->user->id,
            'content_fr' => $text,
            'status' => 'published',
            'published_at' => now(),
        ]);

        app(PartnerTagService::class)->tagFromText($post, $text);

        return $post->fresh()->partners->pluck('name')->all();
    }

    public function test_la_marque_citee_dans_le_texte_est_taguee(): void
    {
        $this->assertSame(['Coca-Cola'], $this->tag('Une pause fraicheur avec Coca-Cola ce midi'));
    }

    public function test_la_casse_et_les_accents_ne_comptent_pas(): void
    {
        $this->assertSame(['Coca-Cola'], $this->tag('On a teste le COCA COLA nouvelle formule'));
    }

    public function test_le_hashtag_colle_est_reconnu(): void
    {
        $this->assertSame(['Coca-Cola'], $this->tag('Belle journee au soleil #cocacola #ete'));
    }

    public function test_une_publication_sans_marque_ne_recoit_rien(): void
    {
        $this->assertSame([], $this->tag('Balade en foret ce matin, la lumiere etait parfaite'));
    }

    public function test_un_nom_trop_court_ne_declenche_rien(): void
    {
        // Deux lettres ramasseraient la moitie des textes.
        Partner::create(['name' => 'Oz', 'slug' => Partner::slugFor('Oz')]);

        $this->assertSame([], $this->tag('On a mange des oz... enfin, on a bien mange'));
    }

    public function test_le_tag_du_texte_survit_au_recalcul_depuis_les_photos(): void
    {
        $post = Post::create([
            'user_id' => $this->user->id,
            'content_fr' => 'Merci Coca-Cola pour cette journee',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $service = app(PartnerTagService::class);
        $service->tagFromText($post, $post->content_fr);

        // Le recalcul 'auto' repart des photos : sans ce garde-fou, son
        // `sync()` effacerait tout ce qu'il ne reconstruit pas.
        $service->syncPost($post->fresh());

        $this->assertSame(['Coca-Cola'], $post->fresh()->partners->pluck('name')->all());
        $this->assertSame(
            PartnerTagService::SOURCE_TEXT,
            $post->fresh()->partners->first()->pivot->source
        );
    }

    public function test_l_adoption_tague_d_apres_le_texte_du_reseau(): void
    {
        $externe = ExternalPost::create([
            'social_account_id' => $this->account->id,
            'platform_id' => $this->platform->id,
            'external_id' => 'ig-1',
            'content' => 'Session photo du jour, merci Coca-Cola pour les rafraichissements',
            'published_at' => now()->subDay(),
        ]);

        $result = app(PostAdoptionService::class)->adopt(collect([$externe]), $this->user);

        $this->assertSame(['Coca-Cola'], $result['post']->fresh()->partners->pluck('name')->all());
    }

    public function test_la_photo_prime_quand_les_deux_designent_la_meme_marque(): void
    {
        $media = MediaFile::create([
            'filename' => 'photo.jpg',
            'path' => 'media/photo.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1000,
        ]);
        $media->partners()->attach($this->marque->id);

        $post = Post::create([
            'user_id' => $this->user->id,
            'content_fr' => 'Merci Coca-Cola',
            'media' => [['url' => '/media/photo.jpg', 'mimetype' => 'image/jpeg']],
            'status' => 'published',
            'published_at' => now(),
        ]);

        $service = app(PartnerTagService::class);
        $service->syncPost($post);
        $service->tagFromText($post->fresh(), $post->content_fr);

        // Une seule ligne de pivot, pas deux : la marque n'est pas comptee double.
        $this->assertCount(1, $post->fresh()->partners);
    }

    public function test_un_humain_peut_retirer_une_marque_deduite_a_tort(): void
    {
        $post = Post::create([
            'user_id' => $this->user->id,
            'content_fr' => 'Merci Coca-Cola pour cette journee',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $service = app(PartnerTagService::class);
        $service->tagFromText($post, $post->content_fr);

        // Le panneau presente les tags du texte pre-coches : les decocher
        // revient a soumettre une liste manuelle qui ne les contient pas.
        $service->syncPost($post->fresh(), manualIds: []);

        $this->assertCount(0, $post->fresh()->partners);
    }

    public function test_un_humain_qui_confirme_transforme_le_tag_en_manuel(): void
    {
        $post = Post::create([
            'user_id' => $this->user->id,
            'content_fr' => 'Merci Coca-Cola pour cette journee',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $service = app(PartnerTagService::class);
        $service->tagFromText($post, $post->content_fr);
        $service->syncPost($post->fresh(), manualIds: [$this->marque->id]);

        $this->assertSame('manual', $post->fresh()->partners->first()->pivot->source);
    }
}
