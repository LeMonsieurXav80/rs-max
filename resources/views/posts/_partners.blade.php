{{--
    Sélecteur de partenaires du formulaire de publication.

    Deux origines coexistent :
      • « auto » — hérité d'une photo taguée. Non modifiable ici : recalculé à
        chaque enregistrement à partir des photos réellement attachées.
      • « manuel » — coché ci-dessous, jamais écrasé par le recalcul.

    S'insère dans le x-data du formulaire (accès à `mediaItems` par héritage de scope).
--}}
@php
    $partnerOptions = $partnerOptions ?? [];
    $selectedPartnerIds = array_map('intval', $selectedPartnerIds ?? []);
    $mediaPartnerMap = $mediaPartnerMap ?? [];
    $oldPartners = old('partners');
    if (is_array($oldPartners)) {
        $selectedPartnerIds = array_map('intval', $oldPartners);
    }
@endphp

<div class="mt-6" x-data="{
    partnerOptions: @js($partnerOptions),
    manualIds: @js($selectedPartnerIds),
    partnerMapByUrl: @js($mediaPartnerMap),
    partnerListOpen: false,
    get autoPartners() {
        const found = {};
        (this.mediaItems || []).forEach(m => {
            const list = (m.partners && m.partners.length) ? m.partners : (this.partnerMapByUrl[m.url] || []);
            list.forEach(p => { found[p.id] = p; });
        });
        return Object.values(found);
    },
    get manualPartners() {
        return this.partnerOptions.filter(p => this.manualIds.includes(p.id));
    },
    toggleManual(id) {
        const i = this.manualIds.indexOf(id);
        if (i === -1) { this.manualIds.push(id); } else { this.manualIds.splice(i, 1); }
    },
}">
    <label class="block text-sm font-medium text-gray-700 mb-2">
        Partenaires
        <span class="ml-1 text-xs font-normal text-gray-400">— usage interne, jamais publié</span>
    </label>

    @if(empty($partnerOptions))
        <p class="text-sm text-gray-400">
            Aucun partenaire enregistré.
            @if(auth()->user()->isManager())
                <a href="{{ route('partners.index') }}" class="text-indigo-600 hover:underline">En créer un</a>.
            @endif
        </p>
    @else
        <div class="rounded-xl border border-gray-200 p-4 space-y-3">
            {{-- Tags hérités des photos --}}
            <div x-show="autoPartners.length > 0" x-cloak>
                <p class="text-xs text-gray-400 mb-1.5">Hérités des photos sélectionnées</p>
                <div class="flex flex-wrap gap-1.5">
                    <template x-for="p in autoPartners" :key="'auto-' + p.id">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium bg-amber-50 text-amber-800 border border-amber-200">
                            <span class="w-2 h-2 rounded-full" :style="'background-color:' + (p.color || '#f59e0b')"></span>
                            <span x-text="p.name"></span>
                        </span>
                    </template>
                </div>
            </div>

            {{-- Tags ajoutés à la main --}}
            <div>
                <div class="flex items-center justify-between">
                    <p class="text-xs text-gray-400">Ajoutés manuellement</p>
                    <button type="button" @click="partnerListOpen = !partnerListOpen"
                            class="text-xs font-medium text-indigo-600 hover:text-indigo-700">
                        <span x-text="partnerListOpen ? 'Fermer' : 'Ajouter un partenaire'"></span>
                    </button>
                </div>

                <div class="flex flex-wrap gap-1.5 mt-1.5" x-show="manualPartners.length > 0" x-cloak>
                    <template x-for="p in manualPartners" :key="'manual-' + p.id">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium bg-indigo-50 text-indigo-700 border border-indigo-200">
                            <span class="w-2 h-2 rounded-full" :style="'background-color:' + (p.color || '#6366f1')"></span>
                            <span x-text="p.name"></span>
                            <button type="button" @click="toggleManual(p.id)" class="text-indigo-400 hover:text-indigo-700" title="Retirer">&times;</button>
                        </span>
                    </template>
                </div>
                <p class="text-xs text-gray-300 mt-1.5" x-show="manualPartners.length === 0">Aucun</p>

                <div x-show="partnerListOpen" x-cloak x-collapse class="mt-3 max-h-56 overflow-y-auto border-t border-gray-100 pt-3 space-y-1">
                    <template x-for="p in partnerOptions" :key="'opt-' + p.id">
                        <label class="flex items-center gap-2.5 px-2 py-1.5 rounded-lg hover:bg-gray-50 cursor-pointer">
                            <input type="checkbox" :value="p.id" :checked="manualIds.includes(p.id)"
                                   @change="toggleManual(p.id)"
                                   class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            <span class="w-2 h-2 rounded-full flex-shrink-0" :style="'background-color:' + (p.color || '#6366f1')"></span>
                            <span class="text-sm text-gray-700" x-text="p.name"></span>
                        </label>
                    </template>
                </div>
            </div>

            {{-- Seuls les tags manuels sont soumis ; les 'auto' sont recalculés côté serveur. --}}
            <template x-for="id in manualIds" :key="'input-' + id">
                <input type="hidden" name="partners[]" :value="id">
            </template>
        </div>
    @endif

    @error('partners')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
</div>
