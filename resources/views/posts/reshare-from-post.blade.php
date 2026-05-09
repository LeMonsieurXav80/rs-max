@extends('layouts.app')

@section('title', 'Repartager une publication')

@section('content')
    <div class="mb-6">
        <a href="{{ route('posts.index') }}" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-700 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
            </svg>
            Retour aux publications
        </a>
    </div>

    <div x-data="reshareForm({
        endpoint: '{{ route('posts.reshare', $post) }}',
        postId: {{ $post->id }},
    })" class="space-y-6">

        {{-- ===== Source post preview ===== --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
            <div class="flex items-center gap-2 mb-3">
                <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 12c0-1.232-.046-2.453-.138-3.662a4.006 4.006 0 0 0-3.7-3.7 48.678 48.678 0 0 0-7.324 0 4.006 4.006 0 0 0-3.7 3.7c-.017.22-.032.441-.046.662M19.5 12l3-3m-3 3-3-3m-12 3c0 1.232.046 2.453.138 3.662a4.006 4.006 0 0 0 3.7 3.7 48.656 48.656 0 0 0 7.324 0 4.006 4.006 0 0 0 3.7-3.7c.017-.22.032-.441.046-.662M4.5 12l3 3m-3-3-3 3" />
                </svg>
                <h2 class="text-base font-semibold text-gray-900">Publication source</h2>
            </div>

            <div class="bg-gray-50 rounded-xl p-4 border border-gray-100">
                <p class="text-sm text-gray-700 whitespace-pre-line">{{ \Illuminate\Support\Str::limit($post->content_fr, 400) }}</p>

                @if($post->postPlatforms->where('status', 'published')->count() > 0)
                    <div class="mt-3 pt-3 border-t border-gray-200 flex flex-wrap gap-2">
                        @foreach($post->postPlatforms->where('status', 'published') as $pp)
                            <a @if($pp->platform_url) href="{{ $pp->platform_url }}" target="_blank" @endif
                               class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-white border border-gray-200 rounded-lg text-xs text-gray-700 @if($pp->platform_url) hover:bg-gray-50 @endif">
                                <x-platform-icon :platform="$pp->platform->slug" size="sm" />
                                <span>{{ $pp->socialAccount->name }}</span>
                                @if($pp->platform_url)
                                    <svg class="w-3 h-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                    </svg>
                                @endif
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- ===== Reshare config ===== --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
            <h2 class="text-base font-semibold text-gray-900 mb-4">Configuration du repartage</h2>

            {{-- Mode selector --}}
            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 mb-2">Mode de repartage</label>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2">
                    @foreach([
                        'auto' => ['Automatique', 'Le meilleur disponible par compte'],
                        'native_repost' => ['Repost natif', 'Retweet pur (X) ou repost (Bluesky)'],
                        'native_quote' => ['Citer', 'Quote tweet (X) ou quote post (Bluesky), texte requis'],
                        'link' => ['Partager le lien', 'Nouvelle publication avec lien — universel'],
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

            {{-- Accompanying text --}}
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

            {{-- Account selector --}}
            <div class="mb-5">
                <x-account-selector
                    :accounts="$accounts"
                    :groups="$groups"
                    :selectedIds="[]"
                    name="reshare_accounts[]"
                    :showSaveButton="false"
                    label="Comptes cibles" />
            </div>

            <p class="text-xs text-gray-500 mb-4">
                <svg class="w-3.5 h-3.5 inline -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
                </svg>
                Le repost natif n'est dispo que si le compte cible est sur la même plateforme que la source (X ou Bluesky). Les autres tombent automatiquement sur "partager le lien".
            </p>

            <div class="flex items-center justify-end gap-3">
                <a href="{{ route('posts.index') }}"
                   class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900 transition-colors">Annuler</a>
                <button type="button"
                        @click="submit"
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

        {{-- ===== Result block ===== --}}
        <template x-if="result">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                <h2 class="text-base font-semibold text-gray-900 mb-3">Résultat</h2>
                <template x-if="result.error">
                    <div class="p-3 bg-red-50 border border-red-200 rounded-xl text-sm text-red-800" x-text="result.error"></div>
                </template>
                <template x-if="!result.error">
                    <div class="space-y-2">
                        <p class="text-sm text-gray-600">
                            Mode utilisé : <span class="font-medium" x-text="result.reshare_mode"></span>
                        </p>
                        <template x-for="t in result.targets" :key="t.account_id">
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-xl border border-gray-100">
                                <div class="flex items-center gap-2 min-w-0">
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
                        <a href="{{ route('posts.index') }}"
                           class="inline-flex items-center gap-1.5 text-sm text-indigo-600 hover:text-indigo-700 mt-3">
                            Retour aux publications
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0-7.5 7.5M21 12H3" />
                            </svg>
                        </a>
                    </div>
                </template>
            </div>
        </template>
    </div>

@push('scripts')
<script>
function reshareForm(config) {
    return {
        endpoint: config.endpoint,
        mode: 'auto',
        text: '',
        submitting: false,
        result: null,

        get canSubmit() {
            const accounts = this.collectAccounts();
            if (accounts.length === 0) return false;
            if (this.mode === 'native_quote' && !this.text.trim()) return false;
            return true;
        },

        collectAccounts() {
            return [...this.$el.querySelectorAll('input[name="reshare_accounts[]"]:checked')].map(cb => parseInt(cb.value));
        },

        async submit() {
            if (this.submitting) return;
            this.result = null;
            const accounts = this.collectAccounts();
            if (accounts.length === 0) {
                this.result = { error: 'Sélectionnez au moins un compte cible.' };
                return;
            }

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
                        accounts: accounts,
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
