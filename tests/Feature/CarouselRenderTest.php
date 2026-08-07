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

    /**
     * L'ajout de couleurs à la palette ne doit RIEN changer aux rendus existants :
     * une couleur non réglée hérite, et garde l'atténuation historique.
     */
    public function test_une_couleur_non_reglee_herite_et_garde_son_attenuation(): void
    {
        $slide = ['brick' => 'photo-title-bl', 'data' => ['title' => 'Titre', 'subtitle' => 'Sous-titre']];

        // Thème d'origine (4 couleurs) : le sous-titre reste le texte principal atténué.
        $avant = $this->service()->buildHtml('4:5', [$slide + ['theme' => [
            'background' => '#0f0f1a', 'text' => '#ffffff', 'accent' => '#0083ff', 'overlay' => '#000000',
        ]]]);
        $this->assertStringContainsString('color:#ffffff; opacity:0.88', $avant);

        // Couleur dédiée fournie : elle porte la nuance, plus d'atténuation.
        $apres = $this->service()->buildHtml('4:5', [$slide + ['theme' => [
            'text' => '#ffffff', 'text_secondary' => '#a1a1aa',
        ]]]);
        $this->assertStringContainsString('color:#a1a1aa; opacity:1', $apres);
        $this->assertStringNotContainsString('opacity:0.88', $apres);
    }

    /**
     * Les barres sont proportionnelles à la plus grande valeur de la série, lue
     * dans le texte saisi (espaces = milliers, virgule = décimale).
     */
    public function test_lhistogramme_dimensionne_les_barres_sur_la_plus_grande_valeur(): void
    {
        $html = $this->service()->buildHtml('4:5', [[
            'brick' => 'bar-chart',
            'data' => ['items' => "Instagram | 42 %\nFacebook | 30 %\nThreads | 8 %"],
        ]]);

        preg_match_all('/width:([\d.]+)%/', $html, $m);
        $this->assertSame(['100', '71.43', '19.05'], $m[1]);

        // Séparateur de milliers et décimale : « 1 036,5 » vaut bien 1036,5.
        $html = $this->service()->buildHtml('4:5', [[
            'brick' => 'bar-chart',
            'data' => ['items' => "A | 1 036,5 €\nB | 518,25 €"],
        ]]);
        preg_match_all('/width:([\d.]+)%/', $html, $m);
        $this->assertSame(['100', '50'], $m[1]);

        // Aucune valeur exploitable : barres à zéro, pas de division par zéro.
        $html = $this->service()->buildHtml('4:5', [[
            'brick' => 'bar-chart',
            'data' => ['items' => "Sans valeur\nAutre"],
        ]]);
        preg_match_all('/width:([\d.]+)%/', $html, $m);
        $this->assertSame(['0', '0'], $m[1]);
    }

    /**
     * Le texte long se resserre quand il s'allonge, et les lignes vides font des
     * paragraphes plutôt qu'un pavé unique.
     */
    public function test_le_texte_long_sadapte_a_sa_longueur(): void
    {
        $taille = function (string $body): int {
            $html = $this->service()->buildHtml('4:5', [['brick' => 'long-text', 'data' => ['body' => $body]]]);
            preg_match('/font-size:(\d+)px; line-height:1\.\d+;\s+color/', $html, $m);

            return (int) ($m[1] ?? 0);
        };

        $court = $taille(str_repeat('a ', 50));       // ~100 signes
        $long = $taille(str_repeat('a ', 700));       // ~1400 signes
        $this->assertGreaterThan(0, $long);
        $this->assertGreaterThan($long, $court, 'un texte long doit être composé plus petit');

        // Une ligne vide sépare deux paragraphes => deux <p>.
        $html = $this->service()->buildHtml('4:5', [[
            'brick' => 'long-text',
            'data' => ['body' => "Premier paragraphe.\n\nSecond paragraphe."],
        ]]);
        $this->assertSame(2, substr_count($html, '<p style='));
    }

    /**
     * Continuité d'image : une photo cochée « prolonger » couvre le groupe entier,
     * chaque slide n'en montrant que sa part (cadre large, décalé de sa position).
     */
    public function test_une_photo_peut_se_prolonger_sur_les_slides_suivantes(): void
    {
        $image = '/media/'.($f = 'test_span_'.uniqid().'.png');
        \Illuminate\Support\Facades\Storage::disk('local')->put(
            "media/{$f}",
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAACgL26xAAAAF0lEQVR4nGP8//8/AzJgYkAD5AswMDAAAA0OAgOfEE2OAAAAAElFTkSuQmCC'),
        );

        // Cadres de l'image : [left, width] par slide qui en porte une.
        $frames = function (array $slides) {
            $html = $this->service()->buildHtml('4:5', $slides);
            preg_match_all('/top:0; left:(-?\d+)px; width:(\d+)px/', $html, $m, PREG_SET_ORDER);

            return array_map(fn ($x) => [(int) $x[1], (int) $x[2]], $m);
        };

        try {
            // Deux slides : un cadre de 2×1080, décalé d'un slide sur la seconde.
            $this->assertSame([[0, 2160], [-1080, 2160]], $frames([
                ['brick' => 'photo-title-bl', 'data' => ['image' => $image, 'extend_image' => true, 'title' => 'A']],
                ['brick' => 'photo-title-bl', 'data' => ['title' => 'B']],
            ]));

            // Chaînage : la 2e prolonge à son tour => panorama sur 3 slides.
            $this->assertSame([[0, 3240], [-1080, 3240], [-2160, 3240]], $frames([
                ['brick' => 'photo-title-bl', 'data' => ['image' => $image, 'extend_image' => true]],
                ['brick' => 'image-full', 'data' => ['image' => $image, 'extend_image' => true]],
                ['brick' => 'photo-title-bl', 'data' => ['title' => 'C']],
            ]));

            // Même chaînage, mais la photo n'est posée QUE sur la 1re slide — le cas
            // naturel dans le Studio : les suivantes reçoivent l'image du groupe,
            // elles n'ont donc pas à en choisir une pour prolonger à leur tour.
            $this->assertSame([[0, 3240], [-1080, 3240], [-2160, 3240]], $frames([
                ['brick' => 'photo-title-bl', 'data' => ['image' => $image, 'extend_image' => true]],
                ['brick' => 'photo-title-bl', 'data' => ['extend_image' => true]],
                ['brick' => 'photo-title-bl', 'data' => ['title' => 'C']],
            ]));

            // La PREMIÈRE slide n'a rien de particulier : elle ouvre un groupe
            // comme une autre (_span >= 2, _span_index = 0 => cadre non décalé).
            $this->assertSame([[0, 2160], [-1080, 2160]], $frames([
                ['brick' => 'photo-title-bl', 'data' => ['image' => $image, 'extend_image' => true, 'title' => 'A']],
                ['brick' => 'image-full', 'data' => []],
            ]));

            // Cas où il n'y a rien à prolonger : aucun cadre étendu, pas d'erreur.
            $this->assertSame([], $frames([                       // dernière slide
                ['brick' => 'photo-title-bl', 'data' => ['title' => 'A']],
                ['brick' => 'photo-title-bl', 'data' => ['image' => $image, 'extend_image' => true]],
            ]));
            $this->assertSame([], $frames([                       // brique suivante sans image
                ['brick' => 'photo-title-bl', 'data' => ['image' => $image, 'extend_image' => true]],
                ['brick' => 'stat-grid', 'data' => ['items' => '42 % | des lecteurs']],
            ]));
            $this->assertSame([], $frames([                       // coché mais sans photo
                ['brick' => 'photo-title-bl', 'data' => ['extend_image' => true, 'title' => 'A']],
                ['brick' => 'photo-title-bl', 'data' => ['title' => 'B']],
            ]));
        } finally {
            \Illuminate\Support\Facades\Storage::disk('local')->delete("media/{$f}");
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
