<?php

namespace App\Http\Controllers;

use App\Models\Persona;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PersonaController extends Controller
{
    public function index(Request $request): View
    {
        if (! $request->user()->isManager()) {
            abort(403);
        }

        $personas = Persona::orderBy('name')->get();

        return view('personas.index', compact('personas'));
    }

    public function create(Request $request): View
    {
        if (! $request->user()->isManager()) {
            abort(403);
        }

        return view('personas.create');
    }

    public function store(Request $request)
    {
        if (! $request->user()->isManager()) {
            abort(403);
        }

        $validated = $request->validate($this->validationRules());

        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['language'] = $validated['language'] ?? 'fr';

        Persona::create($validated);

        return redirect()->route('personas.index')->with('status', 'persona-created');
    }

    public function edit(Request $request, Persona $persona): View
    {
        if (! $request->user()->isManager()) {
            abort(403);
        }

        return view('personas.edit', compact('persona'));
    }

    public function update(Request $request, Persona $persona)
    {
        if (! $request->user()->isManager()) {
            abort(403);
        }

        $validated = $request->validate($this->validationRules());

        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['language'] = $validated['language'] ?? $persona->language;

        $persona->update($validated);

        return redirect()->route('personas.index')->with('status', 'persona-updated');
    }

    private function validationRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'system_prompt' => 'required|string|max:10000',
            'tone' => 'nullable|string|max:100',
            'language' => 'nullable|string|max:10',
            'is_active' => 'boolean',
            'bot_comment_context_article' => 'nullable|string|max:5000',
            'bot_comment_context_text' => 'nullable|string|max:5000',
            'bot_comment_context_image' => 'nullable|string|max:5000',
            'bot_comment_max_length' => 'nullable|integer|min:50|max:1000',
        ];
    }

    public function destroy(Request $request, Persona $persona)
    {
        if (! $request->user()->isManager()) {
            abort(403);
        }

        $persona->delete();

        return redirect()->route('personas.index')->with('status', 'persona-deleted');
    }
}
