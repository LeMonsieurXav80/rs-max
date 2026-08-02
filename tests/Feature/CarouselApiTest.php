<?php

namespace Tests\Feature;

use App\Models\MediaFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * API REST du studio carrousel. La route /render (Chromium) n'est pas couverte
 * ici : elle nécessite un navigateur headless.
 */
class CarouselApiTest extends TestCase
{
    use RefreshDatabase;

    private function actAsApiUser(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_le_manifeste_expose_les_briques_et_leurs_slots_types(): void
    {
        $this->actAsApiUser();

        $response = $this->getJson('/api/carousel/bricks')->assertOk();

        $response->assertJsonStructure([
            'ratios', 'default_ratio', 'positions',
            'bricks' => [['slug', 'name', 'description', 'ratios', 'slots' => [['key', 'label', 'type']]]],
        ]);

        $bricks = collect($response->json('bricks'));
        $photo = $bricks->firstWhere('slug', 'photo-title-bl');
        $slots = collect($photo['slots'])->keyBy('key');

        $this->assertSame('image', $slots['image']['type']);
        $this->assertSame('position', $slots['position']['type']);
        $this->assertSame('bottom-left', $slots['position']['default']);
        $this->assertSame('range', $slots['offset']['type']);

        // La brique image seule n'expose QUE le slot image.
        $imageFull = $bricks->firstWhere('slug', 'image-full');
        $this->assertSame(['image'], array_column($imageFull['slots'], 'key'));
    }

    public function test_lapercu_applique_lancre_et_le_decalage(): void
    {
        $this->actAsApiUser();

        $response = $this->postJson('/api/carousel/preview', [
            'ratio' => '4:5',
            'slides' => [[
                'brick' => 'photo-title-bl',
                'data' => ['title' => 'Titre haut', 'position' => 'top-center', 'offset' => 10],
            ]],
        ])->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('Titre haut', $html);
        // Ancre haut-centre : bloc en haut, texte centré, dégradé descendant.
        $this->assertStringContainsString('justify-content:flex-start', $html);
        $this->assertStringContainsString('text-align:center', $html);
        $this->assertStringContainsString('linear-gradient(to bottom', $html);
        // Décalage de +10 % de 1350 px.
        $this->assertStringContainsString('translateY(135px)', $html);
    }

    public function test_une_position_inconnue_est_rejetee(): void
    {
        $this->actAsApiUser();

        $this->postJson('/api/carousel/preview', [
            'ratio' => '4:5',
            'slides' => [['brick' => 'photo-title-bl', 'data' => ['position' => 'nulle-part']]],
        ])->assertStatus(422);
    }

    public function test_un_decalage_hors_bornes_est_rejete(): void
    {
        $this->actAsApiUser();

        $this->postJson('/api/carousel/preview', [
            'ratio' => '4:5',
            'slides' => [['brick' => 'photo-title-bl', 'data' => ['offset' => 400]]],
        ])->assertStatus(422);
    }

    public function test_le_slot_image_accepte_un_identifiant_de_media(): void
    {
        $this->actAsApiUser();

        $filename = 'test_api_'.uniqid().'.png';
        Storage::disk('local')->put("media/{$filename}", base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAACgL26xAAAAF0lEQVR4nGP8//8/AzJgYkAD5AswMDAAAA0OAgOfEE2OAAAAAElFTkSuQmCC'
        ));

        try {
            $media = MediaFile::create([
                'filename' => $filename,
                'original_name' => $filename,
                'mime_type' => 'image/png',
                'size' => 100,
                'source' => 'upload',
            ]);

            $html = $this->postJson('/api/carousel/preview', [
                'ratio' => '1:1',
                'slides' => [['brick' => 'image-full', 'data' => ['image' => $media->id]]],
            ])->assertOk()->getContent();

            $this->assertStringContainsString('data:image/png;base64,', $html);
        } finally {
            Storage::disk('local')->delete("media/{$filename}");
        }
    }

    public function test_une_url_externe_najamais_atteint_le_rendu(): void
    {
        $this->actAsApiUser();

        $html = $this->postJson('/api/carousel/preview', [
            'ratio' => '1:1',
            'slides' => [[
                'brick' => 'image-full',
                'data' => ['image' => 'http://169.254.169.254/latest/meta-data/'],
            ]],
        ])->assertOk()->getContent();

        $this->assertStringNotContainsString('169.254.169.254', $html);
    }

    public function test_les_briques_listes_parsent_une_ligne_par_item(): void
    {
        $this->actAsApiUser();

        $html = $this->postJson('/api/carousel/preview', [
            'ratio' => '4:5',
            'slides' => [
                [
                    'brick' => 'stat-grid',
                    'data' => [
                        'title' => 'Les chiffres',
                        'items' => "26|essais\n1 036|participants",
                        'columns' => 1,
                    ],
                ],
                [
                    'brick' => 'table-rows',
                    'data' => ['rows' => "Créatinine|↑ artefact\nDFG|inchangé"],
                ],
            ],
        ])->assertOk()->getContent();

        // Colonnes et items de la grille.
        $this->assertStringContainsString('grid-template-columns:repeat(1, 1fr)', $html);
        $this->assertStringContainsString('essais', $html);
        $this->assertStringContainsString('1 036', $html);
        // Lignes du tableau : la barre verticale n'est jamais rendue telle quelle.
        $this->assertStringContainsString('Créatinine', $html);
        $this->assertStringContainsString('inchangé', $html);
        $this->assertStringNotContainsString('Créatinine|', $html);
    }

    public function test_un_nombre_de_colonnes_inconnu_est_rejete(): void
    {
        $this->actAsApiUser();

        $this->postJson('/api/carousel/preview', [
            'ratio' => '4:5',
            'slides' => [['brick' => 'stat-grid', 'data' => ['columns' => 7]]],
        ])->assertStatus(422);
    }

    public function test_lapi_exige_une_authentification(): void
    {
        $this->postJson('/api/carousel/preview', [
            'ratio' => '4:5',
            'slides' => [['brick' => 'bold-text', 'data' => ['title' => 'X']]],
        ])->assertStatus(401);
    }
}
