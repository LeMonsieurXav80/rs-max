@extends('layouts.app')

@section('title', 'Repartager une URL externe')

@section('content')
    <div class="mb-6">
        <a href="{{ route('posts.index') }}" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-700 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
            </svg>
            Retour aux publications
        </a>
    </div>

    <div x-data="reshareUrlForm({
        endpoint: '{{ route('posts.reshareUrl') }}',
    })" class="space-y-6 max-w-3xl">

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
            <h1 class="text-lg font-semibold text-gray-900 mb-1">Repartager une publication externe</h1>
            <p class="text-sm text-gray-500 mb-5">
                Collez l'URL d'un post X (Twitter), Bluesky ou Threads. Le repost natif est dispo pour X et Bluesky ;
                pour Threads et les autres plateformes, on tombe sur "partager le lien".
            </p>

            {{-- URL --}}
            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 mb-2">URL de la publication source <span class="text-red-500">*</span></label>
                <input type="url" x-model="url"
                       placeholder="https://x.com/user/status/12345... ou https://bsky.app/profile/handle/post/..."
                       class="block w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
            </div>

            {{-- Mode --}}
            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 mb-2">Mode de repartage</label>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2">
                    @foreach([
                        'auto' => ['Automatique', 'Le meilleur disponible par compte'],
                        'native_repost' => ['Repost natif', 'Retweet pur (X) ou repost (Bluesky)'],
                        'native_quote' => ['Citer', 'Quote tweet (X) ou quote post (Bluesky)'],
                        'link' => ['Partager le lien', 'Universel'],
                    ] as $val => [$label, $desc])
                        <label class="flex items-start gap-2 p-3 rounded-xl border cursor-pointer transition-colors"
                               :class="mode === '{{ $val }}' ? 'bg-indigo-50 border-indigo-300' : 'border-gray-200 hover:border-indigo-300 bg-white'">
                            <input type="radio" name="mode" value="{{ $val }}" x-model="mode"
                                   class="mt-0.5 border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-medium text-gray-900">{{ $label }}</div>
                                <div class="text-xs text-gray-500 mt-0.5">{{ $desc }}</div>
                            </div>
                        </label>
                    @endforeach
                </div>
            </div>

            {{-- Texte --}}
            <div class="mb-5" x-show="mode !== 'native_repost'" x-transition>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Texte d'accompagnement
                    <span x-show="mode === 'native_quote'" class="text-red-500">*</span>
                    <span x-show="mode !== 'native_quote'" class="text-gray-400 font-normal">(optionnel)</span>
                </label>
                <textarea x-model="text" rows="3"
                          class="block w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                          placeholder="Votre commentaire..."></textarea>
            </div>

            {{-- Comptes --}}
            <div class="mb-5">
                <x-account-selector
                    :accounts="$accounts"
                    :groups="$groups"
                    :selectedIds="[]"
                    name="reshare_accounts[]"
                    :showSaveButton="false"
                    label="Comptes cibles" />
            </div>

            <div class="flex items-center justify-end gap-3">
                <a href="{{ route('posts.index') }}"
                   class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900 transition-colors">Annuler</a>
                <button type="button" @click="submit"
                        :disabled="submitting || !canSubmit"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm disabled:opacity-50">
                    <svg x-show="!submitting" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 12c0-1.232-.046-2.453-.138-3.662a4.006 4.006 0 0 0-3.7-3.7 48.678 48.678 0 0 0-7.324 0 4.006 4.006 0 0 0-3.7 3.7c-.017.22-.032.441-.046.662M19.5 12l3-3m-3 3-3-3m-12 3c0 1.232.046 2.453.138 3.662a4.006 4.006 0 0 0 3.7 3.7 48.656 48.656 0 0 0 7.324 0 4.006 4.006 0 0 0 3.7-3.7c.017-.22.032-.441.046-.662M4.5 12l3 3m-3-3-3 3" />
                    </svg>
                    <svg x-show="submitting" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <span x-text="submitting ? 'Repartage...' : 'Repartager'"></span>
                </button>
            </div>
        </div>

        <template x-if="result">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                <h2 class="text-base font-semibold text-gray-900 mb-3">Résultat</h2>
                <template x-if="result.error">
                    <div class="p-3 bg-red-50 border border-red-200 rounded-xl text-sm text-red-800" x-text="result.error"></div>
                </template>
                <template x-if="!result.error">
                    <div class="space-y-2">
                        <p class="text-sm text-gray-600">Mode utilisé : <span class="font-medium" x-text="result.reshare_mode"></span></p>
                        <template x-for="t in result.targets" :key="t.account_id">
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-xl border border-gray-100">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-medium px-2 py-0.5 rounded"
                                          :class="t.status === 'published' ? 'bg-green-100 text-green-700' : t.status === 'failed' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700'"
                                          x-text="t.status"></span>
                                    <span class="text-sm font-medium text-gray-900" x-text="t.account_name"></span>
                                    <span class="text-xs text-gray-500" x-text="'(' + t.platform + ')'"></span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <template x-if="t.platform_url">
                                        <a :href="t.platform_url" target="_blank" class="text-xs text-indigo-600 hover:underline">Voir</a>
                                    </template>
                                    <template x-if="t.error">
                                        <span class="text-xs text-red-600" x-text="t.error"></span>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </template>
    </div>

@push('scripts')
<script>
function reshareUrlForm(config) {
    return {
        endpoint: config.endpoint,
        url: '',
        mode: 'auto',
        text: '',
        submitting: false,
        result: null,

        get canSubmit() {
            if (!this.url.trim()) return false;
            if (this.collectAccounts().length === 0) return false;
            if (this.mode === 'native_quote' && !this.text.trim()) return false;
            return true;
        },

        collectAccounts() {
            return [...this.$el.querySelectorAll('input[name="reshare_accounts[]"]:checked')].map(cb => parseInt(cb.value));
        },

        async submit() {
            if (this.submitting || !this.canSubmit) return;
            this.result = null;
            this.submitting = true;
            try {
                const resp = await fetch(this.endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                    },
                    body: JSON.stringify({
                        url: this.url,
                        accounts: this.collectAccounts(),
                        mode: this.mode,
                        text: this.text || null,
                    })
                });
                const data = await resp.json();
                this.result = resp.ok ? data : { error: data.error || data.message || 'Erreur HTTP ' + resp.status };
            } catch (e) {
                this.result = { error: 'Erreur de connexion : ' + e.message };
            }
            this.submitting = false;
        },
    };
}
</script>
@endpush
@endsection
