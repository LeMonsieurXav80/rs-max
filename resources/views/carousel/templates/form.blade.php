@extends('layouts.app')

@section('title', $mode === 'edit' ? 'Modifier le template' : 'Nouveau template')

@php
    // Slots du manifeste (clé => définition) → liste indexée pour le formulaire.
    // Calculé ici et pas dans @json : les closures dans @json cassent le parse Blade.
    $slotsForm = [];
    foreach ($template->slots ?: [] as $key => $slot) {
        $default = $slot['default'] ?? null;
        $slotsForm[] = [
            'key' => $key,
            'label' => $slot['label'] ?? $key,
            'type' => $slot['type'] ?? 'text',
            'default' => is_scalar($default) ? (string) $default : '',
        ];
    }
    $slotsForm = old('slots', $slotsForm);
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
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nom</label>
                        <input type="text" name="name" value="{{ old('name', $template->name) }}" required
                               class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <textarea name="description" rows="2"
                                  class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('description', $template->description) }}</textarea>
                        <p class="text-xs text-gray-400 mt-1">Affichée dans le Studio sous le nom du template.</p>
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

                {{-- Champs éditables (slots) --}}
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                    <div class="flex items-center justify-between mb-1">
                        <h2 class="text-sm font-semibold text-gray-900">Champs du template</h2>
                        <button type="button" @click="addSlot()"
                                class="text-xs px-2.5 py-1 rounded-lg border border-gray-200 text-gray-600 hover:border-indigo-300 hover:text-indigo-600">
                            + Ajouter un champ
                        </button>
                    </div>
                    <p class="text-xs text-gray-400 mb-4">
                        Chaque champ devient un marqueur utilisable dans le gabarit : <code class="text-gray-500">&#123;&#123; clé &#125;&#125;</code>.
                    </p>

                    <div class="space-y-3">
                        <template x-for="(slot, i) in slots" :key="i">
                            <div class="grid grid-cols-[1fr_1.4fr_1fr_auto] gap-2 items-center">
                                <input type="text" :name="`slots[${i}][key]`" x-model="slot.key" @input="queuePreview()"
                                       placeholder="cle" class="rounded-lg border-gray-300 text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500">
                                <input type="text" :name="`slots[${i}][label]`" x-model="slot.label"
                                       placeholder="Libellé affiché" class="rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <select :name="`slots[${i}][type]`" x-model="slot.type" @change="queuePreview()"
                                        class="rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    @foreach ($slotTypes as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <input type="hidden" :name="`slots[${i}][default]`" :value="slot.default ?? ''">
                                <button type="button" @click="slots.splice(i, 1); queuePreview()"
                                        class="p-1.5 text-red-400 hover:text-red-600" title="Retirer">✕</button>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Gabarit --}}
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                    <h2 class="text-sm font-semibold text-gray-900 mb-1">Gabarit HTML / CSS</h2>
                    <p class="text-xs text-gray-400 mb-3">
                        Marqueurs : <code>&#123;&#123; cle &#125;&#125;</code> ·
                        <code>&#123;&#123;#if cle&#125;&#125; … &#123;&#123;/if&#125;&#125;</code> ·
                        <code>&#123;&#123;#each items&#125;&#125; &#123;&#123; left &#125;&#125; &#123;&#123; right &#125;&#125; &#123;&#123;/each&#125;&#125;</code>.
                        Tailles en <code>cqh</code>/<code>cqw</code> (6cqh = 6 % de la hauteur).
                        Couleurs du thème : <code>var(--text)</code>, <code>var(--accent)</code>, <code>var(--bg)</code>.
                        Ni script, ni ressource externe.
                    </p>
                    <textarea name="html" x-model="html" @input="queuePreview()" rows="20" spellcheck="false"
                              class="w-full rounded-lg border-gray-300 text-xs font-mono leading-relaxed focus:border-indigo-500 focus:ring-indigo-500">{{ old('html', $template->html) }}</textarea>
                </div>

                {{-- Données d'exemple --}}
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                    <h2 class="text-sm font-semibold text-gray-900 mb-1">Données d’exemple</h2>
                    <p class="text-xs text-gray-400 mb-3">Servent à l’aperçu ci-contre et à la vignette de la galerie.</p>
                    <div class="space-y-2">
                        <template x-for="slot in slots.filter(s => s.key && s.type !== 'image')" :key="slot.key">
                            <div class="grid grid-cols-[140px_1fr] gap-2 items-center">
                                <label class="text-xs text-gray-500 truncate" x-text="slot.key"></label>
                                <input type="text" :name="`sample_data[${slot.key}]`" x-model="sample[slot.key]"
                                       @input="queuePreview()"
                                       class="rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
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
            slots: @json($slotsForm),
            sample: @json($sampleForm),
            html: @json(old('html', $template->html)),
            previewHtml: '',
            previewW: 348,
            _timer: null,

            init() {
                this.updatePreview();
            },

            addSlot() {
                this.slots.push({ key: '', label: '', type: 'text', default: '' });
            },

            ratioW() { return this.ratios[this.ratio]?.w || 1080; },
            ratioH() { return this.ratios[this.ratio]?.h || 1350; },

            queuePreview() {
                clearTimeout(this._timer);
                this._timer = setTimeout(() => this.updatePreview(), 400);
            },

            async updatePreview() {
                try {
                    const resp = await fetch('{{ route('carousel.templates.preview') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                        },
                        body: JSON.stringify({ html: this.html, ratio: this.ratio, data: this.sample }),
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
