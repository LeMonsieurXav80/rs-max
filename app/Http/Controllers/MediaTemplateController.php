<?php

namespace App\Http\Controllers;

use App\Models\MediaTemplate;
use App\Services\GoogleFontsService;
use App\Services\Pinterest\PinterestImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class MediaTemplateController extends Controller
{
    public function index(): View
    {
        $templates = MediaTemplate::orderBy('format')->orderBy('name')->get();
        $fontsService = new GoogleFontsService;
        $localFonts = $fontsService->getLocalFonts();

        return view('media.templates', compact('templates', 'localFonts'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'format' => 'required|string|in:' . implode(',', array_keys(MediaTemplate::FORMATS)),
            'layout' => 'required|string|in:' . implode(',', array_keys(MediaTemplate::LAYOUTS)),
            'title_font' => 'required|string|max:100',
            'title_font_weight' => 'required|string|max:20',
            'title_font_size' => 'required|integer|min:16|max:120',
            'body_font' => 'nullable|string|max:100',
            'body_font_weight' => 'nullable|string|max:20',
            'body_font_size' => 'nullable|integer|min:12|max:80',
            'colors' => 'required|array',
            'colors.background' => 'required|string|max:7',
            'colors.text' => 'required|string|max:7',
            'colors.accent' => 'nullable|string|max:7',
            'colors.overlay_opacity' => 'nullable|numeric|min:0|max:1',
            'colors.title_band_color' => 'nullable|string|max:7',
            'colors.title_band_opacity' => 'nullable|numeric|min:0|max:1',
            'border_enabled' => 'boolean',
            'border_type' => 'nullable|string|in:none,solid,pattern',
            'border_color' => 'nullable|string|max:7',
            'border_thickness' => 'nullable|integer|min:0|max:100',
            'border_inner_padding' => 'nullable|integer|min:0|max:50',
            'border_pattern' => 'nullable|image|max:2048',
        ]);

        $format = MediaTemplate::FORMATS[$validated['format']];
        $slug = Str::slug($validated['name']) . '-' . Str::random(4);

        // Ensure fonts are downloaded
        $fontsService = new GoogleFontsService;
        $fontsService->ensureFont($validated['title_font'], $validated['title_font_weight']);
        if (! empty($validated['body_font'])) {
            $fontsService->ensureFont($validated['body_font'], $validated['body_font_weight'] ?? 'Regular');
        }

        // Handle border pattern upload
        $border = null;
        if ($request->boolean('border_enabled')) {
            $border = [
                'enabled' => true,
                'type' => $validated['border_type'] ?? 'solid',
                'color' => $validated['border_color'] ?? '#000000',
                'thickness' => $validated['border_thickness'] ?? 40,
                'inner_padding' => $validated['border_inner_padding'] ?? 10,
                'pattern_image' => null,
            ];

            if ($request->hasFile('border_pattern')) {
                $path = $request->file('border_pattern')->store('template-borders', 'public');
                $border['pattern_image'] = $path;
            }
        }

        $template = MediaTemplate::create([
            'name' => $validated['name'],
            'slug' => $slug,
            'format' => $validated['format'],
            'width' => $format['width'],
            'height' => $format['height'],
            'layout' => $validated['layout'],
            'title_font' => $validated['title_font'],
            'title_font_weight' => $validated['title_font_weight'],
            'title_font_size' => $validated['title_font_size'],
            'body_font' => $validated['body_font'] ?? null,
            'body_font_weight' => $validated['body_font_weight'] ?? null,
            'body_font_size' => $validated['body_font_size'] ?? null,
            'colors' => $validated['colors'],
            'border' => $border,
        ]);

        return back()->with('success', "Template \"{$template->name}\" créé.");
    }

    public function update(Request $request, MediaTemplate $template): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'layout' => 'required|string|in:' . implode(',', array_keys(MediaTemplate::LAYOUTS)),
            'title_font' => 'required|string|max:100',
            'title_font_weight' => 'required|string|max:20',
            'title_font_size' => 'required|integer|min:16|max:120',
            'body_font' => 'nullable|string|max:100',
            'body_font_weight' => 'nullable|string|max:20',
            'body_font_size' => 'nullable|integer|min:12|max:80',
            'colors' => 'required|array',
            'colors.background' => 'required|string|max:7',
            'colors.text' => 'required|string|max:7',
            'colors.accent' => 'nullable|string|max:7',
            'colors.overlay_opacity' => 'nullable|numeric|min:0|max:1',
            'colors.title_band_color' => 'nullable|string|max:7',
            'colors.title_band_opacity' => 'nullable|numeric|min:0|max:1',
            'border_enabled' => 'boolean',
            'border_type' => 'nullable|string|in:none,solid,pattern',
            'border_color' => 'nullable|string|max:7',
            'border_thickness' => 'nullable|integer|min:0|max:100',
            'border_inner_padding' => 'nullable|integer|min:0|max:50',
            'border_pattern' => 'nullable|image|max:2048',
        ]);

        // Ensure fonts are downloaded
        $fontsService = new GoogleFontsService;
        $fontsService->ensureFont($validated['title_font'], $validated['title_font_weight']);
        if (! empty($validated['body_font'])) {
            $fontsService->ensureFont($validated['body_font'], $validated['body_font_weight'] ?? 'Regular');
        }

        // Handle border
        $border = $template->border;
        if ($request->boolean('border_enabled')) {
            $border = [
                'enabled' => true,
                'type' => $validated['border_type'] ?? 'solid',
                'color' => $validated['border_color'] ?? '#000000',
                'thickness' => $validated['border_thickness'] ?? 40,
                'inner_padding' => $validated['border_inner_padding'] ?? 10,
                'pattern_image' => $border['pattern_image'] ?? null,
            ];

            if ($request->hasFile('border_pattern')) {
                // Delete old pattern
                if (! empty($border['pattern_image'])) {
                    Storage::disk('public')->delete($border['pattern_image']);
                }
                $path = $request->file('border_pattern')->store('template-borders', 'public');
                $border['pattern_image'] = $path;
            }
        } else {
            $border = null;
        }

        $template->update([
            'name' => $validated['name'],
            'layout' => $validated['layout'],
            'title_font' => $validated['title_font'],
            'title_font_weight' => $validated['title_font_weight'],
            'title_font_size' => $validated['title_font_size'],
            'body_font' => $validated['body_font'] ?? null,
            'body_font_weight' => $validated['body_font_weight'] ?? null,
            'body_font_size' => $validated['body_font_size'] ?? null,
            'colors' => $validated['colors'],
            'border' => $border,
        ]);

        return back()->with('success', "Template \"{$template->name}\" mis à jour.");
    }

    public function destroy(MediaTemplate $template): RedirectResponse
    {
        // Delete border pattern file
        if (! empty($template->border['pattern_image'])) {
            Storage::disk('public')->delete($template->border['pattern_image']);
        }

        // Delete preview
        if ($template->preview_path) {
            Storage::disk('public')->delete($template->preview_path);
        }

        $name = $template->name;
        $template->delete();

        return back()->with('success', "Template \"{$name}\" supprimé.");
    }

    /**
     * AJAX: Download a Google Font.
     */
    public function downloadFont(Request $request): JsonResponse
    {
        $request->validate([
            'family' => 'required|string|max:100',
            'weight' => 'required|string|max:20',
        ]);

        $service = new GoogleFontsService;
        $path = $service->ensureFont($request->input('family'), $request->input('weight'));

        if (! $path) {
            return response()->json(['error' => 'Impossible de télécharger cette police.'], 500);
        }

        return response()->json([
            'success' => true,
            'family' => $request->input('family'),
            'weight' => $request->input('weight'),
            'path' => $path,
        ]);
    }

    /**
     * AJAX: Generate a preview image for a template.
     */
    public function preview(Request $request, MediaTemplate $template): JsonResponse
    {
        $sampleTitle = $request->input('title', '7 destinations vanlife incontournables en Europe');

        // TODO: Use TemplateImageService to render a preview
        // For now, return the template data
        return response()->json([
            'template' => $template,
            'message' => 'Preview generation coming soon',
        ]);
    }
}
