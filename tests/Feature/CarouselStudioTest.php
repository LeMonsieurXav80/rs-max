<?php

namespace Tests\Feature;

use App\Models\User;
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
}
