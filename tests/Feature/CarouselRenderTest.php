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

    public function test_la_brique_image_seule_ne_rend_que_limage(): void
    {
        // Image de test locale : un PNG 2×2 écrit dans media/ (résolu en data-URI).
        $filename = 'test_image_full_'.uniqid().'.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAACgL26xAAAAF0lEQVR4nGP8//8/AzJgYkAD5AswMDAAAA0OAgOfEE2OAAAAAElFTkSuQmCC');
        \Illuminate\Support\Facades\Storage::disk('local')->put("media/{$filename}", $png);

        try {
            $html = $this->service()->buildHtml('1:1', [
                ['brick' => 'image-full', 'data' => ['image' => "/media/{$filename}", 'title' => 'ignoré']],
            ]);

            // L'image est embarquée en data-URI, plein cadre.
            $this->assertStringContainsString('data:image/png;base64,', $html);
            $this->assertStringContainsString('object-fit:cover', $html);
            // Aucun texte ni dégradé : le slot title inexistant n'est pas rendu.
            $this->assertStringNotContainsString('ignoré', $html);
            $this->assertStringNotContainsString('linear-gradient', $html);
        } finally {
            \Illuminate\Support\Facades\Storage::disk('local')->delete("media/{$filename}");
        }
    }

    /**
     * `number_style` choisit l'habillage du numéro sans changer de brique.
     * Repères dans le HTML : la pastille est le seul élément en `border-radius:999px`,
     * le filigrane le seul en `opacity:0.16`.
     */
    public function test_le_style_du_numero_pilote_pastille_et_filigrane(): void
    {
        $cas = [
            // [style, pastille attendue, filigrane attendu]
            [null, true, true],     // slot absent => rendu historique
            ['', true, true],       // valeur vide => idem, pas de slide muette
            ['both', true, true],
            ['badge', true, false],
            ['watermark', false, true],
            ['none', false, false],
        ];

        foreach ($cas as [$style, $pastille, $filigrane]) {
            $data = ['number' => '07', 'title' => 'Étape sept'];
            if ($style !== null) {
                $data['number_style'] = $style;
            }

            $html = $this->service()->buildHtml('4:5', [['brick' => 'numbered', 'data' => $data]]);
            $libelle = $style === null ? '(absent)' : ($style === '' ? '(vide)' : $style);

            $this->assertSame($pastille, str_contains($html, 'border-radius:999px'), "pastille, style {$libelle}");
            $this->assertSame($filigrane, str_contains($html, 'opacity:0.16'), "filigrane, style {$libelle}");
            // Le titre reste rendu quel que soit le style retenu.
            $this->assertStringContainsString('Étape sept', $html);
        }
    }

    public function test_une_brique_inconnue_leve_une_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->buildHtml('4:5', [
            ['brick' => 'brique-qui-nexiste-pas', 'data' => []],
        ]);
    }
}
