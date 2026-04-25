@extends('layouts.app')

@section('title', 'Photos à classer')

@section('content')
<div class="px-6 py-8 max-w-7xl mx-auto"
     x-data="{
        items: @js($items->items()),
        loading: {},
        toast: '',
        toastTimer: null,
        showToast(msg) {
            this.toast = msg;
            clearTimeout(this.toastTimer);
            this.toastTimer = setTimeout(() => this.toast = '', 2500);
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
                const labels = { wildycaro: 'Wildycaro', pdc_vantour: 'PdC / Vantour', mamawette: 'Mamawette (privé)', both_public: 'PdC + Wildycaro', never_publish: 'Jamais publier' };
                this.showToast(`Photo classée : ${labels[action]}`);
            } catch (e) {
                this.showToast('Erreur : ' + e.message);
            } finally {
                delete this.loading[id];
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
        <a href="{{ route('media.index') }}" class="text-sm text-indigo-600 hover:underline">← Retour à la médiathèque</a>
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
                <div class="bg-white border border-gray-200 rounded-lg overflow-hidden flex flex-col">
                    <div class="aspect-square bg-gray-100 relative">
                        <img :src="item.url" :alt="item.original_name || ''" class="w-full h-full object-cover">
                        <span x-show="item.pool_suggested"
                              class="absolute top-2 right-2 bg-indigo-600 text-white text-[10px] px-2 py-1 rounded-full uppercase tracking-wide"
                              x-text="'IA suggère: ' + item.pool_suggested"></span>
                        <span x-show="item.pending_analysis"
                              class="absolute top-2 left-2 bg-amber-400 text-amber-900 text-[10px] px-2 py-1 rounded-full uppercase tracking-wide">
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
                                    :disabled="loading[item.id]"
                                    class="bg-emerald-500 hover:bg-emerald-600 disabled:opacity-50 text-white text-xs font-medium py-2 rounded">
                                <span x-show="loading[item.id] !== 'pdc_vantour'">PdC / Vantour</span>
                                <span x-show="loading[item.id] === 'pdc_vantour'">…</span>
                            </button>
                            <button @click="classify(item.id, 'wildycaro')"
                                    :disabled="loading[item.id]"
                                    class="bg-rose-500 hover:bg-rose-600 disabled:opacity-50 text-white text-xs font-medium py-2 rounded">
                                <span x-show="loading[item.id] !== 'wildycaro'">Wildycaro</span>
                                <span x-show="loading[item.id] === 'wildycaro'">…</span>
                            </button>
                            <button @click="classify(item.id, 'both_public')"
                                    :disabled="loading[item.id]"
                                    class="bg-indigo-500 hover:bg-indigo-600 disabled:opacity-50 text-white text-xs font-medium py-2 rounded col-span-2">
                                <span x-show="loading[item.id] !== 'both_public'">PdC + Wildycaro (public)</span>
                                <span x-show="loading[item.id] === 'both_public'">…</span>
                            </button>
                            <button @click="classify(item.id, 'mamawette')"
                                    :disabled="loading[item.id]"
                                    class="bg-purple-700 hover:bg-purple-800 disabled:opacity-50 text-white text-xs font-medium py-2 rounded col-span-2">
                                <span x-show="loading[item.id] !== 'mamawette'">🔒 Mamawette (compte privé)</span>
                                <span x-show="loading[item.id] === 'mamawette'">…</span>
                            </button>
                            <button @click="classify(item.id, 'never_publish')"
                                    :disabled="loading[item.id]"
                                    class="bg-gray-700 hover:bg-gray-800 disabled:opacity-50 text-white text-xs font-medium py-2 rounded col-span-2">
                                <span x-show="loading[item.id] !== 'never_publish'">Jamais publier</span>
                                <span x-show="loading[item.id] === 'never_publish'">…</span>
                            </button>
                        </div>

                        <button @click="deleteItem(item.id)"
                                :disabled="loading[item.id]"
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

    <div x-show="toast"
         x-transition.opacity
         class="fixed bottom-6 right-6 bg-gray-900 text-white text-sm px-4 py-2 rounded shadow-lg"
         x-text="toast"></div>
</div>
@endsection
