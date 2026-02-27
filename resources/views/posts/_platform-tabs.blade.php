{{-- Platform-specific content tabs --}}
{{-- Shared partial for create and edit views --}}
{{-- Expected variables: $charLimits (array), $initialPlatformContents (array, optional) --}}

@php
    $platformLabels = [
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'threads' => 'Threads',
        'twitter' => 'Twitter / X',
        'telegram' => 'Telegram',
        'youtube' => 'YouTube',
    ];
    $platformOrder = ['facebook', 'instagram', 'threads', 'twitter', 'telegram', 'youtube'];
@endphp

<div x-data="platformTabs()" @accounts-changed.window="updatePlatforms()" x-init="updatePlatforms()" x-cloak>
    <div x-show="platforms.length > 0" x-transition class="mt-6">
        {{-- Header with AI buttons --}}
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-2">
                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75" />
                </svg>
                Contenu par plateforme
            </h3>
            <div class="flex items-center gap-2">
                {{-- Generate from text --}}
                <button type="button"
                        x-show="hasPersona"
                        @click="generateAllPlatforms()"
                        :disabled="aiMultiLoading || aiMediaLoading"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-purple-600 bg-purple-50 rounded-lg hover:bg-purple-100 transition-colors disabled:opacity-50">
                    <template x-if="!aiMultiLoading">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 0 0-2.455 2.456Z" />
                        </svg>
                    </template>
                    <template x-if="aiMultiLoading">
                        <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                    </template>
                    <span x-text="aiMultiLoading ? 'Génération...' : 'Générer à partir de texte'"></span>
                </button>
                {{-- Generate from media --}}
                <button type="button"
                        x-show="hasPersona && mediaItems && mediaItems.length > 0"
                        @click="generateFromMedia()"
                        :disabled="aiMultiLoading || aiMediaLoading"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-indigo-600 bg-indigo-50 rounded-lg hover:bg-indigo-100 transition-colors disabled:opacity-50">
                    <template x-if="!aiMediaLoading">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z" />
                        </svg>
                    </template>
                    <template x-if="aiMediaLoading">
                        <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                    </template>
                    <span x-text="aiMediaLoading ? 'Analyse des médias...' : 'Générer à partir des médias'"></span>
                </button>
            </div>
        </div>

        <p class="text-xs text-gray-400 mb-4">Personnalisez le texte pour chaque plateforme. Laissez vide pour utiliser le contenu par défaut.</p>

        {{-- Error messages --}}
        <p x-show="aiMultiError" x-text="aiMultiError" class="mb-3 text-sm text-red-600" x-cloak></p>
        <p x-show="aiMediaError" x-text="aiMediaError" class="mb-3 text-sm text-red-600" x-cloak></p>

        {{-- Tab navigation --}}
        <div class="flex gap-1 border-b border-gray-200 mb-4 overflow-x-auto">
            <template x-for="slug in platforms" :key="slug">
                <button type="button" @click="activeTab = slug"
                    class="flex items-center gap-1.5 px-4 py-2.5 text-sm font-medium border-b-2 transition-colors whitespace-nowrap"
                    :class="activeTab === slug
                        ? 'border-indigo-600 text-indigo-600'
                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'">
                    <span x-text="platformLabels[slug] || slug"></span>
                    <span x-show="(platformContents[slug] || '').trim().length > 0"
                          class="w-1.5 h-1.5 rounded-full bg-indigo-500 flex-shrink-0"></span>
                </button>
            </template>
        </div>

        {{-- Tab panels --}}
        <template x-for="slug in platforms" :key="'panel-' + slug">
            <div x-show="activeTab === slug" x-transition.opacity>
                <textarea
                    :name="'platform_contents[' + slug + ']'"
                    x-model="platformContents[slug]"
                    rows="5"
                    class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm placeholder-gray-400 transition-colors"
                    :placeholder="'Contenu spécifique pour ' + (platformLabels[slug] || slug) + ' (optionnel)'"
                ></textarea>
                <div class="mt-1.5 flex items-center justify-between">
                    <p class="text-xs text-gray-400">
                        <span x-text="(platformContents[slug] || '').length"></span> caractères
                        <template x-if="charLimits[slug]">
                            <span>
                                / <span x-text="charLimits[slug]"></span> max
                                <span x-show="(platformContents[slug] || '').length > charLimits[slug]" class="text-red-500 font-medium ml-1">Dépassé !</span>
                            </span>
                        </template>
                    </p>
                    <button type="button"
                            x-show="(platformContents[slug] || '').trim().length > 0"
                            @click="platformContents[slug] = ''"
                            class="text-xs text-gray-400 hover:text-red-500 transition-colors">
                        Effacer
                    </button>
                </div>
            </div>
        </template>
    </div>
</div>

<script>
function platformTabs() {
    return {
        platforms: [],
        activeTab: null,
        hasPersona: false,
        platformContents: @js($initialPlatformContents ?? []),
        charLimits: @js($charLimits ?? []),
        platformLabels: {
            facebook: 'Facebook',
            instagram: 'Instagram',
            threads: 'Threads',
            twitter: 'Twitter / X',
            telegram: 'Telegram',
            youtube: 'YouTube',
        },
        aiMultiLoading: false,
        aiMultiError: '',
        aiMediaLoading: false,
        aiMediaError: '',

        updatePlatforms() {
            const checked = [...document.querySelectorAll('input[name="accounts[]"]:checked')];
            const slugs = [...new Set(checked.map(el => el.dataset.platform))];
            // Keep platform order consistent
            const order = ['facebook', 'instagram', 'threads', 'twitter', 'telegram', 'youtube'];
            this.platforms = order.filter(s => slugs.includes(s));

            // Check if any selected account has a persona
            this.hasPersona = checked.some(el => el.dataset.hasPersona === '1');

            if (this.platforms.length && !this.platforms.includes(this.activeTab)) {
                this.activeTab = this.platforms[0];
            }
            if (!this.platforms.length) {
                this.activeTab = null;
            }
        },

        async generateAllPlatforms() {
            const checkedAccount = document.querySelector('input[name="accounts[]"]:checked');
            if (!checkedAccount) {
                this.aiMultiError = 'Sélectionnez au moins un compte.';
                setTimeout(() => this.aiMultiError = '', 3000);
                return;
            }

            this.aiMultiLoading = true;
            this.aiMultiError = '';
            const baseFr = document.getElementById('content_fr')?.value || '';

            try {
                const resp = await fetch('{{ route("posts.aiAssistPlatforms") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        content: baseFr,
                        platforms: this.platforms,
                        account_id: parseInt(checkedAccount.value),
                    }),
                });

                const data = await resp.json();
                if (data.platform_contents) {
                    Object.entries(data.platform_contents).forEach(([slug, text]) => {
                        this.platformContents[slug] = text;
                    });
                } else if (data.error) {
                    this.aiMultiError = data.error;
                    setTimeout(() => this.aiMultiError = '', 5000);
                }
            } catch(e) {
                this.aiMultiError = 'Erreur de connexion';
                setTimeout(() => this.aiMultiError = '', 3000);
            }

            this.aiMultiLoading = false;
        },

        async generateFromMedia() {
            const checkedAccount = document.querySelector('input[name="accounts[]"]:checked');
            if (!checkedAccount) {
                this.aiMediaError = 'Sélectionnez au moins un compte.';
                setTimeout(() => this.aiMediaError = '', 3000);
                return;
            }

            // Access mediaItems from parent Alpine.js scope
            const items = this.mediaItems || [];
            if (items.length === 0) {
                this.aiMediaError = 'Ajoutez au moins un média.';
                setTimeout(() => this.aiMediaError = '', 3000);
                return;
            }

            this.aiMediaLoading = true;
            this.aiMediaError = '';

            try {
                const resp = await fetch('{{ route("posts.aiAssistMedia") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        media_urls: items.map(item => item.url),
                        platforms: this.platforms,
                        account_id: parseInt(checkedAccount.value),
                        content: document.getElementById('content_fr')?.value || '',
                    }),
                });

                const data = await resp.json();
                if (data.platform_contents) {
                    Object.entries(data.platform_contents).forEach(([slug, text]) => {
                        this.platformContents[slug] = text;
                    });
                } else if (data.error) {
                    this.aiMediaError = data.error;
                    setTimeout(() => this.aiMediaError = '', 5000);
                }
            } catch(e) {
                this.aiMediaError = 'Erreur de connexion';
                setTimeout(() => this.aiMediaError = '', 3000);
            }

            this.aiMediaLoading = false;
        }
    }
}
</script>
