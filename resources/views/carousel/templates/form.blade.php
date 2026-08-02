@extends('layouts.app')

@section('title', $mode === 'edit' ? 'Modifier le template' : 'Nouveau template')

@php
    // Calculé ici et pas dans @json : les closures dans @json cassent le parse Blade.
    $sampleForm = (object) old('sample_data', $template->sample_data ?: []);
    $defaultRatio = config('carousel.default_ratio', '4:5');
@endphp

@section('content')
<div x-data="templateEditor()" x-init="init()">

    @if ($errors->any())
        <div class="mb-6 rounded-xl bg-red-50 border border-red-200 p-4">
            <p class="text-sm font-medium text-red-700 mb-1">Le template n’a pas pu être enregistré :</p>
            <ul class="list-disc list-inside text-sm text-red-600 space-y-0.5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (session('status') === 'template-updated' || session('status') === 'template-created')
        <div class="mb-6 rounded-xl bg-green-50 border border-green-200 p-4">
            <p class="text-sm text-green-700">Template enregistré. Il est disponible dans le Studio carrousel.</p>
        </div>
    @endif

    <form method="POST"
          action="{{ $mode === 'edit' ? route('carousel.templates.update', $template) : route('carousel.templates.store') }}">
        @csrf
        @if ($mode === 'edit') @method('PUT') @endif

        <div class="grid grid-cols-1 xl:grid-cols-[1fr_380px] gap-6 items-start">

            {{-- ─────────────── Édition ─────────────── --}}
            <div class="space-y-5">

                {{-- Identité --}}
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nom</label>
                            <input type="text" name="name" value="{{ old('name', $template->name) }}" required
                                   class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                            <input type="text" name="description" value="{{ old('description', $template->description) }}"
                                   class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Formats compatibles</label>
                        @php $selected = old('ratios', $template->ratios ?: ['*']); @endphp
                        <label class="inline-flex items-center gap-2 mr-4 text-sm text-gray-600">
                            <input type="checkbox" name="ratios[]" value="*" @checked(in_array('*', $selected))
                                   class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            Tous
                        </label>
                        @foreach ($ratios as $key => $dims)
                            <label class="inline-flex items-center gap-2 mr-4 text-sm text-gray-600">
                                <input type="checkbox" name="ratios[]" value="{{ $key }}" @checked(in_array($key, $selected))
                                       class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                {{ $key }}
                            </label>
                        @endforeach
                    </div>
                </div>

                {{-- Gabarit --}}
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                    <h2 class="text-sm font-semibold text-gray-900 mb-1">Gabarit HTML</h2>
                    <p class="text-xs text-gray-400 mb-3">
                        Écris <code>&#123;&#123; titre &#125;&#125;</code> et le champ « Titre » apparaît tout seul dans le Studio —
                        rien à déclarer. Aussi : <code>&#123;&#123;#if titre&#125;&#125; … &#123;&#123;/if&#125;&#125;</code> pour masquer un bloc vide,
                        <code>&#123;&#123;#each items&#125;&#125; &#123;&#123; left &#125;&#125; &#123;&#123; right &#125;&#125; &#123;&#123;/each&#125;&#125;</code> pour une liste.
                    </p>
                    <textarea name="html" x-model="html" @input="onSourceChange()" rows="16" spellcheck="false"
                              class="w-full rounded-lg border-gray-300 text-xs font-mono leading-relaxed focus:border-indigo-500 focus:ring-indigo-500">{{ old('html', $template->html) }}</textarea>
                </div>

                {{-- CSS --}}
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                    <h2 class="text-sm font-semibold text-gray-900 mb-1">Feuille de style</h2>
                    <p class="text-xs text-gray-400 mb-3">
                        Tailles en <code>cqh</code>/<code>cqw</code> (6cqh = 6 % de la hauteur du slide) pour tenir dans tous les formats.
                        Couleurs du thème : <code>var(--text)</code>, <code>var(--accent)</code>, <code>var(--bg)</code>.
                        Emplacement du texte : <code>var(--justify)</code>, <code>var(--align)</code>, <code>var(--text-align)</code>.
                        Voile de lisibilité tout prêt : classe <code>.brick-scrim</code>. Ni script, ni ressource externe.
                    </p>
                    <textarea name="css" x-model="css" @input="queuePreview()" rows="14" spellcheck="false"
                              class="w-full rounded-lg border-gray-300 text-xs font-mono leading-relaxed focus:border-indigo-500 focus:ring-indigo-500">{{ old('css', $template->css) }}</textarea>
                </div>

                {{-- Champs déduits + données d'exemple --}}
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                    <h2 class="text-sm font-semibold text-gray-900 mb-1">Champs détectés</h2>
                    <p class="text-xs text-gray-400 mb-4">
                        Déduits du gabarit. Les valeurs ci-dessous ne servent qu’à l’aperçu et à la vignette de la galerie.
                    </p>

                    <template x-if="!slots.length">
                        <p class="text-xs text-gray-400 italic">Aucun champ pour l’instant — ajoute un marqueur dans le gabarit.</p>
                    </template>

                    <div class="space-y-2">
                        <template x-for="slot in slots" :key="slot.key">
                            <div class="grid grid-cols-[150px_1fr] gap-3 items-start">
                                <div class="pt-1.5">
                                    <span class="text-xs font-mono text-gray-600" x-text="slot.key"></span>
                                    <span class="block text-[10px] text-gray-400" x-text="slot.type"></span>
                                </div>

                                <template x-if="slot.type === 'image'">
                                    <p class="text-xs text-gray-400 pt-1.5">Une image de la médiathèque est utilisée pour l’aperçu.</p>
                                </template>

                                <template x-if="slot.type === 'textarea'">
                                    <textarea :name="`sample_data[${slot.key}]`" x-model="sample[slot.key]"
                                              @input="queuePreview()" rows="2"
                                              class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                                </template>

                                <template x-if="slot.type !== 'image' && slot.type !== 'textarea'">
                                    <input type="text" :name="`sample_data[${slot.key}]`" x-model="sample[slot.key]"
                                           @input="queuePreview()"
                                           class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                </template>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            {{-- ─────────────── Aperçu live ─────────────── --}}
            <div class="xl:sticky xl:top-6 space-y-4">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-sm font-semibold text-gray-900">Aperçu</span>
                        <select x-model="ratio" @change="updatePreview()"
                                class="rounded-lg border-gray-300 text-xs focus:border-indigo-500 focus:ring-indigo-500">
                            @foreach ($ratios as $key => $dims)
                                <option value="{{ $key }}">{{ $key }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mx-auto bg-gray-100 rounded-lg overflow-hidden"
                         :style="`width:${previewW}px; height:${previewW * ratioH() / ratioW()}px`">
                        <iframe :srcdoc="previewHtml" scrolling="no"
                                class="border-0 origin-top-left pointer-events-none"
                                :style="`width:${ratioW()}px; height:${ratioH()}px; transform:scale(${previewW / ratioW()});`"></iframe>
                    </div>
                </div>

                <button type="submit"
                        class="w-full py-2.5 rounded-xl bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700">
                    {{ $mode === 'edit' ? 'Enregistrer' : 'Créer le template' }}
                </button>
                <a href="{{ route('carousel.templates.index') }}"
                   class="block text-center text-xs text-gray-400 hover:text-gray-600">Retour à la liste</a>
            </div>
        </div>
    </form>
</div>

@push('scripts')
<script>
    function templateEditor() {
        return {
            ratios: @json($ratios),
            ratio: @json($defaultRatio),
            html: @json(old('html', $template->html)),
            css: @json(old('css', $template->css)),
            sample: @json($sampleForm),
            sampleImage: @json($sampleImage),
            slots: [],
            previewHtml: '',
            previewW: 348,
            _timer: null,

            // Valeurs d'exemple par défaut (lorem) — l'aperçu est parlant d'emblée.
            LOREM: {
                title: 'Un titre d’exemple, assez long pour juger',
                subtitle: 'Le sous-titre qui précise l’idée.',
                author: 'Source de la citation, 2026',
                handle: '@moncompte',
                number: '02',
                note: 'Une note discrète en bas de slide.',
                quote: 'Une citation d’exemple, pour juger la mise en page à sa vraie longueur.',
                items: "26|essais contrôlés\n1 036|participants",
                rows: "Première ligne|valeur\nDeuxième ligne|autre valeur",
                columns: '2',
                position: 'bottom-left',
                offset: '0',
            },

            init() {
                this.refreshSlots();
                this.updatePreview();
            },

            // ── Déduction des champs à partir du gabarit (miroir de TemplateRenderer) ──
            refreshSlots() {
                const src = (this.html || '').replace(/<!--[\s\S]*?-->/g, '');
                const lists = new Set([...src.matchAll(/\{\{#each\s+(\w+)\s*\}\}/g)].map(m => m[1]));
                const keys = [];

                for (const m of src.matchAll(/\{\{\s*#?(?:if|unless|each)?\s*([\w.]+)\s*\}\}/g)) {
                    const key = m[1];
                    if (key.includes('.') || ['left', 'right', 'index'].includes(key)) continue;
                    if (!keys.includes(key)) keys.push(key);
                }

                this.slots = keys.map(key => ({ key, type: this.inferType(key, lists.has(key)) }));

                // Pré-remplissage lorem des nouveaux champs.
                for (const slot of this.slots) {
                    if (this.sample[slot.key] !== undefined && this.sample[slot.key] !== '') continue;
                    if (slot.type === 'image') continue;
                    this.sample[slot.key] = this.LOREM[slot.key]
                        ?? (slot.type === 'textarea'
                            ? 'Un paragraphe d’exemple, assez fourni pour voir comment le texte se comporte sur plusieurs lignes.'
                            : 'Texte d’exemple');
                }
            },

            inferType(key, isList) {
                if (key === 'position') return 'position';
                if (key === 'offset') return 'range';
                if (/^(image|photo|visuel|fond|background|illustration)/i.test(key)) return 'image';
                if (isList || /^(items|rows|lignes|body|texte|paragraphe|quote|citation|description)/i.test(key)) return 'textarea';
                return 'text';
            },

            onSourceChange() {
                this.refreshSlots();
                this.queuePreview();
            },

            ratioW() { return this.ratios[this.ratio]?.w || 1080; },
            ratioH() { return this.ratios[this.ratio]?.h || 1350; },

            queuePreview() {
                clearTimeout(this._timer);
                this._timer = setTimeout(() => this.updatePreview(), 400);
            },

            async updatePreview() {
                // Les slots image reçoivent l'illustration de la médiathèque.
                const data = { ...this.sample };
                for (const slot of this.slots) {
                    if (slot.type === 'image') data[slot.key] = this.sampleImage;
                }

                try {
                    const resp = await fetch('{{ route('carousel.templates.preview') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                        },
                        body: JSON.stringify({ html: this.html, css: this.css, ratio: this.ratio, data }),
                    });
                    this.previewHtml = await resp.text();
                } catch (e) {
                    // aperçu best-effort
                }
            },
        };
    }
</script>
@endpush
@endsection
