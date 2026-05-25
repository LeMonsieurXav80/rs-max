@php
    $boostableThreads = $boostableThreads ?? collect();
    $existingBoost = $existingBoost ?? null;

    // Sérialisation pour Alpine : id, titre, aperçu du segment 1, et liste des comptes attachés.
    // account_ids sert au filtre dynamique côté JS selon les comptes cochés dans le formulaire.
    $boostableData = $boostableThreads->map(function ($bt) {
        $first = $bt->segments->first();
        $preview = $first?->content_fr ? \Illuminate\Support\Str::limit($first->content_fr, 80) : '(vide)';
        return [
            'id' => $bt->id,
            'label' => ($bt->title ?: '#'.$bt->id) . ' — ' . $preview,
            'account_ids' => $bt->socialAccounts->pluck('id')->values()->all(),
        ];
    })->values()->all();
@endphp

{{--
    Bloc "Booster un autre fil" — un segment de promotion sera inséré au milieu du nouveau fil.
    Sur X et Bluesky, devient un quote natif (embed visuel) ; sur Threads et autres, un simple lien.
--}}
<div x-data="boostSection({
    enabled: {{ $existingBoost ? 'true' : 'false' }},
    sourceThreadId: {{ $existingBoost['source_thread_id'] ?? 'null' }},
    promoText: @js($existingBoost['promo_text'] ?? ''),
    boostable: {{ json_encode($boostableData) }},
})" class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">

    <label class="flex items-start gap-3 cursor-pointer">
        <input type="checkbox" x-model="enabled" class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
        <div class="flex-1">
            <div class="flex items-center gap-2">
                <span class="text-sm font-semibold text-gray-900">Booster un autre fil</span>
                <span class="text-xs px-2 py-0.5 rounded bg-indigo-50 text-indigo-700 font-medium">optionnel</span>
            </div>
            <p class="text-xs text-gray-500 mt-1">
                Insère un segment au milieu de ce fil qui fait la promotion d'un autre fil publié.
                Sur X et Bluesky, le segment cite le fil source (embed visuel). Sur Threads et autres, le lien est ajouté.
            </p>
        </div>
    </label>

    <div x-show="enabled" x-transition class="mt-4 space-y-4 pt-4 border-t border-gray-100">

        @if(empty($boostableData))
            <div class="p-3 bg-yellow-50 border border-yellow-200 rounded-xl text-sm text-yellow-800">
                Aucun fil publié disponible pour servir de source. Publiez d'abord un fil et son premier segment.
            </div>
        @else
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Fil source à promouvoir <span class="text-red-500">*</span>
                    <span class="ml-1 text-xs font-normal text-gray-500"
                          x-show="checkedAccountIds.length > 0"
                          x-text="'(filtré selon ' + checkedAccountIds.length + ' compte(s) coché(s))'"></span>
                </label>
                <select x-model.number="sourceThreadId" name="boost[source_thread_id]"
                        :disabled="!enabled"
                        class="block w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    <option :value="null">-- Choisir un fil --</option>
                    <template x-for="bt in filteredBoostable" :key="bt.id">
                        <option :value="bt.id" x-text="bt.label"></option>
                    </template>
                </select>
                <p class="text-xs text-gray-500 mt-1" x-show="checkedAccountIds.length > 0 && filteredBoostable.length === 0">
                    Aucun fil publié sur les comptes sélectionnés. Décochez un compte ou choisissez d'autres comptes.
                </p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Texte de promotion <span class="text-red-500">*</span></label>
                <textarea x-model="promoText" name="boost[promo_text]" rows="2"
                          :disabled="!enabled"
                          class="block w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                          placeholder="Ex: Si ce sujet vous intéresse, j'avais aussi parlé de ça il y a quelques mois ⤵️"></textarea>
                <p class="text-xs text-gray-500 mt-1">
                    Ce texte sera publié dans un segment au milieu du fil, suivi du lien vers le fil source.
                </p>
            </div>
        @endif
    </div>
</div>

@once
@push('scripts')
<script>
function boostSection(config) {
    return {
        enabled: config.enabled,
        sourceThreadId: config.sourceThreadId,
        promoText: config.promoText,
        boostable: config.boostable || [],
        checkedAccountIds: [],

        init() {
            this.refreshCheckedAccounts();
            // Synchronise quand l'utilisateur coche/décoche un compte dans le formulaire.
            document.addEventListener('change', (e) => {
                if (e.target && e.target.matches('input[name="accounts[]"]')) {
                    this.refreshCheckedAccounts();
                }
            });
        },

        refreshCheckedAccounts() {
            this.checkedAccountIds = [...document.querySelectorAll('input[name="accounts[]"]:checked')]
                .map(el => parseInt(el.value, 10));
        },

        get filteredBoostable() {
            if (this.checkedAccountIds.length === 0) {
                return this.boostable;
            }
            const checked = new Set(this.checkedAccountIds);
            return this.boostable.filter(bt =>
                Array.isArray(bt.account_ids) && bt.account_ids.some(id => checked.has(id))
            );
        },
    };
}
</script>
@endpush
@endonce
