@extends('layouts.app')

@section('title', 'Edition en masse')

@section('content')
    @php
        // Construit le chemin hiérarchique des dossiers (parent / enfant) pour l'affichage.
        $foldersById = $folders->keyBy('id');
        $folderPath = function ($f) use ($foldersById) {
            $names = [$f->name];
            $cursor = $f->parent_id ? $foldersById->get($f->parent_id) : null;
            $depth = 0;
            while ($cursor && $depth < 10) {
                array_unshift($names, $cursor->name);
                $cursor = $cursor->parent_id ? $foldersById->get($cursor->parent_id) : null;
                $depth++;
            }
            return implode(' / ', $names);
        };
        $foldersJson = $folders->map(fn ($f) => [
            'id' => $f->id,
            'name' => $f->name,
            'parent_id' => $f->parent_id,
            'path' => $folderPath($f),
            'color' => $f->color,
            'files_count' => $f->files_count,
        ])->sortBy('path')->values()->toArray();
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
                <div class="sticky top-20 space-y-4">
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

                    {{-- Dossiers --}}
                    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-3 max-h-[60vh] overflow-y-auto">
                        <h3 class="text-[10px] font-semibold uppercase tracking-widest text-gray-400 px-2 pb-2">Dossiers</h3>
                        <a href="{{ route('media.manage', request()->only('pool')) }}" class="flex items-center justify-between px-2 py-1.5 rounded-lg text-sm transition-colors {{ ! $currentFolder ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-gray-600 hover:bg-gray-50' }}">
                            <span>Tous les dossiers</span>
                            <span class="text-xs text-gray-400">{{ $totalCount }}</span>
                        </a>
                        <a href="{{ route('media.manage', array_merge(request()->only('pool'), ['folder' => 'uncategorized'])) }}" class="flex items-center justify-between px-2 py-1.5 rounded-lg text-sm transition-colors {{ $currentFolder === 'uncategorized' ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-gray-600 hover:bg-gray-50' }}">
                            <span class="text-gray-500 italic">Sans dossier</span>
                            <span class="text-xs text-gray-400">{{ $uncategorizedCount }}</span>
                        </a>
                        @foreach ($foldersJson as $f)
                            <a href="{{ route('media.manage', array_merge(request()->only('pool'), ['folder' => $f['id']])) }}"
                               class="flex items-center justify-between px-2 py-1.5 rounded-lg text-sm transition-colors {{ (string) $currentFolder === (string) $f['id'] ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-gray-600 hover:bg-gray-50' }}">
                                <span class="flex items-center gap-2 min-w-0 truncate" title="{{ $f['path'] }}">
                                    <span class="w-2 h-2 rounded-sm flex-shrink-0" style="background-color: {{ $f['color'] ?? '#9ca3af' }}"></span>
                                    <span class="truncate">{{ $f['path'] }}</span>
                                </span>
                                <span class="text-xs text-gray-400 flex-shrink-0">{{ $f['files_count'] }}</span>
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
                                <div class="aspect-square bg-gray-100 relative overflow-hidden">
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
                                {{-- Tags preview --}}
                                <div class="px-2 py-1.5">
                                    <p class="text-[10px] text-gray-400 truncate" x-text="(item.thematic_tags || []).join(', ') || '—'"></p>
                                </div>
                            </div>
                        </template>
                    </div>
                @endif
            </main>

            {{-- ═══════════ Sidebar droite : toolbar bulk-edit ═══════════ --}}
            <aside class="w-[360px] flex-shrink-0">
                <div class="sticky top-20 space-y-3 max-h-[calc(100vh-6rem)] overflow-y-auto">
                    {{-- Pas de selection --}}
                    <div x-show="selectedIds.length === 0" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 text-center">
                        <svg class="w-10 h-10 text-gray-200 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        <p class="text-xs text-gray-400">Selectionne des photos pour activer l'edition en masse.</p>
                    </div>

                    {{-- Toolbar --}}
                    <div x-show="selectedIds.length > 0" x-cloak class="space-y-3">
                        {{-- Tags --}}
                        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
                            <h3 class="text-xs font-semibold text-gray-700 uppercase tracking-wider mb-2">Tags</h3>
                            <p class="text-[11px] text-gray-400 mb-2">Ajoute ou retire. Une virgule pour plusieurs. Si "Retirer", valide pour appliquer.</p>
                            <div class="space-y-2">
                                <div class="flex gap-1.5">
                                    <input type="text" x-model="tagAddInput" @keyup.enter="bulkTags('add')" placeholder="Ajouter (plage, mer, ...)" class="flex-1 text-xs rounded-lg border-gray-200 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400 px-2 py-1.5">
                                    <button @click="bulkTags('add')" :disabled="busy || !tagAddInput.trim()" class="px-3 py-1.5 text-xs bg-indigo-600 text-white rounded-lg disabled:opacity-50 hover:bg-indigo-700">Ajouter</button>
                                </div>
                                <div class="flex gap-1.5">
                                    <input type="text" x-model="tagRemoveInput" @keyup.enter="bulkTags('remove')" placeholder="Retirer (vague, ...)" class="flex-1 text-xs rounded-lg border-gray-200 focus:border-rose-400 focus:ring-1 focus:ring-rose-400 px-2 py-1.5">
                                    <button @click="bulkTags('remove')" :disabled="busy || !tagRemoveInput.trim()" class="px-3 py-1.5 text-xs bg-rose-600 text-white rounded-lg disabled:opacity-50 hover:bg-rose-700">Retirer</button>
                                </div>
                            </div>
                        </div>

                        {{-- Brands --}}
                        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
                            <h3 class="text-xs font-semibold text-gray-700 uppercase tracking-wider mb-2">Marques</h3>
                            <div class="space-y-2">
                                <div class="flex gap-1.5">
                                    <input type="text" x-model="brandAddInput" @keyup.enter="bulkBrands('add')" placeholder="Ajouter (Decathlon, ...)" class="flex-1 text-xs rounded-lg border-gray-200 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400 px-2 py-1.5">
                                    <button @click="bulkBrands('add')" :disabled="busy || !brandAddInput.trim()" class="px-3 py-1.5 text-xs bg-indigo-600 text-white rounded-lg disabled:opacity-50 hover:bg-indigo-700">Ajouter</button>
                                </div>
                                <div class="flex gap-1.5">
                                    <input type="text" x-model="brandRemoveInput" @keyup.enter="bulkBrands('remove')" placeholder="Retirer" class="flex-1 text-xs rounded-lg border-gray-200 focus:border-rose-400 focus:ring-1 focus:ring-rose-400 px-2 py-1.5">
                                    <button @click="bulkBrands('remove')" :disabled="busy || !brandRemoveInput.trim()" class="px-3 py-1.5 text-xs bg-rose-600 text-white rounded-lg disabled:opacity-50 hover:bg-rose-700">Retirer</button>
                                </div>
                            </div>
                        </div>

                        {{-- People --}}
                        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
                            <h3 class="text-xs font-semibold text-gray-700 uppercase tracking-wider mb-2">Personnes</h3>
                            <p class="text-[11px] text-gray-400 mb-2">Ids normalises (ex: caroline, xavier).</p>
                            <div class="space-y-2">
                                <div class="flex gap-1.5">
                                    <input type="text" x-model="peopleAddInput" @keyup.enter="bulkPeople('add')" placeholder="Ajouter (caroline, ...)" class="flex-1 text-xs rounded-lg border-gray-200 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400 px-2 py-1.5">
                                    <button @click="bulkPeople('add')" :disabled="busy || !peopleAddInput.trim()" class="px-3 py-1.5 text-xs bg-indigo-600 text-white rounded-lg disabled:opacity-50 hover:bg-indigo-700">Ajouter</button>
                                </div>
                                <div class="flex gap-1.5">
                                    <input type="text" x-model="peopleRemoveInput" @keyup.enter="bulkPeople('remove')" placeholder="Retirer" class="flex-1 text-xs rounded-lg border-gray-200 focus:border-rose-400 focus:ring-1 focus:ring-rose-400 px-2 py-1.5">
                                    <button @click="bulkPeople('remove')" :disabled="busy || !peopleRemoveInput.trim()" class="px-3 py-1.5 text-xs bg-rose-600 text-white rounded-lg disabled:opacity-50 hover:bg-rose-700">Retirer</button>
                                </div>
                            </div>
                        </div>

                        {{-- Lieu / Evenement --}}
                        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
                            <h3 class="text-xs font-semibold text-gray-700 uppercase tracking-wider mb-2">Lieu &amp; evenement</h3>
                            <p class="text-[11px] text-gray-400 mb-2">Vide = ne pas modifier. Bouton "Vider" = effacer le champ.</p>
                            <div class="space-y-2">
                                @foreach (['city' => 'Ville', 'region' => 'Region', 'country' => 'Pays', 'event' => 'Evenement'] as $key => $label)
                                    <div>
                                        <label class="text-[10px] text-gray-400 uppercase tracking-wider">{{ $label }}</label>
                                        <div class="flex gap-1.5">
                                            <input type="text" x-model="details.{{ $key }}" placeholder="{{ $label }}" class="flex-1 text-xs rounded-lg border-gray-200 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400 px-2 py-1.5">
                                        </div>
                                    </div>
                                @endforeach
                                <div class="flex gap-1.5 pt-2">
                                    <button @click="bulkDetails(false)" :disabled="busy || !hasDetailValues()" class="flex-1 px-3 py-1.5 text-xs bg-indigo-600 text-white rounded-lg disabled:opacity-40 hover:bg-indigo-700">Appliquer</button>
                                    <button @click="bulkDetails(true)" :disabled="busy" class="px-3 py-1.5 text-xs bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">Vider les champs ci-dessus</button>
                                </div>
                            </div>
                        </div>

                        {{-- Pool / Intimacy --}}
                        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
                            <h3 class="text-xs font-semibold text-gray-700 uppercase tracking-wider mb-2">Classification</h3>
                            <div class="space-y-2 text-xs">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" x-model="classification.allow_pdc_vantour" :indeterminate="classification.allow_pdc_vantour === null" class="rounded border-gray-300">
                                    <span>PdC / Vantour</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" x-model="classification.allow_wildycaro" :indeterminate="classification.allow_wildycaro === null" class="rounded border-gray-300">
                                    <span>Wildycaro</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" x-model="classification.allow_mamawette" :indeterminate="classification.allow_mamawette === null" class="rounded border-gray-300">
                                    <span>🔒 Mamawette</span>
                                </label>
                                <div class="pt-1">
                                    <label class="text-[10px] text-gray-400 uppercase tracking-wider">Niveau d'intimite</label>
                                    <select x-model="classification.intimacy_level" class="w-full text-xs rounded-lg border-gray-200 px-2 py-1.5 mt-0.5">
                                        <option value="">— Ne pas modifier —</option>
                                        <option value="public">Public</option>
                                        <option value="prive">Prive</option>
                                        <option value="never_publish">Jamais publier</option>
                                    </select>
                                </div>
                                <button @click="bulkClassification()" :disabled="busy" class="w-full mt-2 px-3 py-1.5 text-xs bg-indigo-600 text-white rounded-lg disabled:opacity-40 hover:bg-indigo-700">Appliquer la classification</button>
                            </div>
                        </div>

                        {{-- Status / Feedback --}}
                        <div x-show="lastMessage" x-cloak class="bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs px-3 py-2 rounded-lg" x-text="lastMessage"></div>
                        <div x-show="lastError" x-cloak class="bg-red-50 border border-red-200 text-red-700 text-xs px-3 py-2 rounded-lg" x-text="lastError"></div>
                    </div>
                </div>
            </aside>
        </div>
    </div>

    <script>
    function bulkManage() {
        return {
            items: @json($items),
            selectedIds: [],
            busy: false,
            lastMessage: '',
            lastError: '',
            tagAddInput: '',
            tagRemoveInput: '',
            brandAddInput: '',
            brandRemoveInput: '',
            peopleAddInput: '',
            peopleRemoveInput: '',
            details: { city: '', region: '', country: '', event: '' },
            classification: { allow_pdc_vantour: null, allow_wildycaro: null, allow_mamawette: null, intimacy_level: '' },

            init() {},

            isSelected(id) { return this.selectedIds.includes(id); },
            toggle(id) {
                const idx = this.selectedIds.indexOf(id);
                if (idx >= 0) this.selectedIds.splice(idx, 1);
                else this.selectedIds.push(id);
            },
            toggleAll() {
                if (this.selectedIds.length === this.items.length) this.selectedIds = [];
                else this.selectedIds = this.items.map(i => i.id);
            },

            hasDetailValues() {
                return Object.values(this.details).some(v => (v || '').trim() !== '');
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

            async bulkTags(direction) {
                if (this.selectedIds.length === 0) return;
                const input = direction === 'add' ? this.tagAddInput : this.tagRemoveInput;
                const items = input.split(',').map(s => s.trim()).filter(Boolean);
                if (!items.length) return;
                const data = await this.post('{{ route('media.tagsBatch') }}', {
                    ids: this.selectedIds,
                    [direction === 'add' ? 'add' : 'remove']: items,
                });
                if (data) {
                    this.lastMessage = `Tags ${direction === 'add' ? 'ajoutes' : 'retires'} sur ${data.count} photo(s).`;
                    if (direction === 'add') this.tagAddInput = ''; else this.tagRemoveInput = '';
                    await this.refreshSelected();
                }
            },

            async bulkBrands(direction) {
                if (this.selectedIds.length === 0) return;
                const input = direction === 'add' ? this.brandAddInput : this.brandRemoveInput;
                const items = input.split(',').map(s => s.trim()).filter(Boolean);
                if (!items.length) return;
                const data = await this.post('{{ route('media.brandsBatch') }}', {
                    ids: this.selectedIds,
                    [direction === 'add' ? 'add' : 'remove']: items,
                });
                if (data) {
                    this.lastMessage = `Marques ${direction === 'add' ? 'ajoutees' : 'retirees'} sur ${data.count} photo(s).`;
                    if (direction === 'add') this.brandAddInput = ''; else this.brandRemoveInput = '';
                    await this.refreshSelected();
                }
            },

            async bulkPeople(direction) {
                if (this.selectedIds.length === 0) return;
                const input = direction === 'add' ? this.peopleAddInput : this.peopleRemoveInput;
                const items = input.split(',').map(s => s.trim().toLowerCase()).filter(Boolean);
                if (!items.length) return;
                const data = await this.post('{{ route('media.peopleBatch') }}', {
                    ids: this.selectedIds,
                    [direction === 'add' ? 'add' : 'remove']: items,
                });
                if (data) {
                    this.lastMessage = `Personnes ${direction === 'add' ? 'ajoutees' : 'retirees'} sur ${data.count} photo(s).`;
                    if (direction === 'add') this.peopleAddInput = ''; else this.peopleRemoveInput = '';
                    await this.refreshSelected();
                }
            },

            async bulkDetails(emptyMode) {
                if (this.selectedIds.length === 0) return;
                const body = { ids: this.selectedIds };
                if (emptyMode) {
                    // Ne touche que les champs visibles dans le bloc Lieu — les vide.
                    body.city = null; body.region = null; body.country = null; body.event = null;
                } else {
                    for (const k of ['city', 'region', 'country', 'event']) {
                        const v = (this.details[k] || '').trim();
                        if (v !== '') body[k] = v;
                    }
                    if (Object.keys(body).length === 1) return; // que ids
                }
                const data = await this.post('{{ route('media.detailsBatch') }}', body);
                if (data) {
                    this.lastMessage = `${data.count} photo(s) mises a jour (${(data.fields || []).join(', ')}).`;
                    if (emptyMode) this.details = { city: '', region: '', country: '', event: '' };
                    else this.details = { city: '', region: '', country: '', event: '' };
                    await this.refreshSelected();
                }
            },

            async bulkClassification() {
                if (this.selectedIds.length === 0) return;
                const body = { ids: this.selectedIds };
                if (this.classification.allow_pdc_vantour !== null) body.allow_pdc_vantour = this.classification.allow_pdc_vantour;
                if (this.classification.allow_wildycaro !== null) body.allow_wildycaro = this.classification.allow_wildycaro;
                if (this.classification.allow_mamawette !== null) body.allow_mamawette = this.classification.allow_mamawette;
                if (this.classification.intimacy_level) body.intimacy_level = this.classification.intimacy_level;
                if (Object.keys(body).length === 1) return; // que ids
                const data = await this.post('{{ route('media.detailsBatch') }}', body);
                if (data) {
                    this.lastMessage = `${data.count} photo(s) classifiees (${(data.fields || []).join(', ')}).`;
                    await this.refreshSelected();
                }
            },

            // Re-fetche les items selectionnes pour rafraichir leurs tags/brands/etc affiches dans la grille.
            async refreshSelected() {
                // Pour rester simple, on recharge la page apres un court delai (debounce visuel).
                // En production on pourrait taper /api/media/{id} mais c'est plus de code pour peu de gain.
                setTimeout(() => { window.location.reload(); }, 700);
            },
        }
    }
    </script>
@endsection
