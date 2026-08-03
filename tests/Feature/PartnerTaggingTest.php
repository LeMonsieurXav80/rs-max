<?php

namespace Tests\Feature;

use App\Models\MediaFile;
use App\Models\Partner;
use App\Models\Post;
use App\Models\User;
use App\Services\PartnerTagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Couvre le tag partenaire : référentiel de marques, report automatique des
 * photos vers les publications, préservation des tags posés à la main, et les
 * points d'entrée API (CRUD + liste des publications d'un partenaire).
 */
class PartnerTaggingTest extends TestCase
{
    use RefreshDatabase;

    private function media(string $name): MediaFile
    {
        return MediaFile::create([
            'filename' => $name.'.jpg',
            'original_name' => $name.'.jpg',
            'mime_type' => 'image/jpeg',
            'intimacy_level' => 'public',
        ]);
    }

    private function makePost(User $user, array $media = []): Post
    {
        return Post::create([
            'user_id' => $user->id,
            'content_fr' => 'contenu de test',
            'status' => 'draft',
            'media' => $media ?: null,
        ]);
    }

    public function test_les_noms_de_marque_convergent_sur_une_seule_fiche(): void
    {
        $service = app(PartnerTagService::class);
        $media = $this->media('photo-a');

        $names = $service->syncMediaNames($media, ['Coca-Cola', 'coca cola ', 'COCA-COLA']);

        $this->assertSame(['Coca-Cola'], $names);
        $this->assertSame(1, Partner::count());
        // Le miroir dénormalisé suit le nom canonique de la fiche.
        $this->assertSame(['Coca-Cola'], $media->fresh()->brands);
    }

    public function test_une_publication_herite_des_partenaires_de_ses_photos(): void
    {
        $service = app(PartnerTagService::class);
        $user = User::factory()->create();

        $media = $this->media('photo-b');
        $service->syncMediaNames($media, ['Nike']);

        // Format historique de posts.media : URL seule, sans id de MediaFile.
        $post = $this->makePost($user, [['url' => $media->url]]);
        $service->syncPost($post, []);

        $tags = $post->partners()->get();
        $this->assertCount(1, $tags);
        $this->assertSame('Nike', $tags->first()->name);
        $this->assertSame('auto', $tags->first()->pivot->source);
    }

    public function test_les_tags_manuels_survivent_au_recalcul_et_les_auto_suivent_les_photos(): void
    {
        $service = app(PartnerTagService::class);
        $user = User::factory()->create();

        $media = $this->media('photo-c');
        $service->syncMediaNames($media, ['Nike']);
        $manuel = Partner::create(['name' => 'Decathlon']);

        $post = $this->makePost($user, [['url' => $media->url]]);
        $service->syncPost($post, [$manuel->id]);

        $this->assertSame(
            ['Decathlon' => 'manual', 'Nike' => 'auto'],
            $post->partners()->get()->mapWithKeys(fn ($p) => [$p->name => $p->pivot->source])->sortKeys()->all()
        );

        // Re-enregistrement sans liste explicite : les manuels sont conservés.
        $service->syncPost($post, null);
        $this->assertSame(2, $post->partners()->count());

        // La photo retirée emporte son tag auto, pas le tag manuel.
        $post->update(['media' => null]);
        $service->syncPost($post, null);

        $restants = $post->partners()->get();
        $this->assertCount(1, $restants);
        $this->assertSame('Decathlon', $restants->first()->name);
    }

    public function test_le_formulaire_de_post_enregistre_les_tags_auto_et_manuels(): void
    {
        $user = User::factory()->create();
        $media = $this->media('photo-d');
        app(PartnerTagService::class)->syncMediaNames($media, ['Nike']);
        $manuel = Partner::create(['name' => 'Decathlon']);

        $account = $this->socialAccountFor($user);

        $this->actingAs($user)->post(route('posts.store'), [
            'content_fr' => 'un post avec partenaires',
            'status' => 'draft',
            'media' => [json_encode(['url' => $media->url])],
            'partners' => [$manuel->id],
            'accounts' => [$account->id],
        ])->assertRedirect();

        $post = Post::latest('id')->first();
        $this->assertSame(
            ['Decathlon' => 'manual', 'Nike' => 'auto'],
            $post->partners()->get()->mapWithKeys(fn ($p) => [$p->name => $p->pivot->source])->sortKeys()->all()
        );
    }

    public function test_api_crud_et_liste_des_publications_d_un_partenaire(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/partners', ['name' => 'Nike'])
            ->assertCreated()
            ->assertJsonPath('partner.slug', 'nike');

        $partner = Partner::firstWhere('slug', 'nike');

        $this->getJson('/api/partners')->assertOk()->assertJsonCount(1, 'partners');

        // Une publication taguée doit ressortir dans le compte rendu.
        $media = $this->media('photo-e');
        app(PartnerTagService::class)->syncMediaNames($media, ['Nike']);
        $post = $this->makePost($user, [['url' => $media->url]]);
        app(PartnerTagService::class)->syncPost($post, []);

        $this->getJson("/api/partners/{$partner->id}/posts")
            ->assertOk()
            ->assertJsonCount(1, 'posts')
            ->assertJsonPath('posts.0.id', $post->id)
            ->assertJsonPath('posts.0.tag_source', 'auto');

        // Filtre sur l'origine du tag : aucun tag manuel ici.
        $this->getJson("/api/partners/{$partner->id}/posts?source=manual")
            ->assertOk()
            ->assertJsonCount(0, 'posts');

        // Renommer met à jour le miroir `brands` des photos taguées.
        $this->putJson("/api/partners/{$partner->id}", ['name' => 'Nike France'])->assertOk();
        $this->assertSame(['Nike France'], $media->fresh()->brands);

        $this->deleteJson("/api/partners/{$partner->id}")->assertOk();
        $this->assertSame([], $media->fresh()->brands);
        $this->assertSame(0, $post->partners()->count());
    }

    public function test_la_page_de_compte_rendu_est_reservee_aux_managers(): void
    {
        $partner = Partner::create(['name' => 'Nike']);

        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->get(route('partners.posts', $partner))
            ->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'manager']))
            ->get(route('partners.posts', $partner))
            ->assertOk();
    }

    public function test_un_post_deja_publie_reste_taguable_retroactivement(): void
    {
        $user = User::factory()->create();
        $partner = Partner::create(['name' => 'Nike']);

        $post = $this->makePost($user);
        $post->update(['status' => 'published', 'published_at' => now()]);

        // L'édition classique reste fermée sur un post publié…
        $this->actingAs($user)
            ->put(route('posts.update', $post->id), [
                'content_fr' => 'tentative de modif',
                'status' => 'draft',
                'accounts' => [1],
            ])
            ->assertSessionHasErrors('status');

        // …mais le tag partenaire, lui, passe.
        $this->actingAs($user)
            ->put(route('posts.partners.update', $post->id), ['partners' => [$partner->id]])
            ->assertRedirect(route('posts.show', $post->id));

        $this->assertSame('published', $post->fresh()->status);
        $this->assertSame(['Nike' => 'manual'], $post->partners()->get()
            ->mapWithKeys(fn ($p) => [$p->name => $p->pivot->source])->all());

        // Idem par l'API, alors que PUT /api/posts/{id} refuse un post publié.
        Sanctum::actingAs($user);
        $this->putJson("/api/posts/{$post->id}", ['content_fr' => 'x'])->assertStatus(422);
        $this->putJson("/api/posts/{$post->id}/partners", ['partners' => []])
            ->assertOk()
            ->assertJsonCount(0, 'partners');
        $this->assertSame(0, $post->partners()->count());
    }

    public function test_un_fil_herite_des_partenaires_de_ses_segments_et_reste_taguable(): void
    {
        $user = User::factory()->create();
        $media = $this->media('photo-g');
        app(PartnerTagService::class)->syncMediaNames($media, ['Nike']);
        $manuel = Partner::create(['name' => 'Decathlon']);

        $thread = \App\Models\Thread::create([
            'user_id' => $user->id,
            'title' => 'Fil de test',
            'status' => 'published',
            'published_at' => now(),
        ]);
        \App\Models\ThreadSegment::create([
            'thread_id' => $thread->id,
            'position' => 1,
            'content_fr' => 'segment avec photo',
            'media' => [['url' => $media->url]],
        ]);

        app(PartnerTagService::class)->syncThread($thread, []);
        $this->assertSame(['Nike' => 'auto'], $thread->partners()->get()
            ->mapWithKeys(fn ($p) => [$p->name => $p->pivot->source])->all());

        // Tag manuel rétroactif sur un fil déjà publié.
        $this->actingAs($user)
            ->put(route('threads.partners.update', $thread), ['partners' => [$manuel->id]])
            ->assertRedirect(route('threads.show', $thread));

        $this->assertSame(
            ['Decathlon' => 'manual', 'Nike' => 'auto'],
            $thread->partners()->get()->mapWithKeys(fn ($p) => [$p->name => $p->pivot->source])->sortKeys()->all()
        );

        // Le fil ressort dans le compte rendu du partenaire.
        Sanctum::actingAs($user);
        $nike = Partner::firstWhere('slug', 'nike');
        $this->getJson("/api/partners/{$nike->id}/threads")
            ->assertOk()
            ->assertJsonCount(1, 'threads')
            ->assertJsonPath('threads.0.id', $thread->id)
            ->assertJsonPath('threads.0.tag_source', 'auto');
    }

    public function test_le_crud_web_des_partenaires(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);

        $this->actingAs($manager)->get(route('partners.index'))->assertOk();
        $this->actingAs($manager)->get(route('partners.create'))->assertOk();

        $this->actingAs($manager)
            ->post(route('partners.store'), ['name' => 'Nike', 'is_active' => '1'])
            ->assertRedirect(route('partners.index'));

        $partner = Partner::firstWhere('slug', 'nike');
        $this->assertSame('manual', $partner->origin);

        $this->actingAs($manager)->get(route('partners.edit', $partner))->assertOk();

        $this->actingAs($manager)
            ->put(route('partners.update', $partner), ['name' => 'Nike France'])
            ->assertRedirect(route('partners.index'));
        $this->assertSame('nike-france', $partner->fresh()->slug);

        // Un simple utilisateur n'accède pas au référentiel, mais lit la liste des options.
        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->get(route('partners.index'))
            ->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->getJson(route('partners.options'))
            ->assertOk()
            ->assertJsonPath('partners.0.name', 'Nike France');
    }

    public function test_la_mediatheque_tague_les_photos_via_le_referentiel(): void
    {
        $user = User::factory()->create();
        $media = $this->media('photo-f');

        // Édition unitaire : les marques saisies deviennent des fiches partenaires.
        $this->actingAs($user)
            ->patchJson(route('media.updateDetails', $media), ['brands' => ['Nike', 'nike']])
            ->assertOk()
            ->assertJsonPath('brands', ['Nike']);

        $this->assertSame(1, Partner::count());
        $this->assertSame(1, $media->partners()->count());

        // Édition en masse : ajout puis retrait, sans toucher au reste.
        $this->actingAs($user)
            ->postJson(route('media.brandsBatch'), ['ids' => [$media->id], 'add' => ['Decathlon']])
            ->assertOk();
        $this->assertSame(2, $media->partners()->count());

        $this->actingAs($user)
            ->postJson(route('media.brandsBatch'), ['ids' => [$media->id], 'remove' => ['NIKE']])
            ->assertOk();
        $this->assertSame(['Decathlon'], $media->fresh()->brands);

        // L'autocomplete lit désormais le référentiel, pas un scan des colonnes JSON.
        $this->actingAs($user)
            ->getJson(route('media.autocomplete'))
            ->assertOk()
            ->assertJsonPath('brands', ['Decathlon', 'Nike']);
    }

    private function socialAccountFor(User $user): \App\Models\SocialAccount
    {
        $platform = \App\Models\Platform::firstOrCreate(
            ['slug' => 'telegram'],
            ['name' => 'Telegram', 'is_active' => true]
        );

        $account = \App\Models\SocialAccount::create([
            'platform_id' => $platform->id,
            'platform_account_id' => 'test-'.uniqid(),
            'name' => 'Compte de test',
            'is_active' => true,
        ]);

        $account->users()->attach($user->id, ['is_active' => true]);

        return $account;
    }
}
