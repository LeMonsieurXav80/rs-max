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

        $templates = collect($registry->all())->map(function (array $brick) use ($registry, $carousel, $ratio) {
            return [
                'slug' => $brick['slug'],
                'name' => $brick['name'],
                'description' => $brick['description'],
                'is_builtin' => $brick['is_builtin'],
                'slots' => count($brick['slots']),
                'id' => CarouselBrick::where('slug', $brick['slug'])->value('id'),
                // Aperçu réel de la brique, alimenté par ses données d'exemple.
                'preview' => $carousel->buildHtml($ratio, [[
                    'brick' => $brick['slug'],
                    'data' => $registry->sampleData($brick['slug']),
                ]]),
            ];
        })->values();

        return view('carousel.templates.index', [
            'templates' => $templates,
            'ratio' => $ratio,
            'dims' => config("carousel.ratios.{$ratio}"),
        ]);
    }

    public function create(Request $request, BrickRegistry $registry): View
    {
        // ?from=slug : duplication d'un template existant (ou d'une brique fournie).
        $from = $request->query('from');
        $source = $from && $registry->exists($from) ? $registry->get($from) : null;

        return $this->form(new CarouselBrick([
            'name' => $source ? $source['name'].' (copie)' : '',
            'description' => $source['description'] ?? '',
            'ratios' => $source['ratios'] ?? ['*'],
            'slots' => $source ? $this->slotsToForm($source['slots']) : $this->defaultSlots(),
            'html' => $source['template'] ?? $this->starterHtml(),
            'sample_data' => $source ? $registry->sampleData($from) : [],
        ]), 'create');
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
            'ratio' => ['nullable', 'string', 'in:'.implode(',', array_keys(config('carousel.ratios', [])))],
            'data' => ['nullable', 'array'],
        ]);

        $html = $carousel->buildTemplateHtml(
            $validated['ratio'] ?? config('carousel.default_ratio', '4:5'),
            (string) ($validated['html'] ?? ''),
            $this->scalarsOnly($validated['data'] ?? []),
        );

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function form(CarouselBrick $template, string $mode): View
    {
        return view('carousel.templates.form', [
            'template' => $template,
            'mode' => $mode,
            'ratios' => config('carousel.ratios', []),
            'positions' => config('carousel.positions', []),
            'slotTypes' => [
                'text' => 'Texte court',
                'textarea' => 'Texte long / liste',
                'image' => 'Image',
                'position' => 'Emplacement (grille 3×3)',
                'range' => 'Curseur',
                'select' => 'Liste de choix',
            ],
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
            'slots' => ['nullable', 'array', 'max:12'],
            'slots.*.key' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/'],
            'slots.*.label' => ['required', 'string', 'max:120'],
            'slots.*.type' => ['required', 'string', 'in:text,textarea,image,position,range,select'],
            'slots.*.default' => ['nullable', 'string', 'max:200'],
            'sample_data' => ['nullable', 'array'],
        ], [
            'slots.*.key.regex' => 'Une clé de champ doit être en minuscules, sans espace (ex. « titre_bas »).',
            'slug.regex' => 'L’identifiant ne peut contenir que des minuscules, chiffres et tirets.',
        ]);

        // Un gabarit ne doit contenir ni script, ni ressource externe, ni PHP.
        if ($problems = $renderer->violations($validated['html'])) {
            throw ValidationException::withMessages(['html' => $problems]);
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
            'slots' => $this->slotsFromForm($validated['slots'] ?? []),
            'html' => $validated['html'],
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
     * Formulaire (liste indexée) → manifeste (clé => définition).
     */
    private function slotsFromForm(array $slots): array
    {
        $out = [];
        foreach ($slots as $slot) {
            $def = ['label' => $slot['label'], 'type' => $slot['type']];

            if (($slot['default'] ?? '') !== '' && $slot['default'] !== null) {
                $def['default'] = $slot['default'];
            }
            if ($slot['type'] === 'range') {
                $def += ['min' => -25, 'max' => 25, 'step' => 1, 'unit' => '%'];
            }

            $out[$slot['key']] = $def;
        }

        return $out;
    }

    /**
     * Manifeste normalisé → liste pour le formulaire.
     */
    private function slotsToForm(array $slots): array
    {
        return array_values(array_map(fn (array $s) => [
            'key' => $s['key'],
            'label' => $s['label'],
            'type' => $s['type'],
            'default' => is_scalar($s['default'] ?? null) ? (string) $s['default'] : '',
        ], $slots));
    }

    private function defaultSlots(): array
    {
        return [
            ['key' => 'image', 'label' => 'Image de fond', 'type' => 'image', 'default' => ''],
            ['key' => 'title', 'label' => 'Titre', 'type' => 'text', 'default' => ''],
        ];
    }

    private function starterHtml(): string
    {
        return <<<'HTML'
<!-- Les tailles s'expriment en cqh/cqw : 6cqh = 6 % de la hauteur du slide. -->
<div style="position:absolute; inset:0; background:var(--bg);">
  {{#if image}}
    <img src="{{ image }}" alt="" style="position:absolute; inset:0; width:100%; height:100%; object-fit:cover;">
  {{/if}}

  <div class="brick-scrim"></div>

  <div style="position:absolute; inset:0; display:flex; flex-direction:column;
              justify-content:var(--justify); align-items:var(--align);
              padding:7cqw; text-align:var(--text-align);">
    {{#if title}}
      <h1 style="margin:0; font-family:var(--title-font); font-weight:800;
                 font-size:6.2cqh; line-height:1.08; color:var(--text);">{{ title }}</h1>
    {{/if}}
  </div>
</div>
HTML;
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
