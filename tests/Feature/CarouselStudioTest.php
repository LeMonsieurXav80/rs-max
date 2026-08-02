<?php

namespace Tests\Feature;

use App\Models\MediaFolder;
use App\Models\Setting;
use App\Models\User;
use App\Services\Carousel\StudioDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Studio carrousel : page + endpoint d'aperçu (sans Chromium). La génération
 * Browsershot (route render) n'est pas couverte ici (nécessite un navigateur).
 */
class CarouselStudioTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_la_page_studio_repond(): void
    {
        $this->actingAs($this->user())
            ->get(route('carousel.studio'))
            ->assertOk()
            ->assertSee('Studio carrousel');
    }

    public function test_lapercu_renvoie_le_html_de_la_bande(): void
    {
        $response = $this->actingAs($this->user())->postJson(route('carousel.studio.preview'), [
            'ratio' => '4:5',
            'slides' => [
                ['brick' => 'bold-text', 'data' => ['title' => 'Aperçu live']],
                ['brick' => 'bold-text', 'data' => ['title' => 'Slide 2']],
            ],
        ]);

        $response->assertOk();
        $this->assertStringContainsString('data-carousel-slide', $response->getContent());
        $this->assertStringContainsString('Aperçu live', $response->getContent());
        $this->assertSame(2, substr_count($response->getContent(), 'data-carousel-slide data-index='));
    }

    public function test_lapercu_refuse_une_brique_inconnue(): void
    {
        $this->actingAs($this->user())->postJson(route('carousel.studio.preview'), [
            'ratio' => '4:5',
            'slides' => [['brick' => 'inexistante', 'data' => []]],
        ])->assertStatus(422);
    }

    public function test_lapercu_refuse_un_ratio_inconnu(): void
    {
        $this->actingAs($this->user())->postJson(route('carousel.studio.preview'), [
            'ratio' => '42:1',
            'slides' => [['brick' => 'bold-text', 'data' => []]],
        ])->assertStatus(422);
    }

    public function test_une_image_externe_est_rejetee_du_slot(): void
    {
        // Une URL http externe ne doit pas se retrouver dans le HTML (anti-SSRF).
        $response = $this->actingAs($this->user())->postJson(route('carousel.studio.preview'), [
            'ratio' => '1:1',
            'slides' => [[
                'brick' => 'photo-title-bl',
                'data' => ['title' => 'X', 'image' => 'http://169.254.169.254/latest/meta-data/'],
            ]],
        ]);

        $response->assertOk();
        $this->assertStringNotContainsString('169.254.169.254', $response->getContent());
    }

    public function test_le_dossier_de_depot_se_regle_dans_les_parametres(): void
    {
        $folder = MediaFolder::create(['name' => 'Visuels', 'slug' => 'visuels']);

        $this->actingAs($this->user())
            ->patch(route('settings.update'), $this->settingsPayload([
                StudioDefaults::FOLDER_KEY => $folder->id,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame($folder->id, StudioDefaults::folderId());
    }

    public function test_un_dossier_supprime_renvoie_a_la_racine(): void
    {
        $folder = MediaFolder::create(['name' => 'Éphémère', 'slug' => 'ephemere']);
        Setting::set(StudioDefaults::FOLDER_KEY, $folder->id);
        $folder->delete();

        // Sinon le rendu échouerait sur la clé étrangère APRÈS la minute de Chromium.
        $this->assertNull(StudioDefaults::folderId());
    }

    public function test_un_dossier_inconnu_est_refuse(): void
    {
        $this->actingAs($this->user())
            ->patch(route('settings.update'), $this->settingsPayload([
                StudioDefaults::FOLDER_KEY => 999999,
            ]))
            ->assertSessionHasErrors(StudioDefaults::FOLDER_KEY);
    }

    /**
     * La page Paramètres poste tous ses onglets d'un coup : les champs `required`
     * des autres onglets doivent être présents sinon la validation recale tout.
     */
    private function settingsPayload(array $overrides = []): array
    {
        $reflection = new \ReflectionClass(\App\Http\Controllers\SettingsController::class);

        // `+` garde la gauche en cas de collision : les surcharges d'abord.
        return $overrides + $reflection->getConstant('DEFAULTS');
    }
}
