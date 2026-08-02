<?php

namespace Tests\Feature;

use App\Models\CarouselBrick;
use App\Models\User;
use App\Services\Carousel\BrickRegistry;
use App\Services\Carousel\CarouselRenderService;
use App\Services\Carousel\TemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Templates de carrousel = gabarits de slide stockés en base.
 *
 * L'enjeu central est la SÉCURITÉ : un gabarit venant de la base ne doit jamais
 * être exécuté, et les données de slots doivent toujours ressortir échappées.
 */
class CarouselTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        return User::factory()->create(['role' => 'manager']);
    }

    private function renderer(): TemplateRenderer
    {
        return app(TemplateRenderer::class);
    }

    private function brick(array $overrides = []): CarouselBrick
    {
        return CarouselBrick::create(array_merge([
            'slug' => 'ma-brique',
            'name' => 'Ma brique',
            'description' => 'Un gabarit de test.',
            'ratios' => ['*'],
            'slots' => [
                'title' => ['label' => 'Titre', 'type' => 'text'],
                'subtitle' => ['label' => 'Sous-titre', 'type' => 'text'],
            ],
            'html' => '<div class="t">{{ title }}{{#if subtitle}}<p>{{ subtitle }}</p>{{/if}}</div>',
        ], $overrides));
    }

    // ── Moteur de rendu ────────────────────────────────────────────────

    public function test_le_moteur_substitue_les_marqueurs(): void
    {
        $html = $this->renderer()->render(
            '<h1>{{ title }}</h1>',
            ['title' => 'Bonjour'],
            [],
            1080,
            1350
        );

        $this->assertStringContainsString('<h1>Bonjour</h1>', $html);
    }

    public function test_le_conditionnel_masque_un_slot_vide(): void
    {
        $template = '{{#if subtitle}}<p>{{ subtitle }}</p>{{/if}}A{{#unless subtitle}}B{{/unless}}';

        $avec = $this->renderer()->render($template, ['subtitle' => 'Présent'], [], 1080, 1350);
        $sans = $this->renderer()->render($template, [], [], 1080, 1350);

        $this->assertStringContainsString('<p>Présent</p>', $avec);
        $this->assertStringNotContainsString('B', $avec);
        $this->assertStringNotContainsString('<p>', $sans);
        $this->assertStringContainsString('B', $sans);
    }

    public function test_la_boucle_each_deroule_les_lignes(): void
    {
        $html = $this->renderer()->render(
            '{{#each items}}<li>{{ index }}. {{ left }} = {{ right }}</li>{{/each}}',
            ['items' => "26|essais\n1 036|participants"],
            [],
            1080,
            1350
        );

        $this->assertStringContainsString('<li>1. 26 = essais</li>', $html);
        $this->assertStringContainsString('<li>2. 1 036 = participants</li>', $html);
    }

    public function test_une_donnee_de_slot_ressort_echappee(): void
    {
        $html = $this->renderer()->render(
            '<h1>{{ title }}</h1>',
            ['title' => '<script>alert(1)</script>'],
            [],
            1080,
            1350
        );

        // Le script ne doit exister que sous forme échappée.
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_un_gabarit_dangereux_est_signale(): void
    {
        $renderer = $this->renderer();

        $this->assertNotEmpty($renderer->violations('<script>alert(1)</script>'));
        $this->assertNotEmpty($renderer->violations('<div onclick="x()"></div>'));
        $this->assertNotEmpty($renderer->violations('<img src="https://evil.tld/p.png">'));
        $this->assertNotEmpty($renderer->violations('<?php echo 1; ?>'));
        $this->assertSame([], $renderer->violations('<div style="color:var(--text)">{{ title }}</div>'));
    }

    // ── Intégration au système de carrousel ────────────────────────────

    public function test_une_brique_en_base_est_vue_par_le_registre_et_rendue(): void
    {
        $this->brick();

        $registry = app(BrickRegistry::class);
        $this->assertTrue($registry->exists('ma-brique'));
        $this->assertFalse($registry->get('ma-brique')['is_builtin']);

        $html = app(CarouselRenderService::class)->buildHtml('4:5', [
            ['brick' => 'ma-brique', 'data' => ['title' => 'Depuis la base']],
        ]);

        $this->assertStringContainsString('Depuis la base', $html);
        $this->assertStringContainsString('data-carousel-slide', $html);
    }

    public function test_une_brique_en_base_apparait_dans_lapi(): void
    {
        $this->brick();

        $response = $this->actingAs($this->manager())->getJson('/api/carousel/bricks')->assertOk();

        $slugs = array_column($response->json('bricks'), 'slug');
        $this->assertContains('ma-brique', $slugs);
        // Les briques fournies restent présentes.
        $this->assertContains('photo-title-bl', $slugs);
    }

    // ── Pages ──────────────────────────────────────────────────────────

    public function test_la_galerie_liste_les_templates_avec_un_apercu(): void
    {
        $this->brick();

        $this->actingAs($this->manager())
            ->get(route('carousel.templates.index'))
            ->assertOk()
            ->assertSee('Ma brique')
            ->assertSee('Photo + titre positionnable')
            ->assertSee('srcdoc', false);
    }

    public function test_on_peut_creer_un_template(): void
    {
        $this->actingAs($this->manager())
            ->post(route('carousel.templates.store'), [
                'name' => 'Titre géant',
                'html' => '<div style="color:var(--text)">{{ title }}</div>',
                'slots' => [['key' => 'title', 'label' => 'Titre', 'type' => 'text']],
            ])
            ->assertRedirect();

        $brick = CarouselBrick::firstWhere('name', 'Titre géant');
        $this->assertNotNull($brick);
        $this->assertSame('titre-geant', $brick->slug);
        $this->assertSame('text', $brick->slots['title']['type']);
    }

    public function test_un_gabarit_avec_script_est_refuse_a_lenregistrement(): void
    {
        $this->actingAs($this->manager())
            ->post(route('carousel.templates.store'), [
                'name' => 'Malveillant',
                'html' => '<script>fetch("//evil.tld")</script>',
                'slots' => [],
            ])
            ->assertSessionHasErrors('html');

        $this->assertDatabaseCount('carousel_bricks', 0);
    }

    public function test_un_utilisateur_simple_na_pas_acces_aux_templates(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)->get(route('carousel.templates.index'))->assertForbidden();
        $this->actingAs($user)->post(route('carousel.templates.store'), [
            'name' => 'X', 'html' => '<div>{{ title }}</div>',
        ])->assertForbidden();
    }

    public function test_on_peut_supprimer_un_template(): void
    {
        $brick = $this->brick();

        $this->actingAs($this->manager())
            ->delete(route('carousel.templates.destroy', $brick))
            ->assertRedirect(route('carousel.templates.index'));

        $this->assertDatabaseCount('carousel_bricks', 0);
    }
}
