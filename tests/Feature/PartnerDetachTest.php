<?php

namespace Tests\Feature;

use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\Partner;
use App\Models\Post;
use App\Models\Thread;
use App\Models\ThreadSegment;
use App\Models\User;
use App\Services\PartnerTagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Couvre le detachement en masse d'un partenaire et, surtout, le rattrapage du
 * report photo -> publication : le tag 'auto' est recalcule a l'enregistrement
 * du POST, jamais a celui de la PHOTO. Sans rattrapage, un contenu deja publie
 * garde son tag pour toujours.
 *
 * Scenario de reference : le sur-taguage Holafly sur tout un lot photo du Maroc.
 */
class PartnerDetachTest extends TestCase
{
    use RefreshDatabase;

    private function media(string $name, ?MediaFolder $folder = null): MediaFile
    {
        return MediaFile::create([
            'filename' => $name.'.jpg',
            'original_name' => $name.'.jpg',
            'mime_type' => 'image/jpeg',
            'intimacy_level' => 'public',
            'folder_id' => $folder?->id,
        ]);
    }

    private function folder(string $name, bool $private = false, ?MediaFolder $parent = null): MediaFolder
    {
        return MediaFolder::create([
            'name' => $name,
            'slug' => $name,
            'path' => $parent ? $parent->path.'/'.$name : $name,
            'parent_id' => $parent?->id,
            'is_private' => $private,
        ]);
    }

    private function makePost(User $user, array $media, string $status = 'draft'): Post
    {
        return Post::create([
            'user_id' => $user->id,
            'content_fr' => 'sejour a Essaouira',
            'status' => $status,
            'media' => $media,
        ]);
    }

    private function makeThread(User $user, array $media, string $status = 'draft'): Thread
    {
        $thread = Thread::create([
            'user_id' => $user->id,
            'title' => 'Riad El Rahala',
            'status' => $status,
        ]);
        ThreadSegment::create([
            'thread_id' => $thread->id,
            'position' => 1,
            'content_fr' => 'segment',
            'media' => $media,
        ]);

        return $thread;
    }

    /**
     * Le coeur du probleme : detacher la photo doit nettoyer le contenu deja
     * publie, et laisser intact le tag pose a la main.
     */
    public function test_le_detachement_nettoie_le_contenu_publie_et_preserve_le_manual(): void
    {
        $service = app(PartnerTagService::class);
        $user = User::factory()->create();

        $photo = $this->media('essaouira-01');
        $service->syncMediaNames($photo, ['Holafly']);
        $holafly = Partner::where('slug', 'holafly')->firstOrFail();

        $publie = $this->makePost($user, [['id' => $photo->id, 'url' => '/media/essaouira-01.jpg']], 'published');
        $planifie = $this->makeThread($user, [['id' => $photo->id, 'url' => '/media/essaouira-01.jpg']], 'scheduled');
        $service->syncPost($publie);
        $service->syncThread($planifie);

        // Un troisieme contenu porte Holafly a la main : il ne doit pas bouger.
        $manuel = $this->makePost($user, [], 'published');
        $service->syncPost($manuel, [$holafly->id]);

        $this->assertTrue($publie->partners()->where('partners.id', $holafly->id)->exists());
        $this->assertTrue($planifie->partners()->where('partners.id', $holafly->id)->exists());

        // On retire la marque de la photo, puis on rattrape les contenus.
        $service->amendMediaNames($photo, [], ['Holafly']);
        $touched = $service->resyncContentUsingMedia([$photo->fresh()]);

        $this->assertSame([$publie->id], $touched['posts']);
        $this->assertSame([$planifie->id], $touched['threads']);
        $this->assertFalse($publie->fresh()->partners()->where('partners.id', $holafly->id)->exists());
        $this->assertFalse($planifie->fresh()->partners()->where('partners.id', $holafly->id)->exists());
        // Le tag pose a la main survit au nettoyage.
        $this->assertTrue($manuel->fresh()->partners()->where('partners.id', $holafly->id)->exists());
    }

    /**
     * Le rattrapage doit fonctionner meme quand posts.media ne porte que l'URL
     * (format historique, sans id).
     */
    public function test_le_rattrapage_retrouve_le_contenu_par_nom_de_fichier(): void
    {
        $service = app(PartnerTagService::class);
        $user = User::factory()->create();

        $photo = $this->media('riad-02');
        $service->syncMediaNames($photo, ['Holafly']);
        $holafly = Partner::where('slug', 'holafly')->firstOrFail();

        $post = $this->makePost($user, ['/media/riad-02.jpg'], 'published');
        $service->syncPost($post);
        $this->assertTrue($post->partners()->where('partners.id', $holafly->id)->exists());

        $service->amendMediaNames($photo, [], ['Holafly']);
        $service->resyncContentUsingMedia([$photo->fresh()]);

        $this->assertFalse($post->fresh()->partners()->where('partners.id', $holafly->id)->exists());
    }

    /**
     * Un id ne doit pas en attraper un autre par prefixe ("id":58 vs "id":580).
     */
    public function test_la_recherche_par_id_ne_confond_pas_les_prefixes(): void
    {
        $service = app(PartnerTagService::class);
        $user = User::factory()->create();

        $photo = $this->media('cible');
        $service->syncMediaNames($photo, ['Holafly']);

        // Un post qui reference un id commencant par celui de la photo.
        $leurre = $this->makePost($user, [['id' => (int) ($photo->id.'0'), 'url' => '/media/autre.jpg']], 'published');

        $touched = $service->resyncContentUsingMedia([$photo->fresh()], dryRun: true);

        $this->assertNotContains($leurre->id, $touched['posts']);
    }

    /**
     * Le dry-run annonce exactement ce que fait le run reel, sans rien ecrire.
     */
    public function test_le_dry_run_n_ecrit_rien_et_annonce_le_meme_resultat(): void
    {
        $service = app(PartnerTagService::class);
        $user = User::factory()->create();

        $photo = $this->media('essaouira-03');
        $service->syncMediaNames($photo, ['Holafly']);
        $holafly = Partner::where('slug', 'holafly')->firstOrFail();

        $post = $this->makePost($user, [['id' => $photo->id, 'url' => '/media/essaouira-03.jpg']], 'published');
        $service->syncPost($post);

        $service->amendMediaNames($photo, [], ['Holafly']);

        $annonce = $service->resyncContentUsingMedia([$photo->fresh()], dryRun: true);
        // Rien n'a bouge en base.
        $this->assertTrue($post->fresh()->partners()->where('partners.id', $holafly->id)->exists());

        $reel = $service->resyncContentUsingMedia([$photo->fresh()]);
        $this->assertSame($annonce, $reel);
        $this->assertFalse($post->fresh()->partners()->where('partners.id', $holafly->id)->exists());
    }

    // ─── Routes API detach / attach ──────────────────────────────────

    /**
     * Scenario Holafly en reduction, par l'API : un lot photo tague en bloc, un
     * fil publie et un post planifie qui l'utilisent, un contenu tague a la main.
     */
    public function test_la_route_detach_nettoie_le_lot_et_ses_publications(): void
    {
        $service = app(PartnerTagService::class);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $pdc = $this->folder('pdc');
        $photos = collect(['essaouira-a', 'essaouira-b', 'riad-c'])
            ->map(fn ($n) => $this->media($n, $pdc));
        foreach ($photos as $photo) {
            $service->syncMediaNames($photo, ['Holafly']);
        }
        $holafly = Partner::where('slug', 'holafly')->firstOrFail();

        $filPublie = $this->makeThread($user, [['id' => $photos[0]->id, 'url' => '/media/essaouira-a.jpg']], 'published');
        $postPlanifie = $this->makePost($user, [['id' => $photos[1]->id, 'url' => '/media/essaouira-b.jpg']], 'scheduled');
        $service->syncThread($filPublie);
        $service->syncPost($postPlanifie);

        $manuel = $this->makePost($user, [], 'published');
        $service->syncPost($manuel, [$holafly->id]);

        $response = $this->postJson("/api/partners/{$holafly->id}/media/detach", [
            'filters' => ['folder' => 'pdc'],
            'dry_run' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('dry_run', false)
            ->assertJsonPath('media_matched', 3)
            ->assertJsonPath('media_detached', 3)
            ->assertJsonPath('posts_recalculated', 1)
            ->assertJsonPath('threads_recalculated', 1);

        $this->assertEqualsCanonicalizing([$postPlanifie->id], $response->json('posts_now_untagged'));
        $this->assertEqualsCanonicalizing([$filPublie->id], $response->json('threads_now_untagged'));

        // Les photos ne portent plus la marque, ni dans le pivot ni dans le miroir.
        $this->assertSame(0, $holafly->mediaFiles()->count());
        $this->assertSame([], $photos[0]->fresh()->brands);
        // Le contenu tague a la main est intact.
        $this->assertTrue($manuel->fresh()->partners()->where('partners.id', $holafly->id)->exists());
        // La fiche partenaire existe toujours.
        $this->assertNotNull(Partner::find($holafly->id));
    }

    /**
     * Le dry-run est le defaut, il annonce les memes chiffres que le run reel,
     * et ne laisse aucune trace en base.
     */
    public function test_la_route_detach_est_en_dry_run_par_defaut(): void
    {
        $service = app(PartnerTagService::class);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $pdc = $this->folder('pdc');
        $photo = $this->media('essaouira-d', $pdc);
        $service->syncMediaNames($photo, ['Holafly']);
        $holafly = Partner::where('slug', 'holafly')->firstOrFail();

        $post = $this->makePost($user, [['id' => $photo->id, 'url' => '/media/essaouira-d.jpg']], 'published');
        $service->syncPost($post);

        // Aucun dry_run transmis : l'ecriture doit se demander explicitement.
        $annonce = $this->postJson('/api/partners/holafly/media/detach', ['folder' => 'pdc']);
        $annonce->assertOk()->assertJsonPath('dry_run', true);

        // Rien n'a bouge.
        $this->assertTrue($photo->fresh()->partners()->where('partners.id', $holafly->id)->exists());
        $this->assertTrue($post->fresh()->partners()->where('partners.id', $holafly->id)->exists());

        $reel = $this->postJson('/api/partners/holafly/media/detach', ['folder' => 'pdc', 'dry_run' => false]);
        $reel->assertOk();

        foreach (['media_matched', 'media_detached', 'posts_recalculated', 'threads_recalculated'] as $key) {
            $this->assertSame($annonce->json($key), $reel->json($key), "compteur {$key} divergent entre dry-run et reel");
        }
        $this->assertFalse($post->fresh()->partners()->where('partners.id', $holafly->id)->exists());
    }

    /**
     * Une photo rangee dans un dossier prive n'est jamais touchee, meme designee
     * par son id, et le saut est comptabilise.
     */
    public function test_une_photo_en_dossier_prive_est_sautee(): void
    {
        $service = app(PartnerTagService::class);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $public = $this->folder('pdc');
        $prive = $this->folder('perso', private: true);

        $visible = $this->media('visible', $public);
        $cachee = $this->media('cachee', $prive);
        $service->syncMediaNames($visible, ['Holafly']);
        $service->syncMediaNames($cachee, ['Holafly']);
        $holafly = Partner::where('slug', 'holafly')->firstOrFail();

        $response = $this->postJson("/api/partners/{$holafly->id}/media/detach", [
            'media_ids' => [$visible->id, $cachee->id],
            'dry_run' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('media_matched', 2)
            ->assertJsonPath('media_detached', 1)
            ->assertJsonPath('media_skipped_private', 1);

        $this->assertFalse($visible->fresh()->partners()->where('partners.id', $holafly->id)->exists());
        $this->assertTrue($cachee->fresh()->partners()->where('partners.id', $holafly->id)->exists());
    }

    /**
     * Un sous-dossier prive sous un parent public reste cloisonne, comme sur
     * /api/media/search.
     */
    public function test_un_sous_dossier_prive_n_est_pas_atteint_par_le_filtre_dossier(): void
    {
        $service = app(PartnerTagService::class);
        Sanctum::actingAs(User::factory()->create());

        $parent = $this->folder('pdc');
        $enfantPrive = $this->folder('intime', private: true, parent: $parent);

        $ouverte = $this->media('ouverte', $parent);
        $fermee = $this->media('fermee', $enfantPrive);
        $service->syncMediaNames($ouverte, ['Holafly']);
        $service->syncMediaNames($fermee, ['Holafly']);
        $holafly = Partner::where('slug', 'holafly')->firstOrFail();

        $this->postJson("/api/partners/{$holafly->id}/media/detach", ['folder' => 'pdc', 'dry_run' => false])
            ->assertOk()
            ->assertJsonPath('media_matched', 1)
            ->assertJsonPath('media_detached', 1);

        $this->assertTrue($fermee->fresh()->partners()->where('partners.id', $holafly->id)->exists());
    }

    /**
     * Ids et filtres sont exclusifs, et il en faut au moins un.
     */
    public function test_la_selection_refuse_le_vide_et_le_melange(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->folder('pdc');
        $partner = Partner::create(['name' => 'Holafly', 'slug' => 'holafly', 'origin' => 'manual']);

        $this->postJson("/api/partners/{$partner->id}/media/detach", ['dry_run' => false])
            ->assertStatus(422);

        $this->postJson("/api/partners/{$partner->id}/media/detach", [
            'media_ids' => [1],
            'folder' => 'pdc',
        ])->assertStatus(422);
    }

    /**
     * Les filtres sont acceptes groupes sous `filters` (forme documentee) comme
     * a plat (meme vocabulaire que la query string de /api/media/search).
     */
    public function test_les_filtres_sont_acceptes_imbriques_ou_a_plat(): void
    {
        $service = app(PartnerTagService::class);
        Sanctum::actingAs(User::factory()->create());

        $pdc = $this->folder('pdc');
        $essaouira = $this->media('geo-a', $pdc);
        $essaouira->update(['city' => 'Essaouira']);
        $ailleurs = $this->media('geo-b', $pdc);
        $ailleurs->update(['city' => 'Lisbonne']);
        $service->syncMediaNames($essaouira, ['Holafly']);
        $service->syncMediaNames($ailleurs, ['Holafly']);
        $holafly = Partner::where('slug', 'holafly')->firstOrFail();

        // Forme imbriquee, en dry-run : ne doit viser que la photo d'Essaouira.
        $this->postJson('/api/partners/holafly/media/detach', [
            'filters' => ['folder' => 'pdc', 'city' => 'essaouira'],
        ])->assertOk()->assertJsonPath('media_matched', 1);

        // Forme a plat : meme resultat.
        $this->postJson('/api/partners/holafly/media/detach', [
            'folder' => 'pdc',
            'city' => 'Essaouira',
            'dry_run' => false,
        ])->assertOk()->assertJsonPath('media_detached', 1);

        $this->assertFalse($essaouira->fresh()->partners()->where('partners.id', $holafly->id)->exists());
        $this->assertTrue($ailleurs->fresh()->partners()->where('partners.id', $holafly->id)->exists());
    }

    /**
     * attach est le pendant exact : re-taguer les seuls visuels concernes, et
     * reporter le tag sur les publications qui les utilisent deja.
     */
    public function test_la_route_attach_tague_et_reporte_sur_les_publications(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $pdc = $this->folder('pdc');
        $photo = $this->media('esim-promo', $pdc);
        $partner = Partner::create(['name' => 'Holafly', 'slug' => 'holafly', 'origin' => 'manual']);

        $post = $this->makePost($user, [['id' => $photo->id, 'url' => '/media/esim-promo.jpg']], 'published');
        app(PartnerTagService::class)->syncPost($post);
        $this->assertFalse($post->partners()->where('partners.id', $partner->id)->exists());

        $this->postJson('/api/partners/holafly/media/attach', [
            'media_ids' => [$photo->id],
            'dry_run' => false,
        ])->assertOk()
            ->assertJsonPath('action', 'attach')
            ->assertJsonPath('media_attached', 1)
            ->assertJsonPath('posts_recalculated', 1);

        $this->assertTrue($post->fresh()->partners()->where('partners.id', $partner->id)->exists());
        $this->assertSame(['Holafly'], $photo->fresh()->brands);
    }

    /**
     * Rejouer le meme detach ne gonfle pas les compteurs.
     */
    public function test_la_route_detach_est_idempotente(): void
    {
        $service = app(PartnerTagService::class);
        Sanctum::actingAs(User::factory()->create());

        $pdc = $this->folder('pdc');
        $photo = $this->media('essaouira-e', $pdc);
        $service->syncMediaNames($photo, ['Holafly']);
        $holafly = Partner::where('slug', 'holafly')->firstOrFail();

        $this->postJson("/api/partners/{$holafly->id}/media/detach", ['folder' => 'pdc', 'dry_run' => false])
            ->assertOk()->assertJsonPath('media_detached', 1);

        // Deuxieme passage : plus rien d'eligible.
        $this->postJson("/api/partners/{$holafly->id}/media/detach", ['folder' => 'pdc', 'dry_run' => false])
            ->assertOk()
            ->assertJsonPath('media_matched', 1)
            ->assertJsonPath('media_detached', 0)
            ->assertJsonPath('posts_recalculated', 0);
    }

    // ─── Commande de rattrapage ──────────────────────────────────────

    /**
     * Le cas historique : des photos deja detaguees (avant que le report ne soit
     * branche) et des publications restees taguees. La commande les rattrape.
     */
    public function test_la_commande_rattrape_les_publications_desynchronisees(): void
    {
        $service = app(PartnerTagService::class);
        $user = User::factory()->create();

        $photo = $this->media('legacy');
        $service->syncMediaNames($photo, ['Holafly']);
        $holafly = Partner::where('slug', 'holafly')->firstOrFail();

        $post = $this->makePost($user, [['id' => $photo->id, 'url' => '/media/legacy.jpg']], 'published');
        $service->syncPost($post);

        // On simule l'ancien comportement : la photo perd sa marque sans report.
        $photo->partners()->detach();
        $photo->update(['brands' => []]);
        $this->assertTrue($post->fresh()->partners()->where('partners.id', $holafly->id)->exists());

        // Dry-run : signale mais n'ecrit pas.
        $this->artisan('partners:resync-content', ['--partner' => 'holafly'])
            ->assertExitCode(0);
        $this->assertTrue($post->fresh()->partners()->where('partners.id', $holafly->id)->exists());

        $this->artisan('partners:resync-content', ['--partner' => 'holafly', '--commit' => true])
            ->assertExitCode(0);
        $this->assertFalse($post->fresh()->partners()->where('partners.id', $holafly->id)->exists());
    }

    public function test_la_commande_refuse_un_partenaire_inconnu(): void
    {
        $this->artisan('partners:resync-content', ['--partner' => 'inexistant'])
            ->assertExitCode(1);
    }

    /**
     * Rejouer le rattrapage ne doit plus rien signaler : une fois aligne, le
     * contenu ne ressort pas des compteurs.
     */
    public function test_le_rattrapage_est_idempotent(): void
    {
        $service = app(PartnerTagService::class);
        $user = User::factory()->create();

        $photo = $this->media('essaouira-04');
        $service->syncMediaNames($photo, ['Holafly']);

        $post = $this->makePost($user, [['id' => $photo->id, 'url' => '/media/essaouira-04.jpg']], 'published');
        $service->syncPost($post);

        $service->amendMediaNames($photo, [], ['Holafly']);
        $premier = $service->resyncContentUsingMedia([$photo->fresh()]);
        $second = $service->resyncContentUsingMedia([$photo->fresh()]);

        $this->assertSame([$post->id], $premier['posts']);
        $this->assertSame([], $second['posts']);
    }
}
