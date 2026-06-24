<?php

namespace Tests\Feature;

use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Couvre PATCH /api/media/{id} (MediaApiController::updateDescription) :
 * édition description/tags only, refus des dossiers privés, et garantie qu'on
 * ne peut pas changer la visibilité via cet endpoint.
 */
class MediaApiUpdateDescriptionTest extends TestCase
{
    use RefreshDatabase;

    private function media(MediaFolder $folder, string $name): MediaFile
    {
        return MediaFile::create([
            'folder_id' => $folder->id,
            'filename' => $name.'.jpg',
            'original_name' => $name.'.jpg',
            'mime_type' => 'image/jpeg',
            'description_fr' => 'description initiale',
            'thematic_tags' => ['initial'],
            'intimacy_level' => 'public',
        ]);
    }

    public function test_met_a_jour_description_et_tags_dans_un_dossier_public(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $folder = MediaFolder::create(['name' => 'Public', 'slug' => 'public', 'is_private' => false]);
        $media = $this->media($folder, 'pub');

        $this->patchJson("/api/media/{$media->id}", [
            'description_fr' => 'description enrichie depuis la fiche',
            'thematic_tags' => ['Saint Jean', 'Tavira'],
        ])->assertOk()->assertJson([
            'status' => 'updated',
            'description_fr' => 'description enrichie depuis la fiche',
        ]);

        $media->refresh();
        $this->assertSame('description enrichie depuis la fiche', $media->description_fr);
        $this->assertContains('saint jean', $media->thematic_tags); // normalisé minuscule
    }

    public function test_refuse_un_dossier_prive(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $folder = MediaFolder::create(['name' => 'Privé', 'slug' => 'prive', 'is_private' => true]);
        $media = $this->media($folder, 'priv');

        $this->patchJson("/api/media/{$media->id}", [
            'description_fr' => 'ne doit pas passer',
        ])->assertForbidden();

        $this->assertSame('description initiale', $media->fresh()->description_fr);
    }

    public function test_refuse_si_un_ancetre_est_prive(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $parent = MediaFolder::create(['name' => 'Intime', 'slug' => 'intime', 'is_private' => true]);
        $child = MediaFolder::create(['name' => 'Sous', 'slug' => 'sous', 'parent_id' => $parent->id, 'is_private' => false]);
        $media = $this->media($child, 'enfant');

        $this->patchJson("/api/media/{$media->id}", [
            'description_fr' => 'ne doit pas passer',
        ])->assertForbidden();
    }

    public function test_ne_peut_pas_changer_la_visibilite_ni_le_dossier(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $folder = MediaFolder::create(['name' => 'Public', 'slug' => 'public', 'is_private' => false]);
        $other = MediaFolder::create(['name' => 'Autre', 'slug' => 'autre', 'is_private' => false]);
        $media = $this->media($folder, 'visi');

        $this->patchJson("/api/media/{$media->id}", [
            'description_fr' => 'maj',
            'intimacy_level' => 'public',      // doit être ignoré
            'folder_id' => $other->id,         // doit être ignoré
        ])->assertOk();

        $media->refresh();
        $this->assertSame('public', $media->intimacy_level);
        $this->assertSame($folder->id, $media->folder_id);
    }

    public function test_exige_au_moins_un_champ(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $folder = MediaFolder::create(['name' => 'Public', 'slug' => 'public', 'is_private' => false]);
        $media = $this->media($folder, 'vide');

        $this->patchJson("/api/media/{$media->id}", [])->assertStatus(422);
    }

    public function test_refuse_sans_authentification(): void
    {
        $folder = MediaFolder::create(['name' => 'Public', 'slug' => 'public', 'is_private' => false]);
        $media = $this->media($folder, 'noauth');

        $this->patchJson("/api/media/{$media->id}", [
            'description_fr' => 'maj',
        ])->assertUnauthorized();
    }
}
