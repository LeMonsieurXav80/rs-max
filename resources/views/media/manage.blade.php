@extends('layouts.app')

@section('title', 'Edition en masse')

@section('content')
    @php
        // Construit un arbre dossiers : depth-first walk pour avoir parent → enfants juste après,
        // avec parent_chain pour pouvoir masquer/afficher selon l'état d'ouverture des ancêtres.
        $foldersById = $folders->keyBy('id');
        $childrenByParent = $folders->groupBy('parent_id');

        $parentChain = function ($f) use ($foldersById) {
            $chain = [];
            $cursor = $f->parent_id;
            while ($cursor && count($chain) < 10) {
                $chain[] = $cursor;
                $cursor = $foldersById->get($cursor)?->parent_id;
            }
            return $chain;
        };

        $flatTree = collect();
        $walk = function ($parentId, $depth) use (&$walk, $childrenByParent, $parentChain, &$flatTree) {
            $children = $childrenByParent->get($parentId, collect())->sortBy('name')->values();
            foreach ($children as $f) {
                $hasChildren = $childrenByParent->has($f->id) && $childrenByParent->get($f->id)->isNotEmpty();
                $flatTree->push([
                    'id' => $f->id,
                    'name' => $f->name,
                    'parent_id' => $f->parent_id,
                    'parent_chain' => $parentChain($f),
                    'depth' => $depth,
                    'has_children' => $hasChildren,
                    'color' => $f->color,
                    'files_count' => $f->files_count,
                ]);
                if ($hasChildren) {
                    $walk($f->id, $depth + 1);
                }
            }
        };
        $walk(null, 0);
        $foldersJson = $flatTree->values()->toArray();

        // Auto-ouvre les ancêtres du dossier sélectionné pour qu'il soit visible au chargement.
        $autoOpenIds = [];
        if ($currentFolder && is_numeric($currentFolder)) {
            $cursor = $foldersById->get((int) $currentFolder)?->parent_id;
            while ($cursor && count($autoOpenIds) < 10) {
                $autoOpenIds[] = $cursor;
                $cursor = $foldersById->get($cursor)?->parent_id;
            }
        }
    @endphp

    <div x-data="bulkManage()" x-init="init()" class="px-6 py-4">
        {{-- Header --}}
        <div class="flex items-center justify-between mb-5">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">Edition en masse</h1>
                <p class="text-xs text-gray-500 mt-0.5">Selectionne plusieurs photos pour leur appliquer des tags, marques, personnes ou des champs structures d'un coup.</p>
            </div>
            <a href="{{ route('media.index') }}" class="text-sm text-indigo-600 hover:text-indigo-800 flex items-center gap-1.5">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
                Retour a la mediatheque
            </a>
        </div>

        <div class="flex gap-5">
            {{-- ═══════════ Sidebar gauche : dossiers + pools ═══════════ --}}
            <aside class="w-64 flex-shrink-0">
                <div class="sticky top-20 max-h-[calc(100vh-6rem)] overflow-y-auto space-y-4 pr-1">
                    {{-- Dossiers (arbre cliquable) --}}
                    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-3"
                         x-data="folderTree({{ json_encode($foldersJson) }}, {{ json_encode($autoOpenIds) }})">
                        <h3 class="text-[10px] font-semibold uppercase tracking-widest text-gray-400 px-2 pb-2">Dossiers</h3>
                        <a href="{{ route('media.manage', request()->only('pool')) }}" class="flex items-center justify-between px-2 py-1.5 rounded-lg text-sm transition-colors {{ ! $currentFolder ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-gray-600 hover:bg-gray-50' }}">
                            <span>Tous les dossiers</span>
                            <span class="text-xs text-gray-400">{{ $totalCount }}</span>
                        </a>
                        <a href="{{ route('media.manage', array_merge(request()->only('pool'), ['folder' => 'uncategorized'])) }}" class="flex items-center justify-between px-2 py-1.5 rounded-lg text-sm transition-colors {{ $currentFolder === 'uncategorized' ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-gray-600 hover:bg-gray-50' }}">
                            <span class="text-gray-500 italic">Sans dossier</span>
                            <span class="text-xs text-gray-400">{{ $uncategorizedCount }}</span>
                        </a>
                        <template x-for="f in folders" :key="f.id">
                            <div x-show="isVisible(f)" class="flex items-center group" :style="`padding-left: ${f.depth * 12}px`">
                                {{-- Chevron : toggle expand si has_children, sinon spacer --}}
                                <button x-show="f.has_children" @click.stop="toggle(f.id)" type="button"
                                        class="w-4 h-4 flex items-center justify-center text-gray-400 hover:text-gray-700 flex-shrink-0">
                                    <svg class="w-3 h-3 transition-transform" :class="isOpen(f.id) && 'rotate-90'" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                                </button>
                                <span x-show="!f.has_children" class="w-4 h-4 flex-shrink-0"></span>
                                {{-- Lien navigation --}}
                                <a :href="`{{ route('media.manage') }}?folder=${f.id}{{ $currentPool ? '&pool='.urlencode($currentPool) : '' }}`"
                                   class="flex-1 flex items-center justify-between px-2 py-1.5 rounded-lg text-sm transition-colors min-w-0"
                                   :class="String({{ json_encode($currentFolder) }}) === String(f.id) ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-gray-600 hover:bg-gray-50'">
                                    <span class="flex items-center gap-2 min-w-0 truncate">
                                        <span class="w-2 h-2 rounded-sm flex-shrink-0" :style="`background-color: ${f.color || '#9ca3af'}`"></span>
                                        <span class="truncate" x-text="f.name"></span>
                                    </span>
                                    <span class="text-xs text-gray-400 flex-shrink-0" x-text="f.files_count"></span>
                                </a>
                            </div>
                        </template>
                    </div>

                    {{-- Pools --}}
                    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-3">
                        <h3 class="text-[10px] font-semibold uppercase tracking-widest text-gray-400 px-2 pb-2">Pools</h3>
                        <a href="{{ route('media.manage') }}" class="flex items-center justify-between px-2 py-1.5 rounded-lg text-sm transition-colors {{ ! $currentPool ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-gray-600 hover:bg-gray-50' }}">
                            <span>Tous</span>
                            <span class="text-xs text-gray-400">{{ $totalCount }}</span>
                        </a>
                        @foreach (['pdc_vantour' => 'PdC / Vantour', 'wildycaro' => 'Wildycaro', 'mamawette' => 'Mamawette', 'unclassified' => 'A classer', 'never_publish' => 'Jamais publier'] as $slug => $label)
                            <a href="{{ route('media.manage', array_merge(request()->only('folder'), ['pool' => $slug])) }}"
                               class="flex items-center justify-between px-2 py-1.5 rounded-lg text-sm transition-colors {{ $currentPool === $slug ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-gray-600 hover:bg-gray-50' }}">
                                <span>{{ $label }}</span>
                                <span class="text-xs text-gray-400">{{ $poolCounts[$slug] ?? 0 }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            </aside>

            {{-- ═══════════ Centre : grille avec checkboxes ═══════════ --}}
            <main class="flex-1 min-w-0">
                {{-- Selection bar --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm px-4 py-3 mb-4 flex items-center gap-3 flex-wrap">
                    <button type="button" @click="toggleAll()" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        <span x-text="selectedIds.length === items.length && items.length > 0 ? 'Tout deselectionner' : 'Tout selectionner'"></span>
                    </button>
                    <span class="text-xs text-gray-300">·</span>
                    <span class="text-sm text-gray-600">
                        <span x-text="selectedIds.length"></span> / <span x-text="items.length"></span> selectionne(s)
                    </span>
                    <template x-if="selectedIds.length > 0">
                        <button type="button" @click="selectedIds = []" class="ml-auto text-xs text-gray-500 hover:text-red-600">Effacer la selection</button>
                    </template>
                </div>

                {{-- Grid --}}
                @if (count($items) === 0)
                    <div class="bg-white rounded-2xl border border-dashed border-gray-200 p-12 text-center">
                        <p class="text-sm text-gray-500">Aucun media dans ce filtre.</p>
                    </div>
                @else
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
                        <template x-for="item in items" :key="item.id">
                            <div @click="toggle(item.id, $event)"
                                 class="bg-white rounded-xl border-2 overflow-hidden cursor-pointer transition-all shadow-sm hover:shadow-md relative"
                                 :class="isSelected(item.id) ? 'border-amber-400 ring-2 ring-amber-200' : 'border-gray-100 hover:border-gray-300'">
                                <div class="aspect-[4/5] bg-gray-100 relative overflow-hidden">
                                    {{-- Checkbox --}}
                                    <div class="absolute top-1.5 right-1.5 z-10">
                                        <div class="w-5 h-5 rounded-full border-2 flex items-center justify-center transition-colors"
                                             :class="isSelected(item.id) ? 'bg-amber-500 border-amber-500' : 'bg-white/80 border-gray-300 backdrop-blur-sm'">
                                            <svg x-show="isSelected(item.id)" class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                        </div>
                                    </div>
                                    {{-- Folder badge --}}
                                    <template x-if="item.folder_color && !{{ $currentFolder ? 'true' : 'false' }}">
                                        <div class="absolute top-1.5 left-1.5 flex items-center gap-1 px-1.5 py-0.5 bg-black/60 rounded text-[10px] text-white">
                                            <span class="w-1.5 h-1.5 rounded-sm flex-shrink-0" :style="'background-color: ' + item.folder_color"></span>
                                            <span x-text="item.folder_name" class="truncate max-w-[80px]"></span>
                                        </div>
                                    </template>
                                    {{-- Image --}}
                                    <template x-if="item.is_image">
                                        <img :src="item.url" :alt="item.filename" class="w-full h-full object-cover" loading="lazy">
                                    </template>
                                    <template x-if="item.is_video">
                                        <div class="w-full h-full relative">
                                            <img :src="item.thumbnail_url" :alt="item.filename" class="w-full h-full object-cover" loading="lazy">
                                            <div class="absolute inset-0 flex items-center justify-center">
                                                <div class="w-8 h-8 rounded-full bg-black/50 flex items-center justify-center">
                                                    <svg class="w-4 h-4 text-white ml-0.5" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                                {{-- Description editable + tags preview --}}
                                <div class="px-2 py-1.5 space-y-1">
                                    {{-- Description : click to edit --}}
                                    <div @click.stop="editingDescriptionId === item.id ? null : startEditDescription(item.id)"
                                         x-show="editingDescriptionId !== item.id"
                                         class="text-[11px] text-gray-700 leading-tight line-clamp-2 cursor-text hover:bg-gray-50 rounded px-1 -mx-1 min-h-[2em]"
                                         :class="!item.description_fr && 'text-gray-400 italic'"
                                         x-text="item.description_fr || 'Cliquer pour ajouter une description'">
                                    </div>
                                    <textarea x-show="editingDescriptionId === item.id"
                                              :ref="`desc-${item.id}`"
                                              x-model="item.description_fr"
                                              @click.stop
                                              @blur="saveDescription(item.id)"
                                              @keydown.escape.stop="editingDescriptionId = null"
                                              @keydown.enter.meta.stop.prevent="$event.target.blur()"
                                              rows="3"
                                              class="w-full text-[11px] text-gray-700 rounded border-indigo-300 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 px-1 py-0.5 resize-none"
                                              placeholder="Description de la photo"></textarea>
                                    {{-- Tags --}}
                                    <p class="text-[10px] text-gray-400 truncate" x-text="(item.thematic_tags || []).join(', ') || '—'"></p>
                                </div>
                            </div>
                        </template>
                    </div>
                @endif
            </main>

            {{-- ═══════════ Sidebar droite : toolbar bulk-edit ═══════════ --}}
            <aside class="w-[340px] flex-shrink-0 self-start">
                <div class="sticky top-20 max-h-[calc(100vh-6rem)] overflow-y-auto space-y-2 pr-1">
                    {{-- Pas de selection --}}
                    <div x-show="selectedIds.length === 0" class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 text-center">
                        <p class="text-xs text-gray-400">Selectionne des photos pour activer l'edition en masse.<br><span class="text-[10px]">Astuce : <kbd class="px-1 py-0.5 bg-gray-100 rounded">Shift</kbd> + clic = selection par plage.</span></p>
                    </div>

                    {{-- Toolbar --}}
                    <div x-show="selectedIds.length > 0" x-cloak class="space-y-2">
                        {{-- IA — Génération automatique des métadonnées --}}
                        <div class="bg-gradient-to-br from-violet-50 to-indigo-50 rounded-xl border border-violet-200 shadow-sm p-3 space-y-2">
                            <div>
                                <label class="text-[10px] font-semibold text-violet-900 uppercase tracking-wider block mb-1">Contexte (optionnel)</label>
                                <textarea x-model="aiContext" rows="2" placeholder="Ex: voyage Portugal 2024, shooting nature, session lingerie..." class="w-full text-xs rounded-lg border-violet-200 focus:border-violet-400 focus:ring-1 focus:ring-violet-400 px-2 py-1.5 bg-white resize-none"></textarea>
                                <p class="text-[10px] text-violet-700 mt-0.5">Aide l'IA a mieux choisir les tags (lieu, theme, ambiance). Si vide, le nom du dossier est utilise.</p>
                            </div>
                            <button @click="runAiAnalysis()" :disabled="busy || aiInProgress" class="w-full px-3 py-2 text-xs bg-violet-600 text-white rounded-lg disabled:opacity-50 hover:bg-violet-700 font-medium flex items-center justify-center gap-1.5" :title="`Analyse Vision API. Remplit description, tags, personnes, lieu, marques. ~$${(selectedIds.length * 0.005).toFixed(3)}.`">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" /></svg>
                                <span x-show="!aiInProgress">Generer avec IA · <span x-text="selectedIds.length"></span> photo(s)</span>
                                <span x-show="aiInProgress">Analyse en cours...</span>
                            </button>
                            <div x-show="aiInProgress || aiTotal > 0" x-cloak class="mt-1.5">
                                <div class="flex items-center justify-between text-[10px] text-violet-700 mb-0.5">
                                    <span>
                                        <span x-text="aiDone"></span> / <span x-text="aiTotal"></span>
                                        <span x-show="aiErrors > 0" class="text-rose-600">· <span x-text="aiErrors"></span> err</span>
                                    </span>
                                    <span x-show="aiCurrentName" class="truncate ml-2 max-w-[180px]" x-text="aiCurrentName"></span>
                                </div>
                                <div class="w-full h-1 bg-violet-100 rounded-full overflow-hidden">
                                    <div class="h-full bg-violet-500 transition-all" :style="`width: ${aiTotal ? (aiDone / aiTotal * 100) : 0}%`"></div>
                                </div>
                            </div>
                        </div>

                        {{-- Tags --}}
                        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-3">
                            <div class="flex items-center justify-between mb-1.5">
                                <h3 class="text-[11px] font-semibold text-gray-700 uppercase tracking-wider">Tags</h3>
                                <span x-show="tagChips.length > 0" class="text-[10px] text-gray-400" x-text="`${tagChips.length} dans la selection`"></span>
                            </div>
                            <div class="flex gap-1.5">
                                <input type="text" x-model="tagAddInput" @keyup.enter="bulkTags('add')" placeholder="Ajouter (plage, mer, ...)" class="flex-1 text-xs rounded-lg border-gray-200 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400 px-2 py-1.5">
                                <button @click="bulkTags('add')" :disabled="busy || !tagAddInput.trim()" class="px-3 py-1.5 text-xs bg-indigo-600 text-white rounded-lg disabled:opacity-50 hover:bg-indigo-700">+</button>
                            </div>
                            <template x-if="tagChips.length > 0">
                                <div class="flex flex-wrap gap-1 mt-2 max-h-24 overflow-y-auto">
                                    <template x-for="chip in tagChips" :key="chip.value">
                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 text-[11px] bg-gray-100 hover:bg-rose-50 hover:text-rose-700 rounded group cursor-pointer" @click="removeOne('tags', chip.value)" :title="`Cliquer pour retirer de ${chip.count} photo(s)`">
                                            <span x-text="chip.value"></span>
                                            <span class="text-gray-400 text-[9px]" x-show="chip.count < selectedIds.length" x-text="chip.count"></span>
                                            <span class="text-gray-400 group-hover:text-rose-600">×</span>
                                        </span>
                                    </template>
                                </div>
                            </template>
                        </div>

                        {{-- Brands --}}
                        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-3">
                            <div class="flex items-center justify-between mb-1.5">
                                <h3 class="text-[11px] font-semibold text-gray-700 uppercase tracking-wider">Marques</h3>
                                <span x-show="brandChips.length > 0" class="text-[10px] text-gray-400" x-text="`${brandChips.length} dans la selection`"></span>
                            </div>
                            <div class="flex gap-1.5">
                                <input type="text" x-model="brandAddInput" @keyup.enter="bulkBrands()" placeholder="Ajouter (Decathlon, ...)" class="flex-1 text-xs rounded-lg border-gray-200 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400 px-2 py-1.5">
                                <button @click="bulkBrands()" :disabled="busy || !brandAddInput.trim()" class="px-3 py-1.5 text-xs bg-indigo-600 text-white rounded-lg disabled:opacity-50 hover:bg-indigo-700">+</button>
                            </div>
                            <template x-if="brandChips.length > 0">
                                <div class="flex flex-wrap gap-1 mt-2 max-h-24 overflow-y-auto">
                                    <template x-for="chip in brandChips" :key="chip.value">
                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 text-[11px] bg-gray-100 hover:bg-rose-50 hover:text-rose-700 rounded group cursor-pointer" @click="removeOne('brands', chip.value)" :title="`Cliquer pour retirer de ${chip.count} photo(s)`">
                                            <span x-text="chip.value"></span>
                                            <span class="text-gray-400 text-[9px]" x-show="chip.count < selectedIds.length" x-text="chip.count"></span>
                                            <span class="text-gray-400 group-hover:text-rose-600">×</span>
                                        </span>
                                    </template>
                                </div>
                            </template>
                        </div>

                        {{-- People --}}
                        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-3">
                            <div class="flex items-center justify-between mb-1.5">
                                <h3 class="text-[11px] font-semibold text-gray-700 uppercase tracking-wider">Personnes</h3>
                                <span x-show="peopleChips.length > 0" class="text-[10px] text-gray-400" x-text="`${peopleChips.length} dans la selection`"></span>
                            </div>
                            <div class="flex gap-1.5">
                                <input type="text" x-model="peopleAddInput" @keyup.enter="bulkPeople()" placeholder="caroline, xavier, ..." class="flex-1 text-xs rounded-lg border-gray-200 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400 px-2 py-1.5">
                                <button @click="bulkPeople()" :disabled="busy || !peopleAddInput.trim()" class="px-3 py-1.5 text-xs bg-indigo-600 text-white rounded-lg disabled:opacity-50 hover:bg-indigo-700">+</button>
                            </div>
                            <template x-if="peopleChips.length > 0">
                                <div class="flex flex-wrap gap-1 mt-2 max-h-24 overflow-y-auto">
                                    <template x-for="chip in peopleChips" :key="chip.value">
                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 text-[11px] bg-gray-100 hover:bg-rose-50 hover:text-rose-700 rounded group cursor-pointer" @click="removeOne('people', chip.value)" :title="`Cliquer pour retirer de ${chip.count} photo(s)`">
                                            <span x-text="chip.value"></span>
                                            <span class="text-gray-400 text-[9px]" x-show="chip.count < selectedIds.length" x-text="chip.count"></span>
                                            <span class="text-gray-400 group-hover:text-rose-600">×</span>
                                        </span>
                                    </template>
                                </div>
                            </template>
                        </div>

                        {{-- Description (per-photo : 1 selectionnee = pre-rempli, plusieurs = applique a toutes) --}}
                        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-3">
                            <div class="flex items-center justify-between mb-1.5">
                                <h3 class="text-[11px] font-semibold text-gray-700 uppercase tracking-wider">Description</h3>
                                <span class="text-[10px] text-gray-400" x-show="selectedIds.length === 1" x-text="'1 photo'"></span>
                                <span class="text-[10px] text-gray-400" x-show="selectedIds.length > 1" x-text="`appliquer a ${selectedIds.length} photos`"></span>
                            </div>
                            <textarea x-model="descriptionInput" rows="3"
                                      :placeholder="selectedIds.length === 1 ? 'Description de la photo (texte qui sert de contexte aux IA de redaction)' : `Description commune pour les ${selectedIds.length} photos selectionnees`"
                                      class="w-full text-xs rounded-lg border-gray-200 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400 px-2 py-1.5 resize-none"></textarea>
                            <div class="flex gap-1.5 mt-1.5">
                                <button @click="saveDescription()" :disabled="busy" class="flex-1 px-3 py-1.5 text-xs bg-indigo-600 text-white rounded-lg disabled:opacity-40 hover:bg-indigo-700">Sauvegarder</button>
                                <button @click="descriptionInput = ''; saveDescription()" :disabled="busy" class="px-3 py-1.5 text-xs bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200" title="Vider la description">Vider</button>
                            </div>
                        </div>

                        {{-- Sections collapsibles : Lieu + Classification --}}
                        <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden" x-data="{ open: true }">
                            <button @click="open = !open" class="w-full flex items-center justify-between px-3 py-2 hover:bg-gray-50">
                                <span class="text-[11px] font-semibold text-gray-700 uppercase tracking-wider">Lieu &amp; evenement</span>
                                <svg class="w-3.5 h-3.5 text-gray-400 transition-transform" :class="open && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                            </button>
                            <div x-show="open" x-collapse class="px-3 pb-3 space-y-2 border-t border-gray-100">
                                <div class="grid grid-cols-2 gap-1.5 pt-2">
                                    <input type="text" x-model="details.city" placeholder="Ville" class="text-xs rounded-lg border-gray-200 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400 px-2 py-1.5">
                                    <input type="text" x-model="details.region" placeholder="Region" class="text-xs rounded-lg border-gray-200 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400 px-2 py-1.5">
                                    <input type="text" x-model="details.country" placeholder="Pays" class="text-xs rounded-lg border-gray-200 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400 px-2 py-1.5">
                                    <input type="text" x-model="details.event" placeholder="Evenement" class="text-xs rounded-lg border-gray-200 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400 px-2 py-1.5">
                                </div>
                                <div class="flex gap-1.5">
                                    <button @click="bulkDetails(false)" :disabled="busy || !hasDetailValues()" class="flex-1 px-3 py-1.5 text-xs bg-indigo-600 text-white rounded-lg disabled:opacity-40 hover:bg-indigo-700">Appliquer</button>
                                    <button @click="bulkDetails(true)" :disabled="busy" class="px-3 py-1.5 text-xs bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200" title="Vider ces 4 champs sur les photos selectionnees">Vider</button>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden" x-data="{ open: true }">
                            <button @click="open = !open" class="w-full flex items-center justify-between px-3 py-2 hover:bg-gray-50">
                                <span class="text-[11px] font-semibold text-gray-700 uppercase tracking-wider">Classification</span>
                                <svg class="w-3.5 h-3.5 text-gray-400 transition-transform" :class="open && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                            </button>
                            <div x-show="open" x-collapse class="px-3 pb-3 space-y-2 border-t border-gray-100 text-xs">
                                <div class="pt-2 grid grid-cols-3 gap-1">
                                    <label class="flex items-center gap-1 cursor-pointer p-1 rounded hover:bg-gray-50">
                                        <input type="checkbox" x-model="classification.allow_pdc_vantour" class="rounded border-gray-300">
                                        <span class="text-[10px]">PdC</span>
                                    </label>
                                    <label class="flex items-center gap-1 cursor-pointer p-1 rounded hover:bg-gray-50">
                                        <input type="checkbox" x-model="classification.allow_wildycaro" class="rounded border-gray-300">
                                        <span class="text-[10px]">Wildy</span>
                                    </label>
                                    <label class="flex items-center gap-1 cursor-pointer p-1 rounded hover:bg-gray-50">
                                        <input type="checkbox" x-model="classification.allow_mamawette" class="rounded border-gray-300">
                                        <span class="text-[10px]">🔒 Mama</span>
                                    </label>
                                </div>
                                <select x-model="classification.intimacy_level" class="w-full text-xs rounded-lg border-gray-200 px-2 py-1.5">
                                    <option value="">Intimite : ne pas modifier</option>
                                    <option value="public">Public</option>
                                    <option value="prive">Prive</option>
                                    <option value="never_publish">Jamais publier</option>
                                </select>
                                <button @click="bulkClassification()" :disabled="busy" class="w-full px-3 py-1.5 text-xs bg-indigo-600 text-white rounded-lg disabled:opacity-40 hover:bg-indigo-700">Appliquer</button>
                            </div>
                        </div>

                        {{-- Status / Feedback --}}
                        <div x-show="lastMessage" x-cloak class="bg-emerald-50 border border-emerald-200 text-emerald-700 text-[11px] px-3 py-1.5 rounded-lg" x-text="lastMessage"></div>
                        <div x-show="lastError" x-cloak class="bg-red-50 border border-red-200 text-red-700 text-[11px] px-3 py-1.5 rounded-lg" x-text="lastError"></div>
                    </div>
                </div>
            </aside>
        </div>
    </div>

    <script>
    function folderTree(folders, autoOpenIds) {
        return {
            folders,
            openFolders: [...autoOpenIds],
            isOpen(id) { return this.openFolders.includes(id); },
            toggle(id) {
                const idx = this.openFolders.indexOf(id);
                if (idx >= 0) {
                    // Ferme aussi tous les descendants : sinon ils restent dans openFolders
                    // et reapparaitront si on rouvre le parent.
                    const descendants = new Set([id]);
                    let added;
                    do {
                        added = false;
                        for (const f of this.folders) {
                            if (descendants.has(f.parent_id) && !descendants.has(f.id)) {
                                descendants.add(f.id);
                                added = true;
                            }
                        }
                    } while (added);
                    this.openFolders = this.openFolders.filter(o => !descendants.has(o));
                } else {
                    this.openFolders.push(id);
                }
            },
            // Visible si tous les ancetres sont ouverts (ou si root).
            isVisible(f) {
                return f.parent_chain.every(p => this.openFolders.includes(p));
            },
        };
    }

    function bulkManage() {
        return {
            items: @json($items),
            selectedIds: [],
            lastClickedIdx: null, // pour la selection en plage avec Shift
            busy: false,
            lastMessage: '',
            lastError: '',
            tagAddInput: '',
            brandAddInput: '',
            peopleAddInput: '',
            descriptionInput: '',
            lastSelectedSnapshot: '', // pour detecter quand selection a 1 photo change → pre-remplir description
            details: { city: '', region: '', country: '', event: '' },
            classification: { allow_pdc_vantour: null, allow_wildycaro: null, allow_mamawette: null, intimacy_level: '' },

            // IA analysis state
            aiContext: '',
            aiInProgress: false,
            aiTotal: 0,
            aiDone: 0,
            aiErrors: 0,
            aiCurrentName: '',

            init() {
                // Pre-remplit la description quand on a EXACTEMENT 1 photo selectionnee.
                // Multi-select → on laisse vide (l'utilisateur ecrit une description commune).
                this.$watch('selectedIds', (ids) => {
                    if (ids.length === 1) {
                        const item = this.items.find(i => i.id === ids[0]);
                        this.descriptionInput = item?.description_fr || '';
                    } else if (ids.length === 0) {
                        this.descriptionInput = '';
                    }
                    // Si plus d'une → on ne touche pas, l'utilisateur peut ecrire une description commune.
                });
            },

            isSelected(id) { return this.selectedIds.includes(id); },
            toggle(id, $event) {
                const currentIdx = this.items.findIndex(i => i.id === id);
                if ($event && $event.shiftKey && this.lastClickedIdx !== null && this.lastClickedIdx !== currentIdx) {
                    // Selection par plage : ajoute (ou retire si deja selectionnees) toutes les photos entre les deux clics.
                    const start = Math.min(this.lastClickedIdx, currentIdx);
                    const end = Math.max(this.lastClickedIdx, currentIdx);
                    const rangeIds = this.items.slice(start, end + 1).map(i => i.id);
                    const allSelected = rangeIds.every(rid => this.selectedIds.includes(rid));
                    if (allSelected) {
                        // Tout est deja selectionne dans la plage → on les retire toutes.
                        this.selectedIds = this.selectedIds.filter(sid => !rangeIds.includes(sid));
                    } else {
                        for (const rid of rangeIds) {
                            if (!this.selectedIds.includes(rid)) this.selectedIds.push(rid);
                        }
                    }
                } else {
                    const idx = this.selectedIds.indexOf(id);
                    if (idx >= 0) this.selectedIds.splice(idx, 1);
                    else this.selectedIds.push(id);
                }
                this.lastClickedIdx = currentIdx;
            },
            toggleAll() {
                if (this.selectedIds.length === this.items.length) this.selectedIds = [];
                else this.selectedIds = this.items.map(i => i.id);
            },

            // ───── Chips computed ─────
            // Union des valeurs présentes dans la sélection avec leur compteur d'occurrences.
            buildChips(field) {
                const counts = new Map();
                for (const item of this.items) {
                    if (!this.selectedIds.includes(item.id)) continue;
                    const arr = item[field] || [];
                    for (const v of arr) {
                        if (!v) continue;
                        counts.set(v, (counts.get(v) || 0) + 1);
                    }
                }
                return [...counts.entries()]
                    .map(([value, count]) => ({ value, count }))
                    .sort((a, b) => b.count - a.count || a.value.localeCompare(b.value));
            },
            get tagChips() { return this.buildChips('thematic_tags'); },
            get brandChips() { return this.buildChips('brands'); },
            get peopleChips() { return this.buildChips('people_ids'); },

            hasDetailValues() {
                return Object.values(this.details).some(v => (v || '').trim() !== '');
            },

            // ───── Mutation locale ─────
            // Met à jour items[] en miroir de ce que le backend a fait, sans reload.
            applyToSelected(field, mutator) {
                this.items.forEach(item => {
                    if (this.selectedIds.includes(item.id)) {
                        item[field] = mutator(item[field] || []);
                    }
                });
            },

            async post(url, body) {
                this.busy = true;
                this.lastMessage = '';
                this.lastError = '';
                try {
                    const res = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify(body),
                    });
                    const data = await res.json();
                    if (!res.ok) {
                        this.lastError = data.error || data.message || 'Erreur ' + res.status;
                        return null;
                    }
                    return data;
                } catch (e) {
                    this.lastError = 'Erreur reseau : ' + e.message;
                    return null;
                } finally {
                    this.busy = false;
                }
            },

            // ───── Bulk add ─────
            async bulkTags(direction) {
                if (this.selectedIds.length === 0) return;
                const items = this.tagAddInput.split(',').map(s => s.trim().toLowerCase()).filter(Boolean);
                if (!items.length) return;
                const data = await this.post('{{ route('media.tagsBatch') }}', { ids: this.selectedIds, add: items });
                if (data) {
                    this.applyToSelected('thematic_tags', existing => [...new Set([...existing, ...items])]);
                    this.lastMessage = `Tags ajoutes sur ${data.count} photo(s).`;
                    this.tagAddInput = '';
                }
            },
            async bulkBrands() {
                if (this.selectedIds.length === 0) return;
                const items = this.brandAddInput.split(',').map(s => s.trim()).filter(Boolean);
                if (!items.length) return;
                const data = await this.post('{{ route('media.brandsBatch') }}', { ids: this.selectedIds, add: items });
                if (data) {
                    this.applyToSelected('brands', existing => {
                        const seen = new Map(existing.map(b => [b.toLowerCase(), b]));
                        for (const b of items) if (!seen.has(b.toLowerCase())) seen.set(b.toLowerCase(), b);
                        return [...seen.values()];
                    });
                    this.lastMessage = `Marques ajoutees sur ${data.count} photo(s).`;
                    this.brandAddInput = '';
                }
            },
            async bulkPeople() {
                if (this.selectedIds.length === 0) return;
                const items = this.peopleAddInput.split(',').map(s => s.trim().toLowerCase()).filter(Boolean);
                if (!items.length) return;
                const data = await this.post('{{ route('media.peopleBatch') }}', { ids: this.selectedIds, add: items });
                if (data) {
                    this.applyToSelected('people_ids', existing => [...new Set([...existing, ...items])]);
                    this.lastMessage = `Personnes ajoutees sur ${data.count} photo(s).`;
                    this.peopleAddInput = '';
                }
            },

            // ───── Remove via chip × ─────
            async removeOne(kind, value) {
                if (this.selectedIds.length === 0) return;
                const routes = {
                    tags: '{{ route('media.tagsBatch') }}',
                    brands: '{{ route('media.brandsBatch') }}',
                    people: '{{ route('media.peopleBatch') }}',
                };
                const fields = { tags: 'thematic_tags', brands: 'brands', people: 'people_ids' };
                const data = await this.post(routes[kind], { ids: this.selectedIds, remove: [value] });
                if (data) {
                    const cmp = kind === 'brands' ? (a, b) => a.toLowerCase() === b.toLowerCase() : (a, b) => a === b;
                    this.applyToSelected(fields[kind], existing => existing.filter(v => !cmp(v, value)));
                    this.lastMessage = `"${value}" retire de ${data.count} photo(s).`;
                }
            },

            // ───── Description (per-photo, applique a toutes les selectionnees) ─────
            async saveDescription() {
                if (this.selectedIds.length === 0) return;
                this.busy = true;
                this.lastMessage = '';
                this.lastError = '';
                const value = this.descriptionInput.trim();
                let okCount = 0;
                let errCount = 0;
                for (const id of this.selectedIds) {
                    try {
                        const res = await fetch(`/media/${id}/details`, {
                            method: 'PATCH',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                            body: JSON.stringify({ description_fr: value === '' ? null : value }),
                        });
                        if (res.ok) {
                            const data = await res.json();
                            const item = this.items.find(i => i.id === id);
                            if (item) item.description_fr = data.description_fr;
                            okCount++;
                        } else {
                            errCount++;
                        }
                    } catch (e) {
                        errCount++;
                    }
                }
                this.busy = false;
                this.lastMessage = errCount === 0
                    ? `Description ${value === '' ? 'effacee' : 'sauvegardee'} sur ${okCount} photo(s).`
                    : `${okCount} OK · ${errCount} erreur(s).`;
            },

            // ───── Details / Classification ─────
            async bulkDetails(emptyMode) {
                if (this.selectedIds.length === 0) return;
                const body = { ids: this.selectedIds };
                const fields = ['city', 'region', 'country', 'event'];
                if (emptyMode) {
                    for (const k of fields) body[k] = null;
                } else {
                    for (const k of fields) {
                        const v = (this.details[k] || '').trim();
                        if (v !== '') body[k] = v;
                    }
                    if (Object.keys(body).length === 1) return;
                }
                const data = await this.post('{{ route('media.detailsBatch') }}', body);
                if (data) {
                    // Update local
                    this.items.forEach(item => {
                        if (this.selectedIds.includes(item.id)) {
                            for (const k of fields) {
                                if (k in body) item[k] = body[k];
                            }
                        }
                    });
                    this.lastMessage = `${data.count} photo(s) mises a jour (${(data.fields || []).join(', ')}).`;
                    this.details = { city: '', region: '', country: '', event: '' };
                }
            },

            async bulkClassification() {
                if (this.selectedIds.length === 0) return;
                const body = { ids: this.selectedIds };
                if (this.classification.allow_pdc_vantour !== null) body.allow_pdc_vantour = this.classification.allow_pdc_vantour;
                if (this.classification.allow_wildycaro !== null) body.allow_wildycaro = this.classification.allow_wildycaro;
                if (this.classification.allow_mamawette !== null) body.allow_mamawette = this.classification.allow_mamawette;
                if (this.classification.intimacy_level) body.intimacy_level = this.classification.intimacy_level;
                if (Object.keys(body).length === 1) return;
                const data = await this.post('{{ route('media.detailsBatch') }}', body);
                if (data) {
                    this.items.forEach(item => {
                        if (this.selectedIds.includes(item.id)) {
                            for (const flag of ['allow_pdc_vantour', 'allow_wildycaro', 'allow_mamawette', 'intimacy_level']) {
                                if (flag in body) item[flag] = body[flag];
                            }
                        }
                    });
                    this.lastMessage = `${data.count} photo(s) classifiees.`;
                }
            },

            // ───── IA analysis (sequentiel pour eviter de saturer Vision API) ─────
            async runAiAnalysis() {
                if (this.selectedIds.length === 0 || this.aiInProgress) return;
                if (!confirm(`Analyser ${this.selectedIds.length} photo(s) avec l'IA ?\n\nLes tags, personnes, lieu et marques seront REMPLACES par ce que l'IA propose. Cout estime : ~$${(this.selectedIds.length * 0.005).toFixed(3)}.`)) return;

                this.aiInProgress = true;
                this.aiTotal = this.selectedIds.length;
                this.aiDone = 0;
                this.aiErrors = 0;
                this.aiCurrentName = '';
                this.lastMessage = '';
                this.lastError = '';

                const idsCopy = [...this.selectedIds];
                for (const id of idsCopy) {
                    const item = this.items.find(i => i.id === id);
                    this.aiCurrentName = item?.filename || `#${id}`;
                    try {
                        const res = await fetch(`/media/${id}/analyze-vision`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                            body: JSON.stringify(this.aiContext.trim() ? { context: this.aiContext.trim() } : {}),
                        });
                        if (res.ok) {
                            const data = await res.json();
                            // Mise à jour locale
                            if (item) {
                                if ('description_fr' in data) item.description_fr = data.description_fr;
                                if ('thematic_tags' in data) item.thematic_tags = data.thematic_tags || [];
                                if ('people_ids' in data) item.people_ids = data.people_ids || [];
                                if ('brands' in data) item.brands = data.brands || [];
                                if ('city' in data) item.city = data.city;
                                if ('region' in data) item.region = data.region;
                                if ('country' in data) item.country = data.country;
                                if ('event' in data) item.event = data.event;
                            }
                            // Si c'est l'unique photo selectionnee, refresh le textarea description
                            if (this.selectedIds.length === 1 && this.selectedIds[0] === id) {
                                this.descriptionInput = item?.description_fr || '';
                            }
                        } else {
                            this.aiErrors++;
                            console.warn(`Vision failed for ${id}`, await res.text());
                        }
                    } catch (e) {
                        this.aiErrors++;
                        console.warn(`Vision exception for ${id}`, e);
                    }
                    this.aiDone++;
                }

                this.aiInProgress = false;
                this.aiCurrentName = '';
                this.lastMessage = `Analyse IA terminee : ${this.aiDone - this.aiErrors} reussies, ${this.aiErrors} erreurs.`;
            },
        }
    }
    </script>
@endsection
