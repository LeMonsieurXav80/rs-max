<?php

namespace Tests\Feature;

use App\Models\MediaFolder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Couvre POST /api/media/folders et PATCH /api/media/folders/{folder} :
 * création de sous-dossier, renommage (slug dédupliqué), déplacement,
 * et les refus — dossier système, branche privée, cycle de parenté.
 */
class MediaApiFolderWriteTest extends TestCase
{
    use RefreshDatabase;

    private function folder(string $name, array $attributes = []): MediaFolder
    {
        return MediaFolder::create(array_merge([
            'name' => $name,
            'slug' => MediaFolder::uniqueSlug($name),
        ], $attributes));
    }

    public function test_cree_un_sous_dossier_sous_un_parent(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $parent = $this->folder('Glasgow');

        $this->postJson('/api/media/folders', [
            'name' => 'Cottiers',
            'parent_id' => $parent->id,
        ])->assertCreated()->assertJson([
            'status' => 'created',
            'slug' => 'cottiers',
            'name' => 'Cottiers',
            'path' => 'Glasgow / Cottiers',
            'parent_id' => $parent->id,
            'is_private' => false,
        ]);

        $this->assertDatabaseHas('media_folders', ['slug' => 'cottiers', 'parent_id' => $parent->id]);
    }

    public function test_renomme_un_dossier_et_regenere_le_slug(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $glasgow = $this->folder('Glasgow');
        $folder = $this->folder('Òran Mór Cottiers', ['parent_id' => $glasgow->id]);

        $this->patchJson("/api/media/folders/{$folder->id}", ['name' => 'Cottiers'])
            ->assertOk()
            ->assertJson([
                'status' => 'updated',
                'slug' => 'cottiers',
                'name' => 'Cottiers',
                'path' => 'Glasgow / Cottiers',
                'parent_id' => $glasgow->id,
            ]);
    }

    public function test_renommage_vers_un_slug_deja_pris_est_deduplique(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->folder('Cottiers');
        $folder = $this->folder('Òran Mór Cottiers');

        $this->patchJson("/api/media/folders/{$folder->id}", ['name' => 'Cottiers'])
            ->assertOk()
            ->assertJson(['name' => 'Cottiers', 'slug' => 'cottiers-1']);
    }

    public function test_deplace_un_dossier_sous_un_nouveau_parent(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $ecosse = $this->folder('Ecosse');
        $glasgow = $this->folder('Glasgow', ['parent_id' => $ecosse->id]);
        $folder = $this->folder('Cottiers', ['parent_id' => $ecosse->id]);

        $this->patchJson("/api/media/folders/{$folder->id}", ['parent_id' => $glasgow->id])
            ->assertOk()
            ->assertJson(['path' => 'Ecosse / Glasgow / Cottiers']);
    }

    public function test_refuse_un_parent_descendant_du_dossier(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $parent = $this->folder('Ecosse');
        $enfant = $this->folder('Glasgow', ['parent_id' => $parent->id]);

        $this->patchJson("/api/media/folders/{$parent->id}", ['parent_id' => $enfant->id])
            ->assertStatus(422);

        $this->assertNull($parent->fresh()->parent_id);
    }

    public function test_refuse_un_dossier_systeme(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $folder = $this->folder('Flux Pictures', ['is_system' => true]);

        $this->patchJson("/api/media/folders/{$folder->id}", ['name' => 'Renommé'])
            ->assertStatus(403);

        $this->assertSame('Flux Pictures', $folder->fresh()->name);
    }

    public function test_refuse_de_modifier_un_dossier_dans_une_branche_privee(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $prive = $this->folder('Privé', ['is_private' => true]);
        $enfant = $this->folder('Sous-dossier', ['parent_id' => $prive->id]);

        $this->patchJson("/api/media/folders/{$enfant->id}", ['name' => 'Renommé'])
            ->assertStatus(403);
    }

    public function test_refuse_de_creer_sous_une_branche_privee(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $prive = $this->folder('Privé', ['is_private' => true]);

        $this->postJson('/api/media/folders', ['name' => 'Nouveau', 'parent_id' => $prive->id])
            ->assertStatus(403);

        $this->assertDatabaseMissing('media_folders', ['slug' => 'nouveau']);
    }

    public function test_refuse_de_deplacer_vers_une_branche_privee(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $prive = $this->folder('Privé', ['is_private' => true]);
        $folder = $this->folder('Cottiers');

        $this->patchJson("/api/media/folders/{$folder->id}", ['parent_id' => $prive->id])
            ->assertStatus(403);

        $this->assertNull($folder->fresh()->parent_id);
    }

    public function test_ne_permet_pas_de_changer_la_visibilite(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $folder = $this->folder('Privé', ['is_private' => true]);

        // Refusé parce que privé — et `is_private` n'est de toute façon pas un champ accepté.
        $this->patchJson("/api/media/folders/{$folder->id}", ['is_private' => false])
            ->assertStatus(403);

        $this->assertTrue($folder->fresh()->is_private);
    }

    public function test_exige_une_authentification(): void
    {
        $this->postJson('/api/media/folders', ['name' => 'Anonyme'])->assertStatus(401);
    }

    /**
     * Le scénario visé de bout en bout : créer un dossier, créer un sous-dossier
     * dedans, créer un sous-sous-dossier, puis renommer à n'importe quel niveau.
     */
    public function test_arborescence_complete_creation_puis_renommage(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $pays = $this->postJson('/api/media/folders', ['name' => 'Pays'])
            ->assertCreated()->json();

        $ecosse = $this->postJson('/api/media/folders', [
            'name' => 'Ecosse',
            'parent_id' => $pays['id'],
        ])->assertCreated()->assertJson(['path' => 'Pays / Ecosse'])->json();

        $glasgow = $this->postJson('/api/media/folders', [
            'name' => 'Glasgow',
            'parent_id' => $ecosse['id'],
        ])->assertCreated()->assertJson(['path' => 'Pays / Ecosse / Glasgow'])->json();

        $cottiers = $this->postJson('/api/media/folders', [
            'name' => 'Òran Mór Cottiers',
            'parent_id' => $glasgow['id'],
        ])->assertCreated()->assertJson([
            'path' => 'Pays / Ecosse / Glasgow / Òran Mór Cottiers',
            'slug' => 'oran-mor-cottiers',
        ])->json();

        // Renommer une feuille : le chemin est conservé, seul le nom du nœud change.
        $this->patchJson("/api/media/folders/{$cottiers['id']}", ['name' => 'Cottiers'])
            ->assertOk()
            ->assertJson([
                'slug' => 'cottiers',
                'path' => 'Pays / Ecosse / Glasgow / Cottiers',
                'parent_id' => $glasgow['id'],
            ]);

        // Renommer un nœud intermédiaire : les descendants suivent.
        $this->patchJson("/api/media/folders/{$ecosse['id']}", ['name' => 'Écosse'])->assertOk();

        $this->assertSame(
            'Pays / Écosse / Glasgow / Cottiers',
            MediaFolder::find($cottiers['id'])->pathLabel()
        );
    }

    public function test_la_suppression_d_un_dossier_reste_impossible_par_l_api(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $folder = $this->folder('Cottiers');

        // Aucune route DELETE n'est exposée : la suppression reste web-only, à dessein.
        $this->deleteJson("/api/media/folders/{$folder->id}")->assertStatus(405);

        $this->assertDatabaseHas('media_folders', ['id' => $folder->id]);
    }
}
