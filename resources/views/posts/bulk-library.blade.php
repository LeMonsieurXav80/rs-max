@extends('layouts.app')

@section('title', 'Publication en masse (bibliothèque)')

@php
    $accountsJson = $accounts->map(fn($a) => [
        'id' => $a->id,
        'name' => $a->name,
        'picture' => $a->profile_picture_url,
        'platform' => $a->platform->slug,
    ])->values();
@endphp

@section('content')
<div x-data="bulkLibraryEditor()" class="space-y-6">

    {{-- ═══ STEP 1: Configuration ═══ --}}
    <div x-show="step === 1" x-transition>
        <h1 class="text-2xl font-bold text-gray-900 mb-1">Publication en masse — bibliothèque</h1>
        <p class="text-sm text-gray-500 mb-6">Les images sont tirées au hasard dans les dossiers cochés, en évitant celles déjà planifiées ou publiées.</p>

        {{-- Account selection --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 mb-6">
            <x-account-selector
                :accounts="$accounts"
                :selectedIds="$defaultAccountIds"
                :groups="$accountGroups"
                name="bulk_accounts[]"
                :showSaveButton="false"
                :dispatchEvent="'bulk-accounts-changed'"
                label="Comptes de publication"
            />
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            {{-- Folder tree --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold text-gray-900">Dossiers sources</h2>
                    <div class="flex items-center gap-2 text-xs">
                        <button type="button" @click="expandAll(true)" class="px-2 py-1 rounded-lg bg-gray-100 text-gray-600 hover:bg-gray-200 transition-colors">Tout déplier</button>
                        <button type="button" @click="expandAll(false)" class="px-2 py-1 rounded-lg bg-gray-100 text-gray-600 hover:bg-gray-200 transition-colors">Tout replier</button>
                        <span class="text-gray-300">·</span>
                        <button type="button" @click="selectAllFolders(true)" class="px-2 py-1 rounded-lg bg-gray-100 text-gray-600 hover:bg-gray-200 transition-colors">Tout cocher</button>
                        <button type="button" @click="selectAllFolders(false)" class="px-2 py-1 rounded-lg bg-gray-100 text-gray-600 hover:bg-gray-200 transition-colors">Tout décocher</button>
                    </div>
                </div>

                <template x-if="folders.length === 0">
                    <p class="text-sm text-gray-400 py-6 text-center">Aucun dossier public disponible.</p>
                </template>

                <div class="max-h-96 overflow-y-auto pr-1 space-y-0.5" x-show="folders.length > 0">
                    <template x-for="f in folders" :key="f.id">
                        <div class="flex items-center gap-1 py-1.5 rounded-lg hover:bg-gray-50"
                             x-show="isFolderVisible(f)"
                             :style="'padding-left:' + (f.depth * 18 + 4) + 'px'">
                            {{-- Chevron déplier/replier (ou espaceur pour les feuilles) --}}
                            <button type="button" x-show="hasChildren(f.id)" @click="toggleExpand(f.id)"
                                    class="flex-shrink-0 w-4 h-4 flex items-center justify-center text-gray-400 hover:text-gray-600">
                                <svg class="w-3.5 h-3.5 transition-transform" :class="expanded[f.id] && 'rotate-90'" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                                </svg>
                            </button>
                            <span x-show="!hasChildren(f.id)" class="flex-shrink-0 w-4"></span>

                            <label class="flex items-center gap-2 flex-1 min-w-0 cursor-pointer">
                                <input type="checkbox"
                                       :checked="!!checked[f.id]"
                                       @change="toggleFolder(f.id)"
                                       x-effect="$el.indeterminate = boxIndeterminate(f.id)"
                                       class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 h-4 w-4">
                                <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" :style="'background:' + (f.color || '#cbd5e1')"></span>
                                <svg x-show="f.is_private" class="w-3.5 h-3.5 text-amber-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8" title="Dossier privé / intime">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/>
                                </svg>
                                <span class="text-sm text-gray-700 truncate" x-text="f.name"></span>
                                <span class="text-xs text-gray-400 ml-auto flex-shrink-0"
                                      x-text="f.files_count_total + ' img'"
                                      :title="f.files_count_total !== f.files_count ? (f.files_count + ' directes + ' + (f.files_count_total - f.files_count) + ' dans les sous-dossiers') : 'images dans ce dossier'"></span>
                            </label>
                        </div>
                    </template>
                </div>

                {{-- Filtre par mots-clés --}}
                <div class="mt-4 border-t border-gray-100 pt-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Filtrer par mots-clés <span class="text-gray-400 font-normal">(optionnel)</span>
                    </label>
                    <input type="text" x-model="keywordsInput"
                        placeholder="ex : plage, coucher de soleil, algarve"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    <p class="mt-1 text-xs text-gray-400">
                        Séparez par des virgules. Dans les dossiers cochés, une image est retenue si elle contient
                        <span class="font-medium">au moins un</span> des mots-clés (tags, description ou lieu).
                    </p>
                </div>

                <p class="mt-4 text-xs text-gray-500 border-t border-gray-100 pt-3">
                    <span class="font-medium text-gray-700" x-text="selectedFolderIds().length"></span> dossier(s) coché(s) ·
                    <span class="font-medium text-gray-700" x-text="totalSelectedImages()"></span> image(s) publiables
                    <span class="text-gray-400">— hors vidéos, images « à classer » (sans dossier), filtre mots-clés et déjà planifiées/publiées</span>
                </p>
            </div>

            {{-- Schedule configuration --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-4">Planification</h2>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    {{-- Number of posts --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nombre de publications</label>
                        <input type="number" x-model.number="numPosts" min="1" max="100"
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    </div>

                    {{-- Images per post --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Images par publication</label>
                        <input type="number" x-model.number="imagesPerPost" min="1" max="10"
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    </div>

                    {{-- Frequency --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Poster tous les</label>
                        <div class="flex items-center gap-2">
                            <input type="number" x-model.number="frequencyDays" min="1" max="365"
                                class="w-24 rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                            <span class="text-sm text-gray-500">jour(s)</span>
                        </div>
                    </div>

                    {{-- Start date --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date de début</label>
                        <input type="date" x-model="startDate"
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    </div>

                    {{-- Time window --}}
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Plage horaire</label>
                        <div class="flex items-center gap-3">
                            <span class="text-sm text-gray-500">Entre</span>
                            <select x-model.number="hourStart" class="rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                <template x-for="h in 24" :key="h-1">
                                    <option :value="h-1" x-text="String(h-1).padStart(2,'0') + ':00'"></option>
                                </template>
                            </select>
                            <span class="text-sm text-gray-500">et</span>
                            <select x-model.number="hourEnd" class="rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                <template x-for="h in 24" :key="h-1">
                                    <option :value="h-1" x-text="String(h-1).padStart(2,'0') + ':00'"></option>
                                </template>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Error / feedback --}}
        <div x-show="pickError" x-transition class="mb-4 rounded-xl bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-600" x-text="pickError"></div>

        {{-- Continue button --}}
        <div class="flex justify-end">
            <button type="button" @click="generateRows()"
                :disabled="picking || getSelectedAccountIds().length === 0 || numPosts < 1 || selectedFolderIds().length === 0"
                class="inline-flex items-center gap-2 px-6 py-3 bg-indigo-600 text-white rounded-xl font-medium hover:bg-indigo-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                <template x-if="picking">
                    <svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                </template>
                <template x-if="!picking">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 0 1-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625" />
                    </svg>
                </template>
                <span x-text="picking ? 'Tirage en cours…' : 'Générer le tableur (' + numPosts + ' publications)'"></span>
            </button>
        </div>
    </div>

    {{-- ═══ STEP 2: Spreadsheet ═══ --}}
    <div x-show="step === 2" x-transition>
        {{-- Header --}}
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-4">
                <button type="button" @click="step = 1" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>
                </button>
                <h1 class="text-2xl font-bold text-gray-900">
                    Publication en masse — bibliothèque
                    <span class="text-base font-normal text-gray-500" x-text="'(' + rows.length + ' publications)'"></span>
                </h1>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-sm text-gray-500" x-text="savedCount + '/' + rows.length + ' enregistrées'"></span>
                <button type="button" @click="addRow()"
                    class="inline-flex items-center gap-1.5 px-4 py-2 bg-white border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Ajouter une ligne
                </button>
            </div>
        </div>

        {{-- Shortfall warning --}}
        <div x-show="shortfallMsg" x-transition class="mb-4 rounded-xl bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-700" x-text="shortfallMsg"></div>

        {{-- Table --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 border-b border-gray-200">
                            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider w-10">#</th>
                            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider min-w-[250px]">Contenu</th>
                            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider min-w-[180px]">Médias</th>
                            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider min-w-[150px]">Hashtags</th>
                            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider min-w-[180px]">Date de publication</th>
                            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider min-w-[120px]">Réseaux sociaux</th>
                            <th class="px-3 py-3 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider w-20">Statut</th>
                            <th class="px-3 py-3 w-10"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <template x-for="(row, index) in rows" :key="row._key">
                            <tr class="hover:bg-gray-50/50 transition-colors" :class="row.error && 'bg-red-50/30'">
                                {{-- Row number --}}
                                <td class="px-3 py-3 text-gray-400 font-medium" x-text="index + 1"></td>

                                {{-- Content --}}
                                <td class="px-3 py-2">
                                    <textarea
                                        x-model="row.content_fr"
                                        @focus="row._dirty = true"
                                        @blur="if (row._dirty) saveRow(index)"
                                        rows="3"
                                        class="w-full rounded-lg border-gray-200 text-sm resize-y focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400 placeholder-gray-300"
                                        placeholder="Contenu de la publication..."
                                    ></textarea>
                                    <button type="button" @click="generateAi(index)"
                                        :disabled="row._generating || row.media.length === 0"
                                        class="mt-1 inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
                                        :class="row._generating ? 'bg-purple-100 text-purple-600' : 'bg-gray-100 text-gray-500 hover:bg-purple-100 hover:text-purple-600'">
                                        <template x-if="!row._generating">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 0 0-2.455 2.456Z"/>
                                            </svg>
                                        </template>
                                        <template x-if="row._generating">
                                            <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                            </svg>
                                        </template>
                                        <span x-text="row._generating ? 'Génération...' : 'IA'"></span>
                                    </button>
                                </td>

                                {{-- Media --}}
                                <td class="px-3 py-2">
                                    <div class="space-y-2">
                                        {{-- Thumbnails --}}
                                        <div class="flex flex-wrap gap-1.5" x-show="row.media.length > 0">
                                            <template x-for="(m, mi) in row.media" :key="mi">
                                                <div class="relative group w-14 h-14 rounded-lg overflow-hidden border border-gray-200">
                                                    <template x-if="m.mimetype && m.mimetype.startsWith('image/')">
                                                        <img :src="m.url" class="w-full h-full object-cover" alt="">
                                                    </template>
                                                    <template x-if="m.mimetype && m.mimetype.startsWith('video/')">
                                                        <div class="w-full h-full bg-gray-800 flex items-center justify-center">
                                                            <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 24 24">
                                                                <path d="M8 5v14l11-7z"/>
                                                            </svg>
                                                        </div>
                                                    </template>
                                                    <button type="button" @click="removeMedia(index, mi); saveRow(index)"
                                                        class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 flex items-center justify-center transition-opacity">
                                                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                                                        </svg>
                                                    </button>
                                                </div>
                                            </template>
                                        </div>

                                        {{-- Actions : ré-tirer une image / bibliothèque --}}
                                        <div class="flex gap-1.5">
                                            <button type="button" @click="reshuffleRow(index)"
                                                :disabled="row._reshuffling"
                                                class="flex items-center gap-1 px-2.5 py-1.5 rounded-lg border border-dashed border-gray-300 text-xs text-gray-400 hover:border-indigo-400 hover:text-indigo-500 cursor-pointer transition-colors disabled:opacity-40"
                                                title="Tirer d'autres images au hasard">
                                                <svg class="w-3.5 h-3.5" :class="row._reshuffling && 'animate-spin'" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"/>
                                                </svg>
                                                Re-tirer
                                            </button>
                                            <button type="button" @click="openLibrary(index)"
                                                class="flex items-center gap-1 px-2.5 py-1.5 rounded-lg border border-dashed border-gray-300 text-xs text-gray-400 hover:border-indigo-400 hover:text-indigo-500 cursor-pointer transition-colors">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z"/>
                                                </svg>
                                                Bibliothèque
                                            </button>
                                        </div>
                                    </div>
                                </td>

                                {{-- Hashtags --}}
                                <td class="px-3 py-2">
                                    <input type="text"
                                        x-model="row.hashtags"
                                        @focus="row._dirty = true"
                                        @blur="if (row._dirty) saveRow(index)"
                                        class="w-full rounded-lg border-gray-200 text-sm focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400 placeholder-gray-300"
                                        placeholder="#hashtags">
                                </td>

                                {{-- Scheduled at --}}
                                <td class="px-3 py-2">
                                    <input type="datetime-local"
                                        x-model="row.scheduled_at"
                                        @change="row._dirty = true; saveRow(index)"
                                        class="w-full rounded-lg border-gray-200 text-sm focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400">
                                </td>

                                {{-- Social accounts --}}
                                <td class="px-3 py-2">
                                    <div class="flex flex-wrap gap-1">
                                        <template x-for="accId in row.accounts" :key="accId">
                                            <div class="relative" :title="getAccountName(accId)">
                                                <template x-if="getAccountPicture(accId)">
                                                    <img :src="getAccountPicture(accId)" class="w-7 h-7 rounded-full border border-gray-200 object-cover" :alt="getAccountName(accId)">
                                                </template>
                                                <template x-if="!getAccountPicture(accId)">
                                                    <div class="w-7 h-7 rounded-full bg-gray-200 flex items-center justify-center">
                                                        <span class="text-xs font-medium text-gray-500" x-text="getAccountName(accId).charAt(0).toUpperCase()"></span>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </td>

                                {{-- Status --}}
                                <td class="px-3 py-2 text-center">
                                    <template x-if="row._saving">
                                        <span class="inline-flex items-center gap-1 text-amber-500">
                                            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                            </svg>
                                        </span>
                                    </template>
                                    <template x-if="!row._saving && row._saved">
                                        <span class="text-green-500">
                                            <svg class="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                                            </svg>
                                        </span>
                                    </template>
                                    <template x-if="!row._saving && !row._saved && row.id">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700">OK</span>
                                    </template>
                                    <template x-if="!row._saving && !row._saved && !row.id">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">Brouillon</span>
                                    </template>
                                    <template x-if="row.error">
                                        <span class="block text-xs text-red-500 mt-1" x-text="row.error"></span>
                                    </template>
                                </td>

                                {{-- Delete --}}
                                <td class="px-3 py-2">
                                    <button type="button" @click="deleteRow(index)"
                                        class="text-gray-300 hover:text-red-500 transition-colors">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/>
                                        </svg>
                                    </button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Bottom actions --}}
        <div class="flex items-center justify-between mt-4">
            <button type="button" @click="addRow()"
                class="inline-flex items-center gap-1.5 px-4 py-2 text-sm text-gray-500 hover:text-indigo-600 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                </svg>
                Ajouter une ligne
            </button>
            <button type="button" @click="saveAllDirty()"
                class="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 text-white rounded-xl text-sm font-medium hover:bg-indigo-700 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 3.75H6.912a2.25 2.25 0 0 0-2.15 1.588L2.35 13.177a2.25 2.25 0 0 0-.1.661V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 0 0-2.15-1.588H15M2.25 13.5h3.86a2.25 2.25 0 0 1 2.012 1.244l.256.512a2.25 2.25 0 0 0 2.013 1.244h3.218a2.25 2.25 0 0 0 2.013-1.244l.256-.512a2.25 2.25 0 0 1 2.013-1.244h3.859"/>
                </svg>
                Tout enregistrer
            </button>
        </div>
    </div>

    {{-- ═══ Library Modal ═══ --}}
    @include('posts._media-library')
</div>

@push('scripts')
<script>
function bulkLibraryEditor() {
    return {
        // États et méthodes communs de la bibliothèque média (voir _media-library.blade.php).
        ...mediaLibraryData(),
        step: 1,
        numPosts: 5,
        imagesPerPost: 1,
        frequencyDays: 1,
        hourStart: 9,
        hourEnd: 18,
        startDate: (() => {
            const d = new Date();
            d.setDate(d.getDate() + 1);
            return d.toISOString().split('T')[0];
        })(),
        rows: [],
        _keyCounter: 0,

        // Arbre de dossiers (aplati, avec depth/path/files_count).
        folders: @json($folders),
        checked: {},
        expanded: {},
        keywordsInput: '',
        picking: false,
        pickError: null,
        shortfallMsg: null,

        // Bibliothèque : index de la row qui recevra l'item sélectionné.
        libraryTargetRow: null,

        // Account data from server
        accountsData: @json($accountsJson),

        // ── Comptes ────────────────────────────────────────────────
        getSelectedAccountIds() {
            const checkboxes = document.querySelectorAll('input[name="bulk_accounts[]"]:checked');
            return Array.from(checkboxes).map(cb => parseInt(cb.value));
        },
        getAccountName(id) {
            const acc = this.accountsData.find(a => a.id === id);
            return acc ? acc.name : '?';
        },
        getAccountPicture(id) {
            const acc = this.accountsData.find(a => a.id === id);
            return acc ? acc.picture : null;
        },
        get savedCount() {
            return this.rows.filter(r => r.id !== null).length;
        },

        // ── Arbre de dossiers ──────────────────────────────────────
        childrenOf(id) {
            return this.folders.filter(f => f.parent_id === id).map(f => f.id);
        },
        hasChildren(id) {
            return this.folders.some(f => f.parent_id === id);
        },
        // Visible seulement si TOUS ses ancêtres sont dépliés (racines toujours visibles).
        isFolderVisible(f) {
            let pid = f.parent_id;
            while (pid !== null && pid !== undefined) {
                if (!this.expanded[pid]) return false;
                const parent = this.folders.find(x => x.id === pid);
                if (!parent) break;
                pid = parent.parent_id;
            }
            return true;
        },
        toggleExpand(id) {
            this.expanded[id] = !this.expanded[id];
        },
        expandAll(state) {
            for (const f of this.folders) {
                if (this.hasChildren(f.id)) this.expanded[f.id] = state;
            }
        },
        descendantsOf(id) {
            const out = [];
            const stack = [...this.childrenOf(id)];
            while (stack.length) {
                const c = stack.pop();
                out.push(c);
                stack.push(...this.childrenOf(c));
            }
            return out;
        },
        toggleFolder(id) {
            const target = !this.checked[id];
            this.checked[id] = target;
            for (const d of this.descendantsOf(id)) this.checked[d] = target;
        },
        boxIndeterminate(id) {
            if (this.checked[id]) return false;
            return this.descendantsOf(id).some(d => this.checked[d]);
        },
        selectAllFolders(state) {
            for (const f of this.folders) this.checked[f.id] = state;
        },
        selectedFolderIds() {
            return this.folders.filter(f => this.checked[f.id]).map(f => f.id);
        },
        totalSelectedImages() {
            return this.folders.filter(f => this.checked[f.id]).reduce((s, f) => s + (f.files_count || 0), 0);
        },
        parsedKeywords() {
            return this.keywordsInput.split(',').map(s => s.trim()).filter(Boolean);
        },

        // ── Génération des dates (partagé avec addRow) ─────────────
        computeScheduledAt(baseDate) {
            const hStart = Math.min(this.hourStart, this.hourEnd);
            const hEnd = Math.max(this.hourStart, this.hourEnd);
            const hour = hStart + Math.floor(Math.random() * (hEnd - hStart + 1));
            const minute = Math.floor(Math.random() * 60);
            baseDate.setHours(hour, minute, 0, 0);
            const y = baseDate.getFullYear();
            const mo = String(baseDate.getMonth() + 1).padStart(2, '0');
            const d = String(baseDate.getDate()).padStart(2, '0');
            const h = String(baseDate.getHours()).padStart(2, '0');
            const m = String(baseDate.getMinutes()).padStart(2, '0');
            return `${y}-${mo}-${d}T${h}:${m}`;
        },

        newRow(scheduledAt, accounts, media) {
            return {
                _key: ++this._keyCounter,
                id: null,
                content_fr: '',
                hashtags: '',
                media: media || [],
                scheduled_at: scheduledAt,
                accounts: [...accounts],
                _saving: false,
                _saved: false,
                _dirty: false,
                _reshuffling: false,
                _generating: false,
                error: null,
            };
        },

        // ── Tirage depuis la bibliothèque ──────────────────────────
        async generateRows() {
            const accounts = this.getSelectedAccountIds();
            if (accounts.length === 0) return;
            const folderIds = this.selectedFolderIds();
            if (folderIds.length === 0) {
                this.pickError = 'Sélectionnez au moins un dossier.';
                return;
            }

            this.picking = true;
            this.pickError = null;
            this.shortfallMsg = null;

            try {
                const resp = await fetch('{{ route("posts.bulk.library.pick") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        folder_ids: folderIds,
                        num_posts: this.numPosts,
                        images_per_post: this.imagesPerPost,
                        keywords: this.parsedKeywords(),
                    }),
                });
                const data = await resp.json();

                const mediaRows = data.rows || [];
                if (mediaRows.length === 0) {
                    this.pickError = data.message || (this.parsedKeywords().length
                        ? "Aucune image ne correspond à ces mots-clés dans les dossiers cochés (ou déjà planifiées/publiées)."
                        : "Aucune image éligible dans les dossiers choisis (toutes déjà planifiées/publiées ?).");
                    this.picking = false;
                    return;
                }

                this.rows = [];
                const start = new Date(this.startDate + 'T00:00:00');
                for (let i = 0; i < mediaRows.length; i++) {
                    const date = new Date(start);
                    date.setDate(date.getDate() + (i * this.frequencyDays));
                    const media = mediaRows[i].map(m => ({
                        url: m.url,
                        mimetype: m.mimetype,
                        title: m.title,
                    }));
                    this.rows.push(this.newRow(this.computeScheduledAt(date), accounts, media));
                }

                if (data.shortfall) {
                    this.shortfallMsg = `Seulement ${mediaRows.length} publication(s) générée(s) sur ${this.numPosts} demandée(s) : pas assez d'images inédites dans les dossiers cochés.`;
                }

                this.step = 2;
            } catch (e) {
                this.pickError = 'Erreur lors du tirage des images.';
            }
            this.picking = false;
        },

        addRow() {
            let scheduledAt = '';
            if (this.rows.length > 0) {
                const lastDate = new Date(this.rows[this.rows.length - 1].scheduled_at);
                lastDate.setDate(lastDate.getDate() + this.frequencyDays);
                scheduledAt = this.computeScheduledAt(lastDate);
            }
            const accounts = this.rows.length > 0 ? this.rows[0].accounts : this.getSelectedAccountIds();
            this.rows.push(this.newRow(scheduledAt, accounts, []));
        },

        // Re-tire de nouvelles images pour une ligne (en excluant celles déjà présentes ailleurs).
        async reshuffleRow(index) {
            const row = this.rows[index];
            if (!row) return;
            const folderIds = this.selectedFolderIds();
            if (folderIds.length === 0) {
                row.error = 'Aucun dossier source coché (revenez à l\'étape 1).';
                return;
            }
            row._reshuffling = true;
            row.error = null;
            try {
                const resp = await fetch('{{ route("posts.bulk.library.pick") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        folder_ids: folderIds,
                        num_posts: 1,
                        images_per_post: this.imagesPerPost,
                        keywords: this.parsedKeywords(),
                    }),
                });
                const data = await resp.json();
                const mediaRows = data.rows || [];
                if (mediaRows.length === 0) {
                    row.error = 'Plus aucune image éligible à tirer.';
                } else {
                    row.media = mediaRows[0].map(m => ({ url: m.url, mimetype: m.mimetype, title: m.title }));
                    row._dirty = true;
                    await this.saveRow(index);
                }
            } catch (e) {
                row.error = 'Erreur lors du re-tirage.';
            }
            row._reshuffling = false;
        },

        // Override de openLibrary() : mémorise la row cible avant d'ouvrir.
        async openLibrary(rowIndex) {
            this.libraryTargetRow = rowIndex;
            this.showLibrary = true;
            this.libraryFolder = null;
            await this.fetchLibrary();
        },
        isInMedia(item) {
            const idx = this.libraryTargetRow;
            if (idx === null || !this.rows[idx]) return false;
            return this.rows[idx].media.some(m => m.url === item.url);
        },
        selectFromLibrary(item) {
            const idx = this.libraryTargetRow;
            if (idx === null || !this.rows[idx]) return;
            if (!this.rows[idx].media.some(m => m.url === item.url)) {
                this.rows[idx].media.push({
                    url: item.url,
                    mimetype: item.mimetype,
                    size: item.size,
                    title: item.title || item.filename,
                });
                this.rows[idx]._dirty = true;
                this.saveRow(idx);
            }
            this.showLibrary = false;
        },

        // ── IA ─────────────────────────────────────────────────────
        async generateAi(index) {
            const row = this.rows[index];
            if (!row || row.media.length === 0) return;
            const accountId = this.findAccountWithPersona(row.accounts);
            if (!accountId) {
                row.error = 'Aucun compte avec persona configurée';
                return;
            }
            const platforms = [...new Set(row.accounts.map(id => {
                const acc = this.accountsData.find(a => a.id === id);
                return acc ? acc.platform : null;
            }).filter(Boolean))];

            row._generating = true;
            row.error = null;
            try {
                const resp = await fetch('{{ route("posts.aiAssistMedia") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        media_urls: row.media.map(m => m.url),
                        platforms: platforms,
                        account_id: accountId,
                        content: row.content_fr || '',
                    }),
                });
                const data = await resp.json();
                if (data.error) {
                    row.error = data.error;
                } else if (data.platform_contents) {
                    const firstContent = Object.values(data.platform_contents)[0];
                    if (firstContent) {
                        row.content_fr = firstContent;
                        row._dirty = true;
                        await this.saveRow(index);
                    }
                }
            } catch (e) {
                row.error = 'Erreur de génération IA';
            }
            row._generating = false;
        },
        findAccountWithPersona(accountIds) {
            return accountIds.length > 0 ? accountIds[0] : null;
        },

        // ── Persistance (réutilise les endpoints du bulk V1) ───────
        async saveRow(index) {
            const row = this.rows[index];
            if (!row) return;
            if (!row.content_fr && row.media.length === 0 && !row.hashtags) {
                row._dirty = false;
                return;
            }
            row._saving = true;
            row._saved = false;
            row.error = null;
            try {
                const resp = await fetch('{{ route("posts.bulk.saveRow") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        post_id: row.id,
                        content_fr: row.content_fr,
                        hashtags: row.hashtags,
                        media: row.media.map(m => JSON.stringify(m)),
                        scheduled_at: row.scheduled_at ? row.scheduled_at.replace('T', ' ') + ':00' : null,
                        accounts: row.accounts,
                    }),
                });
                const data = await resp.json();
                if (data.success) {
                    row.id = data.post_id;
                    row._saved = true;
                    row._dirty = false;
                    setTimeout(() => { if (this.rows[index]) this.rows[index]._saved = false; }, 2000);
                } else {
                    row.error = data.message || 'Erreur de sauvegarde';
                }
            } catch (e) {
                row.error = 'Erreur de connexion';
            }
            row._saving = false;
        },
        async saveAllDirty() {
            for (let i = 0; i < this.rows.length; i++) {
                const row = this.rows[i];
                if (!row.id || row._dirty) {
                    if (row.content_fr || row.media.length > 0 || row.hashtags) {
                        await this.saveRow(i);
                    }
                }
            }
        },
        async deleteRow(index) {
            const row = this.rows[index];
            if (!row) return;
            if (!confirm('Supprimer cette ligne ?')) return;
            if (row.id) {
                try {
                    await fetch('{{ route("posts.bulk.deleteRow") }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({ post_id: row.id }),
                    });
                } catch (e) {}
            }
            this.rows.splice(index, 1);
        },
        removeMedia(rowIndex, mediaIndex) {
            this.rows[rowIndex].media.splice(mediaIndex, 1);
            this.rows[rowIndex]._dirty = true;
        },
    };
}
</script>
@endpush
@endsection
