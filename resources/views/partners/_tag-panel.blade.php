{{--
    Panneau de tag partenaires d'une publication ou d'un fil, sur sa fiche.

    Utilisable **quel que soit le statut**, y compris sur du déjà publié : le tag est
    une métadonnée interne de compte rendu, pas du contenu. C'est pourquoi il passe
    par sa propre route (`posts.partners.update` / `threads.partners.update`) et non
    par le formulaire d'édition, qui lui refuse les contenus publiés.

    Variables attendues : $taggable (Post|Thread chargé avec `partners`),
    $action (URL du PUT), $partnerOptions (liste des partenaires actifs).
--}}
@php
    $partnerOptions = $partnerOptions ?? [];
    $autoPartners = $taggable->partners->where('pivot.source', 'auto');
    $manualIds = $taggable->partners->where('pivot.source', 'manual')->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
@endphp

<div x-data="{
    editing: false,
    partnerOptions: @js($partnerOptions),
    manualIds: @js($manualIds),
    initialIds: @js($manualIds),
    get manualPartners() {
        return this.partnerOptions.filter(p => this.manualIds.includes(p.id));
    },
    get dirty() {
        return this.manualIds.length !== this.initialIds.length
            || this.manualIds.some(id => ! this.initialIds.includes(id));
    },
    toggle(id) {
        const i = this.manualIds.indexOf(id);
        if (i === -1) { this.manualIds.push(id); } else { this.manualIds.splice(i, 1); }
    },
    cancel() {
        this.manualIds = [...this.initialIds];
        this.editing = false;
    },
}">
    <div class="flex items-center justify-between mb-2">
        <h3 class="text-sm font-medium text-gray-500">
            Partenaires
            <span class="ml-1 text-xs font-normal text-gray-400">— interne, jamais publié</span>
        </h3>
        @if(! empty($partnerOptions))
            <button type="button" @click="editing ? cancel() : editing = true"
                    class="text-xs font-medium text-indigo-600 hover:text-indigo-700">
                <span x-text="editing ? 'Annuler' : 'Modifier'"></span>
            </button>
        @endif
    </div>

    {{-- Lecture --}}
    <div x-show="! editing">
        @if($taggable->partners->isEmpty())
            <p class="text-sm text-gray-300">Aucun partenaire tagué</p>
        @else
            <div class="flex flex-wrap gap-1.5">
                @foreach($taggable->partners as $partner)
                    @php $isAuto = $partner->pivot->source === 'auto'; @endphp
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium border {{ $isAuto ? 'bg-amber-50 text-amber-800 border-amber-200' : 'bg-indigo-50 text-indigo-700 border-indigo-200' }}"
                          title="{{ $isAuto ? 'Hérité d\'une photo taguée' : 'Ajouté manuellement' }}">
                        <span class="w-2 h-2 rounded-full" style="background-color: {{ $partner->color }}"></span>
                        @if(auth()->user()->isManager())
                            <a href="{{ route('partners.posts', $partner) }}" class="hover:underline">{{ $partner->name }}</a>
                        @else
                            {{ $partner->name }}
                        @endif
                    </span>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Édition --}}
    <form x-show="editing" x-cloak method="POST" action="{{ $action }}" class="rounded-xl border border-gray-200 p-4 space-y-3">
        @csrf
        @method('PUT')

        @if($autoPartners->isNotEmpty())
            <div>
                <p class="text-xs text-gray-400 mb-1.5">
                    Hérités des photos — non modifiables ici, ils suivent les photos attachées
                </p>
                <div class="flex flex-wrap gap-1.5">
                    @foreach($autoPartners as $partner)
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium bg-amber-50 text-amber-800 border border-amber-200">
                            <span class="w-2 h-2 rounded-full" style="background-color: {{ $partner->color }}"></span>
                            {{ $partner->name }}
                        </span>
                    @endforeach
                </div>
            </div>
        @endif

        <div>
            <p class="text-xs text-gray-400 mb-1.5">Ajoutés manuellement</p>
            <div class="max-h-56 overflow-y-auto space-y-1">
                <template x-for="p in partnerOptions" :key="'opt-' + p.id">
                    <label class="flex items-center gap-2.5 px-2 py-1.5 rounded-lg hover:bg-gray-50 cursor-pointer">
                        <input type="checkbox" :checked="manualIds.includes(p.id)" @change="toggle(p.id)"
                               class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        <span class="w-2 h-2 rounded-full flex-shrink-0" :style="'background-color:' + (p.color || '#6366f1')"></span>
                        <span class="text-sm text-gray-700" x-text="p.name"></span>
                    </label>
                </template>
            </div>
        </div>

        <template x-for="id in manualIds" :key="'input-' + id">
            <input type="hidden" name="partners[]" :value="id">
        </template>

        <div class="flex items-center gap-3 pt-1">
            <button type="submit" :disabled="! dirty"
                    class="px-4 py-1.5 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
                Enregistrer
            </button>
            <button type="button" @click="cancel()" class="text-sm text-gray-500 hover:text-gray-700">Annuler</button>
        </div>
    </form>
</div>
