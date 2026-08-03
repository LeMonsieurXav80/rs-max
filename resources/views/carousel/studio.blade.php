@extends('layouts.app')

@section('title', 'Studio carrousel')

@section('content')
{{-- Pas de x-init="init()" : Alpine appelle déjà init() tout seul quand la
     donnée en expose un. Le doubler rejouait l'injection du brouillon (?draft=)
     et dupliquait ses slides. --}}
<div x-data="carouselStudio()" class="max-w-7xl">

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Studio carrousel</h1>
            <p class="text-sm text-gray-500 mt-0.5">Compose un carrousel à partir de briques, aperçu en direct, puis génère les images.</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_560px] gap-6 items-start">

        {{-- ─────────────── Colonne composition ─────────────── --}}
        <div class="space-y-5">

            {{-- Ratio --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <label class="block text-sm font-medium text-gray-700 mb-2">Format du carrousel</label>
                <div class="flex flex-wrap gap-2">
                    <template x-for="(dims, key) in ratios" :key="key">
                        <button type="button" @click="ratio = key"
                                class="px-3 py-1.5 rounded-lg text-sm font-medium border transition-colors"
                                :class="ratio === key ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-gray-200 text-gray-600 hover:border-gray-300'">
                            <span x-text="key"></span>
                            <span class="text-xs text-gray-400" x-text="'· ' + dims.label"></span>
                        </button>
                    </template>
                </div>
                <p class="text-xs text-gray-400 mt-2">Toutes les slides partagent ce format (contrainte Instagram).</p>
            </div>

            {{-- Apparence : couleurs + polices, appliquées à TOUT le carrousel --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="flex items-center justify-between mb-3">
                    <label class="block text-sm font-medium text-gray-700">Apparence</label>
                    <button type="button" @click="resetTheme()" class="text-xs text-gray-400 hover:text-indigo-600">
                        Rétablir l’apparence par défaut
                    </button>
                </div>

                {{--
                    Une couleur non réglée HÉRITE de celle dont elle dérive (texte
                    secondaire → texte, etc.) : le sélecteur montre la couleur
                    effective, le champ texte reste vide et dit d'où elle vient.
                    Le ✕ rend la couleur à son héritage.
                --}}
                {{-- 2 colonnes, quel que soit le nombre de couleurs : au-delà, la
                     colonne d'édition s'élargit et pousse l'aperçu hors écran. --}}
                <div class="grid grid-cols-2 gap-3 mb-4">
                    <template x-for="c in colorFields" :key="c.key">
                        <div>
                            <div class="flex items-center justify-between mb-1 gap-1">
                                <label class="block text-xs text-gray-500 truncate" x-text="c.label"></label>
                                <button type="button" x-show="c.fallback && theme[c.key]" @click="clearColor(c.key)"
                                        class="text-[10px] text-gray-300 hover:text-indigo-600 shrink-0"
                                        title="Revenir à la couleur héritée">✕</button>
                            </div>
                            {{-- min-w-0 : sans lui le champ hex refuse de rétrécir et
                                 élargit toute la colonne d'édition. --}}
                            <div class="flex items-center gap-2 min-w-0">
                                <input type="color" :value="resolveColor(c.key)" @input="setColor(c.key, $event.target.value)"
                                       class="w-8 h-8 shrink-0 rounded border border-gray-200 cursor-pointer bg-white p-0.5">
                                <input type="text" :value="theme[c.key] || ''" :placeholder="resolveColor(c.key)"
                                       @input="setColor(c.key, $event.target.value)"
                                       class="w-full rounded-lg border-gray-300 text-xs font-mono focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                            <p class="text-[10px] text-gray-400 mt-1" x-text="theme[c.key] ? c.hint : (inheritedFrom(c) || c.hint)"></p>
                        </div>
                    </template>
                </div>

                {{-- Sélecteur de police : catalogue Google complet, chaque nom
                     affiché DANS sa propre typo. Les polices d'aperçu sont chargées
                     depuis Google (c'est une page web) ; le rendu, lui, utilise
                     toujours la copie locale. --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <template x-for="picker in ['title_font', 'body_font']" :key="picker">
                        {{-- La garde `openPicker === picker` est indispensable : les deux
                             pickers partagent le même état, donc le clic qui ouvre l'un est
                             « outside » de l'autre, dont le handler refermait aussitôt. --}}
                        <div class="relative" @click.outside="if (openPicker === picker) openPicker = null">
                            <label class="block text-xs text-gray-500 mb-1"
                                   x-text="picker === 'title_font' ? 'Police des titres' : 'Police des textes'"></label>

                            <button type="button" @click="togglePicker(picker)"
                                    class="w-full flex items-center justify-between rounded-lg border border-gray-300 px-3 py-2 text-sm text-left hover:border-indigo-300">
                                <span :style="`font-family:'${theme[picker]}', sans-serif`" x-text="theme[picker]"></span>
                                <span class="text-gray-400 text-xs">▾</span>
                            </button>

                            <div x-show="openPicker === picker" x-cloak
                                 class="absolute z-30 mt-1 w-full bg-white rounded-xl border border-gray-200 shadow-lg">
                                <div class="p-2 border-b border-gray-100">
                                    <input type="text" x-model="fontSearch" @input="loadPreviewFonts()"
                                           placeholder="Chercher parmi 1900+ polices…"
                                           class="w-full rounded-lg border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                </div>
                                <div class="max-h-72 overflow-y-auto py-1">
                                    <template x-for="f in filteredFonts()" :key="f.family">
                                        <button type="button" @click="chooseFont(picker, f.family)"
                                                class="w-full text-left px-3 py-2 hover:bg-indigo-50"
                                                :class="theme[picker] === f.family && 'bg-indigo-50'">
                                            <span class="block text-base leading-tight text-gray-900"
                                                  :style="`font-family:'${f.family}', sans-serif`" x-text="f.family"></span>
                                            <span class="block text-[10px] text-gray-400" x-text="f.category"></span>
                                        </button>
                                    </template>
                                    <p x-show="!filteredFonts().length" class="px-3 py-4 text-xs text-gray-400">
                                        Aucune police ne correspond.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
                <p class="text-[10px] text-gray-400 mt-2">
                    La police choisie est téléchargée une fois puis servie par nos soins : le rendu final ne dépend jamais de Google.
                </p>

                {{-- Échelle typographique : les briques dimensionnent leur texte en
                     fraction de la hauteur du slide, ces curseurs multiplient. --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-4">
                    <template x-for="s in scaleFields" :key="s.key">
                        <div>
                            <div class="flex items-baseline justify-between mb-1">
                                <label class="block text-xs text-gray-500" x-text="s.label"></label>
                                <span class="text-[10px] text-gray-400 font-mono"
                                      x-text="Math.round((theme[s.key] ?? 1) * 100) + '%'"></span>
                            </div>
                            <input type="range" :min="scaleMin" :max="scaleMax" step="0.05"
                                   x-model.number="theme[s.key]" @input="queuePreview()"
                                   class="w-full accent-indigo-600">
                            <p class="text-[10px] text-gray-400 mt-1" x-text="s.hint"></p>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Slides --}}
            <div class="space-y-4">
                <template x-for="(slide, i) in slides" :key="slide._id">
                    {{-- Éditer une slide amène l'aperçu dessus : avec 8 slides, on ne cherche plus. --}}
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5"
                         @focusin="scrollPreviewTo(i)">
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-indigo-100 text-indigo-700 text-xs font-semibold" x-text="i + 1"></span>
                                <select x-model="slide.brick" @change="onBrickChange(i)"
                                        class="rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <template x-for="b in bricks" :key="b.slug">
                                        <option :value="b.slug" x-text="b.name"></option>
                                    </template>
                                </select>
                            </div>
                            <div class="flex items-center gap-1">
                                <button type="button" @click="moveSlide(i, -1)" :disabled="i === 0"
                                        class="p-1.5 rounded-lg text-gray-400 hover:bg-gray-100 disabled:opacity-30" title="Monter">▲</button>
                                <button type="button" @click="moveSlide(i, 1)" :disabled="i === slides.length - 1"
                                        class="p-1.5 rounded-lg text-gray-400 hover:bg-gray-100 disabled:opacity-30" title="Descendre">▼</button>
                                <button type="button" @click="removeSlide(i)" :disabled="slides.length === 1"
                                        class="p-1.5 rounded-lg text-red-400 hover:bg-red-50 disabled:opacity-30" title="Supprimer">✕</button>
                            </div>
                        </div>

                        <p class="text-xs text-gray-400 mb-3" x-text="brickDef(slide.brick).description"></p>

                        <div class="space-y-3">
                            {{-- Champs générés à partir des slots TYPÉS du manifeste. --}}
                            <template x-for="slot in brickDef(slide.brick).slots" :key="slot.key">
                                <div>
                                    {{-- Une case à cocher porte son propre libellé, à côté d'elle. --}}
                                    <label x-show="slot.type !== 'toggle'" class="block text-xs font-medium text-gray-600 mb-1">
                                        <span x-text="slot.label"></span>
                                        <span x-show="slot.type === 'range'" class="text-gray-400"
                                              x-text="'· ' + (slide.data[slot.key] ?? 0) + (slot.unit || '')"></span>
                                    </label>

                                    {{-- Slot case à cocher (continuité d'image…) --}}
                                    <template x-if="slot.type === 'toggle'">
                                        <label class="flex items-start gap-2 cursor-pointer">
                                            <input type="checkbox" x-model="slide.data[slot.key]" @change="queuePreview()"
                                                   class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                            <span class="text-xs text-gray-600" x-text="slot.label"></span>
                                        </label>
                                    </template>

                                    {{-- Slot image --}}
                                    <template x-if="slot.type === 'image'">
                                        <div class="flex items-center gap-3">
                                            <template x-if="slide.data._thumb">
                                                <img :src="slide.data._thumb" class="w-14 h-14 rounded-lg object-cover border border-gray-200">
                                            </template>
                                            <button type="button" @click="pickImageFor(i)"
                                                    class="px-3 py-1.5 rounded-lg text-sm border border-gray-200 text-gray-600 hover:border-indigo-300 hover:text-indigo-600">
                                                <span x-text="slide.data.image ? 'Changer l’image' : 'Choisir une image'"></span>
                                            </button>
                                            <button type="button" x-show="slide.data.image" @click="clearImage(i)"
                                                    class="text-xs text-red-400 hover:text-red-600">retirer</button>
                                        </div>
                                    </template>

                                    {{-- Slot texte long --}}
                                    <template x-if="slot.type === 'textarea'">
                                        <textarea x-model="slide.data[slot.key]" @input="queuePreview()" rows="2"
                                                  class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                                    </template>

                                    {{-- Slot position : grille d'ancres 3×3 --}}
                                    <template x-if="slot.type === 'position'">
                                        <div class="inline-grid grid-cols-3 gap-1 p-1.5 rounded-lg bg-gray-50 border border-gray-200">
                                            <template x-for="(pLabel, pKey) in slot.options" :key="pKey">
                                                <button type="button" :title="pLabel"
                                                        @click="slide.data[slot.key] = pKey; queuePreview()"
                                                        class="w-7 h-7 rounded flex items-center justify-center transition-colors"
                                                        :class="slide.data[slot.key] === pKey ? 'bg-indigo-600' : 'bg-white hover:bg-indigo-100 border border-gray-200'">
                                                    <span class="w-1.5 h-1.5 rounded-full"
                                                          :class="slide.data[slot.key] === pKey ? 'bg-white' : 'bg-gray-400'"></span>
                                                </button>
                                            </template>
                                        </div>
                                    </template>

                                    {{-- Slot plage : décalage fin --}}
                                    <template x-if="slot.type === 'range'">
                                        <div class="flex items-center gap-2">
                                            <input type="range" :min="slot.min" :max="slot.max" :step="slot.step"
                                                   x-model.number="slide.data[slot.key]" @input="queuePreview()"
                                                   class="flex-1 accent-indigo-600">
                                            <button type="button" @click="slide.data[slot.key] = slot.default ?? 0; queuePreview()"
                                                    class="text-xs text-gray-400 hover:text-indigo-600">réinit.</button>
                                        </div>
                                    </template>

                                    {{-- Slot liste --}}
                                    <template x-if="slot.type === 'select'">
                                        <select x-model="slide.data[slot.key]" @change="queuePreview()"
                                                class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                            <template x-for="(oLabel, oKey) in slot.options" :key="oKey">
                                                <option :value="oKey" x-text="oLabel"></option>
                                            </template>
                                        </select>
                                    </template>

                                    {{-- Slot texte court (défaut) --}}
                                    <template x-if="slot.type === 'text'">
                                        <input type="text" x-model="slide.data[slot.key]" @input="queuePreview()"
                                               :maxlength="slot.max_length"
                                               class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Ajouter une brique --}}
            <div class="bg-gray-50 rounded-2xl border border-dashed border-gray-200 p-5">
                <p class="text-xs font-medium text-gray-500 mb-2">Ajouter une slide</p>
                <div class="flex flex-wrap gap-2">
                    <template x-for="b in bricks" :key="b.slug">
                        <button type="button" @click="addSlide(b.slug)"
                                class="px-3 py-1.5 rounded-lg text-sm bg-white border border-gray-200 text-gray-700 hover:border-indigo-300 hover:text-indigo-600"
                                :title="b.description">
                            + <span x-text="b.name"></span>
                        </button>
                    </template>
                </div>
            </div>
        </div>

        {{-- ─────────────── Colonne aperçu ─────────────── --}}
        <div class="lg:sticky lg:top-6 space-y-4">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-sm font-semibold text-gray-900">Aperçu</span>
                    <span class="text-xs text-gray-400" x-text="slides.length + ' slide' + (slides.length > 1 ? 's' : '') + ' · ' + ratio"></span>
                </div>

                <div class="relative" x-ref="previewBox">
                    {{--
                        Une slide = toute la largeur de la colonne, quel qu'en soit le nombre :
                        on DÉFILE horizontalement dans la bande au lieu de la rétrécir.
                        Le scroll s'aimante sur chaque couture (scroll-snap).
                    --}}
                    <div class="mx-auto bg-gray-100 rounded-lg overflow-x-auto overflow-y-hidden" x-ref="previewScroll"
                         @scroll.debounce.80ms="onPreviewScroll()"
                         :style="`width:${viewportW}px; height:${slideVisualHeight()}px; scroll-snap-type:x mandatory; scroll-behavior:smooth;`">
                        <div class="relative" :style="`width:${slides.length * slideW}px; height:${slideVisualHeight()}px`">
                            <iframe :srcdoc="previewHtml" scrolling="no"
                                    class="border-0 origin-top-left pointer-events-none absolute top-0 left-0"
                                    :style="`width:${bandCssWidth()}px; height:${ratios[ratio].h}px; transform:scale(${previewScale()});`"></iframe>
                            {{-- Une zone par slide : porte l'aimant de défilement et la couture verticale --}}
                            <div class="absolute inset-0 flex pointer-events-none">
                                <template x-for="(s, n) in slides" :key="s._id">
                                    <div class="h-full shrink-0" :style="`width:${slideW}px; scroll-snap-align:start;`"
                                         :class="n ? 'border-l border-dashed border-white/70' : ''"></div>
                                </template>
                            </div>
                        </div>
                    </div>

                    {{-- Navigation slide par slide (le trackpad défile déjà, la barre reste discrète sur Mac) --}}
                    <template x-if="slides.length > 1">
                        <div class="flex items-center justify-center gap-2 mt-2">
                            <button type="button" @click="scrollPreviewBy(-1)"
                                    class="px-2 py-0.5 rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50 text-sm">‹</button>
                            <span class="text-[11px] text-gray-400" x-text="`slide ${previewIndex + 1} / ${slides.length}`"></span>
                            <button type="button" @click="scrollPreviewBy(1)"
                                    class="px-2 py-0.5 rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50 text-sm">›</button>
                        </div>
                    </template>

                    <div x-show="previewLoading" class="absolute top-2 right-2 text-[10px] px-2 py-0.5 rounded-full bg-black/50 text-white">…</div>
                </div>
            </div>

            <button type="button" @click="generate()" :disabled="rendering"
                    class="w-full py-2.5 rounded-xl bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 disabled:opacity-50">
                <span x-show="!rendering">Générer les images</span>
                <span x-show="rendering">Génération…</span>
            </button>
            <p x-show="renderError" x-text="renderError" class="text-xs text-red-500"></p>

            {{-- Résultats --}}
            <div x-show="generated.length" class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4">
                <p class="text-sm font-semibold text-gray-900 mb-1">Images générées</p>
                <p class="text-xs text-gray-400 mb-3">Ajoutées à la médiathèque (source studio).</p>
                <div class="grid grid-cols-3 gap-2">
                    <template x-for="item in generated" :key="item.id">
                        <a :href="item.url" target="_blank" class="block">
                            <img :src="item.thumbnail_url" class="w-full aspect-square object-cover rounded-lg border border-gray-200">
                        </a>
                    </template>
                </div>
            </div>
        </div>
    </div>

    @include('posts._media-library')
</div>

@push('scripts')
<script>
    function carouselStudio() {
        return {
            ...window.mediaLibraryData(),

            ratios: @json($ratios),
            bricks: @json($bricks),
            ratio: @json($defaultRatio),
            theme: @json($theme),
            defaultTheme: @json($theme),
            draft: @json($draft),
            fontCatalogue: @json($fontCatalogue),
            fontSearch: '',
            openPicker: null,
            // Palette servie par le manifeste (config/carousel.theme_colors).
            colorFields: @json($themeColors),
            scaleMin: @json($scaleRange['min']),
            scaleMax: @json($scaleRange['max']),
            scaleFields: [
                { key: 'title_scale', label: 'Taille des titres', hint: '100 % = taille native de la brique' },
                { key: 'body_scale', label: 'Taille des textes', hint: 'Sous-titres, corps, libellés' },
            ],
            slides: [],
            previewHtml: '',
            previewLoading: false,
            slideW: 348,      // largeur d'affichage d'UNE slide
            viewportW: 348,   // fenêtre visible de l'aperçu (= largeur de la colonne)
            previewIndex: 0,  // slide actuellement au centre du défilement
            rendering: false,
            renderError: '',
            generated: [],
            pickingIndex: null,
            _seq: 0,
            _timer: null,

            init() {
                // Composition déposée par l'API (?draft=…) : on reprend le travail
                // là où il a été laissé plutôt que de démarrer sur une slide vierge.
                if (this.draft) {
                    this.ratio = this.draft.ratio || this.ratio;
                    this.theme = { ...this.theme, ...(this.draft.theme || {}) };
                    for (const slide of this.draft.slides) {
                        this.slides.push({
                            _id: ++this._seq,
                            brick: slide.brick,
                            // Les défauts de la brique comblent les slots que le
                            // brouillon n'a pas renseignés (position, décalage…).
                            data: { ...this.defaultsFor(slide.brick), ...slide.data },
                        });
                    }
                }

                // Une première slide par défaut avec la brique overlay.
                if (!this.slides.length) {
                    this.addSlide(this.bricks[0]?.slug, false);
                }

                // Le ratio change la largeur/hauteur d'affichage d'une slide : re-fit.
                this.$watch('ratio', () => { this.fitPreview(); this.queuePreview(); });
                window.addEventListener('resize', () => this.fitPreview());
                this.$nextTick(() => this.fitPreview());
                this.updatePreview();
            },

            brickDef(slug) {
                return this.bricks.find(b => b.slug === slug) || { slots: [], description: '' };
            },

            // Valeurs initiales issues des `default` du manifeste (position, décalage…).
            defaultsFor(slug) {
                const data = {};
                for (const slot of this.brickDef(slug).slots) {
                    if (slot.default !== null && slot.default !== undefined) data[slot.key] = slot.default;
                }
                return data;
            },

            addSlide(slug, refresh = true) {
                if (!slug) return;
                this.slides.push({ _id: ++this._seq, brick: slug, data: this.defaultsFor(slug) });
                // L'aperçu suit : on amène la slide qu'on vient d'ajouter sous les yeux.
                if (refresh) {
                    this.queuePreview();
                    this.$nextTick(() => this.scrollPreviewTo(this.slides.length - 1));
                }
            },
            // Changement de brique : on complète avec les défauts de la nouvelle
            // (les slots devenus inutiles sont ignorés côté serveur).
            onBrickChange(i) {
                this.slides[i].data = { ...this.defaultsFor(this.slides[i].brick), ...this.slides[i].data };
                this.queuePreview();
            },
            removeSlide(i) {
                if (this.slides.length === 1) return;
                this.slides.splice(i, 1);
                this.queuePreview();
                this.$nextTick(() => this.scrollPreviewTo(Math.min(i, this.slides.length - 1)));
            },
            moveSlide(i, dir) {
                const j = i + dir;
                if (j < 0 || j >= this.slides.length) return;
                [this.slides[i], this.slides[j]] = [this.slides[j], this.slides[i]];
                this.queuePreview();
            },

            // ── Sélection d'image (réutilise le modal médiathèque) ──
            pickImageFor(i) {
                this.pickingIndex = i;
                this.openLibrary();
            },
            selectFromLibrary(item) {
                if (this.pickingIndex === null) return;
                this.slides[this.pickingIndex].data.image = item.url;
                this.slides[this.pickingIndex].data._thumb = item.thumbnail_url || item.url;
                this.showLibrary = false;
                this.pickingIndex = null;
                this.queuePreview();
            },
            isInMedia(item) {
                return this.pickingIndex !== null && this.slides[this.pickingIndex]?.data.image === item.url;
            },
            clearImage(i) {
                delete this.slides[i].data.image;
                delete this.slides[i].data._thumb;
                this.queuePreview();
            },

            // ── Aperçu live (pas de Chromium) ──
            // ── Apparence ──
            // Couleur effective : valeur réglée, sinon on remonte la chaîne
            // d'héritage du manifeste (même logique que Palette côté serveur).
            resolveColor(key) {
                const seen = new Set();
                while (key && !seen.has(key)) {
                    seen.add(key);
                    if (this.theme[key]) return this.theme[key];
                    const c = this.colorFields.find(f => f.key === key);
                    if (!c) break;
                    if (c.default) return c.default;
                    key = c.fallback;
                }
                return '#000000';
            },
            inheritedFrom(c) {
                const src = c.fallback && this.colorFields.find(f => f.key === c.fallback);
                return src ? `Hérite de « ${src.label} »` : '';
            },
            setColor(key, value) {
                this.theme[key] = value;
                this.queuePreview();
            },
            // Rendre la couleur à son héritage : on retire la clé plutôt que d'y
            // recopier la couleur héritée, sinon elle cesserait de suivre.
            clearColor(key) {
                delete this.theme[key];
                this.queuePreview();
            },
            resetTheme() {
                this.theme = { ...this.defaultTheme };
                this.queuePreview();
            },
            // ── Choix de police (catalogue Google complet) ──
            togglePicker(picker) {
                this.openPicker = this.openPicker === picker ? null : picker;
                if (this.openPicker) {
                    this.fontSearch = '';
                    this.loadPreviewFonts();
                }
            },
            filteredFonts() {
                const q = this.fontSearch.trim().toLowerCase();
                const list = q
                    ? this.fontCatalogue.filter(f => f.family.toLowerCase().includes(q))
                    : this.fontCatalogue;
                return list.slice(0, 40);
            },
            chooseFont(picker, family) {
                this.theme[picker] = family;
                this.openPicker = null;
                // Le serveur télécharge la copie locale au premier rendu.
                this.updatePreview();
            },
            // Charge depuis Google les polices actuellement listées, pour que chaque
            // nom s'affiche dans sa propre typo. Un seul <link> par lot, limité aux
            // caractères réellement affichés (paramètre `text`) pour rester léger.
            loadPreviewFonts() {
                const families = this.filteredFonts().map(f => f.family);
                if (!families.length) return;

                const chars = [...new Set(families.join(''))].join('');
                const href = 'https://fonts.googleapis.com/css2?'
                    + families.map(f => 'family=' + encodeURIComponent(f)).join('&')
                    + '&text=' + encodeURIComponent(chars)
                    + '&display=swap';

                let link = document.getElementById('carousel-font-previews');
                if (!link) {
                    link = document.createElement('link');
                    link.id = 'carousel-font-previews';
                    link.rel = 'stylesheet';
                    document.head.appendChild(link);
                }
                link.href = href;
            },

            payload() {
                return {
                    ratio: this.ratio,
                    theme: this.theme,
                    slides: this.slides.map(s => {
                        const data = {};
                        for (const [k, v] of Object.entries(s.data)) {
                            if (k === '_thumb') continue;
                            data[k] = v;
                        }
                        return { brick: s.brick, data };
                    }),
                };
            },
            queuePreview() {
                clearTimeout(this._timer);
                this._timer = setTimeout(() => this.updatePreview(), 350);
            },
            async updatePreview() {
                const seq = ++this._seq;
                this.fitPreview();
                this.previewLoading = true;
                try {
                    const resp = await fetch('{{ route('carousel.studio.preview') }}', {
                        method: 'POST',
                        headers: this.headers(),
                        body: JSON.stringify(this.payload()),
                    });
                    const html = await resp.text();
                    if (seq === this._seq) this.previewHtml = html;
                } catch (e) {
                    // aperçu best-effort
                } finally {
                    this.previewLoading = false;
                }
            },

            // ── Génération finale (Browsershot) ──
            async generate() {
                this.rendering = true;
                this.renderError = '';
                try {
                    const resp = await fetch('{{ route('carousel.studio.render') }}', {
                        method: 'POST',
                        headers: this.headers(),
                        body: JSON.stringify(this.payload()),
                    });
                    const data = await resp.json();
                    if (!resp.ok) {
                        this.renderError = data.message || 'Échec de la génération.';
                        return;
                    }
                    this.generated = data.items || [];
                } catch (e) {
                    this.renderError = 'Erreur réseau lors de la génération.';
                } finally {
                    this.rendering = false;
                }
            },

            // ── Helpers présentation (bande horizontale défilante) ──
            // UNE slide occupe toute la largeur de la colonne : la taille d'aperçu ne
            // dépend donc PAS du nombre de slides (on défile pour voir les suivantes).
            // Seul un format très haut (9:16) est rétréci, pour tenir dans la fenêtre.
            fitPreview() {
                const avail = this.$refs.previewBox?.clientWidth || 348;
                const maxH = window.innerHeight * 0.72;
                this.viewportW = avail;
                this.slideW = Math.min(avail, maxH * this.ratioW() / this.ratioH());
            },
            ratioW() { return this.ratios[this.ratio]?.w || 1080; },
            ratioH() { return this.ratios[this.ratio]?.h || 1350; },
            bandCssWidth() { return this.slides.length * this.ratioW(); },
            previewScale() { return this.slideW / this.ratioW(); },
            slideVisualHeight() { return this.ratioH() * this.previewScale(); },

            // Défilement aimanté d'une slide, dans un sens ou dans l'autre.
            scrollPreviewBy(dir) {
                this.scrollPreviewTo(this.previewIndex + dir);
            },
            scrollPreviewTo(i) {
                const box = this.$refs.previewScroll;
                if (!box) return;
                const target = Math.max(0, Math.min(this.slides.length - 1, i));
                box.scrollTo({ left: target * this.slideW, behavior: 'smooth' });
                this.previewIndex = target;
            },
            // Le défilement peut aussi venir du trackpad : l'indicateur suit.
            onPreviewScroll() {
                if (!this.slideW) return;
                this.previewIndex = Math.round(this.$refs.previewScroll.scrollLeft / this.slideW);
            },
            headers() {
                return {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                };
            },
        };
    }
</script>
@endpush
@endsection
