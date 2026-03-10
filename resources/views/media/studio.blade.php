@extends('layouts.app')

@section('title', 'Media Studio')

@section('content')
    <div x-data="studioApp()" class="space-y-6">

        {{-- Header --}}
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-bold text-gray-900">Media Studio</h1>
                <p class="text-sm text-gray-500 mt-1">Transformez vos fichiers en lot avant de les ajouter à la bibliothèque</p>
            </div>
            <div x-show="results.length > 0" x-cloak class="flex items-center gap-2">
                <span class="text-sm text-gray-500" x-text="results.length + ' fichier(s) traité(s)'"></span>
                <a href="{{ route('media.index') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-indigo-600 bg-indigo-50 rounded-lg hover:bg-indigo-100 transition-colors">
                    Voir dans Médias
                </a>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            {{-- Left: Options --}}
            <div class="lg:col-span-1 space-y-4">

                {{-- Upload zone --}}
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-sm font-semibold text-gray-900">Fichiers source</h2>
                        <span x-show="queue.length > 0" x-cloak class="text-xs text-gray-500" x-text="queue.length + ' fichier(s)'"></span>
                    </div>

                    <div
                        @dragover.prevent="$el.classList.add('border-indigo-400', 'bg-indigo-50/50')"
                        @dragleave.prevent="$el.classList.remove('border-indigo-400', 'bg-indigo-50/50')"
                        @drop.prevent="$el.classList.remove('border-indigo-400', 'bg-indigo-50/50'); handleDrop($event)"
                        @click="$refs.studioFileInput.click()"
                        class="border-2 border-dashed border-gray-300 rounded-xl p-6 text-center cursor-pointer hover:border-gray-400 transition-colors"
                    >
                        <input type="file" x-ref="studioFileInput" @change="handleFileSelect($event)" multiple accept="image/*,video/*" class="hidden">
                        <svg class="w-10 h-10 text-gray-400 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
                        </svg>
                        <p class="text-sm text-gray-600">Glissez vos fichiers ou <span class="text-indigo-600 font-medium">cliquez</span></p>
                        <p class="text-xs text-gray-400 mt-1">Images et vidéos — sélection multiple</p>
                    </div>

                    {{-- File list --}}
                    <div x-show="queue.length > 0" x-cloak class="mt-4 space-y-2 max-h-60 overflow-y-auto">
                        <template x-for="(item, index) in queue" :key="index">
                            <div class="flex items-center gap-3 px-3 py-2 rounded-lg"
                                 :class="item.status === 'done' ? 'bg-green-50' : item.status === 'error' ? 'bg-red-50' : item.status === 'processing' ? 'bg-indigo-50' : 'bg-gray-50'">
                                <div class="flex-shrink-0">
                                    <template x-if="item.status === 'done'">
                                        <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                    </template>
                                    <template x-if="item.status === 'error'">
                                        <svg class="w-4 h-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
                                    </template>
                                    <template x-if="item.status === 'processing'">
                                        <svg class="w-4 h-4 text-indigo-500 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                    </template>
                                    <template x-if="item.status === 'pending'">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                                    </template>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs font-medium text-gray-900 truncate" x-text="item.file.name"></p>
                                    <p class="text-xs text-gray-500" x-text="formatSize(item.file.size)"></p>
                                </div>
                                <button x-show="item.status === 'pending'" type="button" @click="removeFromQueue(index)" class="text-gray-400 hover:text-red-500 flex-shrink-0">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                </button>
                            </div>
                        </template>
                    </div>

                    <button x-show="queue.length > 0 && !processing" x-cloak type="button" @click="clearQueue()"
                        class="mt-2 text-xs text-red-500 hover:text-red-700">Vider la liste</button>
                </div>

                {{-- Options (detect type from queue) --}}
                <div x-show="queue.length > 0" x-cloak class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <h2 class="text-sm font-semibold text-gray-900 mb-4">Options de traitement</h2>
                    <div class="space-y-4">

                        {{-- Format (videos) --}}
                        <div x-show="hasVideos">
                            <label class="block text-xs font-medium text-gray-700 mb-2">Format vidéo</label>
                            <div class="grid grid-cols-3 gap-2">
                                <button type="button" @click="options.format = 'vertical'"
                                    :class="options.format === 'vertical' ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-gray-200 text-gray-600 hover:border-gray-300'"
                                    class="border-2 rounded-lg p-2 text-center text-xs font-medium transition-colors">
                                    <div class="w-5 h-8 bg-current opacity-20 rounded mx-auto mb-1"></div>
                                    9:16
                                </button>
                                <button type="button" @click="options.format = 'square'"
                                    :class="options.format === 'square' ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-gray-200 text-gray-600 hover:border-gray-300'"
                                    class="border-2 rounded-lg p-2 text-center text-xs font-medium transition-colors">
                                    <div class="w-6 h-6 bg-current opacity-20 rounded mx-auto mb-1"></div>
                                    1:1
                                </button>
                                <button type="button" @click="options.format = 'original'"
                                    :class="options.format === 'original' ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-gray-200 text-gray-600 hover:border-gray-300'"
                                    class="border-2 rounded-lg p-2 text-center text-xs font-medium transition-colors">
                                    <div class="w-8 h-5 bg-current opacity-20 rounded mx-auto mb-1"></div>
                                    Original
                                </button>
                            </div>
                        </div>

                        {{-- Logo overlay (videos) --}}
                        <label x-show="hasVideos" class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox" x-model="options.logo_enabled" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            <span class="text-sm text-gray-700">Logo en superposition</span>
                        </label>

                        {{-- Text overlay (videos) --}}
                        <div x-show="hasVideos">
                            <label class="flex items-center gap-3 cursor-pointer mb-2">
                                <input type="checkbox" x-model="options.text_enabled" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                <span class="text-sm text-gray-700">Texte en superposition</span>
                            </label>
                            <input x-show="options.text_enabled" x-cloak type="text" x-model="options.text_content"
                                placeholder="Ex: @moncompte" maxlength="100"
                                class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>

                        {{-- Strip metadata (videos) --}}
                        <label x-show="hasVideos" class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox" x-model="options.strip_metadata" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            <span class="text-sm text-gray-700">Supprimer les métadonnées vidéo</span>
                        </label>

                        <div x-show="hasVideos && hasPhotos" class="border-t border-gray-100"></div>

                        {{-- Strip EXIF (photos) --}}
                        <label x-show="hasPhotos" class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox" x-model="options.strip_exif" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            <span class="text-sm text-gray-700">Supprimer les données EXIF</span>
                        </label>

                        {{-- Watermark (photos) --}}
                        <div x-show="hasPhotos">
                            <label class="flex items-center gap-3 cursor-pointer mb-2">
                                <input type="checkbox" x-model="options.watermark_enabled" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                <span class="text-sm text-gray-700">Filigrane texte</span>
                            </label>
                            <input x-show="options.watermark_enabled" x-cloak type="text" x-model="options.watermark_text"
                                placeholder="Ex: @moncompte" maxlength="100"
                                class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                    </div>
                </div>

                {{-- Destination folder --}}
                <div x-show="queue.length > 0" x-cloak class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <h2 class="text-sm font-semibold text-gray-900 mb-3">Dossier de destination</h2>
                    <select x-model="options.folder_id" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Sans dossier</option>
                        @foreach($folders as $folder)
                            <option value="{{ $folder->id }}">{{ $folder->name }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Process button --}}
                <div x-show="queue.length > 0" x-cloak>
                    <button type="button" @click="processAll()"
                        :disabled="processing || pendingCount === 0"
                        class="w-full flex items-center justify-center gap-2 px-4 py-3 rounded-xl text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                        <template x-if="!processing">
                            <span class="flex items-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 0 0-5.78 1.128 2.25 2.25 0 0 1-2.4 2.245 4.5 4.5 0 0 0 8.4-2.245c0-.399-.078-.78-.22-1.128Zm0 0a15.998 15.998 0 0 0 3.388-1.62m-5.043-.025a15.994 15.994 0 0 1 1.622-3.395m3.42 3.42a15.995 15.995 0 0 0 4.764-4.648l3.876-5.814a1.151 1.151 0 0 0-1.597-1.597L14.146 6.32a15.996 15.996 0 0 0-4.649 4.763m3.42 3.42a6.776 6.776 0 0 0-3.42-3.42" />
                                </svg>
                                <span x-text="'Traiter ' + pendingCount + ' fichier(s)'"></span>
                            </span>
                        </template>
                        <template x-if="processing">
                            <span class="flex items-center gap-2">
                                <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                                <span x-text="processingPhase"></span>
                            </span>
                        </template>
                    </button>
                </div>
            </div>

            {{-- Right: Progress & Results --}}
            <div class="lg:col-span-2 space-y-4">

                {{-- Global progress --}}
                <div x-show="processing" x-cloak class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center gap-3">
                            <svg class="w-5 h-5 text-indigo-500 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <p class="text-sm font-medium text-gray-900" x-text="processingPhase"></p>
                        </div>
                        <span class="text-xs text-gray-500" x-text="processedCount + ' / ' + totalCount"></span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div class="h-2 rounded-full bg-indigo-600 transition-all" :style="'width: ' + Math.round(processedCount / totalCount * 100) + '%'"></div>
                    </div>
                </div>

                {{-- Results grid --}}
                <div x-show="results.length > 0" x-cloak class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-2">
                            <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                            <h2 class="text-sm font-semibold text-gray-900" x-text="results.length + ' fichier(s) ajouté(s) aux médias'"></h2>
                        </div>
                        <button @click="clearAll()" class="text-xs text-indigo-600 hover:text-indigo-700 font-medium">
                            Nouveau lot
                        </button>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                        <template x-for="(res, i) in results" :key="i">
                            <div class="relative group rounded-xl overflow-hidden border border-gray-200 aspect-square bg-gray-100">
                                <template x-if="res.mimetype && res.mimetype.startsWith('image/')">
                                    <img :src="res.url" class="w-full h-full object-cover">
                                </template>
                                <template x-if="res.mimetype && res.mimetype.startsWith('video/')">
                                    <div class="w-full h-full relative bg-gray-900 flex items-center justify-center">
                                        <svg class="w-8 h-8 text-white/70" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                    </div>
                                </template>
                                <div class="absolute bottom-0 inset-x-0 bg-gradient-to-t from-black/60 to-transparent p-2">
                                    <p class="text-xs text-white truncate" x-text="res.original_name || res.filename"></p>
                                    <p class="text-xs text-white/70" x-text="formatSize(res.size)"></p>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Errors --}}
                <div x-show="errors.length > 0" x-cloak class="bg-white rounded-2xl shadow-sm border border-red-100 p-6">
                    <div class="flex items-center gap-2 mb-3">
                        <svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                        </svg>
                        <h2 class="text-sm font-semibold text-red-900" x-text="errors.length + ' erreur(s)'"></h2>
                    </div>
                    <div class="space-y-1">
                        <template x-for="(err, i) in errors" :key="i">
                            <p class="text-xs text-red-700"><span class="font-medium" x-text="err.name + ' : '"></span><span x-text="err.message"></span></p>
                        </template>
                    </div>
                </div>

                {{-- Empty state --}}
                <div x-show="queue.length === 0 && results.length === 0" class="bg-white rounded-2xl shadow-sm border border-gray-100 p-12">
                    <div class="text-center">
                        <div class="w-16 h-16 rounded-2xl bg-gray-50 flex items-center justify-center mx-auto mb-4">
                            <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 0 0-5.78 1.128 2.25 2.25 0 0 1-2.4 2.245 4.5 4.5 0 0 0 8.4-2.245c0-.399-.078-.78-.22-1.128Zm0 0a15.998 15.998 0 0 0 3.388-1.62m-5.043-.025a15.994 15.994 0 0 1 1.622-3.395m3.42 3.42a15.995 15.995 0 0 0 4.764-4.648l3.876-5.814a1.151 1.151 0 0 0-1.597-1.597L14.146 6.32a15.996 15.996 0 0 0-4.649 4.763m3.42 3.42a6.776 6.776 0 0 0-3.42-3.42" />
                            </svg>
                        </div>
                        <h3 class="text-base font-medium text-gray-900 mb-2">Media Studio</h3>
                        <p class="text-sm text-gray-500 max-w-sm mx-auto">
                            Importez vos vidéos et photos en lot pour les transformer :
                            recadrage 9:16, logo, texte, suppression EXIF, filigrane...
                            Les résultats seront ajoutés directement dans votre bibliothèque.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
function studioApp() {
    return {
        queue: [],       // [{file, status: 'pending'|'processing'|'done'|'error'}]
        results: [],     // successful results
        errors: [],      // {name, message}
        processing: false,
        processingPhase: '',
        processedCount: 0,
        totalCount: 0,
        options: {
            format: 'vertical',
            logo_enabled: false,
            text_enabled: false,
            text_content: '',
            strip_metadata: true,
            strip_exif: true,
            watermark_enabled: false,
            watermark_text: '',
            folder_id: '',
        },

        get hasVideos() {
            return this.queue.some(q => q.file.type.startsWith('video/'));
        },
        get hasPhotos() {
            return this.queue.some(q => q.file.type.startsWith('image/'));
        },
        get pendingCount() {
            return this.queue.filter(q => q.status === 'pending').length;
        },

        handleFileSelect(e) {
            const files = Array.from(e.target.files);
            e.target.value = '';
            this.addFiles(files);
        },

        handleDrop(e) {
            const files = Array.from(e.dataTransfer.files).filter(f =>
                f.type.startsWith('video/') || f.type.startsWith('image/')
            );
            this.addFiles(files);
        },

        addFiles(files) {
            for (const file of files) {
                this.queue.push({ file, status: 'pending' });
            }
        },

        removeFromQueue(index) {
            this.queue.splice(index, 1);
        },

        clearQueue() {
            this.queue = this.queue.filter(q => q.status !== 'pending');
        },

        clearAll() {
            this.queue = [];
            this.results = [];
            this.errors = [];
        },

        formatSize(bytes) {
            if (!bytes) return '';
            if (bytes > 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
            return Math.round(bytes / 1024) + ' KB';
        },

        async processAll() {
            if (this.processing) return;

            const pending = this.queue.filter(q => q.status === 'pending');
            if (pending.length === 0) return;

            this.processing = true;
            this.processedCount = 0;
            this.totalCount = pending.length;
            this.results = [];
            this.errors = [];

            for (const item of pending) {
                const isVideo = item.file.type.startsWith('video/');
                item.status = 'processing';
                this.processingPhase = (isVideo ? 'Encodage' : 'Traitement') + ' : ' + item.file.name + '...';

                try {
                    const formData = new FormData();
                    formData.append('file', item.file);

                    if (isVideo) {
                        formData.append('format', this.options.format);
                        formData.append('logo_enabled', this.options.logo_enabled ? '1' : '0');
                        formData.append('text_enabled', this.options.text_enabled ? '1' : '0');
                        formData.append('text_content', this.options.text_content);
                        formData.append('strip_metadata', this.options.strip_metadata ? '1' : '0');
                    } else {
                        formData.append('strip_exif', this.options.strip_exif ? '1' : '0');
                        formData.append('watermark_enabled', this.options.watermark_enabled ? '1' : '0');
                        formData.append('watermark_text', this.options.watermark_text);
                    }

                    if (this.options.folder_id) {
                        formData.append('folder_id', this.options.folder_id);
                    }

                    const response = await fetch('{{ route("media.studio.process") }}', {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                            'Accept': 'application/json',
                        },
                        body: formData,
                    });

                    const data = await response.json();

                    if (!response.ok) {
                        item.status = 'error';
                        this.errors.push({ name: item.file.name, message: data.error || data.message || 'Erreur' });
                    } else {
                        item.status = 'done';
                        data.original_name = item.file.name;
                        this.results.push(data);
                    }
                } catch (error) {
                    item.status = 'error';
                    this.errors.push({ name: item.file.name, message: error.message });
                }

                this.processedCount++;
            }

            this.processing = false;
            this.processingPhase = '';
        },
    };
}
</script>
@endpush
