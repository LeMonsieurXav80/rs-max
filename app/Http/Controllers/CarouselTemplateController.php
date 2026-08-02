<?php

namespace App\Http\Controllers;

use App\Models\CarouselBrick;
use App\Services\Carousel\BrickRegistry;
use App\Services\Carousel\CarouselRenderService;
use App\Services\Carousel\TemplateRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Bibliothèque de « templates » = gabarits de slide (briques) stockés en BASE.
 *
 * Sort les gabarits du code : on les liste avec un aperçu visuel réel, on les
 * crée, duplique, modifie et supprime depuis l'interface — sans déploiement.
 *
 * L'aperçu (galerie comme éditeur) est du HTML rendu dans une iframe `srcdoc`,
 * SANS Chromium : c'est le navigateur qui affiche, donc c'est instantané.
 *
 * Sécurité : le gabarit n'est jamais compilé (pas de Blade en base). Il est
 * validé à l'enregistrement par TemplateRenderer::violations() puis rendu par
 * substitution échappée. Voir TemplateRenderer.
 */
class CarouselTemplateController extends Controller
{
    public function index(BrickRegistry $registry, CarouselRenderService $carousel): View
    {
        $ratio = config('carousel.default_ratio', '4:5');

        // Une seule requête pour tous les identifiants (et non une par brique).
        // Tolère l'absence de table : sans migration, la galerie affiche au moins
        // les briques fournies au lieu de renvoyer une erreur 500.
        try {
            $ids = CarouselBrick::pluck('id', 'slug');
            $migrated = true;
        } catch (\Throwable $e) {
            $ids = collect();
            $migrated = false;
        }

        $templates = collect($registry->all())->map(fn (array $brick) => [
            'slug' => $brick['slug'],
            'name' => $brick['name'],
            'description' => $brick['description'],
            'is_builtin' => $brick['is_builtin'],
            'slots' => count($brick['slots']),
            'id' => $ids[$brick['slug']] ?? null,
            // Aperçu réel de la brique, alimenté par ses données d'exemple.
            // embedFonts:false => les polices passent par la route mise en cache.
            'preview' => $carousel->buildHtml($ratio, [[
                'brick' => $brick['slug'],
                'data' => $registry->sampleData($brick['slug']),
            ]], embedFonts: false),
        ])->values();

        return view('carousel.templates.index', [
            'templates' => $templates,
            'ratio' => $ratio,
            'dims' => config("carousel.ratios.{$ratio}"),
            'migrated' => $migrated,
        ]);
    }

    public function create(Request $request, BrickRegistry $registry, TemplateRenderer $renderer): View
    {
        // ?from=slug : duplication d'un template existant (ou d'une brique fournie).
        $from = $request->query('from');
        $source = $from && $registry->exists($from) ? $registry->get($from) : null;

        $html = $source['template'] ?? $this->starterHtml();

        $draft = new CarouselBrick([
            'name' => $source ? $source['name'].' (copie)' : '',
            'description' => $source['description'] ?? '',
            'ratios' => $source['ratios'] ?? ['*'],
            'slots' => $renderer->extractSlots($html),
            'html' => $html,
            'css' => $source ? ($source['css'] ?? null) : $this->starterCss(),
        ]);

        // Données d'exemple pré-remplies : l'aperçu est parlant dès l'ouverture.
        $draft->sample_data = $source
            ? $registry->sampleData($from)
            : $registry->sampleFor($draft->slots);

        return $this->form($draft, 'create');
    }

    public function edit(CarouselBrick $template): View
    {
        return $this->form($template, 'edit');
    }

    public function store(Request $request, TemplateRenderer $renderer): RedirectResponse
    {
        $data = $this->validated($request, $renderer, null);
        $data['user_id'] = $request->user()->id;

        $brick = CarouselBrick::create($data);

        return redirect()->route('carousel.templates.edit', $brick)
            ->with('status', 'template-created');
    }

    public function update(Request $request, CarouselBrick $template, TemplateRenderer $renderer): RedirectResponse
    {
        $template->update($this->validated($request, $renderer, $template));

        return redirect()->route('carousel.templates.edit', $template)
            ->with('status', 'template-updated');
    }

    public function destroy(CarouselBrick $template): RedirectResponse
    {
        $template->delete();

        return redirect()->route('carousel.templates.index')
            ->with('status', 'template-deleted');
    }

    /**
     * Aperçu live de l'éditeur : rend un gabarit NON ENREGISTRÉ. La brique
     * n'existe pas encore dans le registre, d'où ce chemin dédié.
     */
    public function preview(Request $request, CarouselRenderService $carousel): Response
    {
        $validated = $request->validate([
            'html' => ['nullable', 'string', 'max:60000'],
            'css' => ['nullable', 'string', 'max:30000'],
            'ratio' => ['nullable', 'string', 'in:'.implode(',', array_keys(config('carousel.ratios', [])))],
            'data' => ['nullable', 'array'],
        ]);

        $html = $carousel->buildTemplateHtml(
            $validated['ratio'] ?? config('carousel.default_ratio', '4:5'),
            (string) ($validated['html'] ?? ''),
            $this->scalarsOnly($validated['data'] ?? []),
            embedFonts: false,
            css: $validated['css'] ?? null,
        );

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function form(CarouselBrick $template, string $mode): View
    {
        return view('carousel.templates.form', [
            'template' => $template,
            'mode' => $mode,
            'ratios' => config('carousel.ratios', []),
            // Image d'illustration pour l'aperçu live des slots de type image.
            'sampleImage' => app(BrickRegistry::class)->sampleFor(['image' => ['type' => 'image']])['image'] ?? '',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, TemplateRenderer $renderer, ?CarouselBrick $existing): array
    {
        $unique = 'unique:carousel_bricks,slug'.($existing ? ','.$existing->id : '');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/', $unique],
            'description' => ['nullable', 'string', 'max:500'],
            'ratios' => ['nullable', 'array'],
            'ratios.*' => ['string'],
            'html' => ['required', 'string', 'max:60000'],
            'css' => ['nullable', 'string', 'max:30000'],
            'sample_data' => ['nullable', 'array'],
        ], [
            'slug.regex' => 'L’identifiant ne peut contenir que des minuscules, chiffres et tirets.',
        ]);

        // Ni gabarit ni feuille de style ne doivent contenir de script,
        // de ressource externe ou de PHP.
        if ($problems = $renderer->violations($validated['html'])) {
            throw ValidationException::withMessages(['html' => $problems]);
        }
        if (! empty($validated['css']) && $problems = $renderer->violations($validated['css'])) {
            throw ValidationException::withMessages(['css' => $problems]);
        }

        $slug = $validated['slug'] ?? null;
        if (! $slug) {
            $slug = $existing?->slug ?: $this->uniqueSlug($validated['name']);
        }

        return [
            'slug' => $slug,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'ratios' => $validated['ratios'] ?? ['*'],
            // Les champs éditables sont DÉDUITS du gabarit : écrire {{ titre }}
            // suffit à créer le champ. Rien à déclarer à la main.
            'slots' => $renderer->extractSlots($validated['html']),
            'html' => $validated['html'],
            'css' => $validated['css'] ?? null,
            'sample_data' => $this->scalarsOnly($validated['sample_data'] ?? []),
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'template';
        $slug = $base;
        $i = 2;
        while (CarouselBrick::where('slug', $slug)->exists() || config("carousel.bricks.{$slug}")) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /**
     * Gabarit de départ d'un nouveau template : chaque marqueur crée son champ.
     */
    private function starterHtml(): string
    {
        return <<<'HTML'
<div class="slide">
  {{#if image}}
    <img class="fond" src="{{ image }}" alt="">
  {{/if}}

  <div class="brick-scrim"></div>

  <div class="bloc">
    {{#if title}}<h1>{{ title }}</h1>{{/if}}
    {{#if subtitle}}<p>{{ subtitle }}</p>{{/if}}
  </div>
</div>
HTML;
    }

    /**
     * Feuille de style de départ. Les tailles sont en cqh/cqw (6cqh = 6 % de la
     * hauteur du slide) pour que le template tienne dans tous les formats.
     */
    private function starterCss(): string
    {
        return <<<'CSS'
.slide { position:absolute; inset:0; background:var(--bg); }
.fond  { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }

.bloc {
  position:absolute; inset:0; padding:7cqw;
  display:flex; flex-direction:column;
  justify-content:var(--justify); align-items:var(--align);
  text-align:var(--text-align);
}

h1 {
  margin:0; font-family:var(--title-font); font-weight:800;
  font-size:6.2cqh; line-height:1.08; color:var(--text);
}

p {
  margin:2.2cqh 0 0; font-family:var(--body-font);
  font-size:3cqh; line-height:1.35; color:var(--text); opacity:0.88;
}
CSS;
    }

    /**
     * @return array<string, string>
     */
    private function scalarsOnly(array $data): array
    {
        return array_map(
            fn ($v) => (string) $v,
            array_filter($data, fn ($v) => is_scalar($v))
        );
    }
}
