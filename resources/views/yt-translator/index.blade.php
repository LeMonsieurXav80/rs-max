@extends('layouts.app')
@section('title', 'YT Traducteur')

@section('content')
<div class="max-w-7xl mx-auto" x-data="ytTranslator()">

    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">YT Traducteur</h1>
            <p class="text-sm text-gray-500 mt-1">Traduisez titres, descriptions et sous-titres de vos videos YouTube</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">

        {{-- Left column: Account + Language groups + Options --}}
        <div class="lg:col-span-1 space-y-4">

            {{-- Account selector --}}
            <div class="bg-white rounded-xl border border-gray-200 p-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Chaine YouTube</label>
                @if($accounts->isEmpty())
                    <p class="text-sm text-gray-400">Aucune chaine YouTube connectee.</p>
                    <a href="{{ route('platforms.youtube') }}" class="text-sm text-indigo-600 hover:underline mt-1 inline-block">Connecter une chaine</a>
                @else
                    <select x-model="accountId" @change="loadVideos()" class="w-full rounded-lg border-gray-300 text-sm">
                        <option value="">-- Selectionnez --</option>
                        @foreach($accounts as $account)
                            <option value="{{ $account->id }}">{{ $account->name }}</option>
                        @endforeach
                    </select>
                @endif
            </div>

            {{-- Source language --}}
            <div class="bg-white rounded-xl border border-gray-200 p-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Langue source</label>
                <select x-model="sourceLanguage" class="w-full rounded-lg border-gray-300 text-sm">
                    @foreach($availableLanguages as $code)
                        <option value="{{ $code }}" {{ $code === 'en' ? 'selected' : '' }}>{{ $languageNames[$code] ?? $code }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Translation types --}}
            <div class="bg-white rounded-xl border border-gray-200 p-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Traduire</label>
                <div class="space-y-2">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" x-model="translateTitle" class="rounded border-gray-300 text-indigo-600">
                        <span>Titre</span>
                        <span class="text-gray-400 text-xs">(faible cout)</span>
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" x-model="translateDescription" class="rounded border-gray-300 text-indigo-600">
                        <span>Description</span>
                        <span class="text-gray-400 text-xs">(faible cout)</span>
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" x-model="translateSubtitles" class="rounded border-gray-300 text-indigo-600">
                        <span>Sous-titres</span>
                        <span class="text-red-400 text-xs font-medium">(400 unites/langue)</span>
                    </label>
                </div>
            </div>

            {{-- Target languages --}}
            <div class="bg-white rounded-xl border border-gray-200 p-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Langues cibles</label>

                {{-- Language group quick select --}}
                @if($languageGroups->isNotEmpty())
                <div class="mb-3 space-y-1">
                    <p class="text-xs text-gray-400 mb-1">Groupes rapides :</p>
                    @foreach($languageGroups as $group)
                        <button
                            @click="selectLanguageGroup({{ json_encode($group->languages) }})"
                            class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-xs font-medium bg-indigo-50 text-indigo-600 hover:bg-indigo-100 transition-colors"
                        >
                            {{ $group->name }}
                            <span class="text-indigo-400">({{ count($group->languages) }})</span>
                        </button>
                    @endforeach
                </div>
                @endif

                <div class="grid grid-cols-2 gap-1.5 max-h-64 overflow-y-auto">
                    @foreach($availableLanguages as $code)
                        <label class="flex items-center gap-1.5 text-xs cursor-pointer py-0.5">
                            <input type="checkbox" :value="'{{ $code }}'" x-model="targetLanguages" class="rounded border-gray-300 text-indigo-600 w-3.5 h-3.5">
                            <span>{{ $languageNames[$code] ?? $code }}</span>
                        </label>
                    @endforeach
                </div>

                <div class="flex gap-2 mt-2">
                    <button @click="targetLanguages = []" class="text-xs text-gray-400 hover:text-gray-600">Aucune</button>
                    <button @click="targetLanguages = {{ json_encode($availableLanguages) }}" class="text-xs text-gray-400 hover:text-gray-600">Toutes</button>
                </div>
            </div>

            {{-- Language groups management --}}
            <div class="bg-white rounded-xl border border-gray-200 p-4">
                <div class="flex items-center justify-between mb-2">
                    <label class="text-sm font-medium text-gray-700">Groupes de langues</label>
                    <button @click="showGroupModal = true" class="text-xs text-indigo-600 hover:text-indigo-800">+ Creer</button>
                </div>
                <div class="space-y-2">
                    @forelse($languageGroups as $group)
                        <div class="flex items-center justify-between py-1 text-sm">
                            <span class="font-medium text-gray-800">{{ $group->name }}</span>
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-gray-400">{{ count($group->languages) }} langues</span>
                                <button @click="deleteLanguageGroup({{ $group->id }})" class="text-xs text-red-400 hover:text-red-600">&times;</button>
                            </div>
                        </div>
                    @empty
                        <p class="text-xs text-gray-400">Aucun groupe. Creez-en un pour aller plus vite.</p>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Right column: Video list --}}
        <div class="lg:col-span-3">

            {{-- Loading state --}}
            <template x-if="loading">
                <div class="bg-white rounded-xl border border-gray-200 p-12 text-center">
                    <div class="animate-spin w-8 h-8 border-2 border-indigo-600 border-t-transparent rounded-full mx-auto mb-3"></div>
                    <p class="text-sm text-gray-500">Chargement des videos...</p>
                </div>
            </template>

            {{-- Empty state --}}
            <template x-if="!loading && !accountId">
                <div class="bg-white rounded-xl border border-gray-200 p-12 text-center">
                    <svg class="w-12 h-12 text-gray-300 mx-auto mb-3" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
                    </svg>
                    <p class="text-sm text-gray-500">Selectionnez une chaine YouTube pour commencer</p>
                </div>
            </template>

            {{-- Videos list --}}
            <template x-if="!loading && videos.length > 0">
                <div>
                    {{-- Batch actions --}}
                    <div class="bg-white rounded-xl border border-gray-200 p-4 mb-4 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" @change="toggleAllVideos($event)" :checked="selectedVideos.length === videos.length && videos.length > 0" class="rounded border-gray-300 text-indigo-600">
                                <span class="text-gray-600" x-text="selectedVideos.length + ' / ' + videos.length + ' video(s)'"></span>
                            </label>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-xs text-gray-400" x-show="targetLanguages.length > 0" x-text="targetLanguages.length + ' langue(s) cible(s)'"></span>
                            <button
                                @click="launchTranslation()"
                                :disabled="selectedVideos.length === 0 || targetLanguages.length === 0 || (!translateTitle && !translateDescription && !translateSubtitles) || translating"
                                class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors flex items-center gap-2"
                            >
                                <template x-if="translating">
                                    <div class="animate-spin w-4 h-4 border-2 border-white border-t-transparent rounded-full"></div>
                                </template>
                                <span x-text="translating ? 'Lancement...' : 'Traduire'"></span>
                            </button>
                        </div>
                    </div>

                    {{-- Video cards --}}
                    <div class="space-y-3">
                        <template x-for="video in videos" :key="video.id">
                            <div class="bg-white rounded-xl border border-gray-200 p-4 hover:border-gray-300 transition-colors">
                                <div class="flex gap-4">
                                    {{-- Checkbox --}}
                                    <div class="flex items-start pt-1">
                                        <input type="checkbox" :value="video.id" x-model="selectedVideos" class="rounded border-gray-300 text-indigo-600">
                                    </div>

                                    {{-- Thumbnail --}}
                                    <div class="flex-shrink-0">
                                        <img :src="video.thumbnail" class="w-40 h-24 object-cover rounded-lg" :alt="video.title">
                                    </div>

                                    {{-- Info --}}
                                    <div class="flex-1 min-w-0">
                                        <h3 class="text-sm font-medium text-gray-900 truncate" x-text="video.title"></h3>
                                        <div class="flex items-center gap-3 mt-1 text-xs text-gray-500">
                                            <span x-text="formatDate(video.published_at)"></span>
                                            <span x-text="formatViews(video.view_count) + ' vues'"></span>
                                            <span x-text="formatDuration(video.duration)"></span>
                                        </div>

                                        {{-- Existing localizations --}}
                                        <div class="mt-2 flex flex-wrap gap-1" x-show="video.existing_localizations.length > 0">
                                            <span class="text-[10px] text-gray-400 mr-1">Localisations:</span>
                                            <template x-for="lang in video.existing_localizations" :key="lang">
                                                <span class="inline-flex px-1.5 py-0.5 text-[10px] rounded bg-green-50 text-green-700" x-text="lang"></span>
                                            </template>
                                        </div>

                                        {{-- Translation status --}}
                                        <div class="mt-1.5 flex flex-wrap gap-1" x-show="Object.keys(video.translations || {}).length > 0">
                                            <span class="text-[10px] text-gray-400 mr-1">Traductions:</span>
                                            <template x-for="(types, type) in (video.translations || {})" :key="type">
                                                <template x-for="(langs, lang) in types" :key="lang">
                                                    <template x-for="(status, langCode) in langs" :key="langCode">
                                                        <span
                                                            class="inline-flex px-1.5 py-0.5 text-[10px] rounded"
                                                            :class="{
                                                                'bg-yellow-50 text-yellow-700': status === 'translating',
                                                                'bg-blue-50 text-blue-700': status === 'translated',
                                                                'bg-green-50 text-green-700': status === 'uploaded',
                                                                'bg-red-50 text-red-700': status === 'failed',
                                                                'bg-gray-50 text-gray-500': status === 'pending',
                                                            }"
                                                            x-text="type + ':' + langCode + ' (' + status + ')'"
                                                        ></span>
                                                    </template>
                                                </template>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </template>

            {{-- No videos --}}
            <template x-if="!loading && accountId && videos.length === 0 && !error">
                <div class="bg-white rounded-xl border border-gray-200 p-12 text-center">
                    <p class="text-sm text-gray-500">Aucune video trouvee pour cette chaine.</p>
                </div>
            </template>

            {{-- Error --}}
            <template x-if="error">
                <div class="bg-red-50 rounded-xl border border-red-200 p-6 text-center">
                    <p class="text-sm text-red-600" x-text="error"></p>
                </div>
            </template>
        </div>
    </div>

    {{-- Create language group modal --}}
    <div x-show="showGroupModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
        <div @click.outside="showGroupModal = false" class="bg-white rounded-2xl shadow-xl p-6 w-full max-w-md">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Creer un groupe de langues</h3>

            <div class="space-y-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nom</label>
                    <input type="text" x-model="newGroupName" placeholder="Ex: Europe, Asie..." class="w-full rounded-lg border-gray-300 text-sm">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Langues</label>
                    <div class="grid grid-cols-3 gap-1.5 max-h-48 overflow-y-auto">
                        @foreach($availableLanguages as $code)
                            <label class="flex items-center gap-1.5 text-xs cursor-pointer py-0.5">
                                <input type="checkbox" value="{{ $code }}" x-model="newGroupLanguages" class="rounded border-gray-300 text-indigo-600 w-3.5 h-3.5">
                                <span>{{ $languageNames[$code] ?? $code }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-3 mt-5">
                <button @click="showGroupModal = false" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Annuler</button>
                <button
                    @click="createLanguageGroup()"
                    :disabled="!newGroupName || newGroupLanguages.length === 0"
                    class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 disabled:opacity-50"
                >
                    Creer
                </button>
            </div>
        </div>
    </div>

    {{-- Toast notification --}}
    <div x-show="toast" x-transition x-cloak class="fixed bottom-6 right-6 z-50">
        <div class="px-4 py-3 rounded-xl shadow-lg text-sm font-medium"
             :class="toastType === 'success' ? 'bg-green-600 text-white' : 'bg-red-600 text-white'"
             x-text="toast"></div>
    </div>
</div>

<script>
function ytTranslator() {
    return {
        accountId: '',
        sourceLanguage: 'en',
        targetLanguages: [],
        translateTitle: true,
        translateDescription: true,
        translateSubtitles: false,
        videos: [],
        selectedVideos: [],
        loading: false,
        translating: false,
        error: null,
        toast: null,
        toastType: 'success',
        showGroupModal: false,
        newGroupName: '',
        newGroupLanguages: [],
        pollInterval: null,

        async loadVideos() {
            if (!this.accountId) {
                this.videos = [];
                return;
            }

            this.loading = true;
            this.error = null;
            this.selectedVideos = [];

            try {
                const res = await fetch('{{ route("yt-translator.videos") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    },
                    body: JSON.stringify({ account_id: this.accountId }),
                });

                if (!res.ok) {
                    const data = await res.json();
                    this.error = data.error || 'Erreur lors du chargement';
                    this.videos = [];
                    return;
                }

                this.videos = await res.json();
            } catch (e) {
                this.error = 'Erreur reseau';
            } finally {
                this.loading = false;
            }
        },

        toggleAllVideos(event) {
            if (event.target.checked) {
                this.selectedVideos = this.videos.map(v => v.id);
            } else {
                this.selectedVideos = [];
            }
        },

        selectLanguageGroup(languages) {
            this.targetLanguages = [...languages];
        },

        async launchTranslation() {
            const types = [];
            if (this.translateTitle) types.push('title');
            if (this.translateDescription) types.push('description');
            if (this.translateSubtitles) types.push('subtitles');

            if (types.length === 0 || this.selectedVideos.length === 0 || this.targetLanguages.length === 0) {
                return;
            }

            // Confirmation for subtitles (expensive)
            if (this.translateSubtitles) {
                const cost = this.selectedVideos.length * this.targetLanguages.length * 400;
                if (!confirm(`Attention: la traduction des sous-titres coute 400 unites par langue par video.\n\nCout estime: ${cost} unites pour ${this.selectedVideos.length} video(s) x ${this.targetLanguages.length} langue(s).\n\nContinuer ?`)) {
                    return;
                }
            }

            this.translating = true;

            try {
                const res = await fetch('{{ route("yt-translator.translate") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    },
                    body: JSON.stringify({
                        account_id: this.accountId,
                        video_ids: this.selectedVideos,
                        languages: this.targetLanguages,
                        types: types,
                        source_language: this.sourceLanguage,
                    }),
                });

                const data = await res.json();

                if (res.ok) {
                    this.showToast(data.message, 'success');
                    this.startPolling();
                } else {
                    this.showToast(data.message || 'Erreur', 'error');
                }
            } catch (e) {
                this.showToast('Erreur reseau', 'error');
            } finally {
                this.translating = false;
            }
        },

        startPolling() {
            if (this.pollInterval) clearInterval(this.pollInterval);

            this.pollInterval = setInterval(async () => {
                if (!this.accountId || this.selectedVideos.length === 0) {
                    clearInterval(this.pollInterval);
                    return;
                }

                try {
                    const res = await fetch('{{ route("yt-translator.status") }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        },
                        body: JSON.stringify({
                            account_id: this.accountId,
                            video_ids: this.videos.map(v => v.id),
                        }),
                    });

                    if (res.ok) {
                        const statuses = await res.json();
                        // Update video translations
                        this.videos = this.videos.map(v => {
                            if (statuses[v.id]) {
                                // Reformat the flat array into grouped structure
                                const grouped = {};
                                for (const item of statuses[v.id]) {
                                    if (!grouped[item.type]) grouped[item.type] = {};
                                    grouped[item.type][item.language] = item.status;
                                }
                                v.translations = grouped;
                            }
                            return v;
                        });

                        // Stop polling if all done
                        const allDone = Object.values(statuses).flat().every(
                            t => ['uploaded', 'failed', 'translated'].includes(t.status)
                        );
                        if (allDone) {
                            clearInterval(this.pollInterval);
                        }
                    }
                } catch (e) {
                    // Silent fail
                }
            }, 5000);
        },

        async createLanguageGroup() {
            try {
                const res = await fetch('{{ route("yt-translator.languageGroups.store") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    },
                    body: JSON.stringify({
                        name: this.newGroupName,
                        languages: this.newGroupLanguages,
                    }),
                });

                if (res.ok) {
                    this.showGroupModal = false;
                    this.newGroupName = '';
                    this.newGroupLanguages = [];
                    this.showToast('Groupe cree', 'success');
                    location.reload();
                }
            } catch (e) {
                this.showToast('Erreur', 'error');
            }
        },

        async deleteLanguageGroup(id) {
            if (!confirm('Supprimer ce groupe ?')) return;

            try {
                const res = await fetch(`/tools/yt-translator/language-groups/${id}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    },
                });

                if (res.ok) {
                    this.showToast('Groupe supprime', 'success');
                    location.reload();
                }
            } catch (e) {
                this.showToast('Erreur', 'error');
            }
        },

        showToast(message, type = 'success') {
            this.toast = message;
            this.toastType = type;
            setTimeout(() => { this.toast = null; }, 4000);
        },

        formatDate(dateStr) {
            if (!dateStr) return '';
            const d = new Date(dateStr);
            return d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' });
        },

        formatViews(count) {
            const n = parseInt(count);
            if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
            if (n >= 1000) return (n / 1000).toFixed(1) + 'k';
            return n.toString();
        },

        formatDuration(iso) {
            if (!iso) return '';
            let h = 0, m = 0, s = 0;
            const hMatch = iso.match(/(\d+)H/);
            const mMatch = iso.match(/(\d+)M/);
            const sMatch = iso.match(/(\d+)S/);
            if (hMatch) h = parseInt(hMatch[1]);
            if (mMatch) m = parseInt(mMatch[1]);
            if (sMatch) s = parseInt(sMatch[1]);
            if (h > 0) return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
            return `${m}:${String(s).padStart(2, '0')}`;
        },

        destroy() {
            if (this.pollInterval) clearInterval(this.pollInterval);
        }
    };
}
</script>
@endsection
