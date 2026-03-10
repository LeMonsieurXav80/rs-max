@extends('layouts.app')
@section('title', 'Générateur de prompts vidéo')

@section('content')
<div class="max-w-6xl mx-auto" x-data="videoPromptGenerator()">
    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Générateur de prompts vidéo</h1>
            <p class="text-sm text-gray-500 mt-1">Créez des prompts d'animation pour Runway ML, Pika Labs, Kling</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Options Panel --}}
        <div class="lg:col-span-1 space-y-4">

            {{-- Step 1: Photo Upload --}}
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <div class="flex items-center gap-2 mb-3">
                    <span class="flex items-center justify-center w-6 h-6 rounded-full bg-indigo-100 text-indigo-700 text-xs font-bold">1</span>
                    <h3 class="text-sm font-medium text-gray-700">Photo source</h3>
                </div>

                {{-- Upload area --}}
                <div
                    class="relative border-2 border-dashed rounded-xl p-4 text-center transition-colors"
                    :class="dragOver ? 'border-indigo-400 bg-indigo-50' : 'border-gray-300 hover:border-gray-400'"
                    @dragover.prevent="dragOver = true"
                    @dragleave.prevent="dragOver = false"
                    @drop.prevent="handleDrop($event)"
                >
                    <template x-if="!photoPreview">
                        <div>
                            <svg class="w-10 h-10 mx-auto text-gray-300 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z" />
                            </svg>
                            <p class="text-sm text-gray-500 mb-1">Glissez une photo ou</p>
                            <label class="inline-flex items-center gap-1 px-3 py-1.5 text-sm font-medium text-indigo-600 bg-indigo-50 rounded-lg cursor-pointer hover:bg-indigo-100 transition-colors">
                                <span>Parcourir</span>
                                <input type="file" accept="image/jpeg,image/png,image/webp" @change="handleFileSelect($event)" class="hidden">
                            </label>
                        </div>
                    </template>

                    <template x-if="photoPreview">
                        <div class="relative">
                            <img :src="photoPreview" class="w-full rounded-lg max-h-48 object-contain">
                            <button
                                @click="clearPhoto"
                                class="absolute top-1 right-1 p-1 bg-red-500 text-white rounded-full hover:bg-red-600 transition-colors"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </template>
                </div>

                {{-- Analyze button --}}
                <button
                    x-show="photoFile && !analysis"
                    @click="analyzePhoto"
                    :disabled="analyzing"
                    class="mt-3 w-full flex items-center justify-center gap-2 px-4 py-2.5 bg-amber-500 text-white rounded-xl text-sm font-medium hover:bg-amber-600 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                >
                    <svg x-show="!analyzing" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    </svg>
                    <svg x-show="analyzing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <span x-text="analyzing ? 'Analyse en cours...' : 'Analyser la photo'"></span>
                </button>
            </div>

            {{-- Analysis result --}}
            <div x-show="analysis" class="bg-white rounded-xl border border-gray-200 p-5">
                <div class="flex items-center gap-2 mb-3">
                    <span class="flex items-center justify-center w-6 h-6 rounded-full bg-green-100 text-green-700 text-xs font-bold">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                    </span>
                    <h3 class="text-sm font-medium text-gray-700">Analyse IA</h3>
                </div>
                <div class="text-sm text-gray-600 leading-relaxed max-h-40 overflow-y-auto" x-text="analysis"></div>
            </div>

            {{-- Step 2: Animation Options --}}
            <div class="bg-white rounded-xl border border-gray-200 p-5" :class="!analysis && 'opacity-50 pointer-events-none'">
                <div class="flex items-center gap-2 mb-3">
                    <span class="flex items-center justify-center w-6 h-6 rounded-full bg-indigo-100 text-indigo-700 text-xs font-bold">2</span>
                    <h3 class="text-sm font-medium text-gray-700">Options d'animation</h3>
                </div>

                <div class="space-y-3">
                    {{-- Mode --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Mode</label>
                        <div class="grid grid-cols-2 gap-2">
                            <button
                                @click="mode = 'standard'"
                                class="px-3 py-2 rounded-lg text-sm font-medium border transition-colors"
                                :class="mode === 'standard' ? 'bg-indigo-50 border-indigo-300 text-indigo-700' : 'bg-white border-gray-200 text-gray-600 hover:bg-gray-50'"
                            >Standard</button>
                            <button
                                @click="mode = 'advanced'"
                                class="px-3 py-2 rounded-lg text-sm font-medium border transition-colors"
                                :class="mode === 'advanced' ? 'bg-indigo-50 border-indigo-300 text-indigo-700' : 'bg-white border-gray-200 text-gray-600 hover:bg-gray-50'"
                            >Avancé</button>
                        </div>
                        <p class="text-xs text-gray-400 mt-1" x-text="mode === 'standard' ? 'Caméra fixe, mouvements naturels' : 'Mouvements de caméra, cinématographique'"></p>
                    </div>

                    {{-- Movement type --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Type de mouvement</label>
                        <select x-model="movementType" class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="subtle">Subtil (vent, lumière, respiration)</option>
                            <option value="moderate">Modéré (marche, gestes, vagues)</option>
                            <option value="dynamic">Dynamique (course, vol, action)</option>
                            <option value="cinematic">Cinématographique (slow-mo, dramatique)</option>
                        </select>
                    </div>

                    {{-- Video style --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Style vidéo</label>
                        <select x-model="videoStyle" class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="realistic">Réaliste</option>
                            <option value="cinematic">Cinématographique</option>
                            <option value="dreamy">Onirique</option>
                            <option value="energetic">Énergique</option>
                            <option value="slow_motion">Ralenti artistique</option>
                        </select>
                    </div>

                    {{-- Description --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Instructions supplémentaires</label>
                        <textarea
                            x-model="description"
                            rows="2"
                            class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500"
                            placeholder="Ex: Focus sur le mouvement des cheveux..."
                        ></textarea>
                    </div>

                    {{-- Count --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Nombre de prompts</label>
                        <select x-model="count" class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="1">1</option>
                            <option value="3">3</option>
                            <option value="5">5</option>
                            <option value="8">8</option>
                        </select>
                    </div>
                </div>
            </div>

            {{-- Generate Button --}}
            <button
                @click="generatePrompts"
                :disabled="loading || !analysis"
                class="w-full flex items-center justify-center gap-2 px-4 py-3 bg-indigo-600 text-white rounded-xl font-medium hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            >
                <svg x-show="!loading" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z" />
                </svg>
                <svg x-show="loading" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <span x-text="loading ? 'Génération en cours...' : 'Générer les prompts vidéo'"></span>
            </button>
        </div>

        {{-- Results Panel --}}
        <div class="lg:col-span-2">
            {{-- Error --}}
            <template x-if="error">
                <div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-4">
                    <p class="text-sm text-red-700" x-text="error"></p>
                </div>
            </template>

            {{-- Empty state --}}
            <template x-if="!loading && prompts.length === 0 && !error">
                <div class="bg-white rounded-xl border border-gray-200 p-12 text-center">
                    <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z" />
                    </svg>
                    <h3 class="text-lg font-medium text-gray-600 mb-1">Aucun prompt généré</h3>
                    <p class="text-sm text-gray-400">Uploadez une photo, analysez-la, puis générez des prompts d'animation.</p>
                </div>
            </template>

            {{-- Loading --}}
            <template x-if="loading">
                <div class="bg-white rounded-xl border border-gray-200 p-12 text-center">
                    <svg class="w-12 h-12 mx-auto text-indigo-400 animate-spin mb-4" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <p class="text-sm text-gray-500">L'IA génère vos prompts d'animation...</p>
                </div>
            </template>

            {{-- Prompts list --}}
            <div x-show="prompts.length > 0 && !loading" class="space-y-4">
                <template x-for="(prompt, index) in prompts" :key="index">
                    <div class="bg-white rounded-xl border border-gray-200 p-5 group hover:border-indigo-200 transition-colors">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex-1">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-purple-100 text-purple-700"
                                        x-text="'Animation #' + (index + 1)"></span>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium"
                                        :class="mode === 'advanced' ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-600'"
                                        x-text="mode === 'advanced' ? 'Avancé' : 'Standard'"></span>
                                </div>
                                <p class="text-sm text-gray-800 leading-relaxed whitespace-pre-wrap" x-text="prompt"></p>
                            </div>
                            <button
                                @click="copyPrompt(index)"
                                class="flex-shrink-0 p-2 rounded-lg text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 transition-colors"
                                :title="copied === index ? 'Copié !' : 'Copier'"
                            >
                                <svg x-show="copied !== index" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0 0 13.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 0 1-.75.75H9.75a.75.75 0 0 1-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 0 1 1.927-.184" />
                                </svg>
                                <svg x-show="copied === index" class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </template>

                {{-- Copy all button --}}
                <div class="flex justify-end">
                    <button
                        @click="copyAll"
                        class="flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 7.5V6.108c0-1.135.845-2.098 1.976-2.192.373-.03.748-.057 1.123-.08M15.75 18H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08M15.75 18.75v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5A3.375 3.375 0 0 0 6.375 7.5H5.25m11.9-3.664A2.251 2.251 0 0 0 15 2.25h-1.5a2.251 2.251 0 0 0-2.15 1.586m5.8 0c.065.21.1.433.1.664v.75h-6V4.5c0-.231.035-.454.1-.664M6.75 7.5H4.875c-.621 0-1.125.504-1.125 1.125v12c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V16.5a9 9 0 0 0-9-9Z" />
                        </svg>
                        Copier tous les prompts
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function videoPromptGenerator() {
    return {
        // Photo
        photoFile: null,
        photoPreview: null,
        dragOver: false,
        analyzing: false,
        analysis: null,

        // Options
        mode: 'standard',
        movementType: 'subtle',
        videoStyle: 'realistic',
        description: '',
        count: '3',

        // Results
        loading: false,
        error: null,
        prompts: [],
        copied: null,

        handleFileSelect(e) {
            const file = e.target.files[0];
            if (file) this.setPhoto(file);
        },

        handleDrop(e) {
            this.dragOver = false;
            const file = e.dataTransfer.files[0];
            if (file && file.type.startsWith('image/')) {
                this.setPhoto(file);
            }
        },

        setPhoto(file) {
            this.photoFile = file;
            this.analysis = null;
            this.prompts = [];
            this.error = null;

            const reader = new FileReader();
            reader.onload = (e) => this.photoPreview = e.target.result;
            reader.readAsDataURL(file);
        },

        clearPhoto() {
            this.photoFile = null;
            this.photoPreview = null;
            this.analysis = null;
            this.prompts = [];
            this.error = null;
        },

        async analyzePhoto() {
            if (!this.photoFile) return;

            this.analyzing = true;
            this.error = null;

            const formData = new FormData();
            formData.append('photo', this.photoFile);

            try {
                const response = await fetch('{{ route("prompts.video.analyze") }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: formData,
                });

                const data = await response.json();

                if (!response.ok) {
                    this.error = data.error || data.message || 'Erreur lors de l\'analyse.';
                    return;
                }

                this.analysis = data.analysis;
            } catch (e) {
                this.error = 'Erreur réseau: ' + e.message;
            } finally {
                this.analyzing = false;
            }
        },

        async generatePrompts() {
            if (!this.analysis) return;

            this.loading = true;
            this.error = null;
            this.prompts = [];

            try {
                const response = await fetch('{{ route("prompts.video.generate") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        analysis: this.analysis,
                        description: this.description,
                        mode: this.mode,
                        movement_type: this.movementType,
                        video_style: this.videoStyle,
                        count: parseInt(this.count),
                    }),
                });

                const data = await response.json();

                if (!response.ok) {
                    this.error = data.error || data.message || 'Erreur lors de la génération.';
                    return;
                }

                this.prompts = data.prompts || [];
            } catch (e) {
                this.error = 'Erreur réseau: ' + e.message;
            } finally {
                this.loading = false;
            }
        },

        async copyPrompt(index) {
            await navigator.clipboard.writeText(this.prompts[index]);
            this.copied = index;
            setTimeout(() => this.copied = null, 2000);
        },

        async copyAll() {
            const text = this.prompts.map((p, i) => `--- Animation #${i + 1} ---\n${p}`).join('\n\n');
            await navigator.clipboard.writeText(text);
        },
    };
}
</script>
@endsection
