<?php

namespace App\Http\Controllers;

use App\Models\WpSource;
use App\Services\WordPress\WordPressFetchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WordPressSiteController extends Controller
{
    public function index(Request $request): View
    {
        if (! $request->user()->isManager()) {
            abort(403);
        }

        $sources = WpSource::withCount('wpItems')
            ->with('socialAccounts.platform')
            ->orderBy('name')
            ->get();

        return view('wordpress.index', compact('sources'));
    }

    public function create(Request $request): View
    {
        if (! $request->user()->isManager()) {
            abort(403);
        }

        return view('wordpress.create');
    }

    public function store(Request $request)
    {
        if (! $request->user()->isManager()) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'url' => 'required|url|max:2000',
            'description' => 'nullable|string|max:500',
            'auth_username' => 'nullable|string|max:255',
            'auth_password' => 'nullable|string|max:255',
            'post_types' => 'required|array|min:1',
            'post_types.*' => 'string|max:50',
            'categories' => 'nullable|array',
            'categories.*' => 'integer',
            'language' => 'nullable|string|max:10',
            'is_active' => 'boolean',
        ], [
            'post_types.required' => 'Veuillez d\'abord tester la connexion et sélectionner au moins un type de contenu.',
            'post_types.min' => 'Veuillez sélectionner au moins un type de contenu.',
            'name.required' => 'Le nom est obligatoire.',
            'url.required' => 'L\'URL du site est obligatoire.',
            'url.url' => 'L\'URL fournie n\'est pas valide.',
        ]);

        $source = WpSource::create([
            'name' => $validated['name'],
            'url' => $validated['url'],
            'description' => $validated['description'] ?? null,
            'auth_username' => $validated['auth_username'] ?? null,
            'auth_password' => $validated['auth_password'] ?? null,
            'post_types' => $validated['post_types'],
            'categories' => $validated['categories'] ?? null,
            'language' => $validated['language'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->route('wordpress-sites.index')->with('status', 'source-created');
    }

    public function edit(Request $request, WpSource $wpSource): View
    {
        if (! $request->user()->isManager()) {
            abort(403);
        }

        return view('wordpress.edit', compact('wpSource'));
    }

    public function update(Request $request, WpSource $wpSource)
    {
        if (! $request->user()->isManager()) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'url' => 'required|url|max:2000',
            'description' => 'nullable|string|max:500',
            'auth_username' => 'nullable|string|max:255',
            'auth_password' => 'nullable|string|max:255',
            'post_types' => 'required|array|min:1',
            'post_types.*' => 'string|max:50',
            'categories' => 'nullable|array',
            'categories.*' => 'integer',
            'language' => 'nullable|string|max:10',
            'is_active' => 'boolean',
        ]);

        $updateData = [
            'name' => $validated['name'],
            'url' => $validated['url'],
            'description' => $validated['description'] ?? null,
            'auth_username' => $validated['auth_username'] ?? null,
            'post_types' => $validated['post_types'],
            'categories' => $validated['categories'] ?? null,
            'language' => $validated['language'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ];

        // Only update password if provided (don't clear existing one)
        if (! empty($validated['auth_password'])) {
            $updateData['auth_password'] = $validated['auth_password'];
        }

        $wpSource->update($updateData);

        return redirect()->route('wordpress-sites.index')->with('status', 'source-updated');
    }

    public function destroy(Request $request, WpSource $wpSource)
    {
        if (! $request->user()->isManager()) {
            abort(403);
        }

        $wpSource->delete();

        return redirect()->route('wordpress-sites.index')->with('status', 'source-deleted');
    }

    // ─── AJAX: Test connection ───────────────────────────────────────

    public function testConnection(Request $request): JsonResponse
    {
        if (! $request->user()->isManager()) {
            abort(403);
        }

        $request->validate([
            'url' => 'required|url',
            'auth_username' => 'nullable|string',
            'auth_password' => 'nullable|string',
        ]);

        $service = new WordPressFetchService;
        $result = $service->testConnection(
            $request->input('url'),
            $request->input('auth_username'),
            $request->input('auth_password'),
        );

        return response()->json($result);
    }

    // ─── Fetch ───────────────────────────────────────────────────────

    public function fetchNow(Request $request, WpSource $wpSource)
    {
        if (! $request->user()->isManager()) {
            abort(403);
        }

        $service = new WordPressFetchService;
        $count = $service->fetchSource($wpSource);

        return redirect()->route('wordpress-sites.index')
            ->with('status', 'source-fetched')
            ->with('fetch_count', $count);
    }
}
