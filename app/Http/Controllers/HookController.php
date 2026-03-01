<?php

namespace App\Http\Controllers;

use App\Models\Hook;
use App\Models\HookCategory;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HookController extends Controller
{
    public function index(Request $request): View
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $categories = HookCategory::ordered()
            ->withCount('hooks')
            ->with(['hooks' => fn ($q) => $q->orderBy('times_used')->orderBy('created_at', 'desc')])
            ->get();

        $currentCategoryId = $request->input('category');
        $currentCategory = $currentCategoryId ? HookCategory::find($currentCategoryId) : null;

        return view('hooks.index', compact('categories', 'currentCategory'));
    }

    public function create(Request $request): View
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $categories = HookCategory::ordered()->active()->get();
        $selectedCategoryId = $request->input('category');

        return view('hooks.create', compact('categories', 'selectedCategoryId'));
    }

    public function store(Request $request)
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $validated = $request->validate([
            'hook_category_id' => 'required|exists:hook_categories,id',
            'content' => 'required|string|max:2000',
            'is_active' => 'boolean',
        ]);

        $validated['is_active'] = $request->boolean('is_active', true);

        Hook::create($validated);

        return redirect()->route('hooks.index', ['category' => $validated['hook_category_id']])
            ->with('status', 'hook-created');
    }

    public function edit(Request $request, Hook $hook): View
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $hook->load('category');
        $categories = HookCategory::ordered()->active()->get();

        return view('hooks.edit', compact('hook', 'categories'));
    }

    public function update(Request $request, Hook $hook)
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $validated = $request->validate([
            'hook_category_id' => 'required|exists:hook_categories,id',
            'content' => 'required|string|max:2000',
            'is_active' => 'boolean',
        ]);

        $validated['is_active'] = $request->boolean('is_active', true);

        $hook->update($validated);

        return redirect()->route('hooks.index', ['category' => $hook->hook_category_id])
            ->with('status', 'hook-updated');
    }

    public function destroy(Request $request, Hook $hook)
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $categoryId = $hook->hook_category_id;
        $hook->delete();

        return redirect()->route('hooks.index', ['category' => $categoryId])
            ->with('status', 'hook-deleted');
    }

    // -------------------------------------------------------------------------
    //  Category management (AJAX-friendly)
    // -------------------------------------------------------------------------

    public function storeCategory(Request $request)
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'color' => 'nullable|string|max:7',
        ]);

        $category = HookCategory::create($validated);

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'category' => $category]);
        }

        return redirect()->route('hooks.index', ['category' => $category->id])
            ->with('status', 'category-created');
    }

    public function updateCategory(Request $request, HookCategory $category)
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'color' => 'nullable|string|max:7',
        ]);

        $category->update($validated);

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'category' => $category]);
        }

        return redirect()->route('hooks.index', ['category' => $category->id])
            ->with('status', 'category-updated');
    }

    public function destroyCategory(Request $request, HookCategory $category)
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $category->delete();

        if ($request->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect()->route('hooks.index')->with('status', 'category-deleted');
    }

    public function resetCounters(Request $request, HookCategory $category)
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $category->hooks()->update(['times_used' => 0, 'last_used_at' => null]);

        return redirect()->route('hooks.index', ['category' => $category->id])
            ->with('status', 'counters-reset');
    }
}
