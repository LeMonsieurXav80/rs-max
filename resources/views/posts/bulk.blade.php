@extends('layouts.app')

@section('title', 'Publication en masse')

@php
    $accountsJson = $accounts->map(fn($a) => [
        'id' => $a->id,
        'name' => $a->name,
        'picture' => $a->profile_picture_url,
        'platform' => $a->platform->slug,
    ])->values();
@endphp

@section('content')
<div x-data="bulkEditor()" class="space-y-6">

    {{-- ═══ STEP 1: Configuration ═══ --}}
    <div x-show="step === 1" x-transition>
        <h1 class="text-2xl font-bold text-gray-900 mb-6">Publication en masse</h1>

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

        {{-- Schedule configuration --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 mb-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Planification</h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                {{-- Number of posts --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre de publications</label>
                    <input type="number" x-model.number="numPosts" min="1" max="100"
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
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date de debut</label>
                    <input type="date" x-model="startDate"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                </div>

                {{-- Time window --}}
                <div class="sm:col-span-2 lg:col-span-3">
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

        {{-- Continue button --}}
        <div class="flex justify-end">
            <button type="button" @click="generateRows()"
                :disabled="getSelectedAccountIds().length === 0 || numPosts < 1"
                class="inline-flex items-center gap-2 px-6 py-3 bg-indigo-600 text-white rounded-xl font-medium hover:bg-indigo-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 0 1-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0 1 12 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m19.5 0v1.5c0 .621-.504 1.125-1.125 1.125M2.25 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125" />
                </svg>
                Generer le tableur (<span x-text="numPosts"></span> publications)
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
                    Publication en masse
                    <span class="text-base font-normal text-gray-500" x-text="'(' + rows.length + ' publications)'"></span>
                </h1>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-sm text-gray-500" x-text="savedCount + '/' + rows.length + ' enregistrees'"></span>
                <button type="button" @click="addRow()"
                    class="inline-flex items-center gap-1.5 px-4 py-2 bg-white border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Ajouter une ligne
                </button>
            </div>
        </div>

        {{-- Table --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 border-b border-gray-200">
                            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider w-10">#</th>
                            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider min-w-[250px]">Contenu</th>
                            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider min-w-[180px]">Medias</th>
                            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider min-w-[150px]">Hashtags</th>
                            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider min-w-[180px]">Date de publication</th>
                            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider min-w-[120px]">Reseaux sociaux</th>
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
                                        <span x-text="row._generating ? 'Generation...' : 'IA'"></span>
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

                                        {{-- Upload progress --}}
                                        <div x-show="row._uploading" class="space-y-1">
                                            <div class="w-full bg-gray-200 rounded-full h-1.5">
                                                <div class="bg-indigo-500 h-1.5 rounded-full transition-all" :style="'width:' + row._uploadProgress + '%'"></div>
                                            </div>
                                            <span class="text-xs text-gray-400" x-text="row._uploadPhase === 'processing' ? 'Compression...' : row._uploadProgress + '%'"></span>
                                        </div>

                                        {{-- Drop zone / add buttons --}}
                                        <div class="flex gap-1.5"
                                            @dragover.prevent="$el.classList.add('ring-2','ring-indigo-300')"
                                            @dragleave.prevent="$el.classList.remove('ring-2','ring-indigo-300')"
                                            @drop.prevent="$el.classList.remove('ring-2','ring-indigo-300'); uploadFilesForRow(index, $event.dataTransfer.files)">
                                            <button type="button" @click="openLibrary(index)"
                                                class="flex items-center gap-1 px-2.5 py-1.5 rounded-lg border border-dashed border-gray-300 text-xs text-gray-400 hover:border-indigo-400 hover:text-indigo-500 cursor-pointer transition-colors">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z"/>
                                                </svg>
                                                Bibliotheque
                                            </button>
                                            <label class="flex items-center gap-1 px-2.5 py-1.5 rounded-lg border border-dashed border-gray-300 text-xs text-gray-400 hover:border-indigo-400 hover:text-indigo-500 cursor-pointer transition-colors">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/>
                                                </svg>
                                                Upload
                                                <input type="file" multiple accept="image/*,video/*"
                                                    @change="uploadFilesForRow(index, $event.target.files); $event.target.value=''"
                                                    class="hidden">
                                            </label>
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
    <div x-show="showLibrary" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display:none">
        <div class="absolute inset-0 bg-black/50" @click="showLibrary = false"></div>
        <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-3xl max-h-[80vh] flex flex-col">
            {{-- Header --}}
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">Bibliotheque media</h3>
                <button type="button" @click="showLibrary = false" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            {{-- Folders --}}
            <div class="px-6 py-3 border-b border-gray-100 flex items-center gap-2 flex-wrap" x-show="libraryFolders.length > 0 || libraryFolder">
                <button type="button" @click="libraryFolder = null; fetchLibrary()"
                    class="px-3 py-1 rounded-lg text-xs font-medium transition-colors"
                    :class="!libraryFolder ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'">
                    Tout
                </button>
                <template x-for="folder in libraryFolders" :key="folder.id">
                    <button type="button" @click="libraryFolder = folder.id; fetchLibrary(folder.id)"
                        class="px-3 py-1 rounded-lg text-xs font-medium transition-colors"
                        :class="libraryFolder === folder.id ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                        x-text="folder.name"></button>
                </template>
            </div>

            {{-- Grid --}}
            <div class="flex-1 overflow-y-auto p-6">
                <div x-show="libraryLoading" class="flex items-center justify-center py-12">
                    <svg class="w-6 h-6 animate-spin text-indigo-500" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                </div>
                <div x-show="!libraryLoading" class="grid grid-cols-4 sm:grid-cols-5 md:grid-cols-6 gap-3">
                    <template x-for="item in libraryItems" :key="item.url">
                        <button type="button" @click="selectLibraryItem(item)"
                            class="relative aspect-square rounded-lg overflow-hidden border-2 border-transparent hover:border-indigo-400 transition-colors group cursor-pointer">
                            <template x-if="item.mimetype && item.mimetype.startsWith('image/')">
                                <img :src="item.url" class="w-full h-full object-cover" alt="">
                            </template>
                            <template x-if="item.mimetype && item.mimetype.startsWith('video/')">
                                <div class="w-full h-full bg-gray-800 flex items-center justify-center">
                                    <svg class="w-8 h-8 text-white/70" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M8 5v14l11-7z"/>
                                    </svg>
                                </div>
                            </template>
                            <div class="absolute inset-0 bg-indigo-600/0 group-hover:bg-indigo-600/20 transition-colors flex items-center justify-center">
                                <svg class="w-6 h-6 text-white opacity-0 group-hover:opacity-100 transition-opacity drop-shadow" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                                </svg>
                            </div>
                        </button>
                    </template>
                </div>
                <p x-show="!libraryLoading && libraryItems.length === 0" class="text-center text-gray-400 py-8">Aucun media dans la bibliotheque</p>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function bulkEditor() {
    return {
        step: 1,
        numPosts: 5,
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

        // Library modal
        showLibrary: false,
        libraryItems: [],
        libraryFolders: [],
        libraryFolder: null,
        libraryLoading: false,
        libraryTargetRow: null,

        // Account data from server
        accountsData: @json($accountsJson),

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

        generateRows() {
            const accounts = this.getSelectedAccountIds();
            if (accounts.length === 0) return;

            this.rows = [];
            const start = new Date(this.startDate + 'T00:00:00');
            const hStart = Math.min(this.hourStart, this.hourEnd);
            const hEnd = Math.max(this.hourStart, this.hourEnd);

            for (let i = 0; i < this.numPosts; i++) {
                const date = new Date(start);
                date.setDate(date.getDate() + (i * this.frequencyDays));

                // Random time within the window
                const hour = hStart + Math.floor(Math.random() * (hEnd - hStart + 1));
                const minute = Math.floor(Math.random() * 60);
                date.setHours(hour, minute, 0, 0);

                // Format as datetime-local value
                const y = date.getFullYear();
                const mo = String(date.getMonth() + 1).padStart(2, '0');
                const d = String(date.getDate()).padStart(2, '0');
                const h = String(date.getHours()).padStart(2, '0');
                const m = String(date.getMinutes()).padStart(2, '0');
                const scheduledAt = `${y}-${mo}-${d}T${h}:${m}`;

                this.rows.push({
                    _key: ++this._keyCounter,
                    id: null,
                    content_fr: '',
                    hashtags: '',
                    media: [],
                    scheduled_at: scheduledAt,
                    accounts: [...accounts],
                    _saving: false,
                    _saved: false,
                    _dirty: false,
                    _uploading: false,
                    _uploadProgress: 0,
                    _uploadPhase: 'upload',
                    _generating: false,
                    error: null,
                });
            }

            this.step = 2;
        },

        addRow() {
            // Calculate next date from last row
            let scheduledAt = '';
            if (this.rows.length > 0) {
                const lastDate = new Date(this.rows[this.rows.length - 1].scheduled_at);
                lastDate.setDate(lastDate.getDate() + this.frequencyDays);
                const hStart = Math.min(this.hourStart, this.hourEnd);
                const hEnd = Math.max(this.hourStart, this.hourEnd);
                const hour = hStart + Math.floor(Math.random() * (hEnd - hStart + 1));
                const minute = Math.floor(Math.random() * 60);
                lastDate.setHours(hour, minute, 0, 0);
                const y = lastDate.getFullYear();
                const mo = String(lastDate.getMonth() + 1).padStart(2, '0');
                const d = String(lastDate.getDate()).padStart(2, '0');
                const h = String(lastDate.getHours()).padStart(2, '0');
                const m = String(lastDate.getMinutes()).padStart(2, '0');
                scheduledAt = `${y}-${mo}-${d}T${h}:${m}`;
            }

            const accounts = this.rows.length > 0 ? [...this.rows[0].accounts] : this.getSelectedAccountIds();

            this.rows.push({
                _key: ++this._keyCounter,
                id: null,
                content_fr: '',
                hashtags: '',
                media: [],
                scheduled_at: scheduledAt,
                accounts: accounts,
                _saving: false,
                _saved: false,
                _dirty: false,
                _uploading: false,
                _uploadProgress: 0,
                _uploadPhase: 'upload',
                _generating: false,
                error: null,
            });
        },

        async openLibrary(rowIndex) {
            this.libraryTargetRow = rowIndex;
            this.showLibrary = true;
            this.libraryFolder = null;
            await this.fetchLibrary();
        },

        async fetchLibrary(folderId) {
            this.libraryLoading = true;
            try {
                let url = '{{ route("media.list") }}';
                if (folderId) url += '?folder=' + folderId;
                const resp = await fetch(url, { headers: { 'Accept': 'application/json' } });
                const data = await resp.json();
                this.libraryItems = data.items || [];
                this.libraryFolders = data.folders || [];
            } catch(e) {
                this.libraryItems = [];
            }
            this.libraryLoading = false;
        },

        selectLibraryItem(item) {
            const idx = this.libraryTargetRow;
            if (idx === null || !this.rows[idx]) return;

            // Avoid duplicates
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

        async generateAi(index) {
            const row = this.rows[index];
            if (!row || row.media.length === 0) return;

            // Find the first account that has a persona
            const accountId = this.findAccountWithPersona(row.accounts);
            if (!accountId) {
                row.error = 'Aucun compte avec persona configuree';
                return;
            }

            // Get unique platforms from selected accounts
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
                    // Use the first platform content as the main content
                    const firstContent = Object.values(data.platform_contents)[0];
                    if (firstContent) {
                        row.content_fr = firstContent;
                        row._dirty = true;
                        await this.saveRow(index);
                    }
                }
            } catch (e) {
                row.error = 'Erreur de generation IA';
            }

            row._generating = false;
        },

        findAccountWithPersona(accountIds) {
            // Return the first account ID — the backend checks persona
            // We try accounts in order, frontend doesn't know persona status
            return accountIds.length > 0 ? accountIds[0] : null;
        },

        async saveRow(index) {
            const row = this.rows[index];
            if (!row) return;

            // Don't save completely empty rows
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

        async uploadFilesForRow(index, fileList) {
            const files = Array.from(fileList);
            for (const file of files) {
                await this.uploadFileForRow(index, file);
            }
        },

        async uploadFileForRow(index, file) {
            const row = this.rows[index];
            if (!row) return;

            row._uploading = true;
            row._uploadProgress = 0;
            row._uploadPhase = 'upload';

            const formData = new FormData();
            formData.append('file', file);

            try {
                const result = await new Promise((resolve, reject) => {
                    const xhr = new XMLHttpRequest();
                    xhr.upload.addEventListener('progress', (e) => {
                        if (e.lengthComputable) {
                            row._uploadProgress = Math.round((e.loaded / e.total) * 100);
                            if (row._uploadProgress >= 100) {
                                row._uploadPhase = 'processing';
                            }
                        }
                    });
                    xhr.onload = () => {
                        if (xhr.status >= 200 && xhr.status < 300) {
                            resolve(JSON.parse(xhr.responseText));
                        } else {
                            try {
                                const err = JSON.parse(xhr.responseText);
                                reject(err.message || err.error || "Erreur d'upload");
                            } catch(e) {
                                reject("Erreur d'upload");
                            }
                        }
                    };
                    xhr.onerror = () => reject('Erreur de connexion');
                    xhr.open('POST', '{{ route("media.upload") }}');
                    xhr.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name=csrf-token]').content);
                    xhr.setRequestHeader('Accept', 'application/json');
                    xhr.send(formData);
                });

                row.media.push(result);
                row._dirty = true;
                // Auto-save after upload
                await this.saveRow(index);
            } catch (error) {
                row.error = 'Upload: ' + error;
            } finally {
                row._uploading = false;
                row._uploadProgress = 0;
            }
        },
    };
}
</script>
@endpush
@endsection
