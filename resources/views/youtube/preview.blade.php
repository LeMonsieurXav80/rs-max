@extends('layouts.app')

@section('title', 'Prévisualiser — ' . $ytSource->name)

@section('actions')
    <a href="{{ route('youtube-channels.index') }}"
       class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
        </svg>
        Retour
    </a>
@endsection

@section('content')

<div x-data="ytPreview()" class="max-w-5xl space-y-6">

    {{-- Header info --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
        <div class="flex items-center gap-4 mb-4">
            <div class="w-12 h-12 rounded-xl bg-red-100 text-red-600 flex items-center justify-center flex-shrink-0">
                <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
                </svg>
            </div>
            <div>
                <h2 class="text-lg font-bold text-gray-900">{{ $ytSource->name }}</h2>
                <p class="text-sm text-gray-500">{{ $ytSource->channel_url }}</p>
            </div>
        </div>
        <div class="flex flex-wrap gap-4 text-sm text-gray-600">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-gray-50 rounded-lg">
                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z" />
                </svg>
                {{ $totalItems }} vidéos disponibles
            </span>
            <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-gray-50 rounded-lg">
                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
                {{ $frequencyLabels[$ytSource->schedule_frequency] ?? $ytSource->schedule_frequency }} &middot; {{ $ytSource->schedule_time }}
            </span>
            <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-gray-50 rounded-lg">
                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />
                </svg>
                {{ $ytSource->socialAccounts->count() }} compte(s) lié(s)
            </span>
            <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-red-50 text-red-700 rounded-lg">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
                </svg>
                Prochaine date : {{ $nextDate->translatedFormat('l j F Y à H:i') }}
            </span>
        </div>
    </div>

    {{-- Generation form --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
        <div class="flex items-end gap-4">
            <div class="flex-1">
                <label for="postCount" class="block text-sm font-medium text-gray-700 mb-1">Nombre de publications à générer</label>
                <input type="number" id="postCount" min="1" max="20" x-model.number="postCount"
                       class="w-32 rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
            </div>
            <button type="button" @click="generate()" :disabled="generating"
                    class="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm disabled:opacity-50 disabled:cursor-not-allowed">
                <template x-if="!generating">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 0 0-2.455 2.456Z" />
                    </svg>
                </template>
                <template x-if="generating">
                    <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                </template>
                <span x-text="generating ? 'Génération en cours...' : 'Générer les publications'"></span>
            </button>
        </div>

        {{-- Progress bar --}}
        <div x-show="generating" x-transition class="mt-4">
            <div class="flex items-center justify-between mb-1">
                <span class="text-xs font-medium text-purple-700" x-text="progressText"></span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-2">
                <div class="bg-purple-600 h-2 rounded-full transition-all duration-500"
                     :style="'width: ' + progressPercent + '%'"></div>
            </div>
        </div>

        {{-- Error --}}
        <p x-show="error" x-text="error" class="mt-3 text-sm text-red-600" x-cloak></p>
    </div>

    {{-- Publications list --}}
    <div x-show="publications.length > 0" x-transition class="space-y-4">
        <template x-for="(pub, pubIndex) in publications" :key="pub.yt_item_id">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                {{-- Publication header --}}
                <div class="p-5 border-b border-gray-100">
                    <div class="flex items-start gap-4">
                        {{-- Video thumbnail --}}
                        <div class="relative flex-shrink-0 w-40 h-24 rounded-lg overflow-hidden bg-gray-100">
                            <template x-if="pub.thumbnail_url">
                                <img :src="pub.thumbnail_url" :alt="pub.title" class="w-full h-full object-cover">
                            </template>
                            <template x-if="!pub.thumbnail_url">
                                <div class="w-full h-full flex items-center justify-center text-gray-400">
                                    <svg class="w-8 h-8" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
                                    </svg>
                                </div>
                            </template>
                            {{-- Duration badge --}}
                            <div x-show="pub.duration" class="absolute bottom-1 right-1 px-1.5 py-0.5 bg-black/80 text-white text-xs font-semibold rounded">
                                <span x-text="formatDuration(pub.duration)"></span>
                            </div>
                        </div>

                        {{-- Video info --}}
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0 flex-1">
                                    <a :href="pub.url" target="_blank" class="text-sm font-semibold text-gray-900 hover:text-red-600 transition-colors line-clamp-2" x-text="pub.title"></a>
                                    <div class="flex items-center gap-3 mt-2 text-xs text-gray-500">
                                        <span class="inline-flex items-center gap-1">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
                                            </svg>
                                            <span x-text="pub.scheduled_at_human"></span>
                                        </span>
                                        <template x-if="pub.view_count">
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-red-50 text-red-600 rounded-md">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                                </svg>
                                                <span x-text="formatViews(pub.view_count) + ' vues'"></span>
                                            </span>
                                        </template>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-gray-100 rounded-md">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 12c0-1.232-.046-2.453-.138-3.662a4.006 4.006 0 0 0-3.7-3.7 48.678 48.678 0 0 0-7.324 0 4.006 4.006 0 0 0-3.7 3.7c-.017.22-.032.441-.046.662M19.5 12l3-3m-3 3-3-3m-12 3c0 1.232.046 2.453.138 3.662a4.006 4.006 0 0 0 3.7 3.7 48.656 48.656 0 0 0 7.324 0 4.006 4.006 0 0 0 3.7-3.7c.017-.22.032-.441.046-.662M4.5 12l3 3m-3-3-3 3" />
                                            </svg>
                                            <span x-text="pub.usage_count + ' utilisation(s)'"></span>
                                        </span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 flex-shrink-0">
                                    <button type="button" @click="regenerate(pubIndex)" :disabled="pub.regenerating"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-purple-600 bg-purple-50 rounded-lg hover:bg-purple-100 transition-colors disabled:opacity-50">
                                        <template x-if="!pub.regenerating">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182" />
                                            </svg>
                                        </template>
                                        <template x-if="pub.regenerating">
                                            <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                            </svg>
                                        </template>
                                        <span x-text="pub.regenerating ? 'Régénération...' : 'Régénérer'"></span>
                                    </button>
                                    <button type="button" @click="removePublication(pubIndex)"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-red-600 bg-red-50 rounded-lg hover:bg-red-100 transition-colors">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                        </svg>
                                        Retirer
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Platform tabs --}}
                <div class="p-5" x-data="{ activeTab: Object.keys(pub.platform_contents)[0] }">
                    <div class="flex gap-1 border-b border-gray-200 mb-4 overflow-x-auto" x-show="Object.keys(pub.platform_contents).length > 1">
                        <template x-for="accountId in Object.keys(pub.platform_contents)" :key="'tab-' + pub.yt_item_id + '-' + accountId">
                            <button type="button" @click="activeTab = accountId"
                                    class="flex items-center gap-1.5 px-4 py-2.5 text-sm font-medium border-b-2 transition-colors whitespace-nowrap"
                                    :class="activeTab === accountId
                                        ? 'border-indigo-600 text-indigo-600'
                                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'">
                                <span x-text="pub.platform_contents[accountId].account_name"></span>
                                <span class="text-xs text-gray-400" x-text="'(' + pub.platform_contents[accountId].platform_name + ')'"></span>
                            </button>
                        </template>
                    </div>

                    <template x-for="accountId in Object.keys(pub.platform_contents)" :key="'panel-' + pub.yt_item_id + '-' + accountId">
                        <div x-show="activeTab === accountId" x-transition.opacity>
                            <template x-if="pub.platform_contents[accountId].error">
                                <p class="text-sm text-amber-600 mb-2" x-text="pub.platform_contents[accountId].error"></p>
                            </template>

                            <textarea
                                x-model="pub.platform_contents[accountId].content"
                                rows="6"
                                class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm placeholder-gray-400"
                                placeholder="Contenu de la publication..."
                            ></textarea>
                            <div class="mt-1.5 flex items-center justify-between">
                                <p class="text-xs text-gray-400">
                                    <span x-text="(pub.platform_contents[accountId].content || '').length"></span> caractères
                                </p>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </template>

        {{-- Confirm button --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-semibold text-gray-900" x-text="publications.length + ' publication(s) prête(s)'"></p>
                    <p class="text-xs text-gray-500 mt-1">Les publications seront planifiées avec le statut "Programmé".</p>
                </div>
                <button type="button" @click="confirm()" :disabled="confirming"
                        class="inline-flex items-center gap-2 px-6 py-2.5 bg-green-600 text-white text-sm font-medium rounded-xl hover:bg-green-700 transition-colors shadow-sm disabled:opacity-50 disabled:cursor-not-allowed">
                    <template x-if="!confirming">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                    </template>
                    <template x-if="confirming">
                        <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                    </template>
                    <span x-text="confirming ? 'Planification...' : 'Valider et planifier ' + publications.length + ' publication(s)'"></span>
                </button>
            </div>

            <div x-show="confirmSuccess" x-transition class="mt-4 rounded-xl bg-green-50 border border-green-200 p-4 flex items-center gap-3">
                <svg class="w-5 h-5 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
                <p class="text-sm text-green-700" x-text="confirmMessage"></p>
            </div>

            <p x-show="confirmError" x-text="confirmError" class="mt-3 text-sm text-red-600" x-cloak></p>
        </div>
    </div>
</div>

<script>
function ytPreview() {
    return {
        postCount: 3,
        generating: false,
        progressText: '',
        progressPercent: 0,
        error: '',
        publications: [],
        confirming: false,
        confirmSuccess: false,
        confirmMessage: '',
        confirmError: '',
        frequency: @json($ytSource->schedule_frequency),
        scheduleTime: @json($ytSource->schedule_time),
        baseDate: @json($lastPostDate->format('Y-m-d H:i')),

        formatDuration(iso) {
            if (!iso) return '';
            let match;
            let h = 0, m = 0, s = 0;
            if (match = iso.match(/(\d+)H/)) h = parseInt(match[1]);
            if (match = iso.match(/(\d+)M/)) m = parseInt(match[1]);
            if (match = iso.match(/(\d+)S/)) s = parseInt(match[1]);
            if (h > 0) return h + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
            return m + ':' + String(s).padStart(2, '0');
        },

        formatViews(n) {
            if (!n) return '0';
            if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
            if (n >= 1000) return (n / 1000).toFixed(1) + 'k';
            return n.toString();
        },

        async generate() {
            this.generating = true;
            this.error = '';
            this.publications = [];
            this.confirmSuccess = false;
            this.confirmError = '';
            this.progressText = 'Sélection des vidéos...';
            this.progressPercent = 5;

            try {
                const resp = await fetch('{{ route("youtube-channels.generatePreview", $ytSource) }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ count: this.postCount }),
                });

                if (!resp.ok) {
                    const text = await resp.text();
                    try {
                        const errData = JSON.parse(text);
                        this.error = errData.error || errData.message || `Erreur serveur (${resp.status})`;
                    } catch {
                        this.error = `Erreur serveur (${resp.status}): ${text.substring(0, 200)}`;
                    }
                    this.generating = false;
                    return;
                }

                const data = await resp.json();

                if (data.error) {
                    this.error = data.error;
                    this.generating = false;
                    return;
                }

                if (!data.publications || data.publications.length === 0) {
                    this.error = 'Aucune vidéo sélectionnée.';
                    this.generating = false;
                    return;
                }

                this.publications = data.publications.map(pub => ({
                    ...pub,
                    regenerating: false,
                }));

                this.progressText = `${data.publications.length} vidéo(s) sélectionnée(s), génération du contenu...`;
                this.progressPercent = 10;

                const total = this.publications.length;
                for (let i = 0; i < total; i++) {
                    const pub = this.publications[i];
                    pub.regenerating = true;
                    this.progressText = `Génération de la vidéo ${i + 1}/${total} : ${pub.title.substring(0, 40)}...`;
                    this.progressPercent = 10 + Math.round((i / total) * 85);

                    try {
                        const genResp = await fetch('{{ route("youtube-channels.regenerateItem", $ytSource) }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({ yt_item_id: pub.yt_item_id }),
                        });

                        if (genResp.ok) {
                            const genData = await genResp.json();
                            if (genData.platform_contents) {
                                Object.keys(genData.platform_contents).forEach(accountId => {
                                    if (pub.platform_contents[accountId]) {
                                        pub.platform_contents[accountId].content = genData.platform_contents[accountId].content;
                                        pub.platform_contents[accountId].error = genData.platform_contents[accountId].error;
                                    }
                                });
                            }
                        }
                    } catch (e) {
                        console.error(`Generation failed for item ${pub.yt_item_id}:`, e);
                    }

                    pub.regenerating = false;
                }

                this.progressPercent = 100;
                this.progressText = `${total} publication(s) générée(s)`;
            } catch (e) {
                this.error = `Erreur de connexion : ${e.message}`;
                console.error('Generate error:', e);
            }

            setTimeout(() => {
                this.generating = false;
            }, 500);
        },

        async regenerate(pubIndex) {
            const pub = this.publications[pubIndex];
            pub.regenerating = true;

            try {
                const resp = await fetch('{{ route("youtube-channels.regenerateItem", $ytSource) }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ yt_item_id: pub.yt_item_id }),
                });

                const data = await resp.json();

                if (data.platform_contents) {
                    Object.keys(data.platform_contents).forEach(accountId => {
                        if (pub.platform_contents[accountId]) {
                            pub.platform_contents[accountId].content = data.platform_contents[accountId].content;
                            pub.platform_contents[accountId].error = data.platform_contents[accountId].error;
                        }
                    });
                }
            } catch (e) {
                // Silent fail
            }

            pub.regenerating = false;
        },

        removePublication(pubIndex) {
            this.publications.splice(pubIndex, 1);
            this.recalculateDates();
        },

        recalculateDates() {
            let current = new Date(this.baseDate.replace(' ', 'T'));
            for (let i = 0; i < this.publications.length; i++) {
                current = this._calcNext(current);
                const pub = this.publications[i];
                pub.scheduled_at = this._formatForServer(current);
                pub.scheduled_at_human = this._formatHuman(current);
            }
        },

        _calcNext(from) {
            let next = new Date(from);
            switch (this.frequency) {
                case 'daily':
                    next.setDate(next.getDate() + 1);
                    break;
                case 'twice_weekly': {
                    const day = next.getDay();
                    if (day === 0) next.setDate(next.getDate() + 1);
                    else if (day < 4) next.setDate(next.getDate() + (4 - day));
                    else next.setDate(next.getDate() + (8 - day));
                    break;
                }
                case 'weekly':
                    next.setDate(next.getDate() + 7);
                    break;
                case 'biweekly':
                    next.setDate(next.getDate() + 14);
                    break;
                case 'monthly':
                    next.setMonth(next.getMonth() + 1);
                    break;
                default:
                    next.setDate(next.getDate() + 7);
            }
            if (this.scheduleTime) {
                const [h, m] = this.scheduleTime.split(':');
                next.setHours(parseInt(h), parseInt(m), 0, 0);
            }
            if (next <= new Date()) return this._calcNext(next);
            return next;
        },

        _formatForServer(d) {
            const pad = n => String(n).padStart(2, '0');
            return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
        },

        _formatHuman(d) {
            const opts = { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' };
            const datePart = d.toLocaleDateString('fr-FR', opts);
            const timePart = d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
            return datePart + ' à ' + timePart;
        },

        async confirm() {
            this.confirming = true;
            this.confirmError = '';
            this.confirmSuccess = false;

            const payload = this.publications.map(pub => ({
                yt_item_id: pub.yt_item_id,
                scheduled_at: pub.scheduled_at,
                platform_contents: Object.fromEntries(
                    Object.entries(pub.platform_contents).map(([accountId, data]) => [
                        accountId,
                        { content: data.content }
                    ])
                ),
            }));

            try {
                const resp = await fetch('{{ route("youtube-channels.confirmPublications", $ytSource) }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ publications: payload }),
                });

                const data = await resp.json();

                if (data.success) {
                    this.confirmSuccess = true;
                    this.confirmMessage = data.message;
                    setTimeout(() => {
                        this.publications = [];
                    }, 3000);
                } else if (data.error) {
                    this.confirmError = data.error;
                }
            } catch (e) {
                this.confirmError = 'Erreur de connexion au serveur.';
            }

            this.confirming = false;
        }
    }
}
</script>

@endsection
