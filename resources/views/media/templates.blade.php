@extends('layouts.app')

@section('title', 'Templates')

@section('content')
<div x-data="templateManager()" class="space-y-6">

    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Templates</h1>
            <p class="mt-1 text-sm text-gray-500">Modeles d'images pour Pinterest, Instagram, etc.</p>
        </div>
        <button @click="showCreateModal = true" class="inline-flex items-center gap-2 px-4 py-2.5 text-white text-sm font-medium rounded-xl shadow-sm bg-indigo-600 hover:bg-indigo-700 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            Nouveau template
        </button>
    </div>

    {{-- Templates grid --}}
    @if($templates->count())
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach($templates as $template)
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            {{-- Preview --}}
            <div class="aspect-[2/3] max-h-48 bg-gray-100 flex items-center justify-center overflow-hidden relative">
                @if($template->preview_url)
                    <img src="{{ $template->preview_url }}" class="w-full h-full object-cover" alt="{{ $template->name }}">
                @else
                    <div class="w-full h-full flex items-center justify-center" style="background-color: {{ $template->colors['background'] ?? '#1a1a2e' }}">
                        <div class="text-center px-4">
                            <p class="text-sm font-bold" style="color: {{ $template->colors['text'] ?? '#ffffff' }}">{{ $template->name }}</p>
                            <p class="text-xs mt-1 opacity-60" style="color: {{ $template->colors['text'] ?? '#ffffff' }}">{{ $template->width }}x{{ $template->height }}</p>
                        </div>
                    </div>
                @endif
                {{-- Format badge --}}
                <span class="absolute top-2 right-2 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-black/50 text-white backdrop-blur-sm">
                    {{ \App\Models\MediaTemplate::FORMATS[$template->format]['label'] ?? $template->format }}
                </span>
            </div>

            {{-- Info --}}
            <div class="p-4">
                <h3 class="font-semibold text-gray-900 text-sm">{{ $template->name }}</h3>
                <div class="mt-1 flex items-center gap-2 text-xs text-gray-500">
                    <span>{{ \App\Models\MediaTemplate::LAYOUTS[$template->layout] ?? $template->layout }}</span>
                    <span class="text-gray-300">&middot;</span>
                    <span>{{ $template->title_font }} {{ $template->title_font_weight }}</span>
                </div>

                {{-- Color swatches --}}
                <div class="mt-2 flex items-center gap-1.5">
                    <div class="w-5 h-5 rounded-full border border-gray-200" style="background-color: {{ $template->colors['background'] ?? '#1a1a2e' }}" title="Fond"></div>
                    <div class="w-5 h-5 rounded-full border border-gray-200" style="background-color: {{ $template->colors['text'] ?? '#ffffff' }}" title="Texte"></div>
                    @if(!empty($template->colors['accent']))
                    <div class="w-5 h-5 rounded-full border border-gray-200" style="background-color: {{ $template->colors['accent'] }}" title="Accent"></div>
                    @endif
                    @if(!empty($template->border['enabled']))
                    <span class="ml-auto text-[10px] font-medium text-indigo-600 bg-indigo-50 px-1.5 py-0.5 rounded">Bordure</span>
                    @endif
                </div>

                {{-- Actions --}}
                <div class="mt-3 pt-3 border-t border-gray-100 flex items-center gap-2">
                    <button @click="openEditModal({{ $template->id }}, {{ json_encode($template) }})" class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" />
                        </svg>
                        Modifier
                    </button>
                    <form method="POST" action="{{ route('media.templates.destroy', $template) }}" class="ml-auto" onsubmit="return confirm('Supprimer ce template ?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="p-1.5 text-red-400 hover:text-red-600 transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                            </svg>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        @endforeach
    </div>
    @else
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8 text-center">
        <div class="w-16 h-16 mx-auto rounded-full flex items-center justify-center bg-indigo-50 mb-4">
            <svg class="w-8 h-8 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6Z" />
            </svg>
        </div>
        <h3 class="text-lg font-semibold text-gray-900">Aucun template</h3>
        <p class="mt-2 text-sm text-gray-500">Creez votre premier template pour generer des images optimisees.</p>
    </div>
    @endif

    {{-- Create/Edit Modal --}}
    <template x-teleport="body">
    <div x-show="showCreateModal || showEditModal" x-cloak class="fixed inset-0 z-50 flex items-start justify-center bg-black/40 overflow-y-auto py-8" @keydown.escape.window="closeModals()">
        <div @click.away="closeModals()" class="bg-white rounded-2xl shadow-xl w-full max-w-3xl mx-4 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-5" x-text="editTemplateId ? 'Modifier le template' : 'Nouveau template'"></h2>

            <form :method="editTemplateId ? 'POST' : 'POST'" :action="editTemplateId ? '/media/templates/' + editTemplateId : '{{ route('media.templates.store') }}'" enctype="multipart/form-data">
                @csrf
                <template x-if="editTemplateId">
                    <input type="hidden" name="_method" value="PUT">
                </template>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    {{-- Left column --}}
                    <div class="space-y-4">
                        {{-- Name --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nom du template</label>
                            <input type="text" name="name" x-model="form.name" class="w-full rounded-lg border-gray-300 text-sm" placeholder="Ex: Pin Vanlife Azulejo" required>
                        </div>

                        {{-- Format (only for create) --}}
                        <div x-show="!editTemplateId">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Format</label>
                            <select name="format" x-model="form.format" @change="onFormatChange()" class="w-full rounded-lg border-gray-300 text-sm">
                                @foreach(\App\Models\MediaTemplate::FORMATS as $key => $info)
                                <option value="{{ $key }}">{{ $info['label'] }} ({{ $info['width'] }}x{{ $info['height'] }})</option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Layout --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Layout</label>
                            <div class="grid grid-cols-3 gap-2">
                                @foreach(\App\Models\MediaTemplate::LAYOUTS as $key => $label)
                                <label class="relative cursor-pointer">
                                    <input type="radio" name="layout" value="{{ $key }}" x-model="form.layout" class="sr-only peer">
                                    <div class="p-2 rounded-lg border-2 text-center text-xs transition peer-checked:border-indigo-500 peer-checked:bg-indigo-50 border-gray-200 hover:border-gray-300">
                                        {{ $label }}
                                    </div>
                                </label>
                                @endforeach
                            </div>
                        </div>

                        {{-- Colors --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Couleurs</label>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="text-xs text-gray-500">Fond</label>
                                    <div class="flex items-center gap-1.5 mt-0.5">
                                        <input type="color" name="colors[background]" x-model="form.colors.background" class="h-8 w-10 rounded cursor-pointer border border-gray-300">
                                        <input type="text" x-model="form.colors.background" class="flex-1 rounded-lg border-gray-300 text-xs font-mono" maxlength="7">
                                    </div>
                                </div>
                                <div>
                                    <label class="text-xs text-gray-500">Texte</label>
                                    <div class="flex items-center gap-1.5 mt-0.5">
                                        <input type="color" name="colors[text]" x-model="form.colors.text" class="h-8 w-10 rounded cursor-pointer border border-gray-300">
                                        <input type="text" x-model="form.colors.text" class="flex-1 rounded-lg border-gray-300 text-xs font-mono" maxlength="7">
                                    </div>
                                </div>
                                <div>
                                    <label class="text-xs text-gray-500">Accent / Bandeau</label>
                                    <div class="flex items-center gap-1.5 mt-0.5">
                                        <input type="color" name="colors[title_band_color]" x-model="form.colors.title_band_color" class="h-8 w-10 rounded cursor-pointer border border-gray-300">
                                        <input type="text" x-model="form.colors.title_band_color" class="flex-1 rounded-lg border-gray-300 text-xs font-mono" maxlength="7">
                                    </div>
                                </div>
                                <div>
                                    <label class="text-xs text-gray-500">Opacite overlay</label>
                                    <input type="range" name="colors[overlay_opacity]" x-model="form.colors.overlay_opacity" min="0" max="1" step="0.05" class="w-full mt-2">
                                    <span class="text-xs text-gray-400" x-text="Math.round(form.colors.overlay_opacity * 100) + '%'"></span>
                                </div>
                            </div>
                            <input type="hidden" name="colors[accent]" x-model="form.colors.title_band_color">
                            <input type="hidden" name="colors[title_band_opacity]" value="0.8">
                        </div>
                    </div>

                    {{-- Right column --}}
                    <div class="space-y-4">
                        {{-- Title font --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Police du titre</label>
                            <select name="title_font" x-model="form.title_font" @change="loadFontPreview('title')" class="w-full rounded-lg border-gray-300 text-sm">
                                @foreach(\App\Services\GoogleFontsService::CURATED_FONTS as $category => $fonts)
                                <optgroup label="{{ $category }}">
                                    @foreach($fonts as $font)
                                    <option value="{{ $font }}">{{ $font }}</option>
                                    @endforeach
                                </optgroup>
                                @endforeach
                            </select>
                            <div class="mt-1.5 flex items-center gap-2">
                                <select name="title_font_weight" x-model="form.title_font_weight" class="flex-1 rounded-lg border-gray-300 text-xs">
                                    @foreach(\App\Services\GoogleFontsService::WEIGHTS as $name => $val)
                                    <option value="{{ $name }}">{{ $name }} ({{ $val }})</option>
                                    @endforeach
                                </select>
                                <input type="number" name="title_font_size" x-model="form.title_font_size" min="16" max="120" class="w-20 rounded-lg border-gray-300 text-xs" placeholder="Taille">
                                <span class="text-xs text-gray-400">px</span>
                            </div>
                            {{-- Font preview --}}
                            <div class="mt-2 p-3 rounded-lg bg-gray-50 min-h-[40px]" :style="'font-family: ' + form.title_font + ', sans-serif'">
                                <link rel="stylesheet" :href="'https://fonts.googleapis.com/css2?family=' + encodeURIComponent(form.title_font) + ':wght@' + (fontWeightValues[form.title_font_weight] || 400) + '&display=swap'">
                                <p class="text-lg" :style="'font-weight: ' + (fontWeightValues[form.title_font_weight] || 400) + '; font-size: ' + Math.min(form.title_font_size, 28) + 'px'">
                                    Apercu du titre
                                </p>
                            </div>
                        </div>

                        {{-- Body font --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Police du corps (optionnel)</label>
                            <select name="body_font" x-model="form.body_font" class="w-full rounded-lg border-gray-300 text-sm">
                                <option value="">Aucune</option>
                                @foreach(\App\Services\GoogleFontsService::CURATED_FONTS as $category => $fonts)
                                <optgroup label="{{ $category }}">
                                    @foreach($fonts as $font)
                                    <option value="{{ $font }}">{{ $font }}</option>
                                    @endforeach
                                </optgroup>
                                @endforeach
                            </select>
                            <div x-show="form.body_font" class="mt-1.5 flex items-center gap-2">
                                <select name="body_font_weight" x-model="form.body_font_weight" class="flex-1 rounded-lg border-gray-300 text-xs">
                                    @foreach(\App\Services\GoogleFontsService::WEIGHTS as $name => $val)
                                    <option value="{{ $name }}">{{ $name }} ({{ $val }})</option>
                                    @endforeach
                                </select>
                                <input type="number" name="body_font_size" x-model="form.body_font_size" min="12" max="80" class="w-20 rounded-lg border-gray-300 text-xs">
                                <span class="text-xs text-gray-400">px</span>
                            </div>
                        </div>

                        {{-- Border --}}
                        <div>
                            <label class="flex items-center gap-2 text-sm font-medium text-gray-700 mb-2">
                                <input type="checkbox" name="border_enabled" x-model="form.border_enabled" value="1" class="rounded border-gray-300 text-indigo-600">
                                Bordure / Cadre
                            </label>

                            <div x-show="form.border_enabled" class="space-y-3 p-3 rounded-lg bg-gray-50">
                                <div>
                                    <label class="text-xs text-gray-500">Type</label>
                                    <select name="border_type" x-model="form.border_type" class="w-full rounded-lg border-gray-300 text-xs mt-0.5">
                                        <option value="solid">Couleur unie</option>
                                        <option value="pattern">Motif / Pattern (image)</option>
                                    </select>
                                </div>

                                <div x-show="form.border_type === 'solid'">
                                    <label class="text-xs text-gray-500">Couleur</label>
                                    <div class="flex items-center gap-1.5 mt-0.5">
                                        <input type="color" name="border_color" x-model="form.border_color" class="h-8 w-10 rounded cursor-pointer border border-gray-300">
                                        <input type="text" x-model="form.border_color" class="flex-1 rounded-lg border-gray-300 text-xs font-mono" maxlength="7">
                                    </div>
                                </div>

                                <div x-show="form.border_type === 'pattern'">
                                    <label class="text-xs text-gray-500">Image du motif (tileable)</label>
                                    <input type="file" name="border_pattern" accept="image/*" class="mt-0.5 block w-full text-xs text-gray-500 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                                    <p class="text-[10px] text-gray-400 mt-1">Image qui sera repetee en mosaique pour former la bordure (ex: azulejo)</p>
                                </div>

                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="text-xs text-gray-500">Epaisseur (px)</label>
                                        <input type="number" name="border_thickness" x-model="form.border_thickness" min="0" max="100" class="w-full rounded-lg border-gray-300 text-xs mt-0.5">
                                    </div>
                                    <div>
                                        <label class="text-xs text-gray-500">Marge interieure (px)</label>
                                        <input type="number" name="border_inner_padding" x-model="form.border_inner_padding" min="0" max="50" class="w-full rounded-lg border-gray-300 text-xs mt-0.5">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Actions --}}
                <div class="flex justify-between items-center pt-5 mt-5 border-t border-gray-100">
                    <button type="button" @click="downloadSelectedFonts()" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-indigo-700 bg-indigo-50 rounded-lg hover:bg-indigo-100 transition" :disabled="downloadingFonts">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                        </svg>
                        <span x-text="downloadingFonts ? 'Telechargement...' : 'Telecharger les polices'"></span>
                    </button>

                    <div class="flex gap-3">
                        <button type="button" @click="closeModals()" class="px-4 py-2 text-sm font-medium text-gray-700 hover:text-gray-900 transition">
                            Annuler
                        </button>
                        <button type="submit" class="px-6 py-2.5 text-white text-sm font-medium rounded-xl shadow-sm bg-indigo-600 hover:bg-indigo-700 transition">
                            <span x-text="editTemplateId ? 'Enregistrer' : 'Creer'"></span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    </template>

    {{-- Toast --}}
    <div x-show="toast.show" x-cloak
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 translate-y-2"
         class="fixed bottom-6 right-6 z-50 px-4 py-3 rounded-xl shadow-lg text-sm font-medium"
         :class="toast.type === 'success' ? 'bg-green-600 text-white' : 'bg-red-600 text-white'"
         x-text="toast.message">
    </div>
</div>

<script>
function templateManager() {
    return {
        showCreateModal: false,
        showEditModal: false,
        editTemplateId: null,
        downloadingFonts: false,
        toast: { show: false, message: '', type: 'success' },

        fontWeightValues: {
            'Thin': 100, 'ExtraLight': 200, 'Light': 300, 'Regular': 400,
            'Medium': 500, 'SemiBold': 600, 'Bold': 700, 'ExtraBold': 800, 'Black': 900
        },

        form: {
            name: '',
            format: 'pinterest_pin',
            layout: 'overlay',
            title_font: 'Montserrat',
            title_font_weight: 'ExtraBold',
            title_font_size: 52,
            body_font: '',
            body_font_weight: 'Regular',
            body_font_size: 24,
            colors: {
                background: '#1a1a2e',
                text: '#ffffff',
                title_band_color: '#6366f1',
                overlay_opacity: 0.7,
            },
            border_enabled: false,
            border_type: 'solid',
            border_color: '#000000',
            border_thickness: 40,
            border_inner_padding: 10,
        },

        onFormatChange() {
            // Could auto-adjust layout options based on format
        },

        loadFontPreview(type) {
            // Google Fonts CSS is loaded via link tag in the template
        },

        openEditModal(id, template) {
            this.editTemplateId = id;
            this.form = {
                name: template.name,
                format: template.format,
                layout: template.layout,
                title_font: template.title_font,
                title_font_weight: template.title_font_weight,
                title_font_size: template.title_font_size,
                body_font: template.body_font || '',
                body_font_weight: template.body_font_weight || 'Regular',
                body_font_size: template.body_font_size || 24,
                colors: {
                    background: template.colors?.background || '#1a1a2e',
                    text: template.colors?.text || '#ffffff',
                    title_band_color: template.colors?.title_band_color || template.colors?.accent || '#6366f1',
                    overlay_opacity: template.colors?.overlay_opacity ?? 0.7,
                },
                border_enabled: template.border?.enabled || false,
                border_type: template.border?.type || 'solid',
                border_color: template.border?.color || '#000000',
                border_thickness: template.border?.thickness || 40,
                border_inner_padding: template.border?.inner_padding || 10,
            };
            this.showEditModal = true;
        },

        closeModals() {
            this.showCreateModal = false;
            this.showEditModal = false;
            this.editTemplateId = null;
            this.resetForm();
        },

        resetForm() {
            this.form = {
                name: '',
                format: 'pinterest_pin',
                layout: 'overlay',
                title_font: 'Montserrat',
                title_font_weight: 'ExtraBold',
                title_font_size: 52,
                body_font: '',
                body_font_weight: 'Regular',
                body_font_size: 24,
                colors: {
                    background: '#1a1a2e',
                    text: '#ffffff',
                    title_band_color: '#6366f1',
                    overlay_opacity: 0.7,
                },
                border_enabled: false,
                border_type: 'solid',
                border_color: '#000000',
                border_thickness: 40,
                border_inner_padding: 10,
            };
        },

        async downloadSelectedFonts() {
            this.downloadingFonts = true;
            const fonts = [
                { family: this.form.title_font, weight: this.form.title_font_weight },
            ];
            if (this.form.body_font) {
                fonts.push({ family: this.form.body_font, weight: this.form.body_font_weight });
            }

            for (const font of fonts) {
                try {
                    const resp = await fetch('{{ route("media.templates.downloadFont") }}', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                        body: JSON.stringify(font)
                    });
                    const data = await resp.json();
                    if (!resp.ok) {
                        this.showToast(`Erreur: ${font.family} ${font.weight}`, 'error');
                    }
                } catch (e) {
                    this.showToast(`Erreur telechargement ${font.family}`, 'error');
                }
            }

            this.downloadingFonts = false;
            this.showToast('Polices telechargees !', 'success');
        },

        showToast(message, type = 'success') {
            this.toast = { show: true, message, type };
            setTimeout(() => this.toast.show = false, 3000);
        }
    };
}
</script>
@endsection
