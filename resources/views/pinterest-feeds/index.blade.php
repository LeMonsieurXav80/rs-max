@extends('layouts.app')

@section('title', 'Pinterest Feeds')

@section('content')
<div x-data="pinterestFeedManager()" class="space-y-6">

    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Pinterest Feeds</h1>
            <p class="mt-1 text-sm text-gray-500">Gérez vos flux RSS pour Pinterest auto-publish</p>
        </div>
        @if($pinterestAccounts->count())
        <button @click="showCreateModal = true" class="inline-flex items-center gap-2 px-4 py-2.5 text-white text-sm font-medium rounded-xl shadow-sm hover:opacity-90 transition" style="background-color: #E60023">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            Nouveau flux
        </button>
        @endif
    </div>

    {{-- No Pinterest account connected --}}
    @if($pinterestAccounts->isEmpty())
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8 text-center">
        <div class="w-16 h-16 mx-auto rounded-full flex items-center justify-center mb-4" style="background-color: #fce4e8">
            <svg class="w-8 h-8" fill="#E60023" viewBox="0 0 24 24">
                <path d="M12.017 0C5.396 0 .029 5.367.029 11.987c0 5.079 3.158 9.417 7.618 11.162-.105-.949-.199-2.403.041-3.439.219-.937 1.406-5.957 1.406-5.957s-.359-.72-.359-1.781c0-1.668.967-2.914 2.171-2.914 1.023 0 1.518.769 1.518 1.69 0 1.029-.655 2.568-.994 3.995-.283 1.194.599 2.169 1.777 2.169 2.133 0 3.772-2.249 3.772-5.495 0-2.873-2.064-4.882-5.012-4.882-3.414 0-5.418 2.561-5.418 5.207 0 1.031.397 2.138.893 2.738a.36.36 0 0 1 .083.345l-.333 1.36c-.053.22-.174.267-.402.161-1.499-.698-2.436-2.889-2.436-4.649 0-3.785 2.75-7.262 7.929-7.262 4.163 0 7.398 2.967 7.398 6.931 0 4.136-2.607 7.464-6.227 7.464-1.216 0-2.359-.631-2.75-1.378l-.748 2.853c-.271 1.043-1.002 2.35-1.492 3.146C9.57 23.812 10.763 24 12.017 24c6.624 0 11.99-5.367 11.99-11.988C24.007 5.367 18.641 0 12.017 0z"/>
            </svg>
        </div>
        <h3 class="text-lg font-semibold text-gray-900">Aucun compte Pinterest connecté</h3>
        <p class="mt-2 text-sm text-gray-500">Connectez un compte Pinterest pour commencer à créer des flux RSS.</p>
        <a href="{{ route('pinterest.redirect') }}" class="mt-4 inline-flex items-center gap-2 px-5 py-2.5 text-white text-sm font-medium rounded-xl hover:opacity-90 transition shadow-sm" style="background-color: #E60023">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />
            </svg>
            Connecter Pinterest
        </a>
    </div>
    @else

    {{-- Existing feeds --}}
    @if($feeds->count())
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        @foreach($feeds as $feed)
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
            <div class="flex items-start justify-between mb-3">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">{{ $feed->name }}</h3>
                    <p class="text-xs text-gray-500 mt-0.5">
                        {{ $feed->socialAccount->name }} &middot;
                        Template: <span class="font-medium">{{ $feed->template }}</span> &middot;
                        {{ $feed->pins_count }} pins
                        @if($feed->interest)
                            &middot; <span class="text-indigo-600">{{ \App\Services\Pinterest\PinterestApiService::INTERESTS[$feed->interest] ?? $feed->interest }}</span>
                        @endif
                    </p>
                </div>
                <div class="flex items-center gap-1">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $feed->is_active ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                        {{ $feed->is_active ? 'Actif' : 'Inactif' }}
                    </span>
                </div>
            </div>

            {{-- Feed URL (copyable) --}}
            <div class="flex items-center gap-2 p-2.5 bg-gray-50 rounded-lg mb-3">
                <input type="text" readonly value="{{ $feed->feed_url }}" class="flex-1 text-xs bg-transparent border-none p-0 text-gray-600 focus:ring-0 font-mono" id="feed-url-{{ $feed->id }}">
                <button @click="copyFeedUrl('feed-url-{{ $feed->id }}')" class="text-gray-400 hover:text-gray-600 transition" title="Copier">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0 0 13.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 0 1-.75.75H9.75a.75.75 0 0 1-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 0 1 1.927-.184" />
                    </svg>
                </button>
            </div>

            @if($feed->board_name)
            <p class="text-xs text-gray-500 mb-3">
                Tableau : <span class="font-medium text-gray-700">{{ $feed->board_name }}</span>
            </p>
            @endif

            {{-- Actions --}}
            <div class="flex items-center gap-2 pt-3 border-t border-gray-100">
                <button @click="loadPins({{ $feed->id }})" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    </svg>
                    Voir pins
                </button>
                <button @click="generatePins({{ $feed->id }})" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-blue-700 bg-blue-50 rounded-lg hover:bg-blue-100 transition" :disabled="generating">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182" />
                    </svg>
                    Importer articles
                </button>
                <button @click="batchGenerate({{ $feed->id }})" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-green-700 bg-green-50 rounded-lg hover:bg-green-100 transition" :disabled="generating">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M18 3.75h.75m0 0H21m-2.25 0V6M3.75 21h16.5" />
                    </svg>
                    Générer images
                </button>
                <form method="POST" action="{{ route('pinterest-feeds.destroy', $feed) }}" class="ml-auto" onsubmit="return confirm('Supprimer ce flux ?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="p-1.5 text-red-400 hover:text-red-600 transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                        </svg>
                    </button>
                </form>
            </div>
        </div>
        @endforeach
    </div>
    @else
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8 text-center">
        <h3 class="text-lg font-semibold text-gray-900">Aucun flux créé</h3>
        <p class="mt-2 text-sm text-gray-500">Créez un flux RSS pour chaque tableau Pinterest.</p>
    </div>
    @endif

    @endif

    {{-- Pins panel (shown when viewing a feed's pins) --}}
    <div x-show="selectedFeedPins.length > 0" x-cloak class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
        <h3 class="text-base font-semibold text-gray-900 mb-4">
            Pins du flux <span class="text-indigo-600" x-text="selectedFeedName"></span>
        </h3>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100">
                        <th class="text-left py-2 px-3 text-xs font-medium text-gray-500 uppercase">Image</th>
                        <th class="text-left py-2 px-3 text-xs font-medium text-gray-500 uppercase">Titre original</th>
                        <th class="text-left py-2 px-3 text-xs font-medium text-gray-500 uppercase">Titre Pinterest</th>
                        <th class="text-left py-2 px-3 text-xs font-medium text-gray-500 uppercase">Description</th>
                        <th class="text-left py-2 px-3 text-xs font-medium text-gray-500 uppercase">V.</th>
                        <th class="text-left py-2 px-3 text-xs font-medium text-gray-500 uppercase">Statut</th>
                        <th class="text-left py-2 px-3 text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="pin in selectedFeedPins" :key="pin.id">
                        <tr class="border-b border-gray-50 hover:bg-gray-50/50">
                            <td class="py-2 px-3">
                                <img x-show="pin.generated_image_url" :src="pin.generated_image_url" class="w-10 h-15 object-cover rounded" alt="">
                                <img x-show="!pin.generated_image_url && pin.source_image_url" :src="pin.source_image_url" class="w-10 h-15 object-cover rounded opacity-50" alt="">
                            </td>
                            <td class="py-2 px-3 max-w-[200px] truncate" x-text="pin.title_original"></td>
                            <td class="py-2 px-3 max-w-[200px] truncate font-medium" x-text="pin.title_generated || '—'"></td>
                            <td class="py-2 px-3 max-w-[180px] text-xs text-gray-500 truncate" x-text="pin.description || '—'"></td>
                            <td class="py-2 px-3 text-xs text-gray-500" x-text="'v' + pin.version"></td>
                            <td class="py-2 px-3">
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium"
                                      :class="{
                                          'bg-gray-100 text-gray-600': pin.status === 'pending',
                                          'bg-blue-50 text-blue-700': pin.status === 'generated',
                                          'bg-green-50 text-green-700': pin.status === 'in_feed',
                                          'bg-purple-50 text-purple-700': pin.status === 'published',
                                          'bg-red-50 text-red-700': pin.status === 'failed',
                                      }"
                                      x-text="pin.status"></span>
                            </td>
                            <td class="py-2 px-3">
                                <div class="flex items-center gap-1">
                                    <button x-show="pin.status === 'pending'" @click="generateSinglePin(pin.id)" class="px-2 py-1 text-xs bg-blue-50 text-blue-700 rounded hover:bg-blue-100 transition">
                                        Générer
                                    </button>
                                    <button x-show="pin.status === 'generated'" @click="addPinToFeed(pin.id)" class="px-2 py-1 text-xs bg-green-50 text-green-700 rounded hover:bg-green-100 transition">
                                        Ajouter au flux
                                    </button>
                                    <button x-show="pin.status === 'in_feed' || pin.status === 'published'" @click="repostPin(pin.id)" class="px-2 py-1 text-xs bg-purple-50 text-purple-700 rounded hover:bg-purple-100 transition">
                                        Reposter
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>

    {{-- Create feed modal --}}
    <div x-show="showCreateModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" @keydown.escape.window="showCreateModal = false">
        <div @click.away="showCreateModal = false" class="bg-white rounded-2xl shadow-xl w-full max-w-2xl mx-4 max-h-[90vh] overflow-y-auto p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Nouveau flux Pinterest</h2>

            <form method="POST" action="{{ route('pinterest-feeds.store') }}">
                @csrf

                {{-- Account --}}
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Compte Pinterest</label>
                    <select name="social_account_id" x-model="newFeed.social_account_id" @change="fetchBoards()" class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500" required>
                        <option value="">Sélectionner...</option>
                        @foreach($pinterestAccounts as $acc)
                        <option value="{{ $acc->id }}">{{ $acc->name }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Feed name --}}
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nom du flux / tableau</label>
                    <input type="text" name="name" x-model="newFeed.name" class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="Ex: Voyage & Destinations" required>
                </div>

                {{-- Board selection --}}
                <div class="mb-4" x-show="boards.length > 0">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Tableau Pinterest (optionnel)</label>
                    <select name="board_id" x-model="newFeed.board_id" @change="updateBoardName()" class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">Aucun (saisir manuellement dans Pinterest)</option>
                        <template x-for="board in boards" :key="board.id">
                            <option :value="board.id" x-text="board.name + ' (' + board.pin_count + ' pins)'"></option>
                        </template>
                    </select>
                    <input type="hidden" name="board_name" x-model="newFeed.board_name">
                </div>

                <div class="mb-4" x-show="loadingBoards">
                    <p class="text-sm text-gray-500 animate-pulse">Chargement des tableaux...</p>
                </div>

                {{-- Template --}}
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Template d'image</label>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <label class="relative cursor-pointer">
                            <input type="radio" name="template" value="overlay" x-model="newFeed.template" class="sr-only peer" checked>
                            <div class="p-3 rounded-xl border-2 text-center transition peer-checked:border-indigo-500 peer-checked:bg-indigo-50 border-gray-200 hover:border-gray-300">
                                <div class="w-full h-16 bg-gradient-to-t from-black/70 to-transparent rounded-lg mb-2 relative overflow-hidden">
                                    <div class="absolute bottom-1 left-1 right-1 h-2 bg-white/80 rounded"></div>
                                </div>
                                <span class="text-xs font-medium">Overlay</span>
                            </div>
                        </label>
                        <label class="relative cursor-pointer">
                            <input type="radio" name="template" value="split" x-model="newFeed.template" class="sr-only peer">
                            <div class="p-3 rounded-xl border-2 text-center transition peer-checked:border-indigo-500 peer-checked:bg-indigo-50 border-gray-200 hover:border-gray-300">
                                <div class="w-full h-16 rounded-lg mb-2 overflow-hidden">
                                    <div class="h-8 bg-gray-300"></div>
                                    <div class="h-8 bg-indigo-900 flex items-center justify-center">
                                        <div class="h-1.5 w-8 bg-white/80 rounded"></div>
                                    </div>
                                </div>
                                <span class="text-xs font-medium">Split</span>
                            </div>
                        </label>
                        <label class="relative cursor-pointer">
                            <input type="radio" name="template" value="bold_text" x-model="newFeed.template" class="sr-only peer">
                            <div class="p-3 rounded-xl border-2 text-center transition peer-checked:border-indigo-500 peer-checked:bg-indigo-50 border-gray-200 hover:border-gray-300">
                                <div class="w-full h-16 bg-indigo-900 rounded-lg mb-2 flex items-center justify-center">
                                    <div class="space-y-1">
                                        <div class="h-2 w-10 bg-white/80 rounded mx-auto"></div>
                                        <div class="h-2 w-8 bg-white/60 rounded mx-auto"></div>
                                    </div>
                                </div>
                                <span class="text-xs font-medium">Bold Text</span>
                            </div>
                        </label>
                        <label class="relative cursor-pointer">
                            <input type="radio" name="template" value="numbered" x-model="newFeed.template" class="sr-only peer">
                            <div class="p-3 rounded-xl border-2 text-center transition peer-checked:border-indigo-500 peer-checked:bg-indigo-50 border-gray-200 hover:border-gray-300">
                                <div class="w-full h-16 bg-indigo-900 rounded-lg mb-2 flex items-center justify-center">
                                    <span class="text-2xl font-black text-white/80">7</span>
                                </div>
                                <span class="text-xs font-medium">Numbered</span>
                            </div>
                        </label>
                    </div>
                </div>

                {{-- Colors --}}
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Couleur de fond</label>
                        <div class="flex items-center gap-2">
                            <input type="color" name="colors[background]" x-model="newFeed.colors.background" class="h-9 w-14 rounded cursor-pointer border border-gray-300">
                            <input type="text" x-model="newFeed.colors.background" class="flex-1 rounded-lg border-gray-300 text-sm font-mono" maxlength="7">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Couleur du texte</label>
                        <div class="flex items-center gap-2">
                            <input type="color" name="colors[text]" x-model="newFeed.colors.text" class="h-9 w-14 rounded cursor-pointer border border-gray-300">
                            <input type="text" x-model="newFeed.colors.text" class="flex-1 rounded-lg border-gray-300 text-sm font-mono" maxlength="7">
                        </div>
                    </div>
                </div>

                {{-- Language --}}
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Langue des titres</label>
                    <select name="language" x-model="newFeed.language" class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="fr">Français</option>
                        <option value="en">English</option>
                        <option value="es">Español</option>
                        <option value="de">Deutsch</option>
                        <option value="it">Italiano</option>
                        <option value="pt">Português</option>
                    </select>
                </div>

                {{-- Interest / Thématique --}}
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Thématique Pinterest</label>
                    <p class="text-xs text-gray-500 mb-1">Utilisée pour récupérer les tendances et optimiser les titres/descriptions.</p>
                    <select name="interest" x-model="newFeed.interest" class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">Aucune (pas de tendances)</option>
                        @foreach(\App\Services\Pinterest\PinterestApiService::INTERESTS as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- WordPress categories --}}
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Catégories WordPress</label>
                    <p class="text-xs text-gray-500 mb-2">Sélectionnez les catégories à inclure dans ce flux.</p>

                    @foreach($wpSources as $source)
                    @php
                        $categories = $source->categories;
                        if (is_string($categories)) {
                            $categories = json_decode($categories, true) ?? [];
                        }
                        $categories = is_array($categories) ? $categories : [];
                    @endphp
                    @if(count($categories))
                    <div class="mb-3">
                        <p class="text-xs font-semibold text-gray-600 mb-1">{{ $source->name }}</p>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                            @foreach($categories as $cat)
                            @if(is_array($cat) && isset($cat['id'], $cat['name']))
                            <label class="flex items-center gap-2 p-2 rounded-lg border border-gray-200 hover:bg-gray-50 cursor-pointer text-xs">
                                <input type="checkbox" name="wp_categories[]"
                                       :value="JSON.stringify({wp_source_id: {{ $source->id }}, wp_category_id: {{ $cat['id'] }}, wp_category_name: '{{ addslashes($cat['name']) }}'})"
                                       class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                <span>{{ $cat['name'] }} ({{ $cat['count'] ?? 0 }})</span>
                            </label>
                            @endif
                            @endforeach
                        </div>
                    </div>
                    @endif
                    @endforeach
                </div>

                {{-- Actions --}}
                <div class="flex justify-end gap-3 pt-4 border-t border-gray-100">
                    <button type="button" @click="showCreateModal = false" class="px-4 py-2 text-sm font-medium text-gray-700 hover:text-gray-900 transition">
                        Annuler
                    </button>
                    <button type="submit" class="px-6 py-2.5 text-white text-sm font-medium rounded-xl shadow-sm hover:opacity-90 transition" style="background-color: #E60023">
                        Créer le flux
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Toast --}}
    <div x-show="toast.show" x-cloak
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 translate-y-2"
         class="fixed bottom-6 right-6 z-50 px-4 py-3 rounded-xl shadow-lg text-sm font-medium"
         :class="toast.type === 'success' ? 'bg-green-600 text-white' : 'bg-red-600 text-white'"
         x-text="toast.message">
    </div>
</div>

<script>
function pinterestFeedManager() {
    return {
        showCreateModal: false,
        generating: false,
        boards: [],
        loadingBoards: false,
        selectedFeedPins: [],
        selectedFeedName: '',
        toast: { show: false, message: '', type: 'success' },

        newFeed: {
            social_account_id: '',
            name: '',
            board_id: '',
            board_name: '',
            template: 'overlay',
            colors: { background: '#1a1a2e', text: '#ffffff' },
            language: 'fr',
            interest: '',
        },

        async fetchBoards() {
            if (!this.newFeed.social_account_id) return;
            this.loadingBoards = true;
            this.boards = [];
            try {
                const resp = await fetch('{{ route("pinterest-feeds.boards") }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ social_account_id: this.newFeed.social_account_id })
                });
                const data = await resp.json();
                if (resp.ok && Array.isArray(data)) {
                    this.boards = data;
                } else {
                    this.showToast(data.error || 'Impossible de charger les tableaux', 'error');
                }
            } catch (e) {
                this.showToast('Erreur de connexion Pinterest', 'error');
                console.error(e);
            }
            this.loadingBoards = false;
        },

        updateBoardName() {
            const board = this.boards.find(b => b.id === this.newFeed.board_id);
            this.newFeed.board_name = board ? board.name : '';
        },

        async generatePins(feedId) {
            this.generating = true;
            try {
                const resp = await fetch(`/tools/pinterest-feeds/${feedId}/generate-pins`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
                });
                const data = await resp.json();
                this.showToast(`${data.created} nouveaux pins importés (total: ${data.total})`, 'success');
                this.loadPins(feedId);
            } catch (e) {
                this.showToast('Erreur lors de l\'import', 'error');
            }
            this.generating = false;
        },

        async batchGenerate(feedId) {
            this.generating = true;
            try {
                const resp = await fetch(`/tools/pinterest-feeds/${feedId}/batch-generate`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
                });
                const data = await resp.json();
                this.showToast(`${data.success} images générées, ${data.failed} échoués`, data.failed > 0 ? 'error' : 'success');
                this.loadPins(feedId);
            } catch (e) {
                this.showToast('Erreur lors de la génération', 'error');
            }
            this.generating = false;
        },

        async loadPins(feedId) {
            try {
                const resp = await fetch(`/tools/pinterest-feeds/${feedId}/pins`);
                this.selectedFeedPins = await resp.json();
                // Find feed name from DOM
                const feedCards = document.querySelectorAll('[class*="rounded-2xl"]');
                this.selectedFeedName = '#' + feedId;
            } catch (e) {
                console.error(e);
            }
        },

        async generateSinglePin(pinId) {
            try {
                const resp = await fetch(`/tools/pinterest-feeds/pin/${pinId}/generate`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
                });
                const data = await resp.json();
                if (data.error) {
                    this.showToast(data.error, 'error');
                } else {
                    this.showToast('Image générée !', 'success');
                    // Refresh pin in list
                    const idx = this.selectedFeedPins.findIndex(p => p.id === pinId);
                    if (idx >= 0) {
                        this.selectedFeedPins[idx].title_generated = data.title;
                        this.selectedFeedPins[idx].description = data.description;
                        this.selectedFeedPins[idx].generated_image_url = data.image_url;
                        this.selectedFeedPins[idx].status = data.status;
                    }
                }
            } catch (e) {
                this.showToast('Erreur', 'error');
            }
        },

        async addPinToFeed(pinId) {
            try {
                const resp = await fetch(`/tools/pinterest-feeds/pin/${pinId}/add-to-feed`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
                });
                const data = await resp.json();
                if (data.error) {
                    this.showToast(data.error, 'error');
                } else {
                    this.showToast('Pin ajouté au flux RSS', 'success');
                    const idx = this.selectedFeedPins.findIndex(p => p.id === pinId);
                    if (idx >= 0) this.selectedFeedPins[idx].status = 'in_feed';
                }
            } catch (e) {
                this.showToast('Erreur', 'error');
            }
        },

        async repostPin(pinId) {
            try {
                const resp = await fetch(`/tools/pinterest-feeds/pin/${pinId}/repost`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
                });
                const data = await resp.json();
                this.showToast(`Nouvelle version v${data.version} créée`, 'success');
            } catch (e) {
                this.showToast('Erreur', 'error');
            }
        },

        copyFeedUrl(inputId) {
            const input = document.getElementById(inputId);
            navigator.clipboard.writeText(input.value);
            this.showToast('URL copiée !', 'success');
        },

        showToast(message, type = 'success') {
            this.toast = { show: true, message, type };
            setTimeout(() => this.toast.show = false, 3000);
        }
    };
}
</script>
@endsection
