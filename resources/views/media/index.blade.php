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
            if ($item['is_video'] ?? false) {
                $item['thumbnail_url'] = route('media.thumbnail', $item['filename']);
            }
            return $item;
        })->values()->toArray();

        $foldersJson = $folders->map(function ($f) {
            return [
                'id' => $f->id,
                'name' => $f->name,
                'slug' => $f->slug,
                'color' => $f->color,
                'is_system' => $f->is_system,
                'files_count' => $f->files_count,
            ];
        })->values()->toArray();
    @endphp

    <div x-data="{
        items: @js($itemsJson),
        folders: @js($foldersJson),
        currentFolder: @js($currentFolder),
        totalCount: {{ $totalCount }},
        uncategorizedCount: {{ $uncategorizedCount }},
        selected: null,
        multiSelect: false,
        multiSelected: [],
        lastMultiClickIndex: null,
        bulkMoveFolder: '',
        uploading: false,
        uploadProgress: 0,
        uploadPhase: 'upload',
        deleteConfirm: false,
        dragOver: false,
        newFolderName: '',
        creatingFolder: false,
        editingFolder: null,
        editFolderName: '',
        editFolderColor: '',
        deleteFolderConfirm: null,
        folderMenuOpen: null,
        movingToFolder: false,
        selectItem(item, event) {
            if (this.multiSelect) {
                const currentIndex = this.items.findIndex(i => i.id === item.id);
                if (event && event.shiftKey && this.lastMultiClickIndex !== null && currentIndex !== this.lastMultiClickIndex) {
                    const start = Math.min(this.lastMultiClickIndex, currentIndex);
                    const end = Math.max(this.lastMultiClickIndex, currentIndex);
                    for (let i = start; i <= end; i++) {
                        const id = this.items[i].id;
                        if (!this.multiSelected.includes(id)) {
                            this.multiSelected.push(id);
                        }
                    }
                } else {
                    const idx = this.multiSelected.indexOf(item.id);
                    if (idx === -1) {
                        this.multiSelected.push(item.id);
                    } else {
                        this.multiSelected.splice(idx, 1);
                    }
                }
                this.lastMultiClickIndex = currentIndex;
                return;
            }
            this.selected = (this.selected && this.selected.filename === item.filename) ? null : item;
        },
        isMultiSelected(item) {
            return this.multiSelected.includes(item.id);
        },
        selectAll() {
            if (this.multiSelected.length === this.items.length) {
                this.multiSelected = [];
            } else {
                this.multiSelected = this.items.map(i => i.id);
            }
        },
        exitMultiSelect() {
            this.multiSelect = false;
            this.multiSelected = [];
            this.lastMultiClickIndex = null;
            this.bulkMoveFolder = '';
        },
        async bulkMove() {
            if (this.multiSelected.length === 0 || this.bulkMoveFolder === '') return;
            const folderId = this.bulkMoveFolder === 'uncategorized' ? null : this.bulkMoveFolder;
            try {
                const response = await fetch('{{ route('media.folders.move') }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ file_ids: this.multiSelected, folder_id: folderId }),
                });
                if (response.ok) {
                    window.location.reload();
                }
            } catch(e) {
                alert('Erreur de connexion.');
            }
        },
        navigateFolder(folderId) {
            const params = new URLSearchParams(window.location.search);
            if (folderId === null) {
                params.delete('folder');
            } else {
                params.set('folder', folderId);
            }
            params.delete('filter');
            window.location.href = '{{ route('media.index') }}' + (params.toString() ? '?' + params.toString() : '');
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
            this.uploadPhase = 'upload';
            const formData = new FormData();
            formData.append('file', file);
            if (this.currentFolder && this.currentFolder !== 'uncategorized') {
                formData.append('folder_id', this.currentFolder);
            }
            try {
                const xhr = new XMLHttpRequest();
                xhr.upload.addEventListener('progress', (e) => {
                    if (e.lengthComputable) {
                        this.uploadProgress = Math.round((e.loaded / e.total) * 100);
                        if (this.uploadProgress >= 100) {
                            this.uploadPhase = 'processing';
                        }
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
        async createFolder() {
            if (!this.newFolderName.trim()) return;
            try {
                const response = await fetch('{{ route('media.folders.store') }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ name: this.newFolderName.trim() }),
                });
                if (response.ok) {
                    window.location.reload();
                } else {
                    const data = await response.json();
                    alert(data.message || 'Erreur.');
                }
            } catch(e) {
                alert('Erreur de connexion.');
            }
        },
        startEditFolder(folder) {
            this.editingFolder = folder.id;
            this.editFolderName = folder.name;
            this.editFolderColor = folder.color;
        },
        async saveFolder(folder) {
            try {
                const response = await fetch('/media/folders/' + folder.id, {
                    method: 'PATCH',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ name: this.editFolderName, color: this.editFolderColor }),
                });
                if (response.ok) {
                    window.location.reload();
                }
            } catch(e) {
                alert('Erreur de connexion.');
            }
            this.editingFolder = null;
        },
        async deleteFolder(folder) {
            try {
                const response = await fetch('/media/folders/' + folder.id, {
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
                    alert(data.error || 'Erreur.');
                }
            } catch(e) {
                alert('Erreur de connexion.');
            }
            this.deleteFolderConfirm = null;
        },
        async moveToFolder(folderId) {
            if (!this.selected || !this.selected.id) return;
            try {
                const response = await fetch('{{ route('media.folders.move') }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ file_ids: [this.selected.id], folder_id: folderId }),
                });
                if (response.ok) {
                    window.location.reload();
                }
            } catch(e) {
                alert('Erreur de connexion.');
            }
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
                <div class="text-2xl font-bold text-gray-900">{{ $totalCount }}</div>
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
                        <p class="text-sm font-medium text-indigo-600" x-show="uploadPhase === 'upload'">Upload en cours...</p>
                        <p class="text-sm font-medium text-amber-600" x-show="uploadPhase === 'processing'">Traitement en cours (encodage video)...</p>
                        <div class="w-48 mx-auto mt-3 bg-gray-200 rounded-full h-2">
                            <div class="h-2 rounded-full transition-all"
                                 :class="uploadPhase === 'processing' ? 'bg-amber-500 animate-pulse' : 'bg-indigo-600'"
                                 :style="'width: ' + (uploadPhase === 'processing' ? '100' : uploadProgress) + '%'"></div>
                        </div>
                        <p class="text-xs text-gray-500 mt-2" x-show="uploadPhase === 'upload'" x-text="uploadProgress + '%'"></p>
                        <p class="text-xs text-gray-500 mt-2" x-show="uploadPhase === 'processing'">Veuillez patienter, cela peut prendre quelques minutes...</p>
                    </div>
                </template>
            </div>
        </div>

        {{-- Folder pills --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-6">
            <div class="flex items-center gap-2 flex-wrap">
                {{-- All files pill --}}
                <button @click="navigateFolder(null)"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-lg transition-colors"
                        :class="!currentFolder ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z" />
                    </svg>
                    Tous
                    <span class="text-xs opacity-70" x-text="'(' + totalCount + ')'"></span>
                </button>

                {{-- Dynamic folder pills --}}
                <template x-for="folder in folders" :key="folder.id">
                    <div class="relative inline-flex">
                        <div class="inline-flex items-center rounded-lg transition-colors"
                             :class="currentFolder == folder.id ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'">
                            <button @click="navigateFolder(folder.id)"
                                    class="inline-flex items-center gap-1.5 pl-3 py-1.5 text-sm font-medium">
                                <span class="w-2.5 h-2.5 rounded-sm flex-shrink-0" :style="'background-color: ' + folder.color"></span>
                                <span x-text="folder.name"></span>
                                <span class="text-xs opacity-70" x-text="'(' + folder.files_count + ')'"></span>
                            </button>
                            {{-- Three-dot menu inside the pill --}}
                            <button @click.stop="folderMenuOpen = (folderMenuOpen === folder.id ? null : folder.id)"
                                    class="inline-flex items-center px-1.5 py-1.5 opacity-60 hover:opacity-100 transition-opacity">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z" />
                                </svg>
                            </button>
                        </div>
                        {{-- Dropdown menu --}}
                        <div x-show="folderMenuOpen === folder.id" x-cloak
                             @click.outside="folderMenuOpen = null"
                             class="absolute top-full left-0 mt-1 w-52 bg-white rounded-xl shadow-lg border border-gray-200 py-1.5 z-50">
                            <button @click="startEditFolder(folder); folderMenuOpen = null"
                                    class="w-full flex items-center gap-2.5 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 transition-colors">
                                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" /></svg>
                                Renommer
                            </button>
                            {{-- Color picker --}}
                            <div class="px-3 py-2 flex items-center gap-2.5">
                                <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.098 19.902a3.75 3.75 0 0 0 5.304 0l6.401-6.402M6.75 21A3.75 3.75 0 0 1 3 17.25V4.125C3 3.504 3.504 3 4.125 3h5.25c.621 0 1.125.504 1.125 1.125v4.072M6.75 21a3.75 3.75 0 0 0 3.75-3.75V8.197M6.75 21h13.125c.621 0 1.125-.504 1.125-1.125v-5.25c0-.621-.504-1.125-1.125-1.125h-4.072M10.5 8.197l2.88-2.88c.438-.439 1.15-.439 1.59 0l3.712 3.713c.44.44.44 1.152 0 1.59l-2.879 2.88M6.75 17.25h.008v.008H6.75v-.008Z" /></svg>
                                <span class="text-sm text-gray-700">Couleur</span>
                                <input type="color" :value="folder.color"
                                       @change="editFolderColor = $event.target.value; editFolderName = folder.name; saveFolder(folder)"
                                       class="w-6 h-6 rounded cursor-pointer border-0 p-0 ml-auto">
                            </div>
                            <template x-if="!folder.is_system">
                                <div>
                                    <div class="border-t border-gray-100 my-1"></div>
                                    <button @click="deleteFolderConfirm = folder; folderMenuOpen = null"
                                            class="w-full flex items-center gap-2.5 px-3 py-2 text-sm text-red-600 hover:bg-red-50 transition-colors">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                        Supprimer
                                    </button>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                {{-- Uncategorized pill --}}
                <button @click="navigateFolder('uncategorized')"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-lg transition-colors"
                        :class="currentFolder === 'uncategorized' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'">
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9.776c.112-.017.227-.026.344-.026h15.812c.117 0 .232.009.344.026m-16.5 0a2.25 2.25 0 0 0-1.883 2.542l.857 6a2.25 2.25 0 0 0 2.227 1.932H19.05a2.25 2.25 0 0 0 2.227-1.932l.857-6a2.25 2.25 0 0 0-1.883-2.542m-16.5 0V6A2.25 2.25 0 0 1 6 3.75h3.879a1.5 1.5 0 0 1 1.06.44l2.122 2.12a1.5 1.5 0 0 0 1.06.44H18A2.25 2.25 0 0 1 20.25 9v.776" />
                    </svg>
                    Non classe
                    <span class="text-xs opacity-70" x-text="'(' + uncategorizedCount + ')'"></span>
                </button>

                {{-- Separator --}}
                <div class="w-px h-6 bg-gray-200 mx-1"></div>

                {{-- New folder inline --}}
                <template x-if="!creatingFolder">
                    <button @click="creatingFolder = true; $nextTick(() => $refs.newFolderInput.focus())"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-lg text-indigo-600 bg-indigo-50 hover:bg-indigo-100 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Nouveau dossier
                    </button>
                </template>
                <template x-if="creatingFolder">
                    <div class="inline-flex items-center gap-1.5">
                        <input type="text" x-ref="newFolderInput" x-model="newFolderName" placeholder="Nom du dossier..."
                               class="text-sm border border-gray-200 rounded-lg px-2.5 py-1.5 w-40 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400"
                               @keydown.enter="createFolder()" @keydown.escape="creatingFolder = false; newFolderName = ''">
                        <button @click="createFolder()" :disabled="!newFolderName.trim()"
                                class="p-1.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                            </svg>
                        </button>
                        <button @click="creatingFolder = false; newFolderName = ''" class="p-1.5 text-gray-400 hover:text-gray-600">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </template>
            </div>

            {{-- Edit folder inline form --}}
            <template x-if="editingFolder">
                <div class="mt-3 pt-3 border-t border-gray-100 flex items-center gap-2">
                    <span class="text-xs text-gray-400">Modifier :</span>
                    <input type="text" x-model="editFolderName"
                           class="text-sm border border-gray-200 rounded-lg px-2.5 py-1.5 w-40 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400"
                           @keydown.enter="saveFolder({id: editingFolder})" @keydown.escape="editingFolder = null">
                    <input type="color" x-model="editFolderColor" class="w-8 h-8 rounded cursor-pointer border-0 p-0">
                    <button @click="saveFolder({id: editingFolder})" class="px-2.5 py-1.5 text-xs font-medium bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">Sauver</button>
                    <button @click="editingFolder = null" class="px-2.5 py-1.5 text-xs text-gray-400 hover:text-gray-600">Annuler</button>
                </div>
            </template>
        </div>

        {{-- Filters + multi-select --}}
        <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
            <div class="flex items-center gap-2">
                <a href="{{ route('media.index', array_filter(['folder' => $currentFolder])) }}"
                   class="px-3 py-1.5 text-sm font-medium rounded-lg transition-colors {{ $filter === 'all' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50' }}">
                    Tous
                </a>
                <a href="{{ route('media.index', array_filter(['filter' => 'images', 'folder' => $currentFolder])) }}"
                   class="px-3 py-1.5 text-sm font-medium rounded-lg transition-colors {{ $filter === 'images' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50' }}">
                    Images
                </a>
                <a href="{{ route('media.index', array_filter(['filter' => 'videos', 'folder' => $currentFolder])) }}"
                   class="px-3 py-1.5 text-sm font-medium rounded-lg transition-colors {{ $filter === 'videos' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50' }}">
                    Videos
                </a>
            </div>
            <div class="flex items-center gap-2">
                {{-- Toggle multi-select --}}
                <button type="button" @click="multiSelect ? exitMultiSelect() : (multiSelect = true, selected = null)"
                    class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-xl transition-colors"
                    :class="multiSelect ? 'bg-amber-100 text-amber-700 border border-amber-300' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50'">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    <span x-text="multiSelect ? 'Annuler' : 'Selectionner'"></span>
                </button>
                <button type="button" @click="$refs.fileInput.click()"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Ajouter des medias
                </button>
            </div>
        </div>

        {{-- Bulk action bar --}}
        <div x-show="multiSelect" x-cloak
             class="mb-4 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 flex flex-wrap items-center gap-3">
            <button @click="selectAll()"
                    class="text-sm font-medium text-amber-700 hover:text-amber-900 underline underline-offset-2">
                <span x-text="multiSelected.length === items.length ? 'Tout deselectionner' : 'Tout selectionner'"></span>
            </button>
            <span class="text-sm text-amber-600" x-show="multiSelected.length > 0">
                <span x-text="multiSelected.length"></span> fichier(s) selectionne(s)
            </span>
            <div class="flex items-center gap-2 ml-auto" x-show="multiSelected.length > 0">
                <label class="text-sm text-gray-600">Deplacer vers :</label>
                <select x-model="bulkMoveFolder" class="text-sm border border-gray-200 rounded-lg px-2.5 py-1.5 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400">
                    <option value="">-- Choisir --</option>
                    <option value="uncategorized">Non classe</option>
                    <template x-for="folder in folders" :key="folder.id">
                        <option :value="folder.id" x-text="folder.name"></option>
                    </template>
                </select>
                <button @click="bulkMove()" :disabled="bulkMoveFolder === ''"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9.776c.112-.017.227-.026.344-.026h15.812c.117 0 .232.009.344.026m-16.5 0a2.25 2.25 0 0 0-1.883 2.542l.857 6a2.25 2.25 0 0 0 2.227 1.932H19.05a2.25 2.25 0 0 0 2.227-1.932l.857-6a2.25 2.25 0 0 0-1.883-2.542m-16.5 0V6A2.25 2.25 0 0 1 6 3.75h3.879a1.5 1.5 0 0 1 1.06.44l2.122 2.12a1.5 1.5 0 0 0 1.06.44H18A2.25 2.25 0 0 1 20.25 9v.776" />
                    </svg>
                    Deplacer
                </button>
            </div>
        </div>

        {{-- Main layout: grid + detail panel --}}
        <div class="flex gap-6">
            {{-- Center: media grid --}}
            <div class="min-w-0 flex-1">
                <template x-if="items.length === 0">
                    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-16 text-center">
                        <svg class="w-12 h-12 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z" />
                        </svg>
                        <p class="text-gray-500 text-sm">Aucun media dans ce dossier.</p>
                        <p class="text-gray-400 text-xs mt-1">Glissez des fichiers dans la zone ci-dessus pour commencer.</p>
                    </div>
                </template>

                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                    <template x-for="item in items" :key="item.filename">
                        <div @click="selectItem(item, $event)"
                             class="bg-white rounded-xl border-2 overflow-hidden cursor-pointer transition-all shadow-sm hover:shadow-md"
                             :class="{
                                 'border-indigo-500 ring-2 ring-indigo-200': !multiSelect && selected && selected.filename === item.filename,
                                 'border-amber-400 ring-2 ring-amber-200': multiSelect && isMultiSelected(item),
                                 'border-gray-100 hover:border-gray-300': !((!multiSelect && selected && selected.filename === item.filename) || (multiSelect && isMultiSelected(item)))
                             }">
                            {{-- Thumbnail (portrait 4:5) --}}
                            <div class="aspect-[4/5] bg-gray-100 relative overflow-hidden">
                                {{-- Multi-select checkbox --}}
                                <div x-show="multiSelect" x-cloak
                                     class="absolute top-2 right-2 z-10">
                                    <div class="w-6 h-6 rounded-full border-2 flex items-center justify-center transition-colors"
                                         :class="isMultiSelected(item) ? 'bg-amber-500 border-amber-500' : 'bg-white/80 border-gray-300 backdrop-blur-sm'">
                                        <svg x-show="isMultiSelected(item)" class="w-3.5 h-3.5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                        </svg>
                                    </div>
                                </div>
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

                                {{-- Folder badge --}}
                                <template x-if="item.folder_color && !currentFolder">
                                    <div class="absolute top-1.5 left-1.5 flex items-center gap-1 px-1.5 py-0.5 bg-black/60 rounded text-[10px] text-white">
                                        <span class="w-2 h-2 rounded-sm flex-shrink-0" :style="'background-color: ' + item.folder_color"></span>
                                        <span x-text="item.folder_name" class="truncate max-w-[60px]"></span>
                                    </div>
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

            {{-- Right: detail panel (sticky) --}}
            <div class="hidden lg:block w-80 flex-shrink-0">
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

                                    {{-- Folder selector --}}
                                    <div>
                                        <label class="text-xs text-gray-400 block mb-1">Dossier</label>
                                        <select @change="moveToFolder($event.target.value || null)"
                                                class="w-full text-sm border border-gray-200 rounded-lg px-2.5 py-1.5 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400">
                                            <option value="" :selected="!selected.folder_id">Non classe</option>
                                            <template x-for="folder in folders" :key="folder.id">
                                                <option :value="folder.id" :selected="selected.folder_id == folder.id" x-text="folder.name"></option>
                                            </template>
                                        </select>
                                    </div>

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
                                                       class="block p-2.5 rounded-lg bg-gray-50 hover:bg-indigo-50 transition-colors group">
                                                        <div class="flex items-start gap-2.5">
                                                            <span class="inline-block w-2 h-2 rounded-full flex-shrink-0 mt-1.5" :class="post.status_dot"></span>
                                                            <div class="min-w-0 flex-1">
                                                                <p class="text-xs text-gray-700 group-hover:text-indigo-600 transition-colors truncate" x-text="post.preview"></p>
                                                                <p class="text-[10px] text-gray-400 mt-0.5 capitalize" x-text="post.status === 'scheduled' ? 'Planifie' : post.status === 'published' ? 'Publie' : post.status === 'publishing' ? 'En cours' : post.status === 'failed' ? 'Echoue' : 'Brouillon'"></p>
                                                            </div>
                                                        </div>
                                                        {{-- Platforms --}}
                                                        <template x-if="post.platforms && post.platforms.length > 0">
                                                            <div class="flex items-center gap-1.5 mt-1.5 ml-4.5">
                                                                <template x-for="pf in post.platforms" :key="pf.slug">
                                                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-medium"
                                                                          :class="pf.status === 'published' ? 'bg-green-100 text-green-700' : pf.status === 'failed' ? 'bg-red-100 text-red-700' : pf.status === 'scheduled' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600'"
                                                                          x-text="pf.name"></span>
                                                                </template>
                                                            </div>
                                                        </template>
                                                        {{-- Publication date --}}
                                                        <template x-if="post.published_at || post.scheduled_at">
                                                            <div class="flex items-center gap-1 mt-1 ml-4.5 text-[10px] text-gray-400">
                                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" /></svg>
                                                                <span x-text="post.published_at ? post.published_at : ('Prevu : ' + post.scheduled_at)"></span>
                                                            </div>
                                                        </template>
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

        {{-- Delete file confirmation modal --}}
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

        {{-- Delete folder confirmation modal --}}
        <div x-show="deleteFolderConfirm" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="deleteFolderConfirm = null">
            <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm mx-4 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9.776c.112-.017.227-.026.344-.026h15.812c.117 0 .232.009.344.026m-16.5 0a2.25 2.25 0 0 0-1.883 2.542l.857 6a2.25 2.25 0 0 0 2.227 1.932H19.05a2.25 2.25 0 0 0 2.227-1.932l.857-6a2.25 2.25 0 0 0-1.883-2.542m-16.5 0V6A2.25 2.25 0 0 1 6 3.75h3.879a1.5 1.5 0 0 1 1.06.44l2.122 2.12a1.5 1.5 0 0 0 1.06.44H18A2.25 2.25 0 0 1 20.25 9v.776" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">Supprimer ce dossier ?</h3>
                        <p class="text-sm text-gray-500 mt-0.5">Les fichiers seront deplaces vers "Non classe".</p>
                    </div>
                </div>
                <p class="text-sm text-gray-600 mb-4" x-text="deleteFolderConfirm ? deleteFolderConfirm.name : ''"></p>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="deleteFolderConfirm = null"
                        class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-xl hover:bg-gray-200 transition-colors">
                        Annuler
                    </button>
                    <button type="button" @click="deleteFolder(deleteFolderConfirm)"
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

                            {{-- Mobile folder selector --}}
                            <div>
                                <label class="text-xs text-gray-400 block mb-1">Dossier</label>
                                <select @change="moveToFolder($event.target.value || null)"
                                        class="w-full text-sm border border-gray-200 rounded-lg px-2.5 py-1.5">
                                    <option value="" :selected="!selected.folder_id">Non classe</option>
                                    <template x-for="folder in folders" :key="folder.id">
                                        <option :value="folder.id" :selected="selected.folder_id == folder.id" x-text="folder.name"></option>
                                    </template>
                                </select>
                            </div>

                            <div class="grid grid-cols-2 gap-3 text-xs">
                                <div><span class="text-gray-400 block">Taille</span><span class="text-gray-700 font-medium" x-text="selected.size_human"></span></div>
                                <div><span class="text-gray-400 block">Date</span><span class="text-gray-700 font-medium" x-text="selected.date"></span></div>
                            </div>
                            <template x-if="selected.posts && selected.posts.length > 0">
                                <div class="space-y-2">
                                    <h4 class="text-xs font-semibold text-gray-500 uppercase">Publications liees</h4>
                                    <template x-for="post in selected.posts" :key="post.id">
                                        <a :href="'/posts/' + post.id" class="block p-2 rounded-lg bg-gray-50 text-xs text-gray-700">
                                            <div class="flex items-center gap-2">
                                                <span class="w-2 h-2 rounded-full flex-shrink-0" :class="post.status_dot"></span>
                                                <span class="truncate" x-text="post.preview"></span>
                                            </div>
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
