@extends('layouts.app')

@section('title', 'Medias')

@section('content')
    @if (($unclassifiedCount ?? 0) > 0)
        <div class="bg-amber-50 border-b border-amber-200 px-6 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                <span class="text-sm text-amber-900">
                    <strong>{{ $unclassifiedCount }}</strong> photo(s) à classer (pool non défini).
                </span>
            </div>
            <a href="{{ route('media.index', ['filter' => 'unclassified']) }}" class="text-sm font-medium text-amber-900 hover:underline">Classer maintenant →</a>
        </div>
    @endif
    @php
        $itemsJson = $items->map(function ($item) use ($mediaPostMap) {
            $item['posts'] = $mediaPostMap[$item['filename']] ?? [];
            $statuses = collect($item['posts'])->pluck('status')->unique();
            if ($statuses->contains('published')) {
                $item['status_label'] = 'Publie';
                $item['status_class'] = 'bg-green-500 text-white';
            } elseif ($statuses->contains('publishing')) {
                $item['status_label'] = 'En cours';
                $item['status_class'] = 'bg-yellow-400 text-yellow-900';
            } elseif ($statuses->contains('scheduled')) {
                $item['status_label'] = 'Planifie';
                $item['status_class'] = 'bg-blue-500 text-white';
            } elseif ($statuses->contains('failed')) {
                $item['status_label'] = 'Echoue';
                $item['status_class'] = 'bg-red-500 text-white';
            } elseif ($statuses->isNotEmpty()) {
                $item['status_label'] = 'Brouillon';
                $item['status_class'] = 'bg-gray-500 text-white';
            } else {
                $item['status_label'] = null;
                $item['status_class'] = '';
            }
            if ($item['is_video'] ?? false) {
                $item['thumbnail_url'] = route('media.thumbnail', $item['filename']);
            }
            return $item;
        })->values()->toArray();

        // Map id => folder pour reconstituer le chemin hiérarchique sans N+1.
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
        $folderDepth = function ($f) use ($foldersById) {
            $depth = 0;
            $cursor = $f->parent_id ? $foldersById->get($f->parent_id) : null;
            while ($cursor && $depth < 10) {
                $depth++;
                $cursor = $cursor->parent_id ? $foldersById->get($cursor->parent_id) : null;
            }
            return $depth;
        };
        $childrenCountById = $folders->groupBy('parent_id')->map->count();
        $childrenByParent = $folders->groupBy('parent_id');
        $parentChainOf = function ($f) use ($foldersById) {
            $chain = [];
            $cursor = $f->parent_id;
            while ($cursor && count($chain) < 10) {
                $chain[] = $cursor;
                $cursor = $foldersById->get($cursor)?->parent_id;
            }
            return $chain;
        };
        $foldersJson = $folders->map(function ($f) use ($folderPath, $folderDepth, $childrenCountById, $childrenByParent, $parentChainOf) {
            $hasChildren = $childrenByParent->has($f->id) && $childrenByParent->get($f->id)->isNotEmpty();
            return [
                'id' => $f->id,
                'name' => $f->name,
                'slug' => $f->slug,
                'parent_id' => $f->parent_id,
                'path' => $folderPath($f),
                'depth' => $folderDepth($f),
                'parent_chain' => $parentChainOf($f),
                'has_children' => $hasChildren,
                'color' => $f->color,
                'is_system' => $f->is_system,
                'files_count' => $f->files_count,
                'children_count' => $childrenCountById->get($f->id, 0),
            ];
        })->sortBy('path')->values()->toArray();

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

    <div x-data="{
        items: @js($itemsJson),
        folders: @js($foldersJson),
        currentFolder: @js($currentFolder),
        currentPool: @js($currentPool),
        totalCount: {{ $totalCount }},
        uncategorizedCount: {{ $uncategorizedCount }},
        bulkDeleteConfirm: false,
        selected: null,
        multiSelect: false,
        multiSelected: [],
        lastMultiClickIndex: null,
        bulkMoveFolder: '',
        uploading: false,
        uploadProgress: 0,
        uploadPhase: 'upload',
        deleteConfirm: false,
        dragOver: false,
        newFolderName: '',
        newFolderParentId: null,
        creatingFolder: false,
        newTagInput: '',
        newBrandInput: '',
        newPersonInput: '',
        // Bulk-edit (mode multi-select)
        bulkTagInput: '',
        bulkBrandInput: '',
        bulkPersonInput: '',
        bulkDescriptionInput: '',
        // IA Vision (mono-photo et bulk)
        aiContext: '',
        aiInProgress: false,
        aiTotal: 0,
        aiDone: 0,
        aiErrors: 0,
        aiCurrentName: '',
        autocomplete: { cities: [], regions: [], countries: [], brands: [] },
        editingFolder: null,
        editFolderName: '',
        editFolderColor: '',
        deleteFolderConfirm: null,
        folderMenuOpen: null,
        movingToFolder: false,
        get currentFolderId() {
            if (!this.currentFolder || this.currentFolder === 'uncategorized') return null;
            const n = parseInt(this.currentFolder);
            return isNaN(n) ? null : n;
        },
        get currentFolderObj() {
            const id = this.currentFolderId;
            return id ? (this.folders.find(f => f.id === id) || null) : null;
        },
        get visibleFolders() {
            const parentId = this.currentFolderId;
            return this.folders.filter(f => (f.parent_id || null) === parentId);
        },
        get breadcrumb() {
            const cur = this.currentFolderObj;
            if (!cur) return [];
            const trail = [cur];
            let p = cur.parent_id ? this.folders.find(f => f.id === cur.parent_id) : null;
            while (p) {
                trail.unshift(p);
                p = p.parent_id ? this.folders.find(f => f.id === p.parent_id) : null;
            }
            return trail;
        },
        selectItem(item, event) {
            if (this.multiSelect) {
                const currentIndex = this.items.findIndex(i => i.id === item.id);
                if (event && event.shiftKey && this.lastMultiClickIndex !== null && currentIndex !== this.lastMultiClickIndex) {
                    const start = Math.min(this.lastMultiClickIndex, currentIndex);
                    const end = Math.max(this.lastMultiClickIndex, currentIndex);
                    for (let i = start; i <= end; i++) {
                        const id = this.items[i].id;
                        if (!this.multiSelected.includes(id)) {
                            this.multiSelected.push(id);
                        }
                    }
                } else {
                    const idx = this.multiSelected.indexOf(item.id);
                    if (idx === -1) {
                        this.multiSelected.push(item.id);
                    } else {
                        this.multiSelected.splice(idx, 1);
                    }
                }
                this.lastMultiClickIndex = currentIndex;
                return;
            }
            this.selected = (this.selected && this.selected.filename === item.filename) ? null : item;
        },
        isMultiSelected(item) {
            return this.multiSelected.includes(item.id);
        },
        selectAll() {
            if (this.multiSelected.length === this.items.length) {
                this.multiSelected = [];
            } else {
                this.multiSelected = this.items.map(i => i.id);
            }
        },
        exitMultiSelect() {
            this.multiSelect = false;
            this.multiSelected = [];
            this.lastMultiClickIndex = null;
            this.bulkMoveFolder = '';
        },
        async bulkMove() {
            if (this.multiSelected.length === 0 || this.bulkMoveFolder === '') return;
            const folderId = this.bulkMoveFolder === 'uncategorized' ? null : this.bulkMoveFolder;
            try {
                const response = await fetch('{{ route('media.folders.move') }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ file_ids: this.multiSelected, folder_id: folderId }),
                });
                if (response.ok) {
                    window.location.reload();
                }
            } catch(e) {
                alert('Erreur de connexion.');
            }
        },
        poolUpdateForAction(action) {
            return action === 'wildycaro'
                ? { allow_wildycaro: true, allow_pdc_vantour: false, allow_mamawette: false, intimacy_level: 'public' }
                : action === 'pdc_vantour'
                ? { allow_wildycaro: false, allow_pdc_vantour: true, allow_mamawette: false, intimacy_level: 'public' }
                : action === 'mamawette'
                ? { allow_wildycaro: false, allow_pdc_vantour: false, allow_mamawette: true, intimacy_level: 'prive' }
                : { intimacy_level: 'never_publish' };
        },
        async classifySingle(action) {
            if (!this.selected || !this.selected.id || !action) return;
            const id = this.selected.id;
            try {
                const res = await fetch(`/media/${id}/classify`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ action }),
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const result = await res.json();
                Object.assign(this.selected, {
                    allow_pdc_vantour: !!result.allow_pdc_vantour,
                    allow_wildycaro: !!result.allow_wildycaro,
                    allow_mamawette: !!result.allow_mamawette,
                    intimacy_level: result.intimacy_level,
                });
                const idx = this.items.findIndex(i => i.id === id);
                if (idx !== -1) Object.assign(this.items[idx], {
                    allow_pdc_vantour: !!result.allow_pdc_vantour,
                    allow_wildycaro: !!result.allow_wildycaro,
                    allow_mamawette: !!result.allow_mamawette,
                    intimacy_level: result.intimacy_level,
                });
                // En vue A classer, on retire la photo de la grille si elle vient d etre classee ;
                // si on vient au contraire de la marquer comme A classer, on la laisse visible.
                const params = new URLSearchParams(window.location.search);
                const isUnclassifiedView = params.get('filter') === 'unclassified' || params.get('pool') === 'unclassified';
                if (isUnclassifiedView && action !== 'unclassify') {
                    this.items = this.items.filter(i => i.id !== id);
                    this.selected = null;
                }
            } catch(e) {
                alert('Erreur classification : ' + e.message);
            }
        },
        async bulkClassify(action) {
            if (this.multiSelected.length === 0) return;
            const ids = [...this.multiSelected];
            try {
                const res = await fetch('{{ route('media.classifyBatch') }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ ids, action }),
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const isUnclassifiedFilter = new URLSearchParams(window.location.search).get('filter') === 'unclassified';
                if (isUnclassifiedFilter) {
                    this.items = this.items.filter(i => !ids.includes(i.id));
                } else {
                    const update = this.poolUpdateForAction(action);
                    this.items = this.items.map(i => ids.includes(i.id) ? { ...i, ...update } : i);
                }
                this.exitMultiSelect();
            } catch(e) {
                alert('Erreur classification batch : ' + e.message);
            }
        },
        navigateFolder(folderId) {
            const params = new URLSearchParams(window.location.search);
            if (folderId === null) {
                params.delete('folder');
            } else {
                params.set('folder', folderId);
            }
            params.delete('filter');
            window.location.href = '{{ route('media.index') }}' + (params.toString() ? '?' + params.toString() : '');
        },
        navigatePool(pool) {
            const params = new URLSearchParams(window.location.search);
            if (pool === null) {
                params.delete('pool');
            } else {
                params.set('pool', pool);
            }
            // L'alias rétro-compat filter=unclassified n'est plus utile dès qu'on pose pool=
            if (params.get('filter') === 'unclassified') params.delete('filter');
            window.location.href = '{{ route('media.index') }}' + (params.toString() ? '?' + params.toString() : '');
        },
        async bulkDelete() {
            if (this.multiSelected.length === 0) return;
            const ids = [...this.multiSelected];
            try {
                const res = await fetch('{{ route('media.deleteBatch') }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ ids }),
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                this.items = this.items.filter(i => !ids.includes(i.id));
                this.bulkDeleteConfirm = false;
                this.exitMultiSelect();
            } catch(e) {
                alert('Erreur suppression batch : ' + e.message);
            }
        },
        async patchTags(payload) {
            if (!this.selected || !this.selected.id) return;
            const id = this.selected.id;
            try {
                const res = await fetch(`/media/${id}/tags`, {
                    method: 'PATCH',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(payload),
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const result = await res.json();
                this.selected.thematic_tags = result.thematic_tags;
                const idx = this.items.findIndex(i => i.id === id);
                if (idx !== -1) this.items[idx].thematic_tags = result.thematic_tags;
            } catch(e) {
                alert('Erreur tags : ' + e.message);
            }
        },
        async addTag() {
            const value = (this.newTagInput || '').trim().toLowerCase();
            if (!value) return;
            this.newTagInput = '';
            await this.patchTags({ add: [value] });
        },
        async removeTag(tag) {
            if (!tag) return;
            await this.patchTags({ remove: [tag] });
        },
        async fetchAutocomplete() {
            try {
                const res = await fetch('{{ route('media.autocomplete') }}', { headers: { 'Accept': 'application/json' }});
                if (!res.ok) return;
                this.autocomplete = await res.json();
            } catch(e) { /* silencieux : autocomplete = bonus */ }
        },
        _detailsTimer: null,
        async saveDetails() {
            if (!this.selected || !this.selected.id) return;
            const id = this.selected.id;
            // Debounce 400ms pour ne pas spammer pendant la frappe.
            clearTimeout(this._detailsTimer);
            this._detailsTimer = setTimeout(async () => {
                const payload = {
                    city: this.selected.city || null,
                    region: this.selected.region || null,
                    country: this.selected.country || null,
                    event: this.selected.event || null,
                    taken_at: this.selected.taken_at || null,
                };
                try {
                    const res = await fetch(`/media/${id}/details`, {
                        method: 'PATCH',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify(payload),
                    });
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    const result = await res.json();
                    this.selected.taken_at_label = result.taken_at_label;
                    const idx = this.items.findIndex(i => i.id === id);
                    if (idx !== -1) {
                        Object.assign(this.items[idx], {
                            city: result.city, region: result.region, country: result.country,
                            event: result.event, taken_at: result.taken_at, taken_at_label: result.taken_at_label,
                        });
                    }
                } catch(e) {
                    alert('Erreur sauvegarde : ' + e.message);
                }
            }, 400);
        },
        async patchBrands(brands) {
            if (!this.selected || !this.selected.id) return;
            const id = this.selected.id;
            try {
                const res = await fetch(`/media/${id}/details`, {
                    method: 'PATCH',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ brands }),
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const result = await res.json();
                this.selected.brands = result.brands || [];
                const idx = this.items.findIndex(i => i.id === id);
                if (idx !== -1) this.items[idx].brands = result.brands || [];
                // Met à jour la liste autocomplete locale (les nouvelles marques deviennent disponibles).
                for (const b of (result.brands || [])) {
                    if (!this.autocomplete.brands.some(x => x.toLowerCase() === b.toLowerCase())) {
                        this.autocomplete.brands.push(b);
                    }
                }
            } catch(e) {
                alert('Erreur marques : ' + e.message);
            }
        },
        async addBrand() {
            const value = (this.newBrandInput || '').trim();
            if (!value) return;
            this.newBrandInput = '';
            const current = this.selected.brands || [];
            if (current.some(b => b.toLowerCase() === value.toLowerCase())) return;
            await this.patchBrands([...current, value]);
        },
        async removeBrand(brand) {
            if (!brand) return;
            const current = this.selected.brands || [];
            const next = current.filter(b => b.toLowerCase() !== brand.toLowerCase());
            await this.patchBrands(next);
        },
        // ───── People (chips + add/remove) ─────
        async patchPeople(addList, removeList) {
            if (!this.selected || !this.selected.id) return;
            const id = this.selected.id;
            try {
                const res = await fetch('/media/people-batch', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        ids: [id],
                        add: addList || [],
                        remove: removeList || [],
                    }),
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                // Reconstitue la liste localement (le batch endpoint ne renvoie pas la liste finale).
                let next = (this.selected.people_ids || []).map(p => p.toLowerCase());
                if (removeList && removeList.length) next = next.filter(p => !removeList.includes(p));
                if (addList && addList.length) {
                    for (const a of addList) if (!next.includes(a)) next.push(a);
                }
                this.selected.people_ids = next;
                const idx = this.items.findIndex(i => i.id === id);
                if (idx !== -1) this.items[idx].people_ids = [...next];
            } catch(e) {
                alert('Erreur personnes : ' + e.message);
            }
        },
        async addPerson() {
            const value = (this.newPersonInput || '').trim().toLowerCase();
            if (!value) return;
            this.newPersonInput = '';
            await this.patchPeople([value], []);
        },
        async removePerson(p) {
            if (!p) return;
            await this.patchPeople([], [p.toLowerCase()]);
        },
        // ───── Description (textarea avec save sur blur) ─────
        async saveDescription() {
            if (!this.selected || !this.selected.id) return;
            const id = this.selected.id;
            const value = (this.selected.description_fr || '').trim();
            try {
                const res = await fetch(`/media/${id}/details`, {
                    method: 'PATCH',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ description_fr: value === '' ? null : value }),
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const result = await res.json();
                this.selected.description_fr = result.description_fr;
                const idx = this.items.findIndex(i => i.id === id);
                if (idx !== -1) this.items[idx].description_fr = result.description_fr;
            } catch(e) {
                alert('Erreur description : ' + e.message);
            }
        },
        // ───── IA Vision : analyse mono ou bulk ─────
        async runAiAnalysis(targetIds) {
            const ids = targetIds || (this.multiSelect && this.multiSelected.length > 0 ? [...this.multiSelected] : (this.selected ? [this.selected.id] : []));
            if (ids.length === 0 || this.aiInProgress) return;
            if (!confirm(`Analyser ${ids.length} photo(s) avec l'IA ?\n\nLes tags, personnes, lieu et marques seront REMPLACES par ce que l'IA propose.\nCout estime : ~$${(ids.length * 0.005).toFixed(3)}.`)) return;

            this.aiInProgress = true;
            this.aiTotal = ids.length;
            this.aiDone = 0;
            this.aiErrors = 0;
            this.aiCurrentName = '';
            const ctxBody = this.aiContext.trim() ? { context: this.aiContext.trim() } : {};

            for (const id of ids) {
                const item = this.items.find(i => i.id === id);
                this.aiCurrentName = item?.filename || `#${id}`;
                try {
                    const res = await fetch(`/media/${id}/analyze-vision`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                        },
                        body: JSON.stringify(ctxBody),
                    });
                    if (res.ok) {
                        const data = await res.json();
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
                        if (this.selected && this.selected.id === id && item) {
                            // Refresh le panneau de detail si c'est la photo actuellement selectionnee.
                            Object.assign(this.selected, item);
                        }
                    } else {
                        this.aiErrors++;
                    }
                } catch (e) {
                    this.aiErrors++;
                }
                this.aiDone++;
            }

            this.aiInProgress = false;
            this.aiCurrentName = '';
        },
        // ───── Chips bulk : agrégation des valeurs présentes dans la sélection ─────
        bulkChips(field) {
            const counts = new Map();
            for (const item of this.items) {
                if (!this.multiSelected.includes(item.id)) continue;
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
        get bulkTagChips() { return this.bulkChips('thematic_tags'); },
        get bulkBrandChips() { return this.bulkChips('brands'); },
        get bulkPeopleChips() { return this.bulkChips('people_ids'); },

        // Mutation locale après un appel batch (mirror du backend pour eviter un reload).
        applyToMultiSelected(field, mutator) {
            this.items.forEach(item => {
                if (this.multiSelected.includes(item.id)) {
                    item[field] = mutator(item[field] || []);
                }
            });
        },

        async bulkAddTags() {
            const tags = this.bulkTagInput.split(',').map(s => s.trim().toLowerCase()).filter(Boolean);
            if (tags.length === 0 || this.multiSelected.length === 0) return;
            try {
                const res = await fetch('/media/tags-batch', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                    },
                    body: JSON.stringify({ ids: this.multiSelected, add: tags }),
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                this.applyToMultiSelected('thematic_tags', existing => [...new Set([...existing, ...tags])]);
                this.bulkTagInput = '';
            } catch(e) { alert('Erreur : ' + e.message); }
        },
        async bulkAddBrands() {
            const brands = this.bulkBrandInput.split(',').map(s => s.trim()).filter(Boolean);
            if (brands.length === 0 || this.multiSelected.length === 0) return;
            try {
                const res = await fetch('/media/brands-batch', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                    },
                    body: JSON.stringify({ ids: this.multiSelected, add: brands }),
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                this.applyToMultiSelected('brands', existing => {
                    const seen = new Map(existing.map(b => [b.toLowerCase(), b]));
                    for (const b of brands) if (!seen.has(b.toLowerCase())) seen.set(b.toLowerCase(), b);
                    return [...seen.values()];
                });
                this.bulkBrandInput = '';
            } catch(e) { alert('Erreur : ' + e.message); }
        },
        async bulkAddPeople() {
            const people = this.bulkPersonInput.split(',').map(s => s.trim().toLowerCase()).filter(Boolean);
            if (people.length === 0 || this.multiSelected.length === 0) return;
            try {
                const res = await fetch('/media/people-batch', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                    },
                    body: JSON.stringify({ ids: this.multiSelected, add: people }),
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                this.applyToMultiSelected('people_ids', existing => [...new Set([...existing, ...people])]);
                this.bulkPersonInput = '';
            } catch(e) { alert('Erreur : ' + e.message); }
        },
        async bulkRemoveOne(kind, value) {
            if (this.multiSelected.length === 0) return;
            const routes = { tags: '/media/tags-batch', brands: '/media/brands-batch', people: '/media/people-batch' };
            const fields = { tags: 'thematic_tags', brands: 'brands', people: 'people_ids' };
            try {
                const res = await fetch(routes[kind], {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                    },
                    body: JSON.stringify({ ids: this.multiSelected, remove: [value] }),
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const cmp = kind === 'brands' ? (a, b) => a.toLowerCase() === b.toLowerCase() : (a, b) => a === b;
                this.applyToMultiSelected(fields[kind], existing => existing.filter(v => !cmp(v, value)));
            } catch(e) { alert('Erreur : ' + e.message); }
        },
        async bulkSaveDescription() {
            if (this.multiSelected.length === 0) return;
            const value = this.bulkDescriptionInput.trim();
            const csrf = document.querySelector('meta[name=csrf-token]').getAttribute('content');
            for (const id of this.multiSelected) {
                try {
                    const res = await fetch(`/media/${id}/details`, {
                        method: 'PATCH',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                        body: JSON.stringify({ description_fr: value === '' ? null : value }),
                    });
                    if (res.ok) {
                        const data = await res.json();
                        const item = this.items.find(i => i.id === id);
                        if (item) item.description_fr = data.description_fr;
                    }
                } catch(e) { /* skip */ }
            }
            this.bulkDescriptionInput = '';
        },
        copyMacCommand() {
            if (this.multiSelected.length === 0) return;
            const ids = this.multiSelected.join(',');
            const cmd = `python /Volumes/Samsung_T5/DEV/Scripts/analyse-images.py --legacy --env prod --ids ${ids}`;
            navigator.clipboard.writeText(cmd).then(() => {
                alert(`Commande copiée pour ${this.multiSelected.length} photo(s).\nColle-la dans ton terminal Mac.`);
            }).catch(() => {
                prompt('Copie cette commande dans ton terminal Mac :', cmd);
            });
        },
        handleDrop(e) {
            e.preventDefault();
            this.dragOver = false;
            this.uploadFiles(e.dataTransfer.files);
        },
        handleFileSelect(e) {
            const files = Array.from(e.target.files);
            e.target.value = '';
            this.uploadFiles(files);
        },
        async uploadFiles(files) {
            for (const file of files) {
                await this.uploadFile(file);
            }
            window.location.reload();
        },
        async uploadFile(file) {
            this.uploading = true;
            this.uploadProgress = 0;
            this.uploadPhase = 'upload';
            const formData = new FormData();
            formData.append('file', file);
            if (this.currentFolder && this.currentFolder !== 'uncategorized') {
                formData.append('folder_id', this.currentFolder);
            }
            try {
                const xhr = new XMLHttpRequest();
                xhr.upload.addEventListener('progress', (e) => {
                    if (e.lengthComputable) {
                        this.uploadProgress = Math.round((e.loaded / e.total) * 100);
                        if (this.uploadProgress >= 100) {
                            this.uploadPhase = 'processing';
                        }
                    }
                });
                await new Promise((resolve, reject) => {
                    xhr.onload = () => {
                        if (xhr.status >= 200 && xhr.status < 300) resolve(JSON.parse(xhr.responseText));
                        else {
                            try { const err = JSON.parse(xhr.responseText); reject(err.message || err.error || 'Erreur'); }
                            catch(e) { reject('Erreur d\'upload'); }
                        }
                    };
                    xhr.onerror = () => reject('Erreur de connexion');
                    xhr.open('POST', '{{ route('media.upload') }}');
                    xhr.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name=csrf-token]').getAttribute('content'));
                    xhr.setRequestHeader('Accept', 'application/json');
                    xhr.send(formData);
                });
            } catch (error) {
                alert('Erreur : ' + error);
            } finally {
                this.uploading = false;
                this.uploadProgress = 0;
            }
        },
        async deleteFile() {
            if (!this.selected) return;
            try {
                const response = await fetch('/media/' + this.selected.filename, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                        'Accept': 'application/json',
                    }
                });
                if (response.ok) {
                    window.location.reload();
                } else {
                    const data = await response.json();
                    alert(data.error || 'Erreur lors de la suppression.');
                }
            } catch(e) {
                alert('Erreur de connexion.');
            }
            this.deleteConfirm = false;
        },
        async createFolder() {
            if (!this.newFolderName.trim()) return;
            const parentId = this.newFolderParentId;
            try {
                const response = await fetch('{{ route('media.folders.store') }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        name: this.newFolderName.trim(),
                        parent_id: parentId,
                    }),
                });
                if (response.ok) {
                    // Si on a créé un sous-dossier d'un parent où on n'est pas, naviguer vers ce parent
                    // pour que l'utilisateur voie son nouveau dossier après reload.
                    if (parentId && parentId !== this.currentFolderId) {
                        this.navigateFolder(parentId);
                    } else {
                        window.location.reload();
                    }
                } else {
                    const data = await response.json();
                    alert(data.message || 'Erreur.');
                }
            } catch(e) {
                alert('Erreur de connexion.');
            }
        },
        startEditFolder(folder) {
            this.editingFolder = folder.id;
            this.editFolderName = folder.name;
            this.editFolderColor = folder.color;
        },
        async saveFolder(folder) {
            try {
                const response = await fetch('/media/folders/' + folder.id, {
                    method: 'PATCH',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ name: this.editFolderName, color: this.editFolderColor }),
                });
                if (response.ok) {
                    window.location.reload();
                }
            } catch(e) {
                alert('Erreur de connexion.');
            }
            this.editingFolder = null;
        },
        async deleteFolder(folder) {
            try {
                const response = await fetch('/media/folders/' + folder.id, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                        'Accept': 'application/json',
                    }
                });
                if (response.ok) {
                    window.location.reload();
                } else {
                    const data = await response.json();
                    alert(data.error || 'Erreur.');
                }
            } catch(e) {
                alert('Erreur de connexion.');
            }
            this.deleteFolderConfirm = null;
        },
        async moveToFolder(folderId) {
            if (!this.selected || !this.selected.id) return;
            try {
                const response = await fetch('{{ route('media.folders.move') }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ file_ids: [this.selected.id], folder_id: folderId }),
                });
                if (response.ok) {
                    window.location.reload();
                }
            } catch(e) {
                alert('Erreur de connexion.');
            }
        },
        get statusLabel() {
            if (!this.selected) return '';
            const statuses = (this.selected.posts || []).map(p => p.status);
            if (statuses.includes('published')) return 'Publie';
            if (statuses.includes('publishing')) return 'En cours de publication';
            if (statuses.includes('scheduled')) return 'Planifie';
            if (statuses.includes('failed')) return 'Echec de publication';
            if (statuses.length > 0) return 'Brouillon';
            return 'Non lie a un post';
        },
        get statusColor() {
            if (!this.selected) return 'bg-gray-100 text-gray-600';
            const statuses = (this.selected.posts || []).map(p => p.status);
            if (statuses.includes('published')) return 'bg-green-100 text-green-700';
            if (statuses.includes('publishing')) return 'bg-yellow-100 text-yellow-700';
            if (statuses.includes('scheduled')) return 'bg-blue-100 text-blue-700';
            if (statuses.includes('failed')) return 'bg-red-100 text-red-700';
            if (statuses.length > 0) return 'bg-gray-100 text-gray-600';
            return 'bg-gray-100 text-gray-500';
        }
    }" x-init="fetchAutocomplete()">
        {{-- Datalists pour autocomplete des champs structurés --}}
        <datalist id="cities-autocomplete-list">
            <template x-for="v in autocomplete.cities" :key="v"><option :value="v"></option></template>
        </datalist>
        <datalist id="regions-autocomplete-list">
            <template x-for="v in autocomplete.regions" :key="v"><option :value="v"></option></template>
        </datalist>
        <datalist id="countries-autocomplete-list">
            <template x-for="v in autocomplete.countries" :key="v"><option :value="v"></option></template>
        </datalist>
        <datalist id="brands-autocomplete-list">
            <template x-for="v in autocomplete.brands" :key="v"><option :value="v"></option></template>
        </datalist>

        {{-- Upload zone (drag & drop) --}}
        <div class="mb-6 bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
            <div class="border-2 border-dashed rounded-xl p-8 text-center transition-colors"
                 :class="dragOver ? 'border-indigo-400 bg-indigo-50' : 'border-gray-200'"
                 @dragover.prevent="dragOver = true"
                 @dragleave.prevent="dragOver = false"
                 @drop.prevent="handleDrop($event)">
                <input type="file" x-ref="fileInput" @change="handleFileSelect($event)" multiple accept="image/*,video/*" class="hidden">
                <template x-if="!uploading">
                    <div>
                        <svg class="w-10 h-10 text-gray-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
                        </svg>
                        <p class="text-sm text-gray-600">
                            Glissez vos fichiers ici ou
                            <button type="button" @click="$refs.fileInput.click()" class="text-indigo-600 hover:text-indigo-700 font-medium">cliquez pour parcourir</button>
                        </p>
                        <p class="text-xs text-gray-400 mt-1">Images (JPEG, PNG, GIF, WebP) et videos (MP4, MOV, AVI, WebM) — Max 50 Mo</p>
                    </div>
                </template>
                <template x-if="uploading">
                    <div>
                        <svg class="w-8 h-8 text-indigo-500 mx-auto mb-3 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        <p class="text-sm font-medium text-indigo-600" x-show="uploadPhase === 'upload'">Upload en cours...</p>
                        <p class="text-sm font-medium text-amber-600" x-show="uploadPhase === 'processing'">Traitement en cours (encodage video)...</p>
                        <div class="w-48 mx-auto mt-3 bg-gray-200 rounded-full h-2">
                            <div class="h-2 rounded-full transition-all"
                                 :class="uploadPhase === 'processing' ? 'bg-amber-500 animate-pulse' : 'bg-indigo-600'"
                                 :style="'width: ' + (uploadPhase === 'processing' ? '100' : uploadProgress) + '%'"></div>
                        </div>
                        <p class="text-xs text-gray-500 mt-2" x-show="uploadPhase === 'upload'" x-text="uploadProgress + '%'"></p>
                        <p class="text-xs text-gray-500 mt-2" x-show="uploadPhase === 'processing'">Veuillez patienter, cela peut prendre quelques minutes...</p>
                    </div>
                </template>
            </div>
        </div>

        {{-- Filters + multi-select --}}
        <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
            <div class="flex items-center gap-2">
                <a href="{{ route('media.index', array_filter(['folder' => $currentFolder, 'pool' => $currentPool])) }}"
                   class="px-3 py-1.5 text-sm font-medium rounded-lg transition-colors {{ $filter === 'all' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50' }}">
                    Tous
                </a>
                <a href="{{ route('media.index', array_filter(['filter' => 'images', 'folder' => $currentFolder, 'pool' => $currentPool])) }}"
                   class="px-3 py-1.5 text-sm font-medium rounded-lg transition-colors {{ $filter === 'images' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50' }}">
                    Images
                </a>
                <a href="{{ route('media.index', array_filter(['filter' => 'videos', 'folder' => $currentFolder, 'pool' => $currentPool])) }}"
                   class="px-3 py-1.5 text-sm font-medium rounded-lg transition-colors {{ $filter === 'videos' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50' }}">
                    Videos
                </a>
            </div>
            <div class="flex items-center gap-2">
                {{-- Toggle multi-select --}}
                <button type="button" @click="multiSelect ? exitMultiSelect() : (multiSelect = true, selected = null)"
                    class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-xl transition-colors"
                    :class="multiSelect ? 'bg-amber-100 text-amber-700 border border-amber-300' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50'">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    <span x-text="multiSelect ? 'Annuler' : 'Selectionner'"></span>
                </button>
                <button type="button" @click="$refs.fileInput.click()"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Ajouter des medias
                </button>
            </div>
        </div>

        {{-- Bulk action bar --}}
        <div x-show="multiSelect" x-cloak
             class="mb-4 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 flex flex-wrap items-center gap-3">
            <button @click="selectAll()"
                    class="text-sm font-medium text-amber-700 hover:text-amber-900 underline underline-offset-2">
                <span x-text="multiSelected.length === items.length ? 'Tout deselectionner' : 'Tout selectionner'"></span>
            </button>
            <span class="text-sm text-amber-600" x-show="multiSelected.length > 0">
                <span x-text="multiSelected.length"></span> fichier(s) selectionne(s)
            </span>
            <div class="flex items-center gap-2 flex-wrap ml-auto" x-show="multiSelected.length > 0">
                {{-- Action : déplacer vers un DOSSIER (organisation) --}}
                <div class="flex items-center gap-1.5 px-2 py-1 bg-white border border-amber-200 rounded-lg">
                    <span class="text-[11px] font-medium text-gray-500 uppercase tracking-wide">Dossier</span>
                    <select x-model="bulkMoveFolder" class="text-sm border border-gray-200 rounded px-2 py-1 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400">
                        <option value="">— Choisir —</option>
                        <option value="uncategorized">Non classé</option>
                        <template x-for="folder in folders" :key="folder.id">
                            <option :value="folder.id" x-text="folder.path || folder.name"></option>
                        </template>
                    </select>
                    <button @click="bulkMove()" :disabled="bulkMoveFolder === ''"
                            class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium bg-indigo-600 text-white rounded hover:bg-indigo-700 disabled:opacity-50 transition-colors">
                        Déplacer
                    </button>
                </div>

                {{-- Action : classer dans un POOL de publication --}}
                <div class="flex items-center gap-1.5 px-2 py-1 bg-white border border-amber-200 rounded-lg">
                    <span class="text-[11px] font-medium text-gray-500 uppercase tracking-wide">Pool</span>
                    <button @click="bulkClassify('pdc_vantour')"
                            class="px-2.5 py-1 text-xs font-medium bg-emerald-500 hover:bg-emerald-600 text-white rounded">
                        PdC / Vantour
                    </button>
                    <button @click="bulkClassify('wildycaro')"
                            class="px-2.5 py-1 text-xs font-medium bg-rose-500 hover:bg-rose-600 text-white rounded">
                        Wildycaro
                    </button>
                    <button @click="bulkClassify('mamawette')"
                            class="px-2.5 py-1 text-xs font-medium bg-purple-700 hover:bg-purple-800 text-white rounded">
                        🔒 Mamawette
                    </button>
                    <button @click="bulkClassify('never_publish')"
                            class="px-2.5 py-1 text-xs font-medium bg-gray-700 hover:bg-gray-800 text-white rounded">
                        Jamais publier
                    </button>
                </div>

                {{-- Action : envoyer au Mac pour analyse IA (copie la commande) --}}
                <button @click="copyMacCommand()"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium bg-indigo-50 text-indigo-700 border border-indigo-200 hover:bg-indigo-100 rounded-lg transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 7.5V6.108c0-1.135.845-2.098 1.976-2.192.373-.03.748-.057 1.123-.08M15.75 18H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08M15.75 18.75v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5A3.375 3.375 0 0 0 6.375 7.5H5.25m11.9-3.664A2.251 2.251 0 0 0 15 2.25h-1.5a2.251 2.251 0 0 0-2.15 1.586m5.8 0c.065.21.1.433.1.664v.75h-6V4.5c0-.231.035-.454.1-.664M6.75 7.5H4.875c-.621 0-1.125.504-1.125 1.125v12c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V16.5a9 9 0 0 0-9-9Z" />
                    </svg>
                    Copier commande Mac
                </button>

                {{-- Action : suppression définitive --}}
                <button @click="bulkDeleteConfirm = true"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium bg-red-50 text-red-700 border border-red-200 hover:bg-red-100 rounded-lg transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                    </svg>
                    Supprimer
                </button>
            </div>
        </div>

        {{-- Main layout: sidebar + grid + detail panel --}}
        <div class="flex gap-5">
            {{-- Left sidebar : Dossiers (arbre) + Pools --}}
            <aside class="w-60 flex-shrink-0 hidden md:block">
                <div class="sticky top-20 max-h-[calc(100vh-6rem)] overflow-y-auto space-y-4 pr-1">
                    {{-- IA Vision (contexte + bouton). Cible : la photo selectionnee (mono) ou la selection multi. --}}
                    <div class="bg-gradient-to-br from-violet-50 to-indigo-50 rounded-2xl border border-violet-200 shadow-sm p-3 space-y-2">
                        <h3 class="text-[10px] font-semibold uppercase tracking-widest text-violet-900 px-1">IA Vision</h3>
                        <textarea x-model="aiContext" rows="2" placeholder="Contexte (ex: voyage Portugal 2024)"
                                  class="w-full text-xs rounded-lg border-violet-200 focus:border-violet-400 focus:ring-1 focus:ring-violet-400 px-2 py-1 bg-white resize-none"></textarea>
                        <button @click="runAiAnalysis()"
                                :disabled="aiInProgress || (!selected && multiSelected.length === 0)"
                                class="w-full px-2 py-1.5 text-xs bg-violet-600 text-white rounded-lg disabled:opacity-40 hover:bg-violet-700 font-medium flex items-center justify-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" /></svg>
                            <span x-show="!aiInProgress && multiSelect && multiSelected.length > 0">Analyser <span x-text="multiSelected.length"></span> photo(s)</span>
                            <span x-show="!aiInProgress && (!multiSelect || multiSelected.length === 0) && selected">Analyser cette photo</span>
                            <span x-show="!aiInProgress && (!multiSelect || multiSelected.length === 0) && !selected" class="text-violet-200">Aucune photo</span>
                            <span x-show="aiInProgress">Analyse en cours...</span>
                        </button>
                        <div x-show="aiInProgress || aiTotal > 0" x-cloak class="text-[10px] text-violet-700">
                            <div class="flex items-center justify-between mb-0.5">
                                <span><span x-text="aiDone"></span> / <span x-text="aiTotal"></span><span x-show="aiErrors > 0" class="text-rose-600"> · <span x-text="aiErrors"></span> err</span></span>
                            </div>
                            <div x-show="aiCurrentName" class="truncate" x-text="aiCurrentName"></div>
                            <div class="w-full h-1 bg-violet-100 rounded-full overflow-hidden mt-0.5">
                                <div class="h-full bg-violet-500 transition-all" :style="`width: ${aiTotal ? (aiDone / aiTotal * 100) : 0}%`"></div>
                            </div>
                        </div>
                    </div>

                    {{-- Dossiers (arbre cliquable) --}}
                    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-3"
                         x-data="folderTree({{ json_encode($foldersJson) }}, {{ json_encode($autoOpenIds) }})">
                        <h3 class="text-[10px] font-semibold uppercase tracking-widest text-gray-400 px-2 pb-2">Dossiers</h3>
                        <a href="{{ route('media.index', array_filter(['pool' => $currentPool, 'filter' => $filter !== 'all' ? $filter : null])) }}"
                           class="flex items-center justify-between px-2 py-1.5 rounded-lg text-sm transition-colors {{ ! $currentFolder ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-gray-600 hover:bg-gray-50' }}">
                            <span>Tous les dossiers</span>
                            <span class="text-xs text-gray-400">{{ $totalCount }}</span>
                        </a>
                        <a href="{{ route('media.index', array_filter(['pool' => $currentPool, 'filter' => $filter !== 'all' ? $filter : null, 'folder' => 'uncategorized'])) }}"
                           class="flex items-center justify-between px-2 py-1.5 rounded-lg text-sm transition-colors {{ $currentFolder === 'uncategorized' ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-gray-600 hover:bg-gray-50' }}">
                            <span class="text-gray-500 italic">Sans dossier</span>
                            <span class="text-xs text-gray-400">{{ $uncategorizedCount }}</span>
                        </a>
                        <template x-for="f in folders" :key="f.id">
                            <div x-show="isVisible(f)" class="flex items-center group" :style="`padding-left: ${f.depth * 12}px`">
                                <button x-show="f.has_children" @click.stop="toggle(f.id)" type="button"
                                        class="w-4 h-4 flex items-center justify-center text-gray-400 hover:text-gray-700 flex-shrink-0">
                                    <svg class="w-3 h-3 transition-transform" :class="isOpen(f.id) && 'rotate-90'" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                                </button>
                                <span x-show="!f.has_children" class="w-4 h-4 flex-shrink-0"></span>
                                <a :href="`{{ route('media.index') }}?folder=${f.id}{{ $currentPool ? '&pool='.urlencode($currentPool) : '' }}{{ $filter !== 'all' ? '&filter='.$filter : '' }}`"
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
                        <a href="{{ route('media.index', array_filter(['folder' => $currentFolder, 'filter' => $filter !== 'all' ? $filter : null])) }}"
                           class="flex items-center justify-between px-2 py-1.5 rounded-lg text-sm transition-colors {{ ! $currentPool ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-gray-600 hover:bg-gray-50' }}">
                            <span>Tous</span>
                            <span class="text-xs text-gray-400">{{ $totalCount }}</span>
                        </a>
                        @foreach (['pdc_vantour' => ['PdC / Vantour', 'emerald'], 'wildycaro' => ['Wildycaro', 'rose'], 'mamawette' => ['🔒 Mamawette', 'purple'], 'unclassified' => ['A classer', 'amber'], 'never_publish' => ['Jamais publier', 'gray']] as $slug => $cfg)
                            <a href="{{ route('media.index', array_filter(['folder' => $currentFolder, 'filter' => $filter !== 'all' ? $filter : null, 'pool' => $slug])) }}"
                               class="flex items-center justify-between px-2 py-1.5 rounded-lg text-sm transition-colors {{ $currentPool === $slug ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-gray-600 hover:bg-gray-50' }}">
                                <span>{{ $cfg[0] }}</span>
                                <span class="text-xs text-gray-400">{{ $poolCounts[$slug] ?? 0 }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            </aside>

            {{-- Center: media grid --}}
            <div class="min-w-0 flex-1">
                <template x-if="items.length === 0">
                    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-16 text-center">
                        <svg class="w-12 h-12 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z" />
                        </svg>
                        <p class="text-gray-500 text-sm">Aucun media dans ce dossier.</p>
                        <p class="text-gray-400 text-xs mt-1">Glissez des fichiers dans la zone ci-dessus pour commencer.</p>
                    </div>
                </template>

                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 gap-3">
                    <template x-for="item in items" :key="item.filename">
                        <div @click="selectItem(item, $event)"
                             class="bg-white rounded-xl border-2 overflow-hidden cursor-pointer transition-all shadow-sm hover:shadow-md"
                             :class="{
                                 'border-indigo-500 ring-2 ring-indigo-200': !multiSelect && selected && selected.filename === item.filename,
                                 'border-amber-400 ring-2 ring-amber-200': multiSelect && isMultiSelected(item),
                                 'border-gray-100 hover:border-gray-300': !((!multiSelect && selected && selected.filename === item.filename) || (multiSelect && isMultiSelected(item)))
                             }">
                            {{-- Thumbnail (portrait 4:5) --}}
                            <div class="aspect-[4/5] bg-gray-100 relative overflow-hidden">
                                {{-- Multi-select checkbox --}}
                                <div x-show="multiSelect" x-cloak
                                     class="absolute top-2 right-2 z-10">
                                    <div class="w-6 h-6 rounded-full border-2 flex items-center justify-center transition-colors"
                                         :class="isMultiSelected(item) ? 'bg-amber-500 border-amber-500' : 'bg-white/80 border-gray-300 backdrop-blur-sm'">
                                        <svg x-show="isMultiSelected(item)" class="w-3.5 h-3.5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                        </svg>
                                    </div>
                                </div>
                                <template x-if="item.is_image">
                                    <img :src="item.url" :alt="item.filename" class="w-full h-full object-cover" loading="lazy">
                                </template>
                                <template x-if="item.is_video">
                                    <div class="w-full h-full relative">
                                        <img :src="item.thumbnail_url" :alt="item.filename"
                                             class="w-full h-full object-cover" loading="lazy"
                                             x-on:error="$el.style.display='none'; $el.nextElementSibling.style.display='flex'">
                                        <div class="w-full h-full flex-col items-center justify-center bg-gray-900 text-white" style="display:none">
                                            <svg class="w-10 h-10" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                        </div>
                                        <div class="absolute inset-0 flex items-center justify-center">
                                            <div class="w-10 h-10 rounded-full bg-black/50 flex items-center justify-center backdrop-blur-sm">
                                                <svg class="w-5 h-5 text-white ml-0.5" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                            </div>
                                        </div>
                                    </div>
                                </template>

                                {{-- Status pill --}}
                                <template x-if="item.status_label">
                                    <div class="absolute top-1.5 right-1.5 px-2 py-0.5 text-[10px] font-semibold rounded-full leading-tight"
                                         :class="item.status_class" x-text="item.status_label"></div>
                                </template>

                                {{-- Folder badge --}}
                                <template x-if="item.folder_color && !currentFolder">
                                    <div class="absolute top-1.5 left-1.5 flex items-center gap-1 px-1.5 py-0.5 bg-black/60 rounded text-[10px] text-white">
                                        <span class="w-2 h-2 rounded-sm flex-shrink-0" :style="'background-color: ' + item.folder_color"></span>
                                        <span x-text="item.folder_name" class="truncate max-w-[60px]"></span>
                                    </div>
                                </template>

                                {{-- Size badge --}}
                                <div class="absolute bottom-1.5 left-1.5 px-1.5 py-0.5 bg-black/60 text-white text-[10px] rounded" x-text="item.size_human"></div>
                            </div>

                            {{-- Info below thumbnail --}}
                            <div class="p-2.5">
                                <p class="text-xs font-medium text-gray-900 truncate" x-text="item.filename"></p>
                                <p class="text-[11px] text-gray-400 mt-1">
                                    <span x-text="item.size_human"></span> &middot; <span x-text="item.date"></span>
                                </p>
                                <template x-if="item.posts && item.posts.length > 0">
                                    <p class="text-[11px] text-gray-500 mt-1.5 truncate" x-text="item.posts[0].preview"></p>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Right: detail panel (sticky, scroll indépendant de la grille) --}}
            <div class="hidden lg:block w-[440px] flex-shrink-0">
                <div class="sticky top-20 max-h-[calc(100vh-6rem)] overflow-y-auto">
                    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                        {{-- No selection (et pas de multi-select actif) --}}
                        <template x-if="!selected && (!multiSelect || multiSelected.length === 0)">
                            <div class="p-8 text-center py-20">
                                <svg class="w-16 h-16 text-gray-200 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="0.75">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z" />
                                </svg>
                                <p class="text-sm text-gray-400">Selectionnez un media pour voir ses details<br><span class="text-[10px] text-gray-300">ou bascule en multi-select pour editer plusieurs photos a la fois</span></p>
                            </div>
                        </template>

                        {{-- Multi-select bulk-edit --}}
                        <template x-if="multiSelect && multiSelected.length > 0 && !selected">
                            <div class="p-3 space-y-2.5">
                                <div class="flex items-center justify-between">
                                    <h3 class="text-sm font-semibold text-gray-900">Edition en masse</h3>
                                    <span class="text-xs text-amber-600 font-medium" x-text="multiSelected.length + ' photo(s)'"></span>
                                </div>

                                {{-- IA Vision : deplacee dans la sidebar gauche --}}

                                {{-- Tags --}}
                                <div class="bg-white rounded-xl border border-gray-100 p-2.5">
                                    <h4 class="text-[11px] font-semibold text-gray-700 uppercase tracking-wider mb-1.5">Tags</h4>
                                    <div class="flex gap-1.5">
                                        <input type="text" x-model="bulkTagInput" @keyup.enter="bulkAddTags()" placeholder="Ajouter (plage, mer...)" class="flex-1 text-xs rounded-lg border-gray-200 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400 px-2 py-1.5">
                                        <button @click="bulkAddTags()" :disabled="!bulkTagInput.trim()" class="px-3 py-1.5 text-xs bg-indigo-600 text-white rounded-lg disabled:opacity-50">+</button>
                                    </div>
                                    <template x-if="bulkTagChips.length > 0">
                                        <div class="flex flex-wrap gap-1 mt-2 max-h-24 overflow-y-auto">
                                            <template x-for="chip in bulkTagChips" :key="chip.value">
                                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 text-[11px] bg-gray-100 hover:bg-rose-50 hover:text-rose-700 rounded group cursor-pointer" @click="bulkRemoveOne('tags', chip.value)">
                                                    <span x-text="chip.value"></span>
                                                    <span class="text-gray-400 text-[9px]" x-show="chip.count < multiSelected.length" x-text="chip.count"></span>
                                                    <span class="text-gray-400 group-hover:text-rose-600">×</span>
                                                </span>
                                            </template>
                                        </div>
                                    </template>
                                </div>

                                {{-- Marques --}}
                                <div class="bg-white rounded-xl border border-gray-100 p-2.5">
                                    <h4 class="text-[11px] font-semibold text-gray-700 uppercase tracking-wider mb-1.5">Marques</h4>
                                    <div class="flex gap-1.5">
                                        <input type="text" x-model="bulkBrandInput" @keyup.enter="bulkAddBrands()" placeholder="Ajouter (Decathlon...)" class="flex-1 text-xs rounded-lg border-gray-200 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400 px-2 py-1.5">
                                        <button @click="bulkAddBrands()" :disabled="!bulkBrandInput.trim()" class="px-3 py-1.5 text-xs bg-indigo-600 text-white rounded-lg disabled:opacity-50">+</button>
                                    </div>
                                    <template x-if="bulkBrandChips.length > 0">
                                        <div class="flex flex-wrap gap-1 mt-2 max-h-24 overflow-y-auto">
                                            <template x-for="chip in bulkBrandChips" :key="chip.value">
                                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 text-[11px] bg-gray-100 hover:bg-rose-50 hover:text-rose-700 rounded group cursor-pointer" @click="bulkRemoveOne('brands', chip.value)">
                                                    <span x-text="chip.value"></span>
                                                    <span class="text-gray-400 text-[9px]" x-show="chip.count < multiSelected.length" x-text="chip.count"></span>
                                                    <span class="text-gray-400 group-hover:text-rose-600">×</span>
                                                </span>
                                            </template>
                                        </div>
                                    </template>
                                </div>

                                {{-- Personnes --}}
                                <div class="bg-white rounded-xl border border-gray-100 p-2.5">
                                    <h4 class="text-[11px] font-semibold text-gray-700 uppercase tracking-wider mb-1.5">Personnes</h4>
                                    <div class="flex gap-1.5">
                                        <input type="text" x-model="bulkPersonInput" @keyup.enter="bulkAddPeople()" placeholder="caroline, xavier..." class="flex-1 text-xs rounded-lg border-gray-200 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400 px-2 py-1.5">
                                        <button @click="bulkAddPeople()" :disabled="!bulkPersonInput.trim()" class="px-3 py-1.5 text-xs bg-indigo-600 text-white rounded-lg disabled:opacity-50">+</button>
                                    </div>
                                    <template x-if="bulkPeopleChips.length > 0">
                                        <div class="flex flex-wrap gap-1 mt-2 max-h-24 overflow-y-auto">
                                            <template x-for="chip in bulkPeopleChips" :key="chip.value">
                                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 text-[11px] bg-gray-100 hover:bg-rose-50 hover:text-rose-700 rounded group cursor-pointer" @click="bulkRemoveOne('people', chip.value)">
                                                    <span x-text="chip.value"></span>
                                                    <span class="text-gray-400 text-[9px]" x-show="chip.count < multiSelected.length" x-text="chip.count"></span>
                                                    <span class="text-gray-400 group-hover:text-rose-600">×</span>
                                                </span>
                                            </template>
                                        </div>
                                    </template>
                                </div>

                                {{-- Description bulk --}}
                                <div class="bg-white rounded-xl border border-gray-100 p-2.5">
                                    <h4 class="text-[11px] font-semibold text-gray-700 uppercase tracking-wider mb-1.5">Description (commune)</h4>
                                    <textarea x-model="bulkDescriptionInput" rows="3" placeholder="Description applique a toutes les photos selectionnees"
                                              class="w-full text-xs rounded-lg border-gray-200 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400 px-2 py-1.5 resize-none"></textarea>
                                    <div class="flex gap-1.5 mt-1.5">
                                        <button @click="bulkSaveDescription()" class="flex-1 px-3 py-1.5 text-xs bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">Sauvegarder</button>
                                        <button @click="bulkDescriptionInput = ''; bulkSaveDescription()" class="px-3 py-1.5 text-xs bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">Vider</button>
                                    </div>
                                </div>

                                <p class="text-[10px] text-gray-400 italic px-1">Pour deplacer / classifier / supprimer en masse, utilise la barre amber au-dessus de la grille.</p>
                            </div>
                        </template>

                        {{-- Selected --}}
                        <template x-if="selected">
                            <div>
                                {{-- Preview --}}
                                <div class="bg-gray-950 flex items-center justify-center" style="min-height: 160px; max-height: 220px;">
                                    <template x-if="selected.is_image">
                                        <img :src="selected.url" :alt="selected.filename" class="max-w-full max-h-[220px] object-contain">
                                    </template>
                                    <template x-if="selected.is_video">
                                        <video :src="selected.url" controls playsinline
                                               class="max-w-full max-h-[220px] object-contain"
                                               :key="selected.filename">
                                        </video>
                                    </template>
                                </div>

                                {{-- Info --}}
                                <div class="p-5 space-y-4">
                                    {{-- Status --}}
                                    <span class="inline-flex items-center px-2.5 py-1 text-xs font-semibold rounded-full" :class="statusColor" x-text="statusLabel"></span>

                                    {{-- Filename --}}
                                    <h3 class="text-sm font-semibold text-gray-900 break-all" x-text="selected.filename"></h3>

                                    {{-- Folder + Pool selectors (côte à côte) --}}
                                    <div class="grid grid-cols-2 gap-2">
                                        <div>
                                            <label class="text-xs text-gray-400 block mb-1">Dossier</label>
                                            <select @change="moveToFolder($event.target.value || null)"
                                                    class="w-full text-sm border border-gray-200 rounded-lg px-2.5 py-1.5 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400">
                                                <option value="" :selected="!selected.folder_id">Non classé</option>
                                                <template x-for="folder in folders" :key="folder.id">
                                                    <option :value="folder.id" :selected="selected.folder_id == folder.id" x-text="folder.path || folder.name"></option>
                                                </template>
                                            </select>
                                        </div>
                                        <template x-if="selected.is_image">
                                            <div>
                                                <label class="text-xs text-gray-400 block mb-1">Pool</label>
                                                <select @change="classifySingle($event.target.value)"
                                                        class="w-full text-sm border border-gray-200 rounded-lg px-2.5 py-1.5 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400">
                                                    <option value="unclassify" :selected="!selected.allow_pdc_vantour && !selected.allow_wildycaro && !selected.allow_mamawette && selected.intimacy_level !== 'never_publish'">— À classer —</option>
                                                    <option value="pdc_vantour" :selected="selected.allow_pdc_vantour">PdC / Vantour</option>
                                                    <option value="wildycaro" :selected="selected.allow_wildycaro">Wildycaro</option>
                                                    <option value="mamawette" :selected="selected.allow_mamawette">🔒 Mamawette</option>
                                                    <option value="never_publish" :selected="selected.intimacy_level === 'never_publish'">Jamais publier</option>
                                                </select>
                                            </div>
                                        </template>
                                    </div>

                                    {{-- File details --}}
                                    <div class="grid grid-cols-2 gap-3 text-xs">
                                        <div>
                                            <span class="text-gray-400 block">Type</span>
                                            <span class="text-gray-700 font-medium" x-text="selected.mimetype"></span>
                                        </div>
                                        <div>
                                            <span class="text-gray-400 block">Taille</span>
                                            <span class="text-gray-700 font-medium" x-text="selected.size_human"></span>
                                        </div>
                                        <div>
                                            <span class="text-gray-400 block">Date</span>
                                            <span class="text-gray-700 font-medium" x-text="selected.date"></span>
                                        </div>
                                        <div>
                                            <span class="text-gray-400 block">Format</span>
                                            <span class="text-gray-700 font-medium" x-text="selected.is_image ? 'Image' : 'Video'"></span>
                                        </div>
                                    </div>

                                    {{-- IA / Catalogue (tags, personnes, pool) --}}
                                    <div class="space-y-3 pt-3 border-t border-gray-100">
                                            <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Catalogue & IA</h4>

                                            {{-- Tags (éditables) --}}
                                            <div>
                                                <span class="text-[10px] text-gray-400 block mb-1">Tags</span>
                                                <div class="flex flex-wrap gap-1 mb-1.5">
                                                    <template x-for="tag in (selected.thematic_tags || [])" :key="tag">
                                                        <span class="inline-flex items-center gap-1 bg-gray-100 text-gray-700 text-[10px] pl-2 pr-1 py-0.5 rounded">
                                                            <span x-text="tag"></span>
                                                            <button @click="removeTag(tag)" class="text-gray-400 hover:text-red-600 leading-none" type="button" title="Retirer ce tag">×</button>
                                                        </span>
                                                    </template>
                                                    <template x-if="!selected.thematic_tags || selected.thematic_tags.length === 0">
                                                        <span class="text-[10px] text-gray-400 italic">Aucun tag.</span>
                                                    </template>
                                                </div>
                                                <div class="flex items-center gap-1">
                                                    <input type="text" x-model="newTagInput" placeholder="ajouter un tag…"
                                                           class="flex-1 text-xs border border-gray-200 rounded px-2 py-1 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400"
                                                           @keydown.enter.prevent="addTag()">
                                                    <button @click="addTag()" :disabled="!newTagInput.trim()"
                                                            class="px-2 py-1 text-xs bg-indigo-600 text-white rounded hover:bg-indigo-700 disabled:opacity-50">+</button>
                                                </div>
                                            </div>

                                            {{-- Marques / partenaires (éditables) --}}
                                            <div>
                                                <span class="text-[10px] text-gray-400 block mb-1">Marques / partenaires</span>
                                                <div class="flex flex-wrap gap-1 mb-1.5">
                                                    <template x-for="b in (selected.brands || [])" :key="b">
                                                        <span class="inline-flex items-center gap-1 bg-amber-50 text-amber-800 text-[10px] pl-2 pr-1 py-0.5 rounded">
                                                            <span x-text="b"></span>
                                                            <button @click="removeBrand(b)" class="text-amber-400 hover:text-red-600 leading-none" type="button" title="Retirer cette marque">×</button>
                                                        </span>
                                                    </template>
                                                    <template x-if="!selected.brands || selected.brands.length === 0">
                                                        <span class="text-[10px] text-gray-400 italic">Aucune marque.</span>
                                                    </template>
                                                </div>
                                                <div class="flex items-center gap-1">
                                                    <input type="text" x-model="newBrandInput" placeholder="ajouter une marque…" list="brands-autocomplete-list"
                                                           class="flex-1 text-xs border border-gray-200 rounded px-2 py-1 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400"
                                                           @keydown.enter.prevent="addBrand()">
                                                    <button @click="addBrand()" :disabled="!newBrandInput.trim()"
                                                            class="px-2 py-1 text-xs bg-amber-600 text-white rounded hover:bg-amber-700 disabled:opacity-50">+</button>
                                                </div>
                                            </div>

                                            {{-- Lieu (ville / région / pays) --}}
                                            <div>
                                                <span class="text-[10px] text-gray-400 block mb-1">Lieu</span>
                                                <div class="grid grid-cols-3 gap-1">
                                                    <input type="text" x-model="selected.city" placeholder="Ville" list="cities-autocomplete-list"
                                                           class="text-xs border border-gray-200 rounded px-2 py-1 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400"
                                                           @change="saveDetails()">
                                                    <input type="text" x-model="selected.region" placeholder="Région" list="regions-autocomplete-list"
                                                           class="text-xs border border-gray-200 rounded px-2 py-1 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400"
                                                           @change="saveDetails()">
                                                    <input type="text" x-model="selected.country" placeholder="Pays" list="countries-autocomplete-list"
                                                           class="text-xs border border-gray-200 rounded px-2 py-1 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400"
                                                           @change="saveDetails()">
                                                </div>
                                            </div>

                                            {{-- Événement (facultatif) --}}
                                            <div>
                                                <span class="text-[10px] text-gray-400 block mb-1">Événement</span>
                                                <input type="text" x-model="selected.event" placeholder="ex: Voyage Portugal 2024"
                                                       class="w-full text-xs border border-gray-200 rounded px-2 py-1 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400"
                                                       @change="saveDetails()">
                                            </div>

                                            {{-- Date de prise + compteur de publications --}}
                                            <div class="grid grid-cols-2 gap-2 text-xs">
                                                <div>
                                                    <span class="text-[10px] text-gray-400 block mb-1">Date de prise</span>
                                                    <input type="date" x-model="selected.taken_at"
                                                           class="w-full text-xs border border-gray-200 rounded px-2 py-1 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400"
                                                           @change="saveDetails()">
                                                    <template x-if="selected.taken_at_label">
                                                        <span class="text-[10px] text-gray-400 block mt-0.5" x-text="selected.taken_at_label"></span>
                                                    </template>
                                                </div>
                                                <div>
                                                    <span class="text-[10px] text-gray-400 block mb-1">Publications</span>
                                                    <span class="inline-flex items-center gap-1 text-[11px] font-medium"
                                                          :class="(selected.publication_count || 0) === 0 ? 'text-gray-400' : 'text-indigo-600'">
                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 12a4 4 0 1 0-8 0 4 4 0 0 0 8 0Zm0 0v1.5a2.5 2.5 0 0 0 5 0V12a9 9 0 1 0-9 9m4.5-1.206a8.959 8.959 0 0 1-4.5 1.207" /></svg>
                                                        <span x-text="(selected.publication_count || 0) + (selected.publication_count === 1 ? ' fois' : ' fois')"></span>
                                                    </span>
                                                </div>
                                            </div>

                                            {{-- Personnes (éditables) --}}
                                            <div>
                                                <span class="text-[10px] text-gray-400 block mb-1">Personnes</span>
                                                <div class="flex flex-wrap gap-1 mb-1.5">
                                                    <template x-for="p in (selected.people_ids || [])" :key="p">
                                                        <span class="inline-flex items-center gap-1 bg-pink-100 text-pink-800 text-[10px] pl-2 pr-1 py-0.5 rounded">
                                                            <span class="capitalize" x-text="p"></span>
                                                            <button @click="removePerson(p)" class="text-pink-400 hover:text-red-600 leading-none" type="button" title="Retirer cette personne">×</button>
                                                        </span>
                                                    </template>
                                                    <template x-if="!selected.people_ids || selected.people_ids.length === 0">
                                                        <span class="text-[10px] text-gray-400 italic">Aucune personne.</span>
                                                    </template>
                                                </div>
                                                <div class="flex items-center gap-1">
                                                    <input type="text" x-model="newPersonInput" placeholder="caroline, xavier..."
                                                           class="flex-1 text-xs border border-gray-200 rounded px-2 py-1 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400"
                                                           @keydown.enter.prevent="addPerson()">
                                                    <button @click="addPerson()" :disabled="!newPersonInput.trim()"
                                                            class="px-2 py-1 text-xs bg-pink-600 text-white rounded hover:bg-pink-700 disabled:opacity-50">+</button>
                                                </div>
                                            </div>

                                            {{-- Description (éditable, save on blur) --}}
                                            <div>
                                                <span class="text-[10px] text-gray-400 block mb-1">Description</span>
                                                <textarea x-model="selected.description_fr" @blur="saveDescription()" rows="3"
                                                          placeholder="Description de la photo (sert de contexte aux IA de redaction)"
                                                          class="w-full text-xs border border-gray-200 rounded px-2 py-1.5 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400 resize-none"></textarea>
                                            </div>

                                            {{-- IA Vision : deplacee dans la sidebar gauche --}}

                                            {{-- Pool / Intimacy --}}
                                            <div class="grid grid-cols-2 gap-2 text-xs">
                                                <div>
                                                    <span class="text-[10px] text-gray-400 block mb-1">Pool</span>
                                                    <div class="flex flex-wrap gap-1">
                                                        <template x-if="selected.allow_pdc_vantour">
                                                            <span class="bg-emerald-100 text-emerald-800 text-[10px] px-2 py-0.5 rounded font-medium">PdC / Vantour</span>
                                                        </template>
                                                        <template x-if="selected.allow_wildycaro">
                                                            <span class="bg-rose-100 text-rose-800 text-[10px] px-2 py-0.5 rounded font-medium">Wildycaro</span>
                                                        </template>
                                                        <template x-if="selected.allow_mamawette">
                                                            <span class="bg-purple-100 text-purple-900 text-[10px] px-2 py-0.5 rounded font-medium">🔒 Mamawette</span>
                                                        </template>
                                                        <template x-if="!selected.allow_pdc_vantour && !selected.allow_wildycaro && !selected.allow_mamawette && selected.intimacy_level !== 'never_publish'">
                                                            <span class="bg-amber-100 text-amber-800 text-[10px] px-2 py-0.5 rounded font-medium">À classer</span>
                                                        </template>
                                                        <template x-if="selected.intimacy_level === 'never_publish'">
                                                            <span class="bg-gray-700 text-white text-[10px] px-2 py-0.5 rounded font-medium">Jamais publier</span>
                                                        </template>
                                                    </div>
                                                </div>
                                                <div>
                                                    <span class="text-[10px] text-gray-400 block mb-1">Intimacy</span>
                                                    <span class="text-gray-700 font-medium text-[11px]" x-text="selected.intimacy_level || '—'"></span>
                                                </div>
                                            </div>

                                            {{-- Source pipeline --}}
                                            <template x-if="selected.source === 'mac_pipeline'">
                                                <div class="flex items-center gap-1.5 text-[10px] text-indigo-600">
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" /></svg>
                                                    <span>Analysée par le pipeline Mac</span>
                                                </div>
                                            </template>
                                            <template x-if="selected.pending_analysis">
                                                <div class="flex items-center gap-1.5 text-[10px] text-amber-600">
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                                                    <span>En attente d analyse IA</span>
                                                </div>
                                            </template>
                                    </div>

                                    {{-- Linked posts --}}
                                    <div>
                                        <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Publications liees</h4>
                                        <template x-if="selected.posts && selected.posts.length > 0">
                                            <div class="space-y-2">
                                                <template x-for="post in selected.posts" :key="post.id">
                                                    <a :href="'/posts/' + post.id"
                                                       class="block p-2.5 rounded-lg bg-gray-50 hover:bg-indigo-50 transition-colors group">
                                                        <div class="flex items-start gap-2.5">
                                                            <span class="inline-block w-2 h-2 rounded-full flex-shrink-0 mt-1.5" :class="post.status_dot"></span>
                                                            <div class="min-w-0 flex-1">
                                                                <p class="text-xs text-gray-700 group-hover:text-indigo-600 transition-colors truncate" x-text="post.preview"></p>
                                                                <p class="text-[10px] text-gray-400 mt-0.5 capitalize" x-text="post.status === 'scheduled' ? 'Planifie' : post.status === 'published' ? 'Publie' : post.status === 'publishing' ? 'En cours' : post.status === 'failed' ? 'Echoue' : 'Brouillon'"></p>
                                                            </div>
                                                        </div>
                                                        {{-- Platforms --}}
                                                        <template x-if="post.platforms && post.platforms.length > 0">
                                                            <div class="flex items-center gap-1.5 mt-1.5 ml-4.5">
                                                                <template x-for="pf in post.platforms" :key="pf.slug">
                                                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-medium"
                                                                          :class="pf.status === 'published' ? 'bg-green-100 text-green-700' : pf.status === 'failed' ? 'bg-red-100 text-red-700' : pf.status === 'scheduled' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600'"
                                                                          x-text="pf.name"></span>
                                                                </template>
                                                            </div>
                                                        </template>
                                                        {{-- Publication date --}}
                                                        <template x-if="post.published_at || post.scheduled_at">
                                                            <div class="flex items-center gap-1 mt-1 ml-4.5 text-[10px] text-gray-400">
                                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" /></svg>
                                                                <span x-text="post.published_at ? post.published_at : ('Prevu : ' + post.scheduled_at)"></span>
                                                            </div>
                                                        </template>
                                                    </a>
                                                </template>
                                            </div>
                                        </template>
                                        <template x-if="!selected.posts || selected.posts.length === 0">
                                            <p class="text-xs text-gray-400 italic">Ce media n'est lie a aucune publication.</p>
                                        </template>
                                    </div>

                                    {{-- Actions --}}
                                    <div class="flex items-center gap-2 pt-2 border-t border-gray-100">
                                        <a :href="selected.url" download
                                           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                            </svg>
                                            Telecharger
                                        </a>
                                        <button type="button" @click="deleteConfirm = true"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-red-600 bg-red-50 hover:bg-red-100 rounded-lg transition-colors ml-auto">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                            </svg>
                                            Supprimer
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        {{-- Delete file confirmation modal --}}
        <div x-show="deleteConfirm" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="deleteConfirm = false">
            <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm mx-4 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">Supprimer ce fichier ?</h3>
                        <p class="text-sm text-gray-500 mt-0.5">Cette action est irreversible.</p>
                    </div>
                </div>
                <p class="text-sm text-gray-600 mb-4 break-all" x-text="selected ? selected.filename : ''"></p>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="deleteConfirm = false"
                        class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-xl hover:bg-gray-200 transition-colors">
                        Annuler
                    </button>
                    <button type="button" @click="deleteFile()"
                        class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-xl hover:bg-red-700 transition-colors">
                        Supprimer
                    </button>
                </div>
            </div>
        </div>

        {{-- Bulk delete confirmation modal --}}
        <div x-show="bulkDeleteConfirm" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="bulkDeleteConfirm = false">
            <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm mx-4 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">Supprimer <span x-text="multiSelected.length"></span> fichier(s) ?</h3>
                        <p class="text-sm text-gray-500 mt-0.5">Cette action est irréversible.</p>
                    </div>
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="bulkDeleteConfirm = false"
                        class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-xl hover:bg-gray-200 transition-colors">
                        Annuler
                    </button>
                    <button type="button" @click="bulkDelete()"
                        class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-xl hover:bg-red-700 transition-colors">
                        Supprimer
                    </button>
                </div>
            </div>
        </div>

        {{-- Delete folder confirmation modal --}}
        <div x-show="deleteFolderConfirm" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="deleteFolderConfirm = null">
            <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm mx-4 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9.776c.112-.017.227-.026.344-.026h15.812c.117 0 .232.009.344.026m-16.5 0a2.25 2.25 0 0 0-1.883 2.542l.857 6a2.25 2.25 0 0 0 2.227 1.932H19.05a2.25 2.25 0 0 0 2.227-1.932l.857-6a2.25 2.25 0 0 0-1.883-2.542m-16.5 0V6A2.25 2.25 0 0 1 6 3.75h3.879a1.5 1.5 0 0 1 1.06.44l2.122 2.12a1.5 1.5 0 0 0 1.06.44H18A2.25 2.25 0 0 1 20.25 9v.776" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">Supprimer ce dossier ?</h3>
                        <p class="text-sm text-gray-500 mt-0.5">Les fichiers seront deplaces vers "Non classe".</p>
                    </div>
                </div>
                <p class="text-sm text-gray-600 mb-4" x-text="deleteFolderConfirm ? deleteFolderConfirm.name : ''"></p>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="deleteFolderConfirm = null"
                        class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-xl hover:bg-gray-200 transition-colors">
                        Annuler
                    </button>
                    <button type="button" @click="deleteFolder(deleteFolderConfirm)"
                        class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-xl hover:bg-red-700 transition-colors">
                        Supprimer
                    </button>
                </div>
            </div>
        </div>

        {{-- Mobile detail bottom sheet --}}
        <div x-show="selected" x-cloak class="lg:hidden fixed inset-0 z-50 bg-black/50" @click.self="selected = null">
            <div class="absolute inset-x-0 bottom-0 max-h-[85vh] bg-white rounded-t-2xl shadow-xl overflow-hidden flex flex-col">
                <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100">
                    <h3 class="text-sm font-semibold text-gray-900 truncate" x-text="selected ? selected.filename : ''"></h3>
                    <button @click="selected = null" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <template x-if="selected">
                    <div class="flex-1 overflow-y-auto">
                        <div class="bg-gray-950 flex items-center justify-center min-h-[200px] max-h-[300px]">
                            <template x-if="selected.is_image">
                                <img :src="selected.url" :alt="selected.filename" class="max-w-full max-h-[300px] object-contain">
                            </template>
                            <template x-if="selected.is_video">
                                <video :src="selected.url" controls playsinline class="max-w-full max-h-[300px] object-contain" :key="selected.filename"></video>
                            </template>
                        </div>
                        <div class="p-4 space-y-4">
                            <span class="inline-flex items-center px-2.5 py-1 text-xs font-semibold rounded-full" :class="statusColor" x-text="statusLabel"></span>

                            {{-- Mobile folder selector --}}
                            <div>
                                <label class="text-xs text-gray-400 block mb-1">Dossier</label>
                                <select @change="moveToFolder($event.target.value || null)"
                                        class="w-full text-sm border border-gray-200 rounded-lg px-2.5 py-1.5">
                                    <option value="" :selected="!selected.folder_id">Non classe</option>
                                    <template x-for="folder in folders" :key="folder.id">
                                        <option :value="folder.id" :selected="selected.folder_id == folder.id" x-text="folder.name"></option>
                                    </template>
                                </select>
                            </div>

                            <div class="grid grid-cols-2 gap-3 text-xs">
                                <div><span class="text-gray-400 block">Taille</span><span class="text-gray-700 font-medium" x-text="selected.size_human"></span></div>
                                <div><span class="text-gray-400 block">Date</span><span class="text-gray-700 font-medium" x-text="selected.date"></span></div>
                            </div>
                            <template x-if="selected.posts && selected.posts.length > 0">
                                <div class="space-y-2">
                                    <h4 class="text-xs font-semibold text-gray-500 uppercase">Publications liees</h4>
                                    <template x-for="post in selected.posts" :key="post.id">
                                        <a :href="'/posts/' + post.id" class="block p-2 rounded-lg bg-gray-50 text-xs text-gray-700">
                                            <div class="flex items-center gap-2">
                                                <span class="w-2 h-2 rounded-full flex-shrink-0" :class="post.status_dot"></span>
                                                <span class="truncate" x-text="post.preview"></span>
                                            </div>
                                        </a>
                                    </template>
                                </div>
                            </template>
                            <div class="flex gap-2 pt-2">
                                <a :href="selected.url" download class="flex-1 text-center px-3 py-2 text-xs font-medium text-gray-600 bg-gray-100 rounded-lg">Telecharger</a>
                                <button @click="deleteConfirm = true" class="flex-1 text-center px-3 py-2 text-xs font-medium text-red-600 bg-red-50 rounded-lg">Supprimer</button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <script>
    // Composant Alpine pour l'arbre de dossiers de la sidebar gauche.
    function folderTree(folders, autoOpenIds) {
        return {
            folders,
            openFolders: [...autoOpenIds],
            isOpen(id) { return this.openFolders.includes(id); },
            toggle(id) {
                const idx = this.openFolders.indexOf(id);
                if (idx >= 0) {
                    // Ferme aussi tous les descendants pour eviter qu'ils restent visibles si on rouvre.
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
            isVisible(f) {
                return f.parent_chain.every(p => this.openFolders.includes(p));
            },
        };
    }
    </script>
@endsection
