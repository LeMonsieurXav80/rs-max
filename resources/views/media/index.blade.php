@extends('layouts.app')

@section('title', 'Medias')

@section('content')
    @php
        $itemsJson = $items->map(function ($item) use ($mediaPostMap) {
            $item['posts'] = $mediaPostMap[$item['filename']] ?? [];
            $statuses = collect($item['posts'])->pluck('status')->unique();
            if ($statuses->contains('published')) {
                $item['status_label'] = 'Publie';
                $item['status_class'] = 'bg-green-500 text-white';
            } elseif ($statuses->contains('publishing')) {
                $item['status_label'] = 'En cours';
                $item['status_class'] = 'bg-yellow-400 text-yellow-900';
            } elseif ($statuses->contains('scheduled')) {
                $item['status_label'] = 'Planifie';
                $item['status_class'] = 'bg-blue-500 text-white';
            } elseif ($statuses->contains('failed')) {
                $item['status_label'] = 'Echoue';
                $item['status_class'] = 'bg-red-500 text-white';
            } elseif ($statuses->isNotEmpty()) {
                $item['status_label'] = 'Brouillon';
                $item['status_class'] = 'bg-gray-500 text-white';
            } else {
                $item['status_label'] = null;
                $item['status_class'] = '';
            }
            if ($item['is_video']) {
                $item['thumbnail_url'] = route('media.thumbnail', $item['filename']);
            }
            return $item;
        })->values()->toArray();
    @endphp

    <div x-data="{
        items: @js($itemsJson),
        selected: null,
        uploading: false,
        uploadProgress: 0,
        deleteConfirm: false,
        dragOver: false,
        selectItem(item) {
            this.selected = (this.selected && this.selected.filename === item.filename) ? null : item;
        },
        handleDrop(e) {
            e.preventDefault();
            this.dragOver = false;
            this.uploadFiles(e.dataTransfer.files);
        },
        handleFileSelect(e) {
            this.uploadFiles(e.target.files);
            e.target.value = '';
        },
        async uploadFiles(files) {
            for (const file of files) {
                await this.uploadFile(file);
            }
        },
        async uploadFile(file) {
            this.uploading = true;
            this.uploadProgress = 0;
            const formData = new FormData();
            formData.append('file', file);
            try {
                const xhr = new XMLHttpRequest();
                xhr.upload.addEventListener('progress', (e) => {
                    if (e.lengthComputable) {
                        this.uploadProgress = Math.round((e.loaded / e.total) * 100);
                    }
                });
                await new Promise((resolve, reject) => {
                    xhr.onload = () => {
                        if (xhr.status >= 200 && xhr.status < 300) resolve(JSON.parse(xhr.responseText));
                        else {
                            try { const err = JSON.parse(xhr.responseText); reject(err.message || err.error || 'Erreur'); }
                            catch(e) { reject('Erreur d\'upload'); }
                        }
                    };
                    xhr.onerror = () => reject('Erreur de connexion');
                    xhr.open('POST', '{{ route('media.upload') }}');
                    xhr.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name=csrf-token]').getAttribute('content'));
                    xhr.setRequestHeader('Accept', 'application/json');
                    xhr.send(formData);
                });
                window.location.reload();
            } catch (error) {
                alert('Erreur : ' + error);
            } finally {
                this.uploading = false;
                this.uploadProgress = 0;
            }
        },
        async deleteFile() {
            if (!this.selected) return;
            try {
                const response = await fetch('/media/' + this.selected.filename, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                        'Accept': 'application/json',
                    }
                });
                if (response.ok) {
                    window.location.reload();
                } else {
                    const data = await response.json();
                    alert(data.error || 'Erreur lors de la suppression.');
                }
            } catch(e) {
                alert('Erreur de connexion.');
            }
            this.deleteConfirm = false;
        },
        get statusLabel() {
            if (!this.selected) return '';
            const statuses = (this.selected.posts || []).map(p => p.status);
            if (statuses.includes('published')) return 'Publie';
            if (statuses.includes('publishing')) return 'En cours de publication';
            if (statuses.includes('scheduled')) return 'Planifie';
            if (statuses.includes('failed')) return 'Echec de publication';
            if (statuses.length > 0) return 'Brouillon';
            return 'Non lie a un post';
        },
        get statusColor() {
            if (!this.selected) return 'bg-gray-100 text-gray-600';
            const statuses = (this.selected.posts || []).map(p => p.status);
            if (statuses.includes('published')) return 'bg-green-100 text-green-700';
            if (statuses.includes('publishing')) return 'bg-yellow-100 text-yellow-700';
            if (statuses.includes('scheduled')) return 'bg-blue-100 text-blue-700';
            if (statuses.includes('failed')) return 'bg-red-100 text-red-700';
            if (statuses.length > 0) return 'bg-gray-100 text-gray-600';
            return 'bg-gray-100 text-gray-500';
        }
    }">
        {{-- Stats cards --}}
        <div class="grid grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                <div class="text-2xl font-bold text-gray-900">{{ $items->count() }}</div>
                <div class="text-sm text-gray-500 mt-1">Total des fichiers</div>
            </div>
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                <div class="text-2xl font-bold text-gray-900">{{ $imageCount }}</div>
                <div class="text-sm text-gray-500 mt-1">Images</div>
            </div>
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                <div class="text-2xl font-bold text-gray-900">{{ $videoCount }}</div>
                <div class="text-sm text-gray-500 mt-1">Videos</div>
            </div>
        </div>

        {{-- Upload zone (drag & drop) --}}
        <div class="mb-6 bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
            <div class="border-2 border-dashed rounded-xl p-8 text-center transition-colors"
                 :class="dragOver ? 'border-indigo-400 bg-indigo-50' : 'border-gray-200'"
                 @dragover.prevent="dragOver = true"
                 @dragleave.prevent="dragOver = false"
                 @drop.prevent="handleDrop($event)">
                <input type="file" x-ref="fileInput" @change="handleFileSelect($event)" multiple accept="image/*,video/*" class="hidden">
                <template x-if="!uploading">
                    <div>
                        <svg class="w-10 h-10 text-gray-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
                        </svg>
                        <p class="text-sm text-gray-600">
                            Glissez vos fichiers ici ou
                            <button type="button" @click="$refs.fileInput.click()" class="text-indigo-600 hover:text-indigo-700 font-medium">cliquez pour parcourir</button>
                        </p>
                        <p class="text-xs text-gray-400 mt-1">Images (JPEG, PNG, GIF, WebP) et videos (MP4, MOV, AVI, WebM) — Max 50 Mo</p>
                    </div>
                </template>
                <template x-if="uploading">
                    <div>
                        <svg class="w-8 h-8 text-indigo-500 mx-auto mb-3 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        <p class="text-sm font-medium text-indigo-600">Upload en cours...</p>
                        <div class="w-48 mx-auto mt-3 bg-gray-200 rounded-full h-2">
                            <div class="bg-indigo-600 h-2 rounded-full transition-all" :style="'width: ' + uploadProgress + '%'"></div>
                        </div>
                        <p class="text-xs text-gray-500 mt-2" x-text="uploadProgress + '%'"></p>
                    </div>
                </template>
            </div>
        </div>

        {{-- Filters --}}
        <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
            <div class="flex items-center gap-2">
                <a href="{{ route('media.index') }}"
                   class="px-3 py-1.5 text-sm font-medium rounded-lg transition-colors {{ $filter === 'all' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50' }}">
                    Tous ({{ $items->count() }})
                </a>
                <a href="{{ route('media.index', ['filter' => 'images']) }}"
                   class="px-3 py-1.5 text-sm font-medium rounded-lg transition-colors {{ $filter === 'images' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50' }}">
                    Images ({{ $imageCount }})
                </a>
                <a href="{{ route('media.index', ['filter' => 'videos']) }}"
                   class="px-3 py-1.5 text-sm font-medium rounded-lg transition-colors {{ $filter === 'videos' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50' }}">
                    Videos ({{ $videoCount }})
                </a>
            </div>
            <button type="button" @click="$refs.fileInput.click()"
                class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Ajouter des medias
            </button>
        </div>

        {{-- Main: 2/3 grid + 1/3 detail panel --}}
        <div class="flex gap-6">
            {{-- Left: media grid (2/3) --}}
            <div class="min-w-0" style="flex: 2">
                <template x-if="items.length === 0">
                    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-16 text-center">
                        <svg class="w-12 h-12 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z" />
                        </svg>
                        <p class="text-gray-500 text-sm">Aucun media dans la bibliotheque.</p>
                        <p class="text-gray-400 text-xs mt-1">Glissez des fichiers dans la zone ci-dessus pour commencer.</p>
                    </div>
                </template>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <template x-for="item in items" :key="item.filename">
                        <div @click="selectItem(item)"
                             class="bg-white rounded-xl border-2 overflow-hidden cursor-pointer transition-all shadow-sm hover:shadow-md"
                             :class="selected && selected.filename === item.filename ? 'border-indigo-500 ring-2 ring-indigo-200' : 'border-gray-100 hover:border-gray-300'">
                            {{-- Thumbnail (portrait 4:5) --}}
                            <div class="aspect-[4/5] bg-gray-100 relative overflow-hidden">
                                <template x-if="item.is_image">
                                    <img :src="item.url" :alt="item.filename" class="w-full h-full object-cover" loading="lazy">
                                </template>
                                <template x-if="item.is_video">
                                    <div class="w-full h-full relative">
                                        <img :src="item.thumbnail_url" :alt="item.filename"
                                             class="w-full h-full object-cover" loading="lazy"
                                             x-on:error="$el.style.display='none'; $el.nextElementSibling.style.display='flex'">
                                        <div class="w-full h-full flex-col items-center justify-center bg-gray-900 text-white" style="display:none">
                                            <svg class="w-10 h-10" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                        </div>
                                        <div class="absolute inset-0 flex items-center justify-center">
                                            <div class="w-10 h-10 rounded-full bg-black/50 flex items-center justify-center backdrop-blur-sm">
                                                <svg class="w-5 h-5 text-white ml-0.5" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                            </div>
                                        </div>
                                    </div>
                                </template>

                                {{-- Status pill --}}
                                <template x-if="item.status_label">
                                    <div class="absolute top-1.5 right-1.5 px-2 py-0.5 text-[10px] font-semibold rounded-full leading-tight"
                                         :class="item.status_class" x-text="item.status_label"></div>
                                </template>

                                {{-- Size badge --}}
                                <div class="absolute bottom-1.5 left-1.5 px-1.5 py-0.5 bg-black/60 text-white text-[10px] rounded" x-text="item.size_human"></div>
                            </div>

                            {{-- Info below thumbnail --}}
                            <div class="p-2.5">
                                <p class="text-xs font-medium text-gray-900 truncate" x-text="item.filename"></p>
                                <p class="text-[11px] text-gray-400 mt-1">
                                    <span x-text="item.size_human"></span> &middot; <span x-text="item.date"></span>
                                </p>
                                <template x-if="item.posts && item.posts.length > 0">
                                    <p class="text-[11px] text-gray-500 mt-1.5 truncate" x-text="item.posts[0].preview"></p>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Right: detail panel (1/3, sticky) --}}
            <div class="hidden lg:block flex-shrink-0" style="flex: 1">
                <div class="sticky top-20">
                    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                        {{-- No selection --}}
                        <template x-if="!selected">
                            <div class="p-8 text-center py-20">
                                <svg class="w-16 h-16 text-gray-200 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="0.75">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z" />
                                </svg>
                                <p class="text-sm text-gray-400">Selectionnez un media pour voir ses details</p>
                            </div>
                        </template>

                        {{-- Selected --}}
                        <template x-if="selected">
                            <div>
                                {{-- Preview --}}
                                <div class="bg-gray-950 flex items-center justify-center" style="min-height: 220px; max-height: 360px;">
                                    <template x-if="selected.is_image">
                                        <img :src="selected.url" :alt="selected.filename" class="max-w-full max-h-[360px] object-contain">
                                    </template>
                                    <template x-if="selected.is_video">
                                        <video :src="selected.url" controls playsinline
                                               class="max-w-full max-h-[360px] object-contain"
                                               :key="selected.filename">
                                        </video>
                                    </template>
                                </div>

                                {{-- Info --}}
                                <div class="p-5 space-y-4">
                                    {{-- Status --}}
                                    <span class="inline-flex items-center px-2.5 py-1 text-xs font-semibold rounded-full" :class="statusColor" x-text="statusLabel"></span>

                                    {{-- Filename --}}
                                    <h3 class="text-sm font-semibold text-gray-900 break-all" x-text="selected.filename"></h3>

                                    {{-- File details --}}
                                    <div class="grid grid-cols-2 gap-3 text-xs">
                                        <div>
                                            <span class="text-gray-400 block">Type</span>
                                            <span class="text-gray-700 font-medium" x-text="selected.mimetype"></span>
                                        </div>
                                        <div>
                                            <span class="text-gray-400 block">Taille</span>
                                            <span class="text-gray-700 font-medium" x-text="selected.size_human"></span>
                                        </div>
                                        <div>
                                            <span class="text-gray-400 block">Date</span>
                                            <span class="text-gray-700 font-medium" x-text="selected.date"></span>
                                        </div>
                                        <div>
                                            <span class="text-gray-400 block">Format</span>
                                            <span class="text-gray-700 font-medium" x-text="selected.is_image ? 'Image' : 'Video'"></span>
                                        </div>
                                    </div>

                                    {{-- Linked posts --}}
                                    <div>
                                        <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Publications liees</h4>
                                        <template x-if="selected.posts && selected.posts.length > 0">
                                            <div class="space-y-2">
                                                <template x-for="post in selected.posts" :key="post.id">
                                                    <a :href="'/posts/' + post.id"
                                                       class="flex items-start gap-2.5 p-2.5 rounded-lg bg-gray-50 hover:bg-indigo-50 transition-colors group">
                                                        <span class="inline-block w-2 h-2 rounded-full flex-shrink-0 mt-1.5" :class="post.status_dot"></span>
                                                        <div class="min-w-0 flex-1">
                                                            <p class="text-xs text-gray-700 group-hover:text-indigo-600 transition-colors truncate" x-text="post.preview"></p>
                                                            <p class="text-[10px] text-gray-400 mt-0.5 capitalize" x-text="post.status === 'scheduled' ? 'Planifie' : post.status === 'published' ? 'Publie' : post.status === 'publishing' ? 'En cours' : post.status === 'failed' ? 'Echoue' : 'Brouillon'"></p>
                                                        </div>
                                                    </a>
                                                </template>
                                            </div>
                                        </template>
                                        <template x-if="!selected.posts || selected.posts.length === 0">
                                            <p class="text-xs text-gray-400 italic">Ce media n'est lie a aucune publication.</p>
                                        </template>
                                    </div>

                                    {{-- Actions --}}
                                    <div class="flex items-center gap-2 pt-2 border-t border-gray-100">
                                        <a :href="selected.url" download
                                           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                            </svg>
                                            Telecharger
                                        </a>
                                        <button type="button" @click="deleteConfirm = true"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-red-600 bg-red-50 hover:bg-red-100 rounded-lg transition-colors ml-auto">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                            </svg>
                                            Supprimer
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        {{-- Delete confirmation modal --}}
        <div x-show="deleteConfirm" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="deleteConfirm = false">
            <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm mx-4 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">Supprimer ce fichier ?</h3>
                        <p class="text-sm text-gray-500 mt-0.5">Cette action est irreversible.</p>
                    </div>
                </div>
                <p class="text-sm text-gray-600 mb-4 break-all" x-text="selected ? selected.filename : ''"></p>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="deleteConfirm = false"
                        class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-xl hover:bg-gray-200 transition-colors">
                        Annuler
                    </button>
                    <button type="button" @click="deleteFile()"
                        class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-xl hover:bg-red-700 transition-colors">
                        Supprimer
                    </button>
                </div>
            </div>
        </div>

        {{-- Mobile detail bottom sheet --}}
        <div x-show="selected" x-cloak class="lg:hidden fixed inset-0 z-50 bg-black/50" @click.self="selected = null">
            <div class="absolute inset-x-0 bottom-0 max-h-[85vh] bg-white rounded-t-2xl shadow-xl overflow-hidden flex flex-col">
                <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100">
                    <h3 class="text-sm font-semibold text-gray-900 truncate" x-text="selected ? selected.filename : ''"></h3>
                    <button @click="selected = null" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <template x-if="selected">
                    <div class="flex-1 overflow-y-auto">
                        <div class="bg-gray-950 flex items-center justify-center min-h-[200px] max-h-[300px]">
                            <template x-if="selected.is_image">
                                <img :src="selected.url" :alt="selected.filename" class="max-w-full max-h-[300px] object-contain">
                            </template>
                            <template x-if="selected.is_video">
                                <video :src="selected.url" controls playsinline class="max-w-full max-h-[300px] object-contain" :key="selected.filename"></video>
                            </template>
                        </div>
                        <div class="p-4 space-y-4">
                            <span class="inline-flex items-center px-2.5 py-1 text-xs font-semibold rounded-full" :class="statusColor" x-text="statusLabel"></span>
                            <div class="grid grid-cols-2 gap-3 text-xs">
                                <div><span class="text-gray-400 block">Taille</span><span class="text-gray-700 font-medium" x-text="selected.size_human"></span></div>
                                <div><span class="text-gray-400 block">Date</span><span class="text-gray-700 font-medium" x-text="selected.date"></span></div>
                            </div>
                            <template x-if="selected.posts && selected.posts.length > 0">
                                <div class="space-y-2">
                                    <h4 class="text-xs font-semibold text-gray-500 uppercase">Publications liees</h4>
                                    <template x-for="post in selected.posts" :key="post.id">
                                        <a :href="'/posts/' + post.id" class="flex items-center gap-2 p-2 rounded-lg bg-gray-50 text-xs text-gray-700">
                                            <span class="w-2 h-2 rounded-full flex-shrink-0" :class="post.status_dot"></span>
                                            <span class="truncate" x-text="post.preview"></span>
                                        </a>
                                    </template>
                                </div>
                            </template>
                            <div class="flex gap-2 pt-2">
                                <a :href="selected.url" download class="flex-1 text-center px-3 py-2 text-xs font-medium text-gray-600 bg-gray-100 rounded-lg">Telecharger</a>
                                <button @click="deleteConfirm = true" class="flex-1 text-center px-3 py-2 text-xs font-medium text-red-600 bg-red-50 rounded-lg">Supprimer</button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
@endsection
