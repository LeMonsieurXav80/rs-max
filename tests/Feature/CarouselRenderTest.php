<?php

namespace Tests\Feature;

use App\Services\Carousel\CarouselRenderService;
use Tests\TestCase;

/**
 * Garde-fou du système de briques carrousel HTML/CSS. On teste l'assemblage de la
 * BANDE (buildHtml) qui ne nécessite PAS Chromium — la rasterisation Browsershot
 * elle-même est couverte manuellement via `php artisan carousel:preview --render`.
 */
class CarouselRenderTest extends TestCase
{
    private function service(): CarouselRenderService
    {
        return app(CarouselRenderService::class);
    }

    public function test_les_ratios_par_defaut_sont_configures(): void
    {
        $ratios = config('carousel.ratios');

        $this->assertIsArray($ratios);
        $this->assertArrayHasKey('4:5', $ratios);
        $this->assertSame(1080, $ratios['4:5']['w']);
        $this->assertSame(1350, $ratios['4:5']['h']);
    }

    public function test_buildhtml_empile_une_section_par_slide_aux_bonnes_dimensions(): void
    {
        $slides = [
            ['brick' => 'bold-text', 'data' => ['title' => 'Slide A']],
            ['brick' => 'bold-text', 'data' => ['title' => 'Slide B']],
            ['brick' => 'bold-text', 'data' => ['title' => 'Slide C']],
        ];

        $html = $this->service()->buildHtml('4:5', $slides);

        // Une <section data-carousel-slide> par slide.
        $this->assertSame(3, substr_count($html, 'data-carousel-slide data-index='));
        // Dimensions du ratio 4:5 appliquées à la bande.
        $this->assertStringContainsString('width: 1080px', $html);
        $this->assertStringContainsString('1350px', $html);
        // Le contenu textuel des briques est bien injecté.
        $this->assertStringContainsString('Slide A', $html);
        $this->assertStringContainsString('Slide C', $html);
    }

    public function test_buildhtml_embarque_les_polices_en_font_face(): void
    {
        $html = $this->service()->buildHtml('1:1', [
            ['brick' => 'bold-text', 'data' => ['title' => 'Titre']],
        ]);

        $this->assertStringContainsString('@font-face', $html);
        $this->assertStringContainsString('data:font/ttf;base64,', $html);
    }

    public function test_une_brique_inconnue_leve_une_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->buildHtml('4:5', [
            ['brick' => 'brique-qui-nexiste-pas', 'data' => []],
        ]);
    }
}
