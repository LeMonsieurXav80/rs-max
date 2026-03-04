@extends('layouts.app')

@section('title', 'Modifier le fil')

@section('content')
    <form method="POST" action="{{ route('threads.update', $thread) }}" class="max-w-4xl space-y-6" x-data="threadEditForm()">
        @csrf
        @method('PUT')

        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold text-gray-900">Modifier le fil de discussion</h1>
        </div>

        {{-- Validation errors --}}
        @if($errors->any())
            <div class="rounded-2xl bg-red-50 border border-red-200 p-6">
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                    </svg>
                    <div>
                        <h3 class="text-sm font-medium text-red-800">Veuillez corriger les erreurs suivantes :</h3>
                        <ul class="mt-2 text-sm text-red-700 list-disc list-inside space-y-1">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        @endif

        {{-- Section 1: Metadata --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 lg:p-8">
            <h2 class="text-base font-semibold text-gray-900 mb-4">Informations</h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="title" class="block text-sm font-medium text-gray-700 mb-1">Titre (interne)</label>
                    <input type="text" name="title" id="title"
                           value="{{ old('title', $thread->title) }}"
                           class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                </div>
                <div>
                    <label for="source_url" class="block text-sm font-medium text-gray-700 mb-1">URL source</label>
                    <input type="url" name="source_url" id="source_url"
                           value="{{ old('source_url', $thread->source_url) }}"
                           class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                </div>
            </div>
        </div>

        {{-- Section 2: Account selection --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 lg:p-8">
            <h2 class="text-base font-semibold text-gray-900 mb-4">Comptes de publication <span class="text-red-500">*</span></h2>

            @if($accounts->count())
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    @php
                        $platformLabels = [
                            'facebook' => 'Facebook',
                            'threads' => 'Threads',
                            'twitter' => 'Twitter / X',
                            'bluesky' => 'Bluesky',
                            'telegram' => 'Telegram',
                        ];
                        $platformOrder = ['twitter', 'threads', 'bluesky', 'facebook', 'telegram'];
                        $threadPlatforms = ['twitter', 'threads', 'bluesky'];
                    @endphp

                    @foreach($platformOrder as $slug)
                        @if(isset($accounts[$slug]))
                            <div class="border border-gray-200 rounded-xl overflow-hidden">
                                <div class="flex items-center gap-2.5 px-4 py-3 bg-gray-50 border-b border-gray-200">
                                    <x-platform-icon :platform="$slug" size="sm" />
                                    <span class="text-sm font-medium text-gray-900">{{ $platformLabels[$slug] ?? $slug }}</span>
                                    <span class="ml-auto px-2 py-0.5 text-xs rounded-full {{ in_array($slug, $threadPlatforms) ? 'bg-indigo-100 text-indigo-700' : 'bg-amber-100 text-amber-700' }}">
                                        {{ in_array($slug, $threadPlatforms) ? 'Fil' : 'Compile' }}
                                    </span>
                                </div>
                                <div class="p-3 space-y-1.5">
                                    @foreach($accounts[$slug] as $account)
                                        <label class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-indigo-50/50 transition-colors cursor-pointer">
                                            <input
                                                type="checkbox"
                                                name="accounts[]"
                                                value="{{ $account->id }}"
                                                {{ in_array($account->id, old('accounts', $selectedAccountIds)) ? 'checked' : '' }}
                                                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 transition-colors"
                                            >
                                            @if($account->profile_picture_url)
                                                <img src="{{ $account->profile_picture_url }}" alt="" class="w-6 h-6 rounded-full object-cover flex-shrink-0">
                                            @endif
                                            <span class="text-sm text-gray-700 truncate">{{ $account->name }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Section 3: Segments editor --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 lg:p-8">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-base font-semibold text-gray-900">
                    Segments
                    <span class="text-sm font-normal text-gray-500" x-text="'(' + segments.length + ')'"></span>
                </h2>
                <button type="button" @click="addSegment()"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-gray-100 text-gray-600 hover:bg-gray-200 transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Ajouter un segment
                </button>
            </div>

            <div class="space-y-4">
                <template x-for="(segment, index) in segments" :key="index">
                    <div class="border border-gray-200 rounded-xl overflow-hidden">
                        <div class="flex items-center gap-3 px-4 py-3 bg-gray-50 border-b border-gray-200">
                            <span class="flex items-center justify-center w-7 h-7 rounded-full bg-indigo-100 text-indigo-700 text-xs font-bold" x-text="index + 1"></span>
                            <span class="text-sm font-medium text-gray-700">Segment <span x-text="index + 1"></span></span>

                            <div class="ml-auto flex items-center gap-2">
                                <button type="button" @click="moveSegment(index, -1)" :disabled="index === 0"
                                        class="p-1 rounded text-gray-400 hover:text-gray-600 disabled:opacity-30">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15.75 7.5-7.5 7.5 7.5" /></svg>
                                </button>
                                <button type="button" @click="moveSegment(index, 1)" :disabled="index === segments.length - 1"
                                        class="p-1 rounded text-gray-400 hover:text-gray-600 disabled:opacity-30">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                                </button>
                                <button type="button" @click="removeSegment(index)" :disabled="segments.length <= 1"
                                        class="p-1 rounded text-gray-400 hover:text-red-600 disabled:opacity-30">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                </button>
                            </div>
                        </div>

                        <div class="p-4 space-y-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Contenu principal</label>
                                <textarea x-model="segment.content_fr"
                                          :name="'segments[' + index + '][content_fr]'"
                                          rows="3"
                                          class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm resize-y"
                                          placeholder="Contenu du segment..."></textarea>
                                <input type="hidden" :name="'segments[' + index + '][media_json]'"
                                       :value="segment.media ? JSON.stringify(segment.media) : ''">
                                <div class="flex items-center justify-between mt-1">
                                    <button type="button" @click="openMediaLibrary(index)"
                                            class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium rounded-lg text-indigo-600 hover:bg-indigo-50 transition-colors">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z" />
                                        </svg>
                                        Image
                                        <span x-show="segment.media && segment.media.length > 0"
                                              class="px-1.5 py-0.5 text-[10px] bg-indigo-100 text-indigo-700 rounded-full"
                                              x-text="segment.media.length"></span>
                                    </button>
                                    <span class="text-xs" :class="(segment.content_fr || '').length > 500 ? 'text-red-500' : 'text-gray-400'"
                                          x-text="(segment.content_fr || '').length + ' car.'"></span>
                                </div>
                            </div>

                            {{-- Media preview (multi-image) --}}
                            <template x-if="segment.media && segment.media.length > 0">
                                <div class="flex flex-wrap gap-2 p-3 bg-gray-50 rounded-xl">
                                    <template x-for="(m, mi) in segment.media" :key="mi">
                                        <div class="relative group">
                                            <img :src="m.url" alt="" class="w-20 h-14 object-cover rounded-lg border border-gray-200">
                                            <button type="button" @click="removeSegmentMedia(index, mi)"
                                                    class="absolute -top-1.5 -right-1.5 w-5 h-5 bg-red-500 text-white rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            <div x-data="{ showOverrides: Object.values(segment.platform_contents || {}).some(v => v) }">
                                <button type="button" @click="showOverrides = !showOverrides"
                                        class="text-xs text-indigo-600 hover:text-indigo-700 font-medium flex items-center gap-1">
                                    <svg class="w-3 h-3 transition-transform" :class="showOverrides && 'rotate-90'" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                                    </svg>
                                    Contenu par plateforme
                                </button>

                                <div x-show="showOverrides" x-collapse class="mt-2 space-y-2">
                                    <div>
                                        <label class="flex items-center gap-1.5 text-xs font-medium text-gray-500 mb-1">
                                            <x-platform-icon platform="twitter" size="xs" /> Twitter (280 car.)
                                        </label>
                                        <textarea x-model="segment.platform_contents.twitter"
                                                  :name="'segments[' + index + '][platform_contents][twitter]'"
                                                  rows="2" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-xs resize-y"
                                                  placeholder="Version Twitter (optionnel)..."></textarea>
                                        <div class="flex justify-end">
                                            <span class="text-xs" :class="(segment.platform_contents.twitter || '').length > 280 ? 'text-red-500' : 'text-gray-400'"
                                                  x-text="(segment.platform_contents.twitter || '').length + '/280'"></span>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="flex items-center gap-1.5 text-xs font-medium text-gray-500 mb-1">
                                            <x-platform-icon platform="threads" size="xs" /> Threads (500 car.)
                                        </label>
                                        <textarea x-model="segment.platform_contents.threads"
                                                  :name="'segments[' + index + '][platform_contents][threads]'"
                                                  rows="2" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-xs resize-y"
                                                  placeholder="Version Threads (optionnel)..."></textarea>
                                        <div class="flex justify-end">
                                            <span class="text-xs" :class="(segment.platform_contents.threads || '').length > 500 ? 'text-red-500' : 'text-gray-400'"
                                                  x-text="(segment.platform_contents.threads || '').length + '/500'"></span>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="flex items-center gap-1.5 text-xs font-medium text-gray-500 mb-1">
                                            <x-platform-icon platform="bluesky" size="xs" /> Bluesky (300 car.)
                                        </label>
                                        <textarea x-model="segment.platform_contents.bluesky"
                                                  :name="'segments[' + index + '][platform_contents][bluesky]'"
                                                  rows="2" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-xs resize-y"
                                                  placeholder="Version Bluesky (optionnel)..."></textarea>
                                        <div class="flex justify-end">
                                            <span class="text-xs" :class="(segment.platform_contents.bluesky || '').length > 300 ? 'text-red-500' : 'text-gray-400'"
                                                  x-text="(segment.platform_contents.bluesky || '').length + '/300'"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        {{-- Section 4: Status --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 lg:p-8">
            <h2 class="text-base font-semibold text-gray-900 mb-4">Statut</h2>

            <div class="flex gap-6">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="radio" name="status" value="draft" {{ $thread->status === 'draft' ? 'checked' : '' }}
                           class="text-indigo-600 focus:ring-indigo-500">
                    <span class="text-sm text-gray-700">Brouillon</span>
                </label>
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="radio" name="status" value="scheduled" {{ $thread->status === 'scheduled' ? 'checked' : '' }}
                           class="text-indigo-600 focus:ring-indigo-500">
                    <span class="text-sm text-gray-700">Programme</span>
                </label>
            </div>

            <div class="mt-4">
                <label for="scheduled_at" class="block text-sm font-medium text-gray-700 mb-1">Date et heure</label>
                <input type="datetime-local" name="scheduled_at" id="scheduled_at"
                       value="{{ $thread->scheduled_at?->format('Y-m-d\TH:i') }}"
                       class="rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
            </div>
        </div>

        {{-- Submit --}}
        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('threads.show', $thread) }}" class="px-4 py-2.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50 transition-colors">
                Annuler
            </a>
            <button type="submit" class="inline-flex items-center gap-2 px-6 py-2.5 text-sm font-medium text-white bg-indigo-600 rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                Enregistrer
            </button>
        </div>
        {{-- Media library modal --}}
        <template x-if="showMediaLibrary">
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showMediaLibrary = false">
                <div class="bg-white rounded-2xl shadow-xl w-full max-w-3xl max-h-[80vh] flex flex-col mx-4">
                    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
                        <h3 class="text-base font-semibold text-gray-900">
                            Mediatheque
                            <span class="text-sm font-normal text-gray-500" x-text="'— Segment ' + (activeMediaSegmentIndex + 1)"></span>
                        </h3>
                        <button type="button" @click="showMediaLibrary = false" class="text-gray-400 hover:text-gray-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="flex-1 overflow-y-auto p-6">
                        {{-- Upload zone --}}
                        <div class="mb-4">
                            <label class="flex flex-col items-center justify-center gap-2 px-4 py-3 border-2 border-dashed rounded-xl transition-colors"
                                   :class="mediaUploading ? 'border-indigo-400 bg-indigo-50/50' : 'border-gray-300 cursor-pointer hover:border-indigo-400 hover:bg-indigo-50/50'">
                                <div class="flex items-center gap-2">
                                    <template x-if="!mediaUploading">
                                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
                                        </svg>
                                    </template>
                                    <template x-if="mediaUploading">
                                        <svg class="w-5 h-5 text-indigo-500 animate-spin" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                        </svg>
                                    </template>
                                    <span class="text-sm text-gray-600" x-show="!mediaUploading">Uploader un fichier</span>
                                    <span class="text-sm text-indigo-600 font-medium" x-show="mediaUploading && mediaUploadPhase === 'upload'" x-text="'Upload en cours... ' + mediaUploadProgress + '%'"></span>
                                    <span class="text-sm text-indigo-600 font-medium" x-show="mediaUploading && mediaUploadPhase === 'processing'">Traitement en cours...</span>
                                </div>
                                <template x-if="mediaUploading">
                                    <div class="w-full bg-gray-200 rounded-full h-1.5 mt-1">
                                        <div class="h-1.5 rounded-full transition-all duration-300"
                                             :class="mediaUploadPhase === 'processing' ? 'bg-amber-500 animate-pulse' : 'bg-indigo-600'"
                                             :style="'width: ' + (mediaUploadPhase === 'processing' ? '100' : mediaUploadProgress) + '%'"></div>
                                    </div>
                                </template>
                                <input type="file" class="hidden" accept="image/*,video/*" multiple @change="uploadMediaForSegment($event)" :disabled="mediaUploading">
                            </label>
                        </div>

                        {{-- Folder pills --}}
                        <div class="flex flex-wrap items-center gap-2 mb-4" x-show="mediaLibraryFolders.length > 0">
                            <button type="button" @click="filterMediaFolder(null)"
                                    class="px-3 py-1 text-xs font-medium rounded-full transition-colors"
                                    :class="!mediaLibraryFolder ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'">
                                Tous
                            </button>
                            <template x-for="folder in mediaLibraryFolders" :key="folder.id">
                                <button type="button" @click="filterMediaFolder(folder.id)"
                                        class="inline-flex items-center gap-1.5 px-3 py-1 text-xs font-medium rounded-full transition-colors"
                                        :class="mediaLibraryFolder == folder.id ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'">
                                    <span class="w-2 h-2 rounded-sm" :style="'background-color: ' + folder.color" x-show="mediaLibraryFolder != folder.id"></span>
                                    <span x-text="folder.name"></span>
                                    <span class="text-[10px] opacity-70" x-text="'(' + folder.files_count + ')'"></span>
                                </button>
                            </template>
                        </div>

                        {{-- Loading --}}
                        <div x-show="mediaLibraryLoading" class="text-center py-8">
                            <svg class="w-8 h-8 text-gray-400 mx-auto animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                        </div>
                        <div x-show="!mediaLibraryLoading && mediaLibraryItems.length === 0" class="text-center py-8 text-sm text-gray-400">
                            Aucun media disponible.
                        </div>

                        {{-- Image grid --}}
                        <div x-show="!mediaLibraryLoading" class="grid grid-cols-3 sm:grid-cols-4 gap-3">
                            <template x-for="item in mediaLibraryItems" :key="item.url">
                                <div @click="selectMediaForSegment(item)"
                                     class="relative rounded-xl overflow-hidden border-2 aspect-square cursor-pointer transition-all"
                                     :class="isMediaSelected(item) ? 'border-indigo-500 ring-2 ring-indigo-200' : 'border-gray-200 hover:border-indigo-300'">
                                    <img :src="item.url" class="w-full h-full object-cover" loading="lazy">
                                    <div x-show="isMediaSelected(item)" class="absolute top-1.5 right-1.5 w-5 h-5 bg-indigo-500 text-white rounded-full flex items-center justify-center">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                        </svg>
                                    </div>
                                    <div class="absolute bottom-1 left-1 px-1.5 py-0.5 bg-black/60 text-white text-xs rounded" x-text="item.size_human"></div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div class="px-6 py-3 border-t border-gray-200 flex justify-end">
                        <button type="button" @click="showMediaLibrary = false"
                                class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors">
                            Fermer
                        </button>
                    </div>
                </div>
            </div>
        </template>
    </form>

    @php
        $segmentsJson = $thread->segments->map(function ($s) {
            $pc = $s->platform_contents ?? [];
            return [
                'content_fr' => $s->content_fr,
                'platform_contents' => [
                    'twitter' => $pc['twitter'] ?? '',
                    'threads' => $pc['threads'] ?? '',
                    'bluesky' => $pc['bluesky'] ?? '',
                ],
                'media' => $s->media,
            ];
        })->values();
    @endphp
    <script>
        function threadEditForm() {
            return {
                segments: @json($segmentsJson),

                // Media picker state
                showMediaLibrary: false,
                activeMediaSegmentIndex: null,
                mediaLibraryItems: [],
                mediaLibraryFolders: [],
                mediaLibraryFolder: null,
                mediaLibraryLoading: false,
                mediaUploading: false,
                mediaUploadProgress: 0,
                mediaUploadPhase: '',

                addSegment() {
                    this.segments.push({
                        content_fr: '',
                        platform_contents: { twitter: '', threads: '', bluesky: '' },
                        media: null,
                    });
                },

                removeSegment(index) {
                    if (this.segments.length > 1) {
                        this.segments.splice(index, 1);
                    }
                },

                moveSegment(index, direction) {
                    const newIndex = index + direction;
                    if (newIndex < 0 || newIndex >= this.segments.length) return;
                    const temp = this.segments[index];
                    this.segments[index] = this.segments[newIndex];
                    this.segments[newIndex] = temp;
                    this.segments = [...this.segments];
                },

                // --- Media picker methods ---

                openMediaLibrary(index) {
                    this.activeMediaSegmentIndex = index;
                    this.showMediaLibrary = true;
                    if (this.mediaLibraryItems.length === 0) {
                        this.fetchMediaLibrary();
                    }
                },

                async fetchMediaLibrary(folder) {
                    this.mediaLibraryLoading = true;
                    try {
                        let url = '{{ route("media.list") }}';
                        if (folder) url += '?folder=' + folder;
                        const resp = await fetch(url, {
                            headers: { 'Accept': 'application/json' },
                        });
                        const data = await resp.json();
                        this.mediaLibraryItems = data.items || [];
                        this.mediaLibraryFolders = data.folders || [];
                    } catch (err) {}
                    this.mediaLibraryLoading = false;
                },

                filterMediaFolder(folderId) {
                    this.mediaLibraryFolder = folderId;
                    this.fetchMediaLibrary(folderId);
                },

                selectMediaForSegment(item) {
                    const seg = this.segments[this.activeMediaSegmentIndex];
                    if (!seg.media) seg.media = [];
                    if (seg.media.some(m => m.url === item.url)) {
                        seg.media = seg.media.filter(m => m.url !== item.url);
                    } else {
                        seg.media.push({ type: item.is_video ? 'video' : 'image', url: item.url });
                    }
                },

                isMediaSelected(item) {
                    const seg = this.segments[this.activeMediaSegmentIndex];
                    if (!seg || !seg.media) return false;
                    return seg.media.some(m => m.url === item.url);
                },

                uploadMediaForSegment(event) {
                    const files = event.target.files;
                    if (!files.length) return;
                    const self = this;

                    const uploadNext = (index) => {
                        if (index >= files.length) {
                            self.mediaUploading = false;
                            self.mediaUploadProgress = 0;
                            self.mediaUploadPhase = '';
                            event.target.value = '';
                            return;
                        }

                        self.mediaUploading = true;
                        self.mediaUploadProgress = 0;
                        self.mediaUploadPhase = 'upload';

                        const formData = new FormData();
                        formData.append('file', files[index]);

                        const xhr = new XMLHttpRequest();
                        xhr.open('POST', '{{ route("media.upload") }}');
                        xhr.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name=csrf-token]').getAttribute('content'));
                        xhr.setRequestHeader('Accept', 'application/json');

                        xhr.upload.addEventListener('progress', (e) => {
                            if (e.lengthComputable) {
                                self.mediaUploadProgress = Math.round((e.loaded / e.total) * 100);
                                if (self.mediaUploadProgress >= 100) {
                                    self.mediaUploadPhase = 'processing';
                                }
                            }
                        });

                        xhr.onload = () => {
                            if (xhr.status >= 200 && xhr.status < 300) {
                                try {
                                    const data = JSON.parse(xhr.responseText);
                                    if (data.url) {
                                        const seg = self.segments[self.activeMediaSegmentIndex];
                                        if (!seg.media) seg.media = [];
                                        seg.media.push({ type: data.mimetype?.startsWith('video/') ? 'video' : 'image', url: data.url });
                                        self.fetchMediaLibrary(self.mediaLibraryFolder);
                                    }
                                } catch (err) {}
                            }
                            uploadNext(index + 1);
                        };

                        xhr.onerror = () => uploadNext(index + 1);
                        xhr.send(formData);
                    };

                    uploadNext(0);
                },

                removeSegmentMedia(segmentIndex, mediaIndex) {
                    this.segments[segmentIndex].media.splice(mediaIndex, 1);
                    if (this.segments[segmentIndex].media.length === 0) {
                        this.segments[segmentIndex].media = null;
                    }
                },
            };
        }
    </script>
@endsection
