@extends('layouts.app')

@section('title', 'Modifier le hook')

@section('actions')
    <a href="{{ route('hooks.index', ['category' => $hook->hook_category_id]) }}"
       class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
        </svg>
        Retour
    </a>
@endsection

@section('content')

    <form method="POST" action="{{ route('hooks.update', $hook) }}" class="max-w-3xl">
        @csrf
        @method('PUT')

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-6">

            {{-- Catégorie --}}
            <div>
                <h3 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2">
                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6Z" />
                    </svg>
                    Catégorie
                </h3>
                <select name="hook_category_id" id="hook_category_id" required
                        class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}" {{ old('hook_category_id', $hook->hook_category_id) == $category->id ? 'selected' : '' }}>
                            {{ $category->name }}
                        </option>
                    @endforeach
                </select>
                @error('hook_category_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <hr class="border-gray-100">

            {{-- Contenu du hook --}}
            <div>
                <h3 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2">
                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.076-4.076a1.526 1.526 0 0 1 1.037-.443 48.282 48.282 0 0 0 5.68-.494c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" />
                    </svg>
                    Exemple d'accroche
                </h3>
                <textarea name="content" id="content" rows="4" required
                          class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">{{ old('content', $hook->content) }}</textarea>
                @error('content') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <hr class="border-gray-100">

            {{-- Stats --}}
            <div class="bg-gray-50 rounded-xl p-4">
                <div class="flex items-center gap-6 text-sm text-gray-500">
                    <span class="flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75Z" />
                        </svg>
                        {{ $hook->times_used }} utilisation{{ $hook->times_used !== 1 ? 's' : '' }}
                    </span>
                    <span>
                        @if($hook->last_used_at)
                            Dernière utilisation : {{ $hook->last_used_at->diffForHumans() }}
                        @else
                            Jamais utilisé
                        @endif
                    </span>
                </div>
            </div>

            <hr class="border-gray-100">

            {{-- Actif --}}
            <div class="flex items-center gap-3">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" id="is_active" value="1" {{ old('is_active', $hook->is_active) ? 'checked' : '' }}
                       class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                <label for="is_active" class="text-sm font-medium text-gray-700">Hook actif</label>
            </div>
        </div>

        {{-- Submit --}}
        <div class="mt-6 flex items-center gap-3">
            <button type="submit"
                    class="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                </svg>
                Mettre à jour
            </button>
            <a href="{{ route('hooks.index', ['category' => $hook->hook_category_id]) }}" class="text-sm text-gray-500 hover:text-gray-700">Annuler</a>
        </div>
    </form>

@endsection
