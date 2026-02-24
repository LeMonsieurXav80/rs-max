@extends('layouts.app')

@section('title', 'Médias')

@section('content')
    <div class="max-w-6xl" x-data="{
        uploading: false,
        uploadProgress: 0,
        deleteConfirm: null,
        handleDrop(e) {
            e.preventDefault();
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
                        if (xhr.status >= 200 && xhr.status < 300) {
                            resolve(JSON.parse(xhr.responseText));
                        } else {
                            try {
                                const err = JSON.parse(xhr.responseText);
                                reject(err.message || err.error || 'Erreur d\'upload');
                            } catch(e) {
                                reject('Erreur d\'upload');
                            }
                        }
                    };
                    xhr.onerror = () => reject('Erreur de connexion');
                    xhr.open('POST', '{{ route('media.upload') }}');
                    xhr.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name=csrf-token]').getAttribute('content'));
                    xhr.setRequestHeader('Accept', 'application/json');
                    xhr.send(formData);
                });

                // Reload page to show new file
                window.location.reload();
            } catch (error) {
                alert('Erreur : ' + error);
            } finally {
                this.uploading = false;
                this.uploadProgress = 0;
            }
        },
        async deleteFile(filename) {
            try {
                const response = await fetch('/media/' + filename, {
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
            this.deleteConfirm = null;
        }
    }">
        {{-- Stats --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-2xl font-bold text-gray-900">{{ $items->count() }}</div>
                <div class="text-sm text-gray-500 mt-1">Total des fichiers</div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-2xl font-bold text-gray-900">{{ $imageCount }}</div>
                <div class="text-sm text-gray-500 mt-1">Images</div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-2xl font-bold text-gray-900">{{ $videoCount }}</div>
                <div class="text-sm text-gray-500 mt-1">Vidéos</div>
            </div>
        </div>

        {{-- Upload zone --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6">
            <div
                @dragover.prevent="$el.classList.add('border-indigo-400', 'bg-indigo-50/50')"
                @dragleave.prevent="$el.classList.remove('border-indigo-400', 'bg-indigo-50/50')"
                @drop.prevent="$el.classList.remove('border-indigo-400', 'bg-indigo-50/50'); handleDrop($event)"
                @click="$refs.fileInput.click()"
                class="border-2 border-dashed border-gray-300 rounded-xl p-8 text-center cursor-pointer hover:border-gray-400 transition-colors"
            >
                <input type="file" x-ref="fileInput" @change="handleFileSelect($event)" multiple accept="image/*,video/*" class="hidden">

                <div x-show="!uploading">
                    <svg class="w-10 h-10 text-gray-400 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
                    </svg>
                    <p class="text-sm text-gray-600">Glissez vos fichiers ici ou <span class="text-indigo-600 font-medium">cliquez pour parcourir</span></p>
                    <p class="text-xs text-gray-400 mt-1">Images (JPEG, PNG, GIF, WebP) et vidéos (MP4, MOV, AVI, WebM) &mdash; Max 50 Mo</p>
                </div>

                <div x-show="uploading" x-cloak>
                    <svg class="w-8 h-8 text-indigo-500 mx-auto mb-2 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <p class="text-sm text-indigo-600 font-medium">Upload en cours... <span x-text="uploadProgress + '%'"></span></p>
                    <div class="mt-2 w-48 mx-auto bg-gray-200 rounded-full h-1.5">
                        <div class="bg-indigo-600 h-1.5 rounded-full transition-all" :style="'width: ' + uploadProgress + '%'"></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Filters --}}
        <div class="flex items-center gap-2 mb-6">
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
                Vidéos ({{ $videoCount }})
            </a>
        </div>

        {{-- Media grid --}}
        @if($items->isEmpty())
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-12 text-center">
                <svg class="w-12 h-12 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z" />
                </svg>
                <p class="text-gray-500 text-sm">Aucun média dans la bibliothèque.</p>
                <p class="text-gray-400 text-xs mt-1">Glissez des fichiers dans la zone ci-dessus pour commencer.</p>
            </div>
        @else
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
                @foreach($items as $item)
                    <div class="relative group bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        {{-- Preview --}}
                        <div class="aspect-square bg-gray-100 relative">
                            @if($item['is_image'])
                                <img src="{{ $item['url'] }}" alt="{{ $item['filename'] }}" class="w-full h-full object-cover">
                            @elseif($item['is_video'])
                                <div class="w-full h-full flex flex-col items-center justify-center bg-gray-900 text-white">
                                    <svg class="w-10 h-10 mb-2" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M8 5v14l11-7z"/>
                                    </svg>
                                    <span class="text-xs opacity-75 px-2 text-center truncate w-full">{{ $item['filename'] }}</span>
                                </div>
                            @endif

                            {{-- Type badge --}}
                            <div class="absolute top-2 left-2 px-1.5 py-0.5 text-xs font-medium rounded {{ $item['is_image'] ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700' }}">
                                {{ $item['is_image'] ? 'IMG' : 'VID' }}
                            </div>

                            {{-- Delete button --}}
                            <button type="button"
                                @click="deleteConfirm = '{{ $item['filename'] }}'"
                                class="absolute top-2 right-2 w-7 h-7 bg-red-500 text-white rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity shadow-sm hover:bg-red-600">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                </svg>
                            </button>
                        </div>

                        {{-- Info --}}
                        <div class="p-3">
                            <p class="text-xs font-medium text-gray-700 truncate" title="{{ $item['filename'] }}">{{ $item['filename'] }}</p>
                            <div class="flex items-center justify-between mt-1.5">
                                <span class="text-xs text-gray-400">{{ $item['size_human'] }}</span>
                                <span class="text-xs text-gray-400">{{ $item['date'] }}</span>
                            </div>

                            {{-- Linked posts --}}
                            @if(isset($mediaPostMap[$item['filename']]) && count($mediaPostMap[$item['filename']]) > 0)
                                <div class="mt-2 space-y-1">
                                    @foreach($mediaPostMap[$item['filename']] as $linkedPost)
                                        <a href="{{ route('posts.show', $linkedPost['id']) }}"
                                           class="flex items-center gap-1.5 text-xs text-gray-500 hover:text-indigo-600 transition-colors">
                                            <span class="inline-block w-1.5 h-1.5 rounded-full flex-shrink-0 {{ $linkedPost['status_dot'] ?? 'bg-gray-300' }}"></span>
                                            <span class="truncate">{{ $linkedPost['preview'] }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            @else
                                <p class="mt-2 text-xs text-gray-300 italic">Non lié</p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Delete confirmation modal --}}
        <div x-show="deleteConfirm" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="deleteConfirm = null">
            <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm mx-4 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">Supprimer ce fichier ?</h3>
                        <p class="text-sm text-gray-500 mt-0.5">Cette action est irréversible.</p>
                    </div>
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="deleteConfirm = null"
                        class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-xl hover:bg-gray-200 transition-colors">
                        Annuler
                    </button>
                    <button type="button" @click="deleteFile(deleteConfirm)"
                        class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-xl hover:bg-red-700 transition-colors">
                        Supprimer
                    </button>
                </div>
            </div>
        </div>
    </div>
@endsection
