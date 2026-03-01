@extends('layouts.app')

@section('title', 'Hooks d\'accroche')

@section('actions')
    <a href="{{ route('hooks.create') }}"
       class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
        </svg>
        Nouveau hook
    </a>
@endsection

@section('content')

    {{-- Flash messages --}}
    @foreach(['hook-created' => 'Hook créé avec succès.', 'hook-updated' => 'Hook mis à jour.', 'hook-deleted' => 'Hook supprimé.', 'category-created' => 'Catégorie créée.', 'category-updated' => 'Catégorie mise à jour.', 'category-deleted' => 'Catégorie supprimée.', 'counters-reset' => 'Compteurs réinitialisés.'] as $statusKey => $statusMsg)
        @if(session('status') === $statusKey)
            <div class="mb-6" x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 3000)">
                <div class="rounded-xl bg-green-50 border border-green-200 p-4 flex items-center gap-3">
                    <svg class="w-5 h-5 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    <p class="text-sm text-green-700">{{ $statusMsg }}</p>
                </div>
            </div>
        @endif
    @endforeach

    <div x-data="hookManager()" class="space-y-6">

        {{-- Explanation --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
            <div class="flex items-start gap-3">
                <div class="w-10 h-10 rounded-xl bg-amber-50 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 18v-5.25m0 0a6.01 6.01 0 0 0 1.5-.189m-1.5.189a6.01 6.01 0 0 1-1.5-.189m3.75 7.478a12.06 12.06 0 0 1-4.5 0m3.75 2.383a14.406 14.406 0 0 1-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 1 0-7.517 0c.85.493 1.509 1.333 1.509 2.316V18" />
                    </svg>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-gray-900">Comment fonctionnent les hooks ?</h3>
                    <p class="text-sm text-gray-500 mt-1">Les hooks sont des exemples d'accroches pour le premier segment d'un fil de discussion. Classez-les par thématique. Lors de la génération d'un fil, l'IA recevra le hook le moins utilisé de la catégorie choisie pour s'en inspirer. Chaque hook a un compteur d'utilisation pour garantir une rotation équitable.</p>
                </div>
            </div>
        </div>

        {{-- Category pills + Add category --}}
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('hooks.index') }}"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium transition-colors {{ !$currentCategory ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                Toutes
                <span class="text-xs opacity-70">({{ $categories->sum('hooks_count') }})</span>
            </a>

            @foreach($categories as $category)
                <div class="relative group inline-flex">
                    <a href="{{ route('hooks.index', ['category' => $category->id]) }}"
                       class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium transition-colors {{ $currentCategory && $currentCategory->id === $category->id ? 'text-white' : 'text-gray-600 hover:bg-gray-200' }}"
                       style="{{ $currentCategory && $currentCategory->id === $category->id ? 'background-color: ' . $category->color : 'background-color: ' . $category->color . '20' }}">
                        <span class="w-2 h-2 rounded-full flex-shrink-0" style="background-color: {{ $category->color }}"></span>
                        {{ $category->name }}
                        <span class="text-xs opacity-70">({{ $category->hooks_count }})</span>
                    </a>

                    {{-- Category actions dropdown --}}
                    <div x-data="{ open: false }" class="relative">
                        <button @click.stop="open = !open" class="opacity-0 group-hover:opacity-100 p-1 text-gray-400 hover:text-gray-600 transition-opacity">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5ZM12 12.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5ZM12 18.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Z" />
                            </svg>
                        </button>
                        <div x-show="open" @click.away="open = false" x-transition
                             class="absolute right-0 top-full mt-1 w-48 bg-white rounded-xl shadow-lg border border-gray-200 py-1 z-20">
                            <button @click="editCategory({{ $category->id }}, '{{ addslashes($category->name) }}', '{{ $category->color }}'); open = false"
                                    class="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2">
                                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" />
                                </svg>
                                Renommer
                            </button>
                            <form method="POST" action="{{ route('hooks.categories.reset', $category) }}"
                                  onsubmit="return confirm('Remettre tous les compteurs de cette catégorie à zéro ?')">
                                @csrf
                                <button type="submit" class="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2">
                                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182" />
                                    </svg>
                                    Réinitialiser compteurs
                                </button>
                            </form>
                            <hr class="my-1 border-gray-100">
                            <form method="POST" action="{{ route('hooks.categories.destroy', $category) }}"
                                  onsubmit="return confirm('Supprimer cette catégorie et tous ses hooks ?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="w-full text-left px-3 py-2 text-sm text-red-600 hover:bg-red-50 flex items-center gap-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                    </svg>
                                    Supprimer
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            @endforeach

            {{-- Add category button --}}
            <button @click="showNewCategory = true"
                    x-show="!showNewCategory"
                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors border border-dashed border-gray-300">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Catégorie
            </button>

            {{-- Inline new category form --}}
            <form x-show="showNewCategory" x-transition method="POST" action="{{ route('hooks.categories.store') }}"
                  class="inline-flex items-center gap-2" @keydown.escape="showNewCategory = false">
                @csrf
                <input type="color" name="color" value="#6366f1" class="w-8 h-8 rounded cursor-pointer border-0 p-0">
                <input type="text" name="name" placeholder="Nom de la catégorie" required
                       class="rounded-lg border-gray-300 text-sm py-1.5 px-3 w-40 focus:border-indigo-500 focus:ring-indigo-500"
                       x-ref="newCategoryInput"
                       x-effect="if (showNewCategory) $nextTick(() => $refs.newCategoryInput.focus())">
                <button type="submit" class="p-1.5 text-green-600 hover:bg-green-50 rounded-lg">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                    </svg>
                </button>
                <button type="button" @click="showNewCategory = false" class="p-1.5 text-gray-400 hover:bg-gray-100 rounded-lg">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </form>
        </div>

        {{-- Edit category modal --}}
        <div x-show="editingCategory" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-gray-600/50" @click.self="editingCategory = null">
            <div class="bg-white rounded-2xl shadow-xl p-6 w-full max-w-sm">
                <h3 class="text-base font-semibold text-gray-900 mb-4">Modifier la catégorie</h3>
                <form :action="`{{ url('hooks/categories') }}/${editingCategory?.id}`" method="POST">
                    @csrf
                    @method('PATCH')
                    <div class="space-y-4">
                        <div class="flex items-center gap-3">
                            <input type="color" name="color" :value="editingCategory?.color || '#6366f1'" class="w-10 h-10 rounded cursor-pointer border-0 p-0">
                            <input type="text" name="name" :value="editingCategory?.name" required
                                   class="flex-1 rounded-xl border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                   placeholder="Nom de la catégorie">
                        </div>
                    </div>
                    <div class="flex items-center gap-3 mt-5">
                        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors">
                            Enregistrer
                        </button>
                        <button type="button" @click="editingCategory = null" class="text-sm text-gray-500 hover:text-gray-700">Annuler</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Hooks list --}}
        @php
            $displayHooks = $currentCategory
                ? $currentCategory->hooks()->orderBy('times_used')->orderBy('created_at', 'desc')->get()
                : \App\Models\Hook::with('category')->orderBy('times_used')->orderBy('created_at', 'desc')->get();
        @endphp

        @if($displayHooks->isEmpty())
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-16 text-center">
                <div class="w-16 h-16 rounded-2xl bg-gray-50 flex items-center justify-center mx-auto mb-6">
                    <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 18v-5.25m0 0a6.01 6.01 0 0 0 1.5-.189m-1.5.189a6.01 6.01 0 0 1-1.5-.189m3.75 7.478a12.06 12.06 0 0 1-4.5 0m3.75 2.383a14.406 14.406 0 0 1-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 1 0-7.517 0c.85.493 1.509 1.333 1.509 2.316V18" />
                    </svg>
                </div>
                <h3 class="text-base font-semibold text-gray-900 mb-2">Aucun hook</h3>
                <p class="text-sm text-gray-500 mb-8">
                    @if($categories->isEmpty())
                        Créez d'abord une catégorie, puis ajoutez vos hooks d'accroche.
                    @else
                        Ajoutez des exemples d'accroches pour varier les premiers segments de vos fils.
                    @endif
                </p>
                @if($categories->isNotEmpty())
                    <a href="{{ route('hooks.create', $currentCategory ? ['category' => $currentCategory->id] : []) }}"
                       class="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Créer un hook
                    </a>
                @endif
            </div>
        @else
            <div class="space-y-3">
                @foreach($displayHooks as $hook)
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 hover:shadow-md transition-shadow">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1 min-w-0">
                                {{-- Category badge --}}
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-xs font-medium"
                                          style="background-color: {{ $hook->category->color }}20; color: {{ $hook->category->color }}">
                                        <span class="w-1.5 h-1.5 rounded-full" style="background-color: {{ $hook->category->color }}"></span>
                                        {{ $hook->category->name }}
                                    </span>
                                    @if(! $hook->is_active)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-red-50 text-red-600">Inactif</span>
                                    @endif
                                </div>

                                {{-- Content --}}
                                <p class="text-sm text-gray-800 leading-relaxed whitespace-pre-line">{{ $hook->content }}</p>

                                {{-- Stats --}}
                                <div class="flex items-center gap-4 mt-3 text-xs text-gray-400">
                                    <span class="flex items-center gap-1">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75Z" />
                                        </svg>
                                        {{ $hook->times_used }} utilisation{{ $hook->times_used !== 1 ? 's' : '' }}
                                    </span>
                                    @if($hook->last_used_at)
                                        <span>Dernière : {{ $hook->last_used_at->diffForHumans() }}</span>
                                    @else
                                        <span>Jamais utilisé</span>
                                    @endif
                                </div>
                            </div>

                            {{-- Actions --}}
                            <div class="flex items-center gap-1 flex-shrink-0">
                                <a href="{{ route('hooks.edit', $hook) }}"
                                   class="p-2 text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors"
                                   title="Modifier">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                                    </svg>
                                </a>
                                <form method="POST" action="{{ route('hooks.destroy', $hook) }}"
                                      onsubmit="return confirm('Supprimer ce hook ?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            class="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                                            title="Supprimer">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                        </svg>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <script>
        function hookManager() {
            return {
                showNewCategory: false,
                editingCategory: null,

                editCategory(id, name, color) {
                    this.editingCategory = { id, name, color };
                },
            }
        }
    </script>

@endsection
