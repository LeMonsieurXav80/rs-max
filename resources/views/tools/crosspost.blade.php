@extends('layouts.app')
@section('title', 'Cross-post Instagram → Bluesky')

@section('content')
<div class="max-w-5xl mx-auto" x-data="crossPostApp()">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Cross-post Instagram → Bluesky</h1>
            <p class="text-sm text-gray-500 mt-1">Outil temporaire - publie les posts Instagram sur Bluesky (du plus ancien au plus récent)</p>
        </div>
    </div>

    {{-- Account selector --}}
    <div class="bg-white rounded-xl border border-gray-200 p-5 mb-6">
        <label class="block text-sm font-medium text-gray-700 mb-3">Paire de comptes</label>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            @foreach($pairs as $i => $pair)
                <button
                    @click="selectPair({{ $pair['instagram']->id }}, {{ $pair['bluesky']->id }}, '{{ addslashes($pair['instagram']->name) }}', '{{ addslashes($pair['bluesky']->name) }}')"
                    class="flex items-center gap-3 p-4 rounded-xl border-2 transition-colors text-left"
                    :class="instagramId === {{ $pair['instagram']->id }} ? 'border-indigo-500 bg-indigo-50' : 'border-gray-200 hover:border-gray-300'"
                >
                    <div class="flex-1">
                        <p class="text-sm font-medium text-gray-900">{{ $pair['instagram']->name }}</p>
                        <p class="text-xs text-gray-500">Instagram → {{ $pair['bluesky']->name }} (Bluesky)</p>
                    </div>
                </button>
            @endforeach
        </div>
    </div>

    {{-- Fetch button --}}
    <div x-show="instagramId && !posts.length && !fetching" class="mb-6">
        <button
            @click="fetchPosts"
            class="flex items-center gap-2 px-5 py-3 bg-indigo-600 text-white rounded-xl font-medium hover:bg-indigo-700 transition-colors"
        >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
            </svg>
            Charger les posts Instagram de <span x-text="igName" class="font-bold"></span>
        </button>
    </div>

    {{-- Fetching spinner --}}
    <div x-show="fetching" class="bg-white rounded-xl border border-gray-200 p-8 text-center mb-6">
        <svg class="w-10 h-10 mx-auto text-indigo-400 animate-spin mb-3" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
        <p class="text-sm text-gray-600">Chargement des posts Instagram...</p>
    </div>

    {{-- Posts loaded - controls --}}
    <div x-show="posts.length > 0" class="space-y-4">
        {{-- Summary --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <div class="flex items-center justify-between flex-wrap gap-3">
                <div>
                    <p class="text-sm text-gray-700">
                        <span class="font-bold text-lg" x-text="posts.length"></span> posts trouvés
                        <span class="text-gray-400 mx-2">|</span>
                        <span class="text-indigo-600 font-medium" x-text="selectedCount"></span> sélectionnés
                        <span class="text-gray-400 mx-2">|</span>
                        <span class="text-green-600 font-medium" x-text="doneCount"></span> publiés
                        <span class="text-gray-400 mx-2">|</span>
                        <span class="text-red-600 font-medium" x-text="errorCount"></span> erreurs
                        <span class="text-gray-400 mx-2">|</span>
                        <span class="text-yellow-600 font-medium" x-text="skippedCount"></span> ignorés
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        x-show="!running"
                        @click="selectAll"
                        class="px-3 py-1.5 text-xs font-medium text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors"
                    >Tout cocher</button>
                    <button
                        x-show="!running"
                        @click="deselectAll"
                        class="px-3 py-1.5 text-xs font-medium text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors"
                    >Tout décocher</button>
                    <button
                        x-show="!running && selectedCount > 0"
                        @click="startCrossPost"
                        class="flex items-center gap-2 px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 transition-colors"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z" />
                        </svg>
                        Lancer (<span x-text="selectedCount"></span> posts)
                    </button>
                    <button
                        x-show="running"
                        @click="stopCrossPost"
                        class="flex items-center gap-2 px-4 py-2 bg-red-600 text-white rounded-lg text-sm font-medium hover:bg-red-700 transition-colors"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 7.5A2.25 2.25 0 0 1 7.5 5.25h9a2.25 2.25 0 0 1 2.25 2.25v9a2.25 2.25 0 0 1-2.25 2.25h-9a2.25 2.25 0 0 1-2.25-2.25v-9Z" />
                        </svg>
                        Arrêter
                    </button>
                </div>
            </div>

            {{-- Progress bar --}}
            <div class="mt-3 bg-gray-200 rounded-full h-2.5 overflow-hidden">
                <div class="h-full bg-indigo-600 transition-all duration-300 rounded-full"
                     :style="'width: ' + progressPercent + '%'"></div>
            </div>
            <p class="text-xs text-gray-400 mt-1" x-text="progressPercent.toFixed(0) + '%'"></p>
        </div>

        {{-- Current post being processed --}}
        <div x-show="currentPost" class="bg-amber-50 border border-amber-200 rounded-xl p-4">
            <div class="flex items-center gap-2 mb-2">
                <svg class="w-4 h-4 text-amber-500 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <span class="text-sm font-medium text-amber-700">Publication en cours...</span>
            </div>
            <p class="text-xs text-amber-600 truncate" x-text="currentPost?.caption?.substring(0, 120) + '...'"></p>
        </div>

        {{-- Waiting countdown --}}
        <div x-show="waitingUntil && !currentPost" class="bg-blue-50 border border-blue-200 rounded-xl p-4">
            <div class="flex items-center gap-2">
                <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
                <span class="text-sm font-medium text-blue-700">Prochain post dans <span x-text="countdownText" class="font-bold"></span></span>
            </div>
        </div>

        {{-- Post list --}}
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="max-h-[500px] overflow-y-auto divide-y divide-gray-100">
                <template x-for="(post, index) in posts" :key="post.id">
                    <div class="flex items-center gap-3 px-4 py-3 text-sm"
                         :class="{
                             'bg-green-50': post.status === 'done',
                             'bg-red-50': post.status === 'error',
                             'bg-yellow-50': post.status === 'skipped',
                             'bg-amber-50': post.status === 'processing',
                         }">
                        {{-- Checkbox / Status icon --}}
                        <div class="flex-shrink-0 w-6">
                            <template x-if="!post.status || post.status === 'retry'">
                                <input type="checkbox" x-model="post.selected"
                                    class="w-4 h-4 text-indigo-600 rounded border-gray-300 focus:ring-indigo-500 cursor-pointer"
                                    :disabled="running">
                            </template>
                            <template x-if="post.status === 'processing'">
                                <svg class="w-4 h-4 text-amber-500 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                            </template>
                            <template x-if="post.status === 'done'">
                                <button @click="if (!running) { post.status = 'retry'; post.selected = true; post.error = null; }"
                                    class="cursor-pointer hover:opacity-60 transition-opacity" :class="{ 'pointer-events-none': running }"
                                    title="Cliquer pour re-publier">
                                    <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                    </svg>
                                </button>
                            </template>
                            <template x-if="post.status === 'error'">
                                <button @click="if (!running) { post.status = 'retry'; post.selected = true; post.error = null; }"
                                    class="cursor-pointer hover:opacity-60 transition-opacity" :class="{ 'pointer-events-none': running }"
                                    title="Cliquer pour réessayer">
                                    <svg class="w-4 h-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </template>
                            <template x-if="post.status === 'skipped'">
                                <button @click="if (!running) { post.status = 'retry'; post.selected = true; post.error = null; }"
                                    class="cursor-pointer hover:opacity-60 transition-opacity" :class="{ 'pointer-events-none': running }"
                                    title="Cliquer pour réessayer">
                                    <svg class="w-4 h-4 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 8.689c0-.864.933-1.406 1.683-.977l7.108 4.061a1.125 1.125 0 0 1 0 1.954l-7.108 4.061A1.125 1.125 0 0 1 3 16.811V8.69ZM12.75 8.689c0-.864.933-1.406 1.683-.977l7.108 4.061a1.125 1.125 0 0 1 0 1.954l-7.108 4.061a1.125 1.125 0 0 1-1.683-.977V8.69Z" />
                                    </svg>
                                </button>
                            </template>
                        </div>

                        {{-- Media type badge --}}
                        <div class="flex-shrink-0">
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium"
                                :class="{
                                    'bg-blue-100 text-blue-700': post.media_type === 'IMAGE',
                                    'bg-purple-100 text-purple-700': post.media_type === 'CAROUSEL_ALBUM',
                                    'bg-pink-100 text-pink-700': post.media_type === 'VIDEO',
                                }"
                                x-text="post.media_type === 'CAROUSEL_ALBUM' ? 'CAROUSEL' : post.media_type"></span>
                            <span class="text-xs text-gray-400 ml-1" x-text="post.media.length + ' media'"></span>
                        </div>

                        {{-- Date --}}
                        <span class="flex-shrink-0 text-xs text-gray-400 w-20" x-text="new Date(post.timestamp).toLocaleDateString('fr-FR')"></span>

                        {{-- Caption --}}
                        <span class="flex-1 text-gray-600 truncate" x-text="post.caption?.substring(0, 80) || '(sans texte)'"></span>

                        {{-- Error message --}}
                        <span x-show="post.error" class="text-xs text-red-500 max-w-48 truncate" x-text="post.error"></span>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>

<script>
function crossPostApp() {
    return {
        instagramId: null,
        blueskyId: null,
        igName: '',
        bsName: '',
        fetching: false,
        posts: [],
        running: false,
        stopped: false,
        currentPost: null,
        waitingUntil: null,
        countdownText: '',
        countdownInterval: null,

        get selectedCount() { return this.posts.filter(p => p.selected).length; },
        get doneCount() { return this.posts.filter(p => p.status === 'done').length; },
        get errorCount() { return this.posts.filter(p => p.status === 'error').length; },
        get skippedCount() { return this.posts.filter(p => p.status === 'skipped').length; },
        get processedCount() { return this.doneCount + this.errorCount + this.skippedCount; },
        get progressPercent() {
            const selected = this.selectedCount;
            if (!selected) return 0;
            const processed = this.posts.filter(p => p.selected && (p.status === 'done' || p.status === 'error' || p.status === 'skipped')).length;
            return processed / selected * 100;
        },

        selectAll() { this.posts.forEach(p => { if (!p.status || p.status === 'retry' || p.status === 'error') p.selected = true; }); },
        deselectAll() { this.posts.forEach(p => { if (!p.status || p.status === 'retry' || p.status === 'error') p.selected = false; }); },

        init() {
            this.countdownInterval = setInterval(() => {
                if (this.waitingUntil) {
                    const diff = this.waitingUntil - Date.now();
                    if (diff <= 0) { this.countdownText = ''; return; }
                    const m = Math.floor(diff / 60000);
                    const s = Math.floor((diff % 60000) / 1000);
                    this.countdownText = `${m}m ${s.toString().padStart(2, '0')}s`;
                } else {
                    this.countdownText = '';
                }
            }, 1000);
        },

        selectPair(igId, bsId, igName, bsName) {
            this.instagramId = igId;
            this.blueskyId = bsId;
            this.igName = igName;
            this.bsName = bsName;
            this.posts = [];
            this.running = false;
            this.stopped = false;
        },

        async fetchPosts() {
            this.fetching = true;
            try {
                const res = await fetch('{{ route("crosspost.fetch") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ instagram_id: this.instagramId }),
                });
                const data = await res.json();
                if (data.success) {
                    const doneIds = data.done_ids || [];
                    this.posts = data.posts.map(p => ({
                        ...p,
                        selected: !doneIds.includes(p.id),
                        status: doneIds.includes(p.id) ? 'done' : null,
                        error: null,
                    }));
                } else {
                    alert(data.error || 'Erreur');
                }
            } catch (e) {
                alert('Erreur: ' + e.message);
            } finally {
                this.fetching = false;
            }
        },

        async startCrossPost() {
            this.running = true;
            this.stopped = false;

            for (let i = 0; i < this.posts.length; i++) {
                if (this.stopped) break;
                if (this.posts[i].status === 'done' || this.posts[i].status === 'skipped') continue;
                if (!this.posts[i].selected) continue;

                const post = this.posts[i];

                // Skip posts with no media and no caption
                if (!post.caption && post.media.length === 0) {
                    this.posts[i].status = 'skipped';
                    this.posts[i].error = 'Pas de contenu';
                    continue;
                }

                this.posts[i].status = 'processing';
                this.currentPost = post;

                try {
                    const controller = new AbortController();
                    const timeout = setTimeout(() => controller.abort(), 10 * 60 * 1000); // 10 min

                    const res = await fetch('{{ route("crosspost.post") }}', {
                        method: 'POST',
                        signal: controller.signal,
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({
                            instagram_id: this.instagramId,
                            post_id: post.id,
                            caption: post.caption,
                            media: post.media,
                        }),
                    });
                    clearTimeout(timeout);

                    if (!res.ok) {
                        this.posts[i].status = 'error';
                        this.posts[i].error = `HTTP ${res.status} — ${res.statusText}`;
                    } else {
                        const data = await res.json();
                        if (data.success) {
                            this.posts[i].status = 'done';
                        } else {
                            this.posts[i].status = 'error';
                            this.posts[i].error = data.error || 'Erreur inconnue';
                        }
                    }
                } catch (e) {
                    this.posts[i].status = 'error';
                    this.posts[i].error = e.name === 'AbortError' ? 'Timeout (10 min)' : e.message;
                }

                this.currentPost = null;

                // Pause 1h15 between posts to space out publications
                if (!this.stopped && i < this.posts.length - 1) {
                    this.waitingUntil = new Date(Date.now() + 75 * 60 * 1000);
                    await new Promise(r => setTimeout(r, 75 * 60 * 1000));
                    this.waitingUntil = null;
                }
            }

            this.running = false;
        },

        stopCrossPost() {
            this.stopped = true;
            this.running = false;
            this.currentPost = null;
            this.waitingUntil = null;
        },
    };
}
</script>
@endsection
