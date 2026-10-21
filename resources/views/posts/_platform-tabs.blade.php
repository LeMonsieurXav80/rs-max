{{-- Platform-specific content tabs --}}
{{-- Shared partial for create and edit views --}}
{{-- Expected variables: $charLimits (array), $initialPlatformContents (array, optional) --}}

@php
    $platformLabels = [
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'threads' => 'Threads',
        'twitter' => 'Twitter / X',
        'bluesky' => 'Bluesky',
        'telegram' => 'Telegram',
        'youtube' => 'YouTube',
        'linkedin' => 'LinkedIn',
        'pinterest' => 'Pinterest',
    ];
    $platformOrder = ['facebook', 'instagram', 'threads', 'twitter', 'bluesky', 'telegram', 'youtube', 'linkedin', 'pinterest'];
@endphp

<div x-data="platformTabs()" @accounts-changed.window="updatePlatforms()" x-init="updatePlatforms()" x-cloak>
    <div x-show="platforms.length > 0" x-transition class="mt-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-2">
                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75" />
                </svg>
                Contenu par plateforme
            </h3>
        </div>

        <p class="text-xs text-gray-400 mb-4">Personnalisez le texte pour chaque plateforme. Laissez vide pour utiliser le contenu par défaut.</p>

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
                {{-- Article X : n'apparaît que si tous les comptes X sélectionnés sont abonnés,
                     puisque l'API refuse l'article sur un compte gratuit. --}}
                <template x-if="slug === 'twitter' && articleAvailable">
                    <div class="mb-3 p-3 bg-amber-50/60 border border-amber-200 rounded-xl">
                        <label class="block text-xs font-semibold text-amber-800 mb-1.5">
                            Titre de l'Article X
                            <span class="font-normal text-amber-600">— laisser vide pour publier un post normal</span>
                        </label>
                        <input type="text"
                               name="article_title"
                               x-model="articleTitle"
                               maxlength="255"
                               placeholder="Ex. : Ce que nous avons appris cette saison"
                               class="w-full rounded-lg border-amber-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 text-sm placeholder-amber-300">
                        <p class="text-xs text-amber-700 mt-1.5" x-show="(articleTitle || '').trim().length > 0">
                            Le contenu ci-dessous partira en Article long format. Les sauts de ligne font les
                            paragraphes ; <code>#</code>, <code>##</code>, <code>-</code> et <code>&gt;</code> sont
                            reconnus. Les médias ne sont pas encore pris en charge dans un article.
                        </p>
                    </div>
                </template>
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
                        <template x-if="effectiveLimits[slug]">
                            <span>
                                / <span x-text="effectiveLimits[slug]"></span> max
                                <span x-show="slug === 'twitter' && effectiveLimits[slug] > charLimits.twitter"
                                      class="text-amber-600 font-medium ml-1">Premium</span>
                                <span x-show="(platformContents[slug] || '').length > effectiveLimits[slug]" class="text-red-500 font-medium ml-1">Dépassé !</span>
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
        platformContents: @js($initialPlatformContents ?? []),
        charLimits: @js($charLimits ?? []),
        effectiveLimits: {},
        articleTitle: @js($articleTitle ?? ''),
        articleAvailable: false,
        platformLabels: {
            facebook: 'Facebook',
            instagram: 'Instagram',
            threads: 'Threads',
            twitter: 'Twitter / X',
            bluesky: 'Bluesky',
            telegram: 'Telegram',
            youtube: 'YouTube',
            linkedin: 'LinkedIn',
            pinterest: 'Pinterest',
        },
        updatePlatforms() {
            const checked = [...document.querySelectorAll('input[name="accounts[]"]:checked')];
            const slugs = [...new Set(checked.map(el => el.dataset.platform))];
            // Keep platform order consistent
            const order = ['facebook', 'instagram', 'threads', 'twitter', 'bluesky', 'telegram', 'youtube', 'linkedin', 'pinterest'];
            this.platforms = order.filter(s => slugs.includes(s));

            this.updateEffectiveLimits(checked);

            if (this.platforms.length && !this.platforms.includes(this.activeTab)) {
                this.activeTab = this.platforms[0];
            }
            if (!this.platforms.length) {
                this.activeTab = null;
            }
        },

        // La limite X dépend du compte, pas de la plateforme : les 25 000 caractères
        // ne sont acceptés par l'API que si le compte qui publie est Premium.
        // Dès qu'un compte non-Premium est sélectionné on retombe sur 280, sinon la
        // publication échouerait sur celui-là (erreur 111).
        updateEffectiveLimits(checked) {
            const limits = { ...this.charLimits };
            delete limits.twitter_premium;

            const twitterAccounts = checked.filter(el => el.dataset.platform === 'twitter');
            const allPremium = twitterAccounts.length > 0
                && twitterAccounts.every(el => el.dataset.premium === '1');

            if (allPremium) {
                limits.twitter = this.charLimits.twitter_premium || limits.twitter;
            }

            this.effectiveLimits = limits;

            // Même condition que la limite longue : un Article est refusé sur un
            // compte gratuit, donc on n'ouvre pas la porte si l'un d'eux l'est.
            this.articleAvailable = allPremium;
            if (! allPremium) {
                this.articleTitle = '';
            }
        }
    }
}
</script>
