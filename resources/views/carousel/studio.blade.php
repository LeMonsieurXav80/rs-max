@extends('layouts.app')

@section('title', 'Studio carrousel')

@section('content')
<div x-data="carouselStudio()" x-init="init()" class="max-w-7xl">

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Studio carrousel</h1>
            <p class="text-sm text-gray-500 mt-0.5">Compose un carrousel à partir de briques, aperçu en direct, puis génère les images.</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-[1fr_380px] gap-6 items-start">

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

            {{-- Slides --}}
            <div class="space-y-4">
                <template x-for="(slide, i) in slides" :key="slide._id">
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-indigo-100 text-indigo-700 text-xs font-semibold" x-text="i + 1"></span>
                                <select x-model="slide.brick" @change="queuePreview()"
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
                            <template x-for="(label, key) in brickDef(slide.brick).slots" :key="key">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1" x-text="label"></label>

                                    {{-- Slot image --}}
                                    <template x-if="key === 'image'">
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
                                    <template x-if="key === 'body'">
                                        <textarea x-model="slide.data.body" @input="queuePreview()" rows="2"
                                                  class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                                    </template>

                                    {{-- Slots texte court --}}
                                    <template x-if="key !== 'image' && key !== 'body'">
                                        <input type="text" x-model="slide.data[key]" @input="queuePreview()"
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

                <div class="relative">
                    {{-- Toutes les slides côte à côte, ajustées pour tenir dans la largeur (pas de défilement). --}}
                    <div class="mx-auto bg-gray-100 rounded-lg overflow-hidden"
                         :style="`width:${previewW}px; height:${slideVisualHeight()}px`">
                        <div class="relative" :style="`width:${previewW}px; height:${slideVisualHeight()}px`">
                            <iframe :srcdoc="previewHtml" scrolling="no"
                                    class="border-0 origin-top-left pointer-events-none absolute top-0 left-0"
                                    :style="`width:${bandCssWidth()}px; height:${ratios[ratio].h}px; transform:scale(${previewScale()});`"></iframe>
                            {{-- Guides de découpe entre slides (couture verticale) --}}
                            <template x-for="n in Math.max(0, slides.length - 1)" :key="n">
                                <div class="absolute top-0 bottom-0 border-l border-dashed border-white/70 pointer-events-none"
                                     :style="`left:${n * (previewW / slides.length)}px`"></div>
                            </template>
                        </div>
                    </div>
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
            slides: [],
            previewHtml: '',
            previewLoading: false,
            previewW: 348,
            rendering: false,
            renderError: '',
            generated: [],
            pickingIndex: null,
            _seq: 0,
            _timer: null,

            init() {
                // Une première slide par défaut avec la brique overlay.
                this.addSlide(this.bricks[0]?.slug, false);
                this.$watch('ratio', () => this.queuePreview());
                this.updatePreview();
            },

            brickDef(slug) {
                return this.bricks.find(b => b.slug === slug) || { slots: {}, description: '' };
            },

            addSlide(slug, refresh = true) {
                if (!slug) return;
                this.slides.push({ _id: ++this._seq, brick: slug, data: {} });
                if (refresh) this.queuePreview();
            },
            removeSlide(i) {
                if (this.slides.length === 1) return;
                this.slides.splice(i, 1);
                this.queuePreview();
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
            payload() {
                return {
                    ratio: this.ratio,
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

            // ── Helpers présentation (bande horizontale, toutes les slides tiennent dans previewW) ──
            ratioW() { return this.ratios[this.ratio]?.w || 1080; },
            ratioH() { return this.ratios[this.ratio]?.h || 1350; },
            bandCssWidth() { return this.slides.length * this.ratioW(); },
            previewScale() { return this.previewW / this.bandCssWidth(); },
            slideVisualHeight() { return this.ratioH() * this.previewScale(); },
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
