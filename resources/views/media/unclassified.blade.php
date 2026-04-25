@extends('layouts.app')

@section('title', 'Photos à classer')

@section('content')
<div class="px-6 pt-8 pb-32 max-w-7xl mx-auto"
     x-data="{
        items: @js($items->items()),
        loading: {},
        selected: [],
        batchLoading: '',
        toast: '',
        toastTimer: null,
        showToast(msg) {
            this.toast = msg;
            clearTimeout(this.toastTimer);
            this.toastTimer = setTimeout(() => this.toast = '', 2500);
        },
        toggleSelect(id) {
            const idx = this.selected.indexOf(id);
            if (idx === -1) this.selected.push(id);
            else this.selected.splice(idx, 1);
        },
        selectAllVisible() {
            if (this.selected.length === this.items.length) this.selected = [];
            else this.selected = this.items.map(i => i.id);
        },
        clearSelection() {
            this.selected = [];
        },
        async classify(id, action) {
            this.loading[id] = action;
            try {
                const res = await fetch(`/media/${id}/classify`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ action }),
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                this.items = this.items.filter(i => i.id !== id);
                this.selected = this.selected.filter(s => s !== id);
                const labels = { wildycaro: 'Wildycaro', pdc_vantour: 'PdC / Vantour', mamawette: 'Mamawette (privé)', never_publish: 'Jamais publier' };
                this.showToast(`Photo classée : ${labels[action]}`);
            } catch (e) {
                this.showToast('Erreur : ' + e.message);
            } finally {
                delete this.loading[id];
            }
        },
        async classifyBatch(action) {
            if (!this.selected.length) return;
            const ids = [...this.selected];
            this.batchLoading = action;
            try {
                const res = await fetch('/media/classify-batch', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ ids, action }),
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                this.items = this.items.filter(i => !ids.includes(i.id));
                this.selected = [];
                const labels = { wildycaro: 'Wildycaro', pdc_vantour: 'PdC / Vantour', mamawette: 'Mamawette (privé)', never_publish: 'Jamais publier' };
                this.showToast(`${ids.length} photo(s) classée(s) : ${labels[action]}`);
            } catch (e) {
                this.showToast('Erreur batch : ' + e.message);
            } finally {
                this.batchLoading = '';
            }
        },
        async deleteItem(id) {
            if (!confirm('Supprimer cette photo définitivement ?')) return;
            const item = this.items.find(i => i.id === id);
            if (!item) return;
            this.loading[id] = 'delete';
            try {
                const res = await fetch(`/media/${item.filename}`, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                this.items = this.items.filter(i => i.id !== id);
                this.showToast('Photo supprimée');
            } catch (e) {
                this.showToast('Erreur : ' + e.message);
            } finally {
                delete this.loading[id];
            }
        },
     }">

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Photos à classer</h1>
            <p class="text-sm text-gray-500 mt-1">
                {{ $totalUnclassified }} photo(s) en attente de classement.
                Choisissez un pool pour rendre une photo publiable, ou « Jamais publier » pour la verrouiller.
            </p>
        </div>
        <div class="flex items-center gap-4">
            <button @click="selectAllVisible"
                    x-show="items.length"
                    class="text-sm text-indigo-600 hover:underline">
                <span x-show="selected.length !== items.length">Tout sélectionner</span>
                <span x-show="selected.length === items.length && items.length">Tout désélectionner</span>
            </button>
            <a href="{{ route('media.index') }}" class="text-sm text-indigo-600 hover:underline">← Retour à la médiathèque</a>
        </div>
    </div>

    @if ($items->isEmpty())
        <div class="bg-white border border-gray-200 rounded-lg p-12 text-center">
            <div class="text-5xl mb-3">✓</div>
            <p class="text-gray-700 font-medium">Tout est classé.</p>
            <p class="text-sm text-gray-500 mt-1">Aucune photo n'attend de pool pour le moment.</p>
        </div>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            <template x-for="item in items" :key="item.id">
                <div class="bg-white border-2 rounded-lg overflow-hidden flex flex-col transition-colors"
                     :class="selected.includes(item.id) ? 'border-indigo-600' : 'border-gray-200'">
                    <div class="aspect-square bg-gray-100 relative cursor-pointer"
                         @click="toggleSelect(item.id)">
                        <img :src="item.url" :alt="item.original_name || ''" class="w-full h-full object-cover">
                        <div class="absolute top-2 left-2 w-7 h-7 rounded-md flex items-center justify-center shadow-md transition-colors pointer-events-none"
                             :class="selected.includes(item.id) ? 'bg-indigo-600 border-2 border-indigo-600' : 'bg-white/90 border-2 border-gray-300'">
                            <svg x-show="selected.includes(item.id)" class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                            </svg>
                        </div>
                        <span x-show="item.pool_suggested"
                              class="absolute top-2 right-2 bg-indigo-600 text-white text-[10px] px-2 py-1 rounded-full uppercase tracking-wide"
                              x-text="'IA suggère: ' + item.pool_suggested"></span>
                        <span x-show="item.pending_analysis"
                              class="absolute bottom-2 right-2 bg-amber-400 text-amber-900 text-[10px] px-2 py-1 rounded-full uppercase tracking-wide">
                            Analyse en attente
                        </span>
                    </div>

                    <div class="p-3 flex-1 flex flex-col gap-2">
                        <p class="text-xs text-gray-700 line-clamp-2" x-text="item.description_fr || item.original_name || item.filename"></p>

                        <template x-if="item.thematic_tags && item.thematic_tags.length">
                            <div class="flex flex-wrap gap-1">
                                <template x-for="tag in item.thematic_tags.slice(0, 4)" :key="tag">
                                    <span class="bg-gray-100 text-gray-700 text-[10px] px-2 py-0.5 rounded" x-text="tag"></span>
                                </template>
                            </div>
                        </template>

                        <template x-if="item.people_ids && item.people_ids.length">
                            <div class="flex flex-wrap gap-1">
                                <template x-for="p in item.people_ids" :key="p">
                                    <span class="bg-pink-100 text-pink-800 text-[10px] px-2 py-0.5 rounded" x-text="p"></span>
                                </template>
                            </div>
                        </template>

                        <div class="grid grid-cols-2 gap-1 mt-auto pt-2">
                            <button @click="classify(item.id, 'pdc_vantour')"
                                    :class="loading[item.id] ? 'opacity-50 pointer-events-none' : ''"
                                    class="bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-medium py-2 rounded">
                                <span x-show="loading[item.id] !== 'pdc_vantour'">PdC / Vantour</span>
                                <span x-show="loading[item.id] === 'pdc_vantour'">…</span>
                            </button>
                            <button @click="classify(item.id, 'wildycaro')"
                                    :class="loading[item.id] ? 'opacity-50 pointer-events-none' : ''"
                                    class="bg-rose-500 hover:bg-rose-600 text-white text-xs font-medium py-2 rounded">
                                <span x-show="loading[item.id] !== 'wildycaro'">Wildycaro</span>
                                <span x-show="loading[item.id] === 'wildycaro'">…</span>
                            </button>
                            <button @click="classify(item.id, 'mamawette')"
                                    :class="loading[item.id] ? 'opacity-50 pointer-events-none' : ''"
                                    class="bg-purple-700 hover:bg-purple-800 text-white text-xs font-medium py-2 rounded col-span-2">
                                <span x-show="loading[item.id] !== 'mamawette'">🔒 Mamawette (compte privé)</span>
                                <span x-show="loading[item.id] === 'mamawette'">…</span>
                            </button>
                            <button @click="classify(item.id, 'never_publish')"
                                    :class="loading[item.id] ? 'opacity-50 pointer-events-none' : ''"
                                    class="bg-gray-700 hover:bg-gray-800 text-white text-xs font-medium py-2 rounded col-span-2">
                                <span x-show="loading[item.id] !== 'never_publish'">Jamais publier</span>
                                <span x-show="loading[item.id] === 'never_publish'">…</span>
                            </button>
                        </div>

                        <button @click="deleteItem(item.id)"
                                :class="loading[item.id] ? 'opacity-50 pointer-events-none' : ''"
                                class="text-[11px] text-gray-400 hover:text-red-600 mt-1 self-end">
                            Supprimer
                        </button>
                    </div>
                </div>
            </template>
        </div>

        <div class="mt-6">
            {{ $items->links() }}
        </div>
    @endif

    <div x-show="selected.length"
         x-transition.opacity
         class="fixed bottom-0 left-0 right-0 bg-white border-t border-gray-200 shadow-lg z-40">
        <div class="max-w-7xl mx-auto px-6 py-3 flex flex-wrap items-center gap-3">
            <span class="font-semibold text-sm">
                <span x-text="selected.length"></span> photo(s) sélectionnée(s)
            </span>
            <button @click="clearSelection" class="text-xs text-gray-500 hover:text-gray-700 underline">
                Annuler
            </button>
            <div class="flex-1"></div>
            <span class="text-xs text-gray-500 mr-1">Classer dans :</span>
            <button @click="classifyBatch('pdc_vantour')"
                    :class="batchLoading ? 'opacity-50 pointer-events-none' : ''"
                    class="bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-medium px-3 py-2 rounded">
                <span x-show="batchLoading !== 'pdc_vantour'">PdC / Vantour</span>
                <span x-show="batchLoading === 'pdc_vantour'">…</span>
            </button>
            <button @click="classifyBatch('wildycaro')"
                    :class="batchLoading ? 'opacity-50 pointer-events-none' : ''"
                    class="bg-rose-500 hover:bg-rose-600 text-white text-xs font-medium px-3 py-2 rounded">
                <span x-show="batchLoading !== 'wildycaro'">Wildycaro</span>
                <span x-show="batchLoading === 'wildycaro'">…</span>
            </button>
            <button @click="classifyBatch('mamawette')"
                    :class="batchLoading ? 'opacity-50 pointer-events-none' : ''"
                    class="bg-purple-700 hover:bg-purple-800 text-white text-xs font-medium px-3 py-2 rounded">
                <span x-show="batchLoading !== 'mamawette'">🔒 Mamawette</span>
                <span x-show="batchLoading === 'mamawette'">…</span>
            </button>
            <button @click="classifyBatch('never_publish')"
                    :class="batchLoading ? 'opacity-50 pointer-events-none' : ''"
                    class="bg-gray-700 hover:bg-gray-800 text-white text-xs font-medium px-3 py-2 rounded">
                <span x-show="batchLoading !== 'never_publish'">Jamais publier</span>
                <span x-show="batchLoading === 'never_publish'">…</span>
            </button>
        </div>
    </div>

    <div x-show="toast"
         x-transition.opacity
         class="fixed bottom-24 right-6 bg-gray-900 text-white text-sm px-4 py-2 rounded shadow-lg z-50"
         x-text="toast"></div>
</div>
@endsection
