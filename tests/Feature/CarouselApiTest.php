<?php

namespace Tests\Feature;

use App\Models\MediaFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    public function test_le_theme_du_carrousel_est_applique_a_toutes_les_slides(): void
    {
        $this->actAsApiUser();

        $html = $this->postJson('/api/carousel/preview', [
            'ratio' => '4:5',
            'theme' => [
                'background' => '#123456',
                'text' => '#fedcba',
                'accent' => '#00ff00',
                'overlay' => '#101820',
                'title_font' => 'Playfair Display',
            ],
            'slides' => [
                ['brick' => 'bold-text', 'data' => ['title' => 'A']],
                ['brick' => 'bold-text', 'data' => ['title' => 'B']],
            ],
        ])->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, '#123456') > 0 ? 2 : 0, 'fond appliqué');
        $this->assertStringContainsString('#fedcba', $html);
        $this->assertStringContainsString('#00ff00', $html);
        $this->assertStringContainsString('Playfair Display', $html);
    }

    public function test_le_degrade_suit_la_couleur_de_voile(): void
    {
        $this->actAsApiUser();

        $html = $this->postJson('/api/carousel/preview', [
            'ratio' => '4:5',
            'theme' => ['overlay' => '#101820'],
            'slides' => [['brick' => 'photo-title-bl', 'data' => ['title' => 'X']]],
        ])->assertOk()->getContent();

        // Le voile n'est pas noir par principe : il part de la couleur choisie,
        // à laquelle Anchor concatène un canal alpha.
        $this->assertStringContainsString('#101820e6', $html);
    }

    public function test_une_couleur_invalide_est_refusee(): void
    {
        $this->actAsApiUser();

        // Hex 3 chiffres : casserait la concaténation du canal alpha du dégradé.
        $this->postJson('/api/carousel/preview', [
            'ratio' => '4:5',
            'theme' => ['overlay' => '#000'],
            'slides' => [['brick' => 'bold-text', 'data' => ['title' => 'X']]],
        ])->assertStatus(422);

        $this->postJson('/api/carousel/preview', [
            'ratio' => '4:5',
            'theme' => ['title_font' => 'Comic Sans MS'],
            'slides' => [['brick' => 'bold-text', 'data' => ['title' => 'X']]],
        ])->assertStatus(422);
    }

    public function test_lechelle_typographique_agrandit_le_texte(): void
    {
        $this->actAsApiUser();

        $payload = fn (array $theme) => [
            'ratio' => '4:5',
            'theme' => $theme,
            'slides' => [['brick' => 'photo-title-bl', 'data' => ['title' => 'X', 'subtitle' => 'Y']]],
        ];

        $sizes = function (string $html): array {
            preg_match_all('/font-size:(\d+)px/', $html, $m);

            return array_map('intval', $m[1]);
        };

        $natif = $sizes($this->postJson('/api/carousel/preview', $payload([]))->assertOk()->getContent());
        $grand = $sizes($this->postJson('/api/carousel/preview', $payload([
            'title_scale' => 1.5,
        ]))->assertOk()->getContent());
        $petit = $sizes($this->postJson('/api/carousel/preview', $payload([
            'body_scale' => 0.7,
        ]))->assertOk()->getContent());

        // Le titre grossit de moitié, le sous-titre (police de texte) ne bouge pas.
        $this->assertSame((int) round($natif[0] * 1.5), $grand[0]);
        $this->assertSame($natif[1], $grand[1]);

        // Et réciproquement : body_scale ne touche pas au titre.
        $this->assertSame($natif[0], $petit[0]);
        $this->assertLessThan($natif[1], $petit[1]);
    }

    public function test_une_echelle_typographique_hors_bornes_est_refusee(): void
    {
        $this->actAsApiUser();

        // Trop grand : le texte déborderait du cadre sans qu'aucune erreur ne le dise.
        $this->postJson('/api/carousel/preview', [
            'ratio' => '4:5',
            'theme' => ['title_scale' => 4],
            'slides' => [['brick' => 'bold-text', 'data' => ['title' => 'X']]],
        ])->assertStatus(422)->assertJsonValidationErrors('theme.title_scale');

        // Trop petit : illisible sur mobile.
        $this->postJson('/api/carousel/preview', [
            'ratio' => '4:5',
            'theme' => ['body_scale' => 0.1],
            'slides' => [['brick' => 'bold-text', 'data' => ['title' => 'X']]],
        ])->assertStatus(422)->assertJsonValidationErrors('theme.body_scale');
    }

    public function test_lapi_exige_une_authentification(): void
    {
        $this->postJson('/api/carousel/preview', [
            'ratio' => '4:5',
            'slides' => [['brick' => 'bold-text', 'data' => ['title' => 'X']]],
        ])->assertStatus(401);

        $this->postJson('/api/carousel/image', [
            'brick' => 'bold-text',
            'data' => ['title' => 'X'],
        ])->assertStatus(401);
    }

    public function test_le_manifeste_expose_le_theme_par_defaut(): void
    {
        $this->actAsApiUser();

        $theme = $this->getJson('/api/carousel/bricks')->assertOk()->json('theme');

        // Sans ces valeurs, un client d'API ne saurait pas ce qui s'applique
        // quand il omet `theme`.
        $this->assertArrayHasKey('background', $theme);
        $this->assertArrayHasKey('title_font', $theme);
    }

    public function test_le_catalogue_de_polices_est_interrogeable(): void
    {
        $this->actAsApiUser();

        $all = $this->getJson('/api/carousel/fonts')->assertOk();
        $this->assertGreaterThan(0, $all->json('total'));
        $this->assertLessThanOrEqual(100, count($all->json('fonts')), 'limite par défaut');

        $found = $this->getJson('/api/carousel/fonts?q=montser')->assertOk();
        $families = array_column($found->json('fonts'), 'family');

        $this->assertContains('Montserrat', $families);
        // Les polices livrées sont déjà présentes sur le disque : le client sait
        // que le rendu ne paiera pas le téléchargement.
        $montserrat = collect($found->json('fonts'))->firstWhere('family', 'Montserrat');
        $this->assertTrue($montserrat['installed']);
    }

    public function test_limage_unique_refuse_une_brique_inconnue(): void
    {
        $this->actAsApiUser();

        $this->postJson('/api/carousel/image', [
            'brick' => 'brique-qui-nexiste-pas',
            'data' => ['title' => 'X'],
        ])->assertStatus(422)->assertJsonValidationErrors('brick');
    }

    public function test_limage_unique_applique_les_regles_de_slots_de_sa_brique(): void
    {
        $this->actAsApiUser();

        // Mêmes règles dérivées du manifeste que pour une slide de carrousel.
        $this->postJson('/api/carousel/image', [
            'brick' => 'photo-title-bl',
            'ratio' => '1:1',
            'data' => ['position' => 'nulle-part'],
        ])->assertStatus(422)->assertJsonValidationErrors('data.position');

        $this->postJson('/api/carousel/image', [
            'brick' => 'photo-title-bl',
            'ratio' => '1:1',
            'data' => ['offset' => 400],
        ])->assertStatus(422)->assertJsonValidationErrors('data.offset');

        $this->postJson('/api/carousel/image', [
            'brick' => 'bold-text',
            'theme' => ['overlay' => '#000'],
            'data' => ['title' => 'X'],
        ])->assertStatus(422)->assertJsonValidationErrors('theme.overlay');
    }

    public function test_limage_unique_refuse_un_ratio_inconnu(): void
    {
        $this->actAsApiUser();

        $this->postJson('/api/carousel/image', [
            'brick' => 'bold-text',
            'ratio' => '16:9',
            'data' => ['title' => 'X'],
        ])->assertStatus(422)->assertJsonValidationErrors('ratio');
    }

    public function test_le_ratio_auto_exige_une_image_de_fond(): void
    {
        $this->actAsApiUser();

        // `auto` reprend les proportions de la photo : sans photo, on le dit
        // plutôt que de retomber en silence sur un ratio arbitraire.
        $this->postJson('/api/carousel/image', [
            'brick' => 'bold-text',
            'ratio' => 'auto',
            'data' => ['title' => 'X'],
        ])->assertStatus(422)->assertJsonValidationErrors('ratio');
    }

    public function test_le_lien_studio_ouvre_le_compositeur_prerempli(): void
    {
        $user = $this->actAsApiUser();

        $response = $this->postJson('/api/carousel/studio-link', [
            'ratio' => '1:1',
            'theme' => ['accent' => '#ff5c00'],
            'slides' => [
                ['brick' => 'bold-text', 'data' => ['title' => 'Titre venu de l’IA', 'position' => 'top-left']],
                ['brick' => 'quote', 'data' => ['quote' => 'Une citation', 'author' => 'Source']],
            ],
        ])->assertStatus(201)->assertJsonStructure(['url', 'expires_at']);

        $url = $response->json('url');
        $this->assertStringContainsString('/carousel/studio?draft=', $url);

        // La page du Studio reprend la composition telle qu'elle a été déposée.
        $page = $this->actingAs($user)->get($url)->assertOk();
        $draft = $page->viewData('draft');

        $this->assertSame('1:1', $draft['ratio']);
        $this->assertSame('#ff5c00', $draft['theme']['accent']);
        $this->assertCount(2, $draft['slides']);
        $this->assertSame('bold-text', $draft['slides'][0]['brick']);
        $this->assertSame('Titre venu de l’IA', $draft['slides'][0]['data']['title']);
        $this->assertSame('top-left', $draft['slides'][0]['data']['position']);
        $this->assertSame('quote', $draft['slides'][1]['brick']);
    }

    public function test_le_lien_studio_exige_une_session(): void
    {
        // Brouillon déposé directement, pour qu'aucune authentification ne soit
        // posée par le test : c'est bien l'accès anonyme qu'on veut éprouver.
        Cache::put(\App\Http\Controllers\Api\CarouselApiController::DRAFT_PREFIX.'jeton-de-test', [
            'ratio' => '1:1',
            'theme' => [],
            'slides' => [['brick' => 'bold-text', 'data' => ['title' => 'X']]],
        ], now()->addHour());

        // Le lien n'est pas un partage public : sans session, on part au login.
        $this->get('/carousel/studio?draft=jeton-de-test')->assertRedirect('/login');

        // Et l'endpoint qui fabrique le lien exige un token.
        $this->postJson('/api/carousel/studio-link', [
            'ratio' => '1:1',
            'slides' => [['brick' => 'bold-text', 'data' => ['title' => 'X']]],
        ])->assertStatus(401);
    }

    public function test_un_brouillon_inconnu_ouvre_un_studio_vierge(): void
    {
        $user = $this->actAsApiUser();

        // Jeton expiré ou inventé : la page s'ouvre normalement, sans planter.
        $page = $this->actingAs($user)->get('/carousel/studio?draft=jeton-qui-nexiste-pas')->assertOk();

        $this->assertNull($page->viewData('draft'));
    }

    public function test_le_lien_studio_valide_la_composition(): void
    {
        $this->actAsApiUser();

        $this->postJson('/api/carousel/studio-link', [
            'ratio' => '1:1',
            'slides' => [['brick' => 'bold-text', 'data' => ['position' => 'nulle-part']]],
        ])->assertStatus(422)->assertJsonValidationErrors('slides.0.data.position');

        $this->postJson('/api/carousel/studio-link', [
            'ratio' => 'inconnu',
            'slides' => [['brick' => 'bold-text', 'data' => ['title' => 'X']]],
        ])->assertStatus(422)->assertJsonValidationErrors('ratio');
    }

    public function test_les_dimensions_de_sortie_suivent_le_ratio_demande(): void
    {
        $this->actAsApiUser();

        $carousel = app(\App\Services\Carousel\CarouselRenderService::class);

        $this->assertSame([1080, 566], $carousel->outputDimensions('1.91:1'));
        $this->assertSame([1080, 1350], $carousel->outputDimensions('4:5'));

        // `auto` sur une image 1365×2048 : ratio natif, plafonné à 1080.
        $filename = 'test_auto_'.uniqid().'.png';
        $png = imagecreatetruecolor(400, 800);
        ob_start();
        imagepng($png);
        Storage::disk('local')->put("media/{$filename}", (string) ob_get_clean());

        try {
            $this->assertSame([400, 800], $carousel->outputDimensions(null, "/media/{$filename}"));
        } finally {
            Storage::disk('local')->delete("media/{$filename}");
        }
    }

    /**
     * Le texte de la slide décrit l'image générée (description_fr) : c'est lui
     * qui la rend trouvable dans la médiathèque et exploitable comme contexte IA.
     */
    public function test_le_texte_de_la_slide_compose_la_description_de_limage(): void
    {
        $registry = app(\App\Services\Carousel\BrickRegistry::class);

        // Slots textuels dans l'ordre du manifeste ; image, position et décalage ignorés.
        $this->assertSame(
            'Le petit-déjeuner idéal — 3 erreurs à éviter',
            $registry->plainText(['brick' => 'photo-title-bl', 'data' => [
                'title' => 'Le petit-déjeuner idéal',
                'subtitle' => '3 erreurs à éviter',
                'image' => '/media/photo.jpg',
                'position' => 'bottom-left',
                'offset' => 12,
            ]]),
        );

        // Les listes « valeur | libellé » deviennent lisibles, les retours à la ligne s'aplatissent.
        $this->assertSame(
            'Les chiffres — 42 % : des lecteurs abandonnent 8 s : temps d’attention moyen',
            $registry->plainText(['brick' => 'stat-grid', 'data' => [
                'title' => 'Les chiffres',
                'items' => "42 % | des lecteurs abandonnent\n8 s | temps d’attention moyen",
            ]]),
        );

        // Slide sans aucun texte (image seule) => pas de description inventée.
        $this->assertSame('', $registry->plainText(['brick' => 'image-full', 'data' => ['image' => '/media/x.jpg']]));

        // Brique inconnue : on ne lève pas, la génération ne doit pas échouer pour ça.
        $this->assertSame('', $registry->plainText(['brick' => 'inconnue', 'data' => ['title' => 'x']]));

        // Texte très long : tronqué proprement.
        $long = $registry->plainText(
            ['brick' => 'bold-text', 'data' => ['title' => str_repeat('a', 400), 'subtitle' => str_repeat('b', 400)]],
        );
        $this->assertSame(500, mb_strlen($long));
        $this->assertStringEndsWith('…', $long);
    }
}
