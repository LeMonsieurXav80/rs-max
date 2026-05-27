{{--
    Modal "Bibliothèque de médias" partagé entre /posts/create, /posts/{id}/edit et /posts/bulk.

    Attendus côté x-data parent (en plus de mediaLibraryData()) :
      - selectFromLibrary(item)  : que faire quand l'utilisateur clique un media
      - isInMedia(item)          : true si l'item est déjà sélectionné (affichage coche)

    Voir window.mediaLibraryData() injecté plus bas pour les états/méthodes communs.
--}}
<div x-show="showLibrary" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showLibrary = false">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-5xl max-h-[85vh] flex flex-col mx-4">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
            <h3 class="text-base font-semibold text-gray-900">Bibliothèque de médias</h3>
            <button type="button" @click="showLibrary = false" class="text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <div class="flex-1 flex min-h-0 overflow-hidden">
            {{-- Sidebar : arbre des dossiers --}}
            <aside class="w-60 flex-shrink-0 border-r border-gray-200 overflow-y-auto p-3 space-y-0.5 bg-gray-50/50">
                <h4 class="text-[10px] font-semibold uppercase tracking-widest text-gray-400 px-2 pb-1">Dossiers</h4>
                <button type="button" @click="filterLibraryFolder(null)"
                        class="w-full flex items-center justify-between px-2 py-1.5 rounded-lg text-sm transition-colors"
                        :class="!libraryFolder ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-gray-600 hover:bg-gray-100'">
                    <span>Tous les dossiers</span>
                    <span class="text-xs text-gray-400" x-text="libraryTotalCount"></span>
                </button>
                <button type="button" @click="filterLibraryFolder('uncategorized')"
                        class="w-full flex items-center justify-between px-2 py-1.5 rounded-lg text-sm transition-colors"
                        :class="libraryFolder === 'uncategorized' ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-gray-600 hover:bg-gray-100'">
                    <span class="text-gray-500 italic">Sans dossier</span>
                    <span class="text-xs text-gray-400" x-text="libraryUncategorizedCount"></span>
                </button>
                <template x-for="f in libraryFolders" :key="f.id">
                    <div x-show="isLibraryFolderVisible(f)" class="flex items-center"
                         :style="`padding-left: ${f.depth * 8}px`">
                        <button x-show="f.has_children" @click.stop="toggleLibraryFolderOpen(f.id)" type="button"
                                class="w-4 h-4 flex items-center justify-center text-gray-400 hover:text-gray-700 flex-shrink-0">
                            <svg class="w-3 h-3 transition-transform" :class="isLibraryFolderOpen(f.id) && 'rotate-90'" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                        </button>
                        <span x-show="!f.has_children" class="w-4 h-4 flex-shrink-0"></span>
                        <button type="button" @click="filterLibraryFolder(f.id)"
                                class="flex-1 flex items-center justify-between px-2 py-1.5 rounded-lg text-sm transition-colors min-w-0"
                                :class="String(libraryFolder) === String(f.id) ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-gray-600 hover:bg-gray-100'">
                            <span class="flex items-center gap-2 min-w-0 truncate">
                                <span class="w-2 h-2 rounded-sm flex-shrink-0" :style="`background-color: ${f.color || '#9ca3af'}`"></span>
                                <span class="truncate" x-text="f.name"></span>
                                <template x-if="f.is_private">
                                    <svg class="w-3 h-3 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                                </template>
                            </span>
                            <span class="text-xs text-gray-400 flex-shrink-0" x-text="f.files_count"></span>
                        </button>
                    </div>
                </template>
            </aside>

            {{-- Grille des médias --}}
            <div class="flex-1 overflow-y-auto p-6 min-w-0">
                <div x-show="libraryLoading" class="text-center py-8">
                    <svg class="w-8 h-8 text-gray-400 mx-auto animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                </div>
                <div x-show="!libraryLoading && libraryItems.length === 0" class="text-center py-8 text-sm text-gray-400">
                    Aucun média dans ce dossier.
                </div>
                <div x-show="!libraryLoading" class="grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-5 gap-3">
                    <template x-for="item in libraryItems" :key="item.url">
                        <div @click="selectFromLibrary(item)"
                             class="relative rounded-xl overflow-hidden border-2 aspect-square cursor-pointer transition-all"
                             :class="isInMedia(item) ? 'border-indigo-500 ring-2 ring-indigo-200' : 'border-gray-200 hover:border-indigo-300'">
                            <template x-if="item.is_image">
                                <img :src="item.url" class="w-full h-full object-cover">
                            </template>
                            <template x-if="item.is_video">
                                <div class="w-full h-full relative">
                                    <img :src="item.thumbnail_url || '/media/thumbnail/' + item.filename" class="w-full h-full object-cover" loading="lazy"
                                         x-on:error="$el.style.display='none'; $el.nextElementSibling.style.display='flex'">
                                    <div class="w-full h-full flex-col items-center justify-center bg-gray-900 text-white" style="display:none">
                                        <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                    </div>
                                    <div class="absolute inset-0 flex items-center justify-center">
                                        <div class="w-8 h-8 rounded-full bg-black/50 flex items-center justify-center">
                                            <svg class="w-4 h-4 text-white ml-0.5" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                        </div>
                                    </div>
                                </div>
                            </template>
                            <div x-show="isInMedia(item)" class="absolute top-1.5 right-1.5 w-5 h-5 bg-indigo-500 text-white rounded-full flex items-center justify-center">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                </svg>
                            </div>
                            {{-- Badge statut publication (deja publie / planifie / etc.) --}}
                            <template x-if="item.status_label">
                                <div class="absolute top-1.5 left-1.5 px-1.5 py-0.5 text-[10px] font-semibold rounded-full leading-tight"
                                     :class="item.status_class" x-text="item.status_label"></div>
                            </template>
                            <div class="absolute bottom-1 left-1 px-1.5 py-0.5 bg-black/60 text-white text-xs rounded" x-text="item.size_human"></div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
        <div class="px-6 py-3 border-t border-gray-200 flex justify-end">
            <button type="button" @click="showLibrary = false" class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors">
                Fermer
            </button>
        </div>
    </div>
</div>

@once
    @push('scripts')
    <script>
        // États et méthodes communs du modal Bibliothèque — à fusionner dans le x-data parent
        // via le spread : x-data="{ ...mediaLibraryData(), selectFromLibrary(item) {...}, isInMedia(item) {...} }".
        window.mediaLibraryData = function () {
            return {
                showLibrary: false,
                libraryItems: [],
                libraryFolders: [],
                libraryFolder: null,
                libraryLoading: false,
                libraryOpenFolders: [],
                libraryTotalCount: 0,
                libraryUncategorizedCount: 0,
                async openLibrary() {
                    this.showLibrary = true;
                    this.libraryFolder = null;
                    await this.fetchLibrary();
                },
                async fetchLibrary(folder) {
                    this.libraryLoading = true;
                    try {
                        let url = '{{ route('media.list') }}';
                        if (folder) url += '?folder=' + folder;
                        const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
                        const data = await response.json();
                        this.libraryItems = data.items || [];
                        this.libraryFolders = data.folders || [];
                        this.libraryTotalCount = data.totalCount ?? 0;
                        this.libraryUncategorizedCount = data.uncategorizedCount ?? 0;
                    } catch (e) {
                        this.libraryItems = [];
                    } finally {
                        this.libraryLoading = false;
                    }
                },
                async filterLibraryFolder(folderId) {
                    this.libraryFolder = folderId;
                    // Auto-ouvre les ancêtres pour que le dossier sélectionné soit visible dans l'arbre.
                    if (folderId && folderId !== 'uncategorized') {
                        const target = this.libraryFolders.find(f => f.id == folderId);
                        if (target && Array.isArray(target.parent_chain)) {
                            target.parent_chain.forEach(p => {
                                if (!this.libraryOpenFolders.includes(p)) this.libraryOpenFolders.push(p);
                            });
                        }
                    }
                    await this.fetchLibrary(folderId);
                },
                isLibraryFolderOpen(id) { return this.libraryOpenFolders.includes(id); },
                toggleLibraryFolderOpen(id) {
                    const idx = this.libraryOpenFolders.indexOf(id);
                    if (idx >= 0) {
                        // Ferme aussi tous les descendants
                        const descendants = new Set([id]);
                        let added;
                        do {
                            added = false;
                            for (const f of this.libraryFolders) {
                                if (descendants.has(f.parent_id) && !descendants.has(f.id)) {
                                    descendants.add(f.id);
                                    added = true;
                                }
                            }
                        } while (added);
                        this.libraryOpenFolders = this.libraryOpenFolders.filter(o => !descendants.has(o));
                    } else {
                        this.libraryOpenFolders.push(id);
                    }
                },
                isLibraryFolderVisible(f) {
                    return (f.parent_chain || []).every(p => this.libraryOpenFolders.includes(p));
                },
            };
        };
    </script>
    @endpush
@endonce
