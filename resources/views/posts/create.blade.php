@extends('layouts.app')

@section('title', 'Nouveau post')

@section('content')
    <form method="POST" action="{{ route('posts.store') }}" class="max-w-4xl space-y-6" x-data="{
        publishMode: '{{ old('publish_now') ? 'now' : 'schedule' }}',
        showTelegramChannel: false,
        mediaItems: [],
        uploading: false,
        uploadProgress: 0,
        showLibrary: false,
        libraryItems: [],
        libraryLoading: false,
        // Publishing progress
        isPublishing: false,
        publishDone: false,
        publishPostId: null,
        publishShowUrl: '',
        publishPlatforms: [],
        publishCurrent: 0,
        publishTotal: 0,
        publishErrors: [],
        async submitPublishNow(e) {
            if (this.publishMode !== 'now') return;
            e.preventDefault();
            this.isPublishing = true;
            this.publishDone = false;
            this.publishErrors = [];

            const form = e.target;
            const formData = new FormData(form);

            try {
                const resp = await fetch(form.action, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: formData,
                });

                if (!resp.ok) {
                    const err = await resp.json();
                    this.isPublishing = false;
                    if (err.errors) {
                        let msgs = [];
                        Object.values(err.errors).forEach(v => msgs.push(...v));
                        alert(msgs.join('\n'));
                    }
                    return;
                }

                const data = await resp.json();
                this.publishPostId = data.post_id;
                this.publishShowUrl = data.show_url;
                this.publishPlatforms = data.post_platforms.map(pp => ({
                    ...pp,
                    status: 'pending',
                    error: null,
                }));
                this.publishTotal = this.publishPlatforms.length;
                this.publishCurrent = 0;

                // Publish each platform sequentially
                for (let i = 0; i < this.publishPlatforms.length; i++) {
                    const pp = this.publishPlatforms[i];
                    pp.status = 'publishing';
                    this.publishCurrent = i;

                    try {
                        const pubResp = await fetch(pp.publish_url, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                                'Accept': 'application/json',
                            },
                        });

                        const pubData = await pubResp.json();
                        if (pubData.success) {
                            pp.status = 'published';
                        } else {
                            pp.status = 'failed';
                            pp.error = pubData.error || 'Erreur inconnue';
                            this.publishErrors.push(pp.account_name + ': ' + pp.error);
                        }
                    } catch (err) {
                        pp.status = 'failed';
                        pp.error = 'Erreur de connexion';
                        this.publishErrors.push(pp.account_name + ': Erreur de connexion');
                    }

                    this.publishCurrent = i + 1;
                }

                this.publishDone = true;
            } catch (err) {
                this.isPublishing = false;
                alert('Erreur lors de la création du post.');
            }
        },
        get publishSuccessCount() {
            return this.publishPlatforms.filter(p => p.status === 'published').length;
        },
        get publishProgressPercent() {
            return this.publishTotal > 0 ? Math.round((this.publishCurrent / this.publishTotal) * 100) : 0;
        },
        init() {
            this.checkTelegram();
            // Restore media from old input
            const existingMedia = document.querySelectorAll('input[name=\'existing_media[]\']');
            existingMedia.forEach(el => {
                try {
                    const data = JSON.parse(el.value);
                    this.mediaItems.push(data);
                } catch(e) {}
            });
        },
        checkTelegram() {
            const checked = document.querySelectorAll('input[name=\'accounts[]\']:checked');
            this.showTelegramChannel = false;
            checked.forEach(el => {
                if (el.dataset.platform === 'telegram') {
                    this.showTelegramChannel = true;
                }
            });
        },
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

                const result = await new Promise((resolve, reject) => {
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

                this.mediaItems.push(result);
            } catch (error) {
                alert('Erreur : ' + error);
            } finally {
                this.uploading = false;
                this.uploadProgress = 0;
            }
        },
        removeMedia(index) {
            this.mediaItems.splice(index, 1);
        },
        async openLibrary() {
            this.showLibrary = true;
            this.libraryLoading = true;
            try {
                const response = await fetch('{{ route('media.list') }}', {
                    headers: { 'Accept': 'application/json' }
                });
                this.libraryItems = await response.json();
            } catch(e) {
                this.libraryItems = [];
            } finally {
                this.libraryLoading = false;
            }
        },
        selectFromLibrary(item) {
            // Don't add duplicates
            if (this.mediaItems.find(m => m.url === item.url)) return;
            this.mediaItems.push({
                url: item.url,
                mimetype: item.mimetype,
                size: item.size,
                title: item.filename,
            });
        },
        isInMedia(item) {
            return this.mediaItems.find(m => m.url === item.url);
        }
    }" @submit="submitPublishNow($event)">
        @csrf

        {{-- Restore old media values --}}
        @if(old('media'))
            @foreach(old('media') as $oldMedia)
                <input type="hidden" name="existing_media[]" value="{{ $oldMedia }}">
            @endforeach
        @endif

        {{-- Validation errors summary --}}
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

        {{-- Section 1: Comptes de publication --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 lg:p-8" x-data="{
            saving: false,
            saved: false,
            async saveDefaults() {
                this.saving = true;
                const checked = [...document.querySelectorAll('input[name=\'accounts[]\']:checked')].map(el => parseInt(el.value));
                try {
                    const resp = await fetch('{{ route('posts.defaultAccounts') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({ accounts: checked })
                    });
                    if (resp.ok) {
                        this.saved = true;
                        setTimeout(() => this.saved = false, 2000);
                    }
                } catch(e) {}
                this.saving = false;
            }
        }">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-base font-semibold text-gray-900">Comptes de publication <span class="text-red-500">*</span></h2>
                <button type="button" @click="saveDefaults()"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg transition-colors"
                    :class="saved ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                    :disabled="saving">
                    <template x-if="!saving && !saved">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185V21L12 17.25 4.5 21V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0 1 11.186 0Z" />
                        </svg>
                    </template>
                    <template x-if="saving">
                        <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                    </template>
                    <template x-if="saved">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                    </template>
                    <span x-text="saved ? 'Enregistré' : 'Enregistrer'"></span>
                </button>
            </div>

            @if($accounts->count())
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    @php
                        $platformLabels = [
                            'facebook' => 'Facebook',
                            'instagram' => 'Instagram',
                            'threads' => 'Threads',
                            'twitter' => 'Twitter / X',
                            'telegram' => 'Telegram',
                        ];
                        $platformOrder = ['facebook', 'instagram', 'threads', 'twitter', 'telegram'];
                    @endphp

                    @foreach($platformOrder as $slug)
                        @if(isset($accounts[$slug]))
                            <div class="border border-gray-200 rounded-xl overflow-hidden">
                                <div class="flex items-center gap-2.5 px-4 py-3 bg-gray-50 border-b border-gray-200">
                                    <x-platform-icon :platform="$slug" size="sm" />
                                    <span class="text-sm font-medium text-gray-900">{{ $platformLabels[$slug] ?? $slug }}</span>
                                    <span class="text-xs text-gray-400 ml-auto">{{ $accounts[$slug]->count() }}</span>
                                </div>
                                <div class="p-3 space-y-1.5">
                                    @foreach($accounts[$slug] as $account)
                                        <label class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-indigo-50/50 transition-colors cursor-pointer">
                                            <input
                                                type="checkbox"
                                                name="accounts[]"
                                                value="{{ $account->id }}"
                                                data-platform="{{ $slug }}"
                                                @change="checkTelegram()"
                                                {{ in_array($account->id, old('accounts', $defaultAccountIds ?? [])) ? 'checked' : '' }}
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
            @else
                <div class="text-sm text-gray-500 bg-gray-50 rounded-xl p-4">
                    Aucun compte actif disponible.
                    <a href="{{ route('platforms.facebook') }}" class="text-indigo-600 hover:text-indigo-700 font-medium">Configurer les plateformes</a>
                </div>
            @endif

            @error('accounts')
                <p class="mt-3 text-sm text-red-600">{{ $message }}</p>
            @enderror
            @error('accounts.*')
                <p class="mt-3 text-sm text-red-600">{{ $message }}</p>
            @enderror

            {{-- Telegram channel (shown when a Telegram account is selected) --}}
            <div x-show="showTelegramChannel" x-transition x-cloak class="mt-4">
                <label for="telegram_channel" class="block text-sm font-medium text-gray-700 mb-2">
                    Canal Telegram (optionnel, remplace le chat_id du compte)
                </label>
                <input
                    type="text"
                    id="telegram_channel"
                    name="telegram_channel"
                    value="{{ old('telegram_channel') }}"
                    class="w-full sm:w-1/2 rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm placeholder-gray-400 transition-colors"
                    placeholder="@mon_canal"
                >
            </div>
        </div>

        {{-- Section 2: Médias --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 lg:p-8">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-base font-semibold text-gray-900">Médias</h2>
                <button type="button" @click="openLibrary()" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-indigo-600 bg-indigo-50 rounded-lg hover:bg-indigo-100 transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z" />
                    </svg>
                    Bibliothèque
                </button>
            </div>

            {{-- Drop zone --}}
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
                    <p class="text-xs text-gray-400 mt-1">Images (JPEG, PNG, GIF, WebP) et vidéos (MP4, MOV, AVI, WebM)</p>
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

            {{-- Media previews --}}
            <div x-show="mediaItems.length > 0" x-cloak class="mt-4">
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                    <template x-for="(item, index) in mediaItems" :key="item.url">
                        <div class="relative group rounded-xl overflow-hidden border border-gray-200 aspect-square bg-gray-100">
                            {{-- Image preview --}}
                            <template x-if="item.mimetype && item.mimetype.startsWith('image/')">
                                <img :src="item.url" :alt="item.title" class="w-full h-full object-cover">
                            </template>
                            {{-- Video preview --}}
                            <template x-if="item.mimetype && item.mimetype.startsWith('video/')">
                                <div class="w-full h-full flex flex-col items-center justify-center bg-gray-900 text-white">
                                    <svg class="w-8 h-8 mb-1" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M8 5v14l11-7z"/>
                                    </svg>
                                    <span class="text-xs opacity-75" x-text="item.title || 'Vidéo'"></span>
                                </div>
                            </template>
                            {{-- Remove button --}}
                            <button type="button" @click.stop="removeMedia(index)"
                                class="absolute top-1.5 right-1.5 w-6 h-6 bg-red-500 text-white rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity shadow-sm hover:bg-red-600">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                </svg>
                            </button>
                            {{-- Size badge --}}
                            <div class="absolute bottom-1.5 left-1.5 px-1.5 py-0.5 bg-black/60 text-white text-xs rounded" x-show="item.size">
                                <span x-text="item.size > 1048576 ? (item.size / 1048576).toFixed(1) + ' MB' : Math.round(item.size / 1024) + ' KB'"></span>
                            </div>
                            {{-- Hidden input --}}
                            <input type="hidden" name="media[]" :value="JSON.stringify(item)">
                        </div>
                    </template>
                </div>
            </div>

            {{-- Library modal --}}
            <div x-show="showLibrary" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showLibrary = false">
                <div class="bg-white rounded-2xl shadow-xl w-full max-w-3xl max-h-[80vh] flex flex-col mx-4">
                    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
                        <h3 class="text-base font-semibold text-gray-900">Bibliothèque de médias</h3>
                        <button type="button" @click="showLibrary = false" class="text-gray-400 hover:text-gray-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <div class="flex-1 overflow-y-auto p-6">
                        <div x-show="libraryLoading" class="text-center py-8">
                            <svg class="w-8 h-8 text-gray-400 mx-auto animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                        </div>
                        <div x-show="!libraryLoading && libraryItems.length === 0" class="text-center py-8 text-sm text-gray-400">
                            Aucun média dans la bibliothèque.
                        </div>
                        <div x-show="!libraryLoading" class="grid grid-cols-3 sm:grid-cols-4 gap-3">
                            <template x-for="item in libraryItems" :key="item.url">
                                <div @click="selectFromLibrary(item)"
                                     class="relative rounded-xl overflow-hidden border-2 aspect-square cursor-pointer transition-all"
                                     :class="isInMedia(item) ? 'border-indigo-500 ring-2 ring-indigo-200' : 'border-gray-200 hover:border-indigo-300'">
                                    <template x-if="item.is_image">
                                        <img :src="item.url" class="w-full h-full object-cover">
                                    </template>
                                    <template x-if="item.is_video">
                                        <div class="w-full h-full flex flex-col items-center justify-center bg-gray-900 text-white">
                                            <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                            <span class="text-xs mt-1 opacity-75" x-text="item.filename"></span>
                                        </div>
                                    </template>
                                    <div x-show="isInMedia(item)" class="absolute top-1.5 right-1.5 w-5 h-5 bg-indigo-500 text-white rounded-full flex items-center justify-center">
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
                        <button type="button" @click="showLibrary = false" class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors">
                            Fermer
                        </button>
                    </div>
                </div>
            </div>

            {{-- Link URL --}}
            <div class="mt-6">
                <label for="link_url" class="block text-sm font-medium text-gray-700 mb-2">
                    URL du lien
                </label>
                <input
                    type="url"
                    id="link_url"
                    name="link_url"
                    value="{{ old('link_url') }}"
                    class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm placeholder-gray-400 transition-colors"
                    placeholder="https://exemple.com/mon-article"
                >
                @error('link_url')
                    <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Location --}}
            <div class="mt-6" x-data="{
                locationQuery: '',
                locationName: '{{ old('location_name', '') }}',
                locationId: '{{ old('location_id', '') }}',
                locationResults: [],
                locationLoading: false,
                locationOpen: false,
                locationTimeout: null,
                searchLocation() {
                    if (this.locationQuery.length < 2) { this.locationResults = []; this.locationOpen = false; return; }
                    clearTimeout(this.locationTimeout);
                    this.locationTimeout = setTimeout(async () => {
                        this.locationLoading = true;
                        try {
                            let url = '{{ route('locations.search') }}?q=' + encodeURIComponent(this.locationQuery);
                            const resp = await fetch(url, { headers: { 'Accept': 'application/json' } });
                            if (!resp.ok) { this.locationResults = []; this.locationOpen = false; this.locationLoading = false; return; }
                            const data = await resp.json();
                            this.locationResults = Array.isArray(data) ? data : [];
                            this.locationOpen = this.locationResults.length > 0;
                        } catch(e) { this.locationResults = []; }
                        this.locationLoading = false;
                    }, 300);
                },
                selectLocation(place) {
                    this.locationName = place.name;
                    this.locationId = place.id;
                    this.locationQuery = '';
                    this.locationOpen = false;
                    this.locationResults = [];
                },
                clearLocation() {
                    this.locationName = '';
                    this.locationId = '';
                    this.locationQuery = '';
                    this.locationResults = [];
                    this.locationOpen = false;
                }
            }">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Localisation
                </label>

                {{-- Selected location display --}}
                <div x-show="locationName" x-cloak class="flex items-center gap-2 mb-2 px-3 py-2 bg-indigo-50 border border-indigo-200 rounded-xl">
                    <svg class="w-4 h-4 text-indigo-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
                    </svg>
                    <span class="text-sm text-indigo-700 font-medium" x-text="locationName"></span>
                    <button type="button" @click="clearLocation()" class="ml-auto text-indigo-400 hover:text-indigo-600">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                {{-- Search input --}}
                <div x-show="!locationName" class="relative">
                    <input
                        type="text"
                        x-model="locationQuery"
                        @input="searchLocation()"
                        @focus="if (locationResults.length) locationOpen = true"
                        @click.away="locationOpen = false"
                        class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm placeholder-gray-400 transition-colors pl-9"
                        placeholder="Rechercher un lieu..."
                    >
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
                    </svg>
                    <svg x-show="locationLoading" class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>

                    {{-- Dropdown results --}}
                    <div x-show="locationOpen" x-cloak class="absolute z-20 mt-1 w-full bg-white border border-gray-200 rounded-xl shadow-lg max-h-48 overflow-y-auto">
                        <template x-for="place in locationResults" :key="place.id">
                            <button type="button" @click="selectLocation(place)"
                                class="w-full text-left px-4 py-2.5 hover:bg-indigo-50 transition-colors flex items-start gap-2 border-b border-gray-50 last:border-0">
                                <svg class="w-4 h-4 text-gray-400 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
                                </svg>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-gray-900" x-text="place.name"></p>
                                    <p class="text-xs text-gray-400" x-text="[place.city, place.country].filter(Boolean).join(', ')"></p>
                                </div>
                            </button>
                        </template>
                    </div>
                </div>

                <input type="hidden" name="location_name" :value="locationName">
                <input type="hidden" name="location_id" :value="locationId">
                <p class="mt-1.5 text-xs text-gray-400">Facebook, Instagram et Threads uniquement</p>
            </div>
        </div>

        {{-- Section 3: Contenu --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 lg:p-8">
            <h2 class="text-base font-semibold text-gray-900 mb-6">Contenu</h2>

            <div class="space-y-6">
                {{-- Contenu français --}}
                <div>
                    <label for="content_fr" class="block text-sm font-medium text-gray-700 mb-2">
                        Contenu français <span class="text-red-500">*</span>
                    </label>
                    <textarea
                        id="content_fr"
                        name="content_fr"
                        rows="5"
                        required
                        class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm placeholder-gray-400 transition-colors"
                        placeholder="Rédigez votre publication en français..."
                    >{{ old('content_fr') }}</textarea>
                    @error('content_fr')
                        <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Hashtags with tag input --}}
                <div x-data="hashtagInput()" x-init="init()">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Hashtags
                    </label>

                    {{-- Tags display + input --}}
                    <div @click="$refs.input.focus()" class="w-full min-h-[42px] rounded-xl border border-gray-300 shadow-sm focus-within:border-indigo-500 focus-within:ring-1 focus-within:ring-indigo-500 p-2 flex flex-wrap gap-2 items-center cursor-text transition-colors">
                        {{-- Existing tags as badges --}}
                        <template x-for="(tag, index) in tags" :key="index">
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-indigo-100 text-indigo-700 rounded-full text-sm font-medium">
                                <span x-text="'#' + tag"></span>
                                <button type="button" @click.stop="removeTag(index)" class="hover:text-indigo-900 transition-colors">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </span>
                        </template>

                        {{-- Input field --}}
                        <input
                            x-ref="input"
                            type="text"
                            x-model="inputValue"
                            @keydown.enter.prevent="addTag"
                            @keydown.comma.prevent="addTag"
                            @keydown.space="handleSpace"
                            @keydown.backspace="handleBackspace"
                            class="flex-1 min-w-[120px] border-0 focus:ring-0 p-0 text-sm placeholder-gray-400"
                            placeholder="Tapez un hashtag puis Entrée ou ,"
                        >
                    </div>

                    {{-- Hidden input for form submission --}}
                    <input type="hidden" name="hashtags" :value="tags.join(' ')">

                    @error('hashtags')
                        <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                    @enderror

                    {{-- Suggestions --}}
                    <div x-show="suggestions.length > 0" class="mt-3">
                        <p class="text-xs font-medium text-gray-500 mb-2">Hashtags fréquents :</p>
                        <div class="flex flex-wrap gap-2">
                            <template x-for="suggestion in suggestions" :key="suggestion">
                                <button
                                    type="button"
                                    @click="addSuggestion(suggestion)"
                                    class="inline-flex items-center px-3 py-1 bg-gray-100 hover:bg-indigo-100 text-gray-700 hover:text-indigo-700 rounded-full text-sm font-medium transition-colors"
                                    x-text="'#' + suggestion"
                                ></button>
                            </template>
                        </div>
                    </div>
                </div>

                {{-- Traduction automatique --}}
                <div class="flex items-start gap-3">
                    <input
                        type="checkbox"
                        id="auto_translate"
                        name="auto_translate"
                        value="1"
                        {{ old('auto_translate', true) ? 'checked' : '' }}
                        class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 transition-colors"
                    >
                    <div>
                        <label for="auto_translate" class="text-sm text-gray-700 font-medium">
                            Traduction automatique
                        </label>
                        <p class="text-xs text-gray-400 mt-0.5">Traduit le contenu dans les langues configurees sur chaque compte social</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Section 4: Publication --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 lg:p-8">
            <h2 class="text-base font-semibold text-gray-900 mb-6">Publication</h2>

            <div class="space-y-6">
                {{-- Publish mode --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-4">Mode de publication</label>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        {{-- Publier immédiatement --}}
                        <label class="relative flex items-start gap-3 p-4 rounded-xl border-2 cursor-pointer transition-colors"
                               :class="publishMode === 'now' ? 'border-indigo-600 bg-indigo-50/40' : 'border-gray-200 hover:border-gray-300'">
                            <input
                                type="radio"
                                x-model="publishMode"
                                value="now"
                                class="mt-0.5 border-gray-300 text-indigo-600 focus:ring-indigo-500"
                            >
                            <div>
                                <span class="block text-sm font-medium text-gray-900">Publier immédiatement</span>
                                <span class="block text-xs text-gray-500 mt-0.5">Le post sera publié dès sa création</span>
                            </div>
                        </label>

                        {{-- Programmer --}}
                        <label class="relative flex items-start gap-3 p-4 rounded-xl border-2 cursor-pointer transition-colors"
                               :class="publishMode === 'schedule' ? 'border-indigo-600 bg-indigo-50/40' : 'border-gray-200 hover:border-gray-300'">
                            <input
                                type="radio"
                                x-model="publishMode"
                                value="schedule"
                                class="mt-0.5 border-gray-300 text-indigo-600 focus:ring-indigo-500"
                            >
                            <div>
                                <span class="block text-sm font-medium text-gray-900">Programmer</span>
                                <span class="block text-xs text-gray-500 mt-0.5">Choisir la date et l'heure de publication</span>
                            </div>
                        </label>
                    </div>
                </div>

                {{-- Scheduled date --}}
                <div x-show="publishMode === 'schedule'" x-transition x-cloak>
                    <label for="scheduled_at" class="block text-sm font-medium text-gray-700 mb-2">
                        Date et heure de publication
                    </label>
                    <input
                        type="datetime-local"
                        id="scheduled_at"
                        name="scheduled_at"
                        value="{{ old('scheduled_at') }}"
                        class="w-full sm:w-auto rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm transition-colors"
                    >
                    @error('scheduled_at')
                        <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Hidden fields for controller compatibility --}}
                <input type="hidden" name="status" value="scheduled">
                <input type="hidden" name="publish_now" :value="publishMode === 'now' ? '1' : '0'">
            </div>
        </div>

        {{-- Submit --}}
        <div x-show="!isPublishing" class="flex items-center justify-end gap-4">
            <a href="{{ route('posts.index') }}" class="px-4 py-2 text-sm font-medium text-gray-700 hover:text-gray-900 transition-colors">
                Annuler
            </a>
            <button type="submit"
                    class="inline-flex items-center gap-2 px-6 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                <span x-text="publishMode === 'now' ? 'Publier maintenant' : 'Créer le post'"></span>
            </button>
        </div>

        {{-- Publishing progress (inline) --}}
        <div x-show="isPublishing" x-cloak class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 lg:p-8">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-base font-semibold text-gray-900" x-text="publishDone ? 'Publication terminée' : 'Publication en cours...'"></h2>
                <span class="text-sm font-medium text-gray-500" x-text="publishCurrent + '/' + publishTotal"></span>
            </div>

            {{-- Progress bar --}}
            <div class="w-full bg-gray-200 rounded-full h-2.5 mb-6">
                <div class="h-2.5 rounded-full transition-all duration-500"
                     :class="publishDone && publishErrors.length === 0 ? 'bg-green-500' : publishDone && publishErrors.length > 0 ? 'bg-yellow-500' : 'bg-indigo-600'"
                     :style="'width: ' + publishProgressPercent + '%'"></div>
            </div>

            {{-- Platform list --}}
            <div class="space-y-3">
                <template x-for="(pp, index) in publishPlatforms" :key="pp.id">
                    <div class="flex items-center gap-3 px-4 py-3 rounded-xl"
                         :class="{
                             'bg-gray-50': pp.status === 'pending',
                             'bg-indigo-50': pp.status === 'publishing',
                             'bg-green-50': pp.status === 'published',
                             'bg-red-50': pp.status === 'failed',
                         }">
                        {{-- Status icon --}}
                        <div class="flex-shrink-0 w-6 h-6 flex items-center justify-center">
                            {{-- Pending --}}
                            <template x-if="pp.status === 'pending'">
                                <div class="w-4 h-4 rounded-full border-2 border-gray-300"></div>
                            </template>
                            {{-- Publishing --}}
                            <template x-if="pp.status === 'publishing'">
                                <svg class="w-5 h-5 text-indigo-600 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                            </template>
                            {{-- Published --}}
                            <template x-if="pp.status === 'published'">
                                <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                </svg>
                            </template>
                            {{-- Failed --}}
                            <template x-if="pp.status === 'failed'">
                                <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                </svg>
                            </template>
                        </div>

                        {{-- Platform info --}}
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-gray-900" x-text="pp.account_name"></span>
                                <span class="text-xs text-gray-400" x-text="pp.platform_slug"></span>
                            </div>
                            <p x-show="pp.error" class="text-xs text-red-600 mt-0.5" x-text="pp.error"></p>
                        </div>

                        {{-- Status label --}}
                        <span class="text-xs font-medium flex-shrink-0"
                              :class="{
                                  'text-gray-400': pp.status === 'pending',
                                  'text-indigo-600': pp.status === 'publishing',
                                  'text-green-600': pp.status === 'published',
                                  'text-red-600': pp.status === 'failed',
                              }"
                              x-text="{
                                  'pending': 'En attente',
                                  'publishing': 'En cours...',
                                  'published': 'Publié',
                                  'failed': 'Erreur',
                              }[pp.status]"></span>
                    </div>
                </template>
            </div>

            {{-- Summary & actions (after publish complete) --}}
            <div x-show="publishDone" x-cloak class="mt-6 pt-6 border-t border-gray-100">
                <p class="text-sm text-gray-700 mb-4"
                   x-text="publishSuccessCount + '/' + publishTotal + ' publication(s) réussie(s)' + (publishErrors.length > 0 ? ', ' + publishErrors.length + ' erreur(s)' : '')">
                </p>
                <div class="flex items-center gap-3">
                    <a :href="publishShowUrl"
                       class="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                        </svg>
                        Voir le post
                    </a>
                    <a href="{{ route('posts.create') }}"
                       class="inline-flex items-center gap-2 px-5 py-2.5 bg-white text-gray-700 text-sm font-medium rounded-xl hover:bg-gray-50 transition-colors border border-gray-200 shadow-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Nouveau post
                    </a>
                </div>
            </div>
        </div>
    </form>
@endsection

@push('scripts')
<script>
function hashtagInput() {
    return {
        tags: [],
        inputValue: '',
        suggestions: [],

        init() {
            // Load initial tags from old() value
            const oldValue = '{{ old("hashtags") }}';
            if (oldValue) {
                this.tags = oldValue.split(/[\s,]+/).filter(t => t).map(t => t.replace(/^#/, '').toLowerCase());
            }

            // Fetch most used hashtags
            fetch('/api/hashtags')
                .then(res => res.json())
                .then(data => {
                    this.suggestions = data.filter(s => !this.tags.includes(s));
                })
                .catch(err => console.error('Failed to load hashtag suggestions:', err));
        },

        addTag() {
            const value = this.inputValue.trim().replace(/^#/, '').toLowerCase();
            if (value && !this.tags.includes(value)) {
                this.tags.push(value);
                this.suggestions = this.suggestions.filter(s => s !== value);
            }
            this.inputValue = '';
        },

        addSuggestion(tag) {
            if (!this.tags.includes(tag)) {
                this.tags.push(tag);
                this.suggestions = this.suggestions.filter(s => s !== tag);
            }
        },

        removeTag(index) {
            const removed = this.tags.splice(index, 1)[0];
            // Re-add to suggestions if not already there
            if (!this.suggestions.includes(removed)) {
                this.suggestions.unshift(removed);
            }
        },

        handleSpace(event) {
            if (this.inputValue.trim()) {
                event.preventDefault();
                this.addTag();
            }
        },

        handleBackspace() {
            if (this.inputValue === '' && this.tags.length > 0) {
                this.tags.pop();
            }
        }
    }
}
</script>
@endpush
