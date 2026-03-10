@extends('layouts.app')
@section('title', 'Générateur de prompts image')

@section('content')
<div class="max-w-6xl mx-auto" x-data="imagePromptGenerator()">
    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Générateur de prompts image</h1>
            <p class="text-sm text-gray-500 mt-1">Créez des prompts optimisés pour Midjourney, DALL-E, Stable Diffusion</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Options Panel --}}
        <div class="lg:col-span-1 space-y-4">
            {{-- Description --}}
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <label class="block text-sm font-medium text-gray-700 mb-2">Description / Thème</label>
                <textarea
                    x-model="description"
                    rows="3"
                    class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500"
                    placeholder="Ex: Une femme dans un van aménagé regardant un coucher de soleil sur l'océan..."
                ></textarea>
            </div>

            {{-- Content Type --}}
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <label class="block text-sm font-medium text-gray-700 mb-2">Type de contenu</label>
                <select x-model="contentType" class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500">
                    <option value="">-- Libre --</option>
                    <option value="lifestyle">Lifestyle</option>
                    <option value="travel">Voyage / Travel</option>
                    <option value="portrait">Portrait</option>
                    <option value="nature">Nature / Paysage</option>
                    <option value="food">Food / Gastronomie</option>
                    <option value="architecture">Architecture / Urbain</option>
                    <option value="fashion">Mode / Fashion</option>
                    <option value="sport">Sport / Action</option>
                    <option value="product">Produit / E-commerce</option>
                    <option value="abstract">Abstrait / Artistique</option>
                    <option value="vanlife">Vanlife / Roadtrip</option>
                </select>
            </div>

            {{-- Season & Time --}}
            <div class="bg-white rounded-xl border border-gray-200 p-5 space-y-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Saison</label>
                    <select x-model="season" class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">-- Peu importe --</option>
                        <option value="spring">Printemps</option>
                        <option value="summer">Été</option>
                        <option value="autumn">Automne</option>
                        <option value="winter">Hiver</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Moment de la journée</label>
                    <select x-model="timeOfDay" class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">-- Peu importe --</option>
                        <option value="golden hour sunrise">Golden hour (lever)</option>
                        <option value="morning">Matin</option>
                        <option value="midday">Midi</option>
                        <option value="afternoon">Après-midi</option>
                        <option value="golden hour sunset">Golden hour (coucher)</option>
                        <option value="blue hour">Blue hour</option>
                        <option value="night">Nuit</option>
                    </select>
                </div>
            </div>

            {{-- Photo Style & Shot --}}
            <div class="bg-white rounded-xl border border-gray-200 p-5 space-y-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Style photo</label>
                    <select x-model="photoStyle" class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">-- Libre --</option>
                        <option value="cinematic">Cinématographique</option>
                        <option value="editorial">Éditorial / Magazine</option>
                        <option value="documentary">Documentaire</option>
                        <option value="vintage film">Film vintage / Argentique</option>
                        <option value="minimalist">Minimaliste</option>
                        <option value="dramatic">Dramatique</option>
                        <option value="dreamy soft">Onirique / Soft</option>
                        <option value="hyperrealistic">Hyperréaliste</option>
                        <option value="illustration">Illustration</option>
                        <option value="watercolor">Aquarelle</option>
                        <option value="3d render">Rendu 3D</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Type de plan</label>
                    <select x-model="shotType" class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">-- Libre --</option>
                        <option value="extreme close-up">Très gros plan</option>
                        <option value="close-up">Gros plan</option>
                        <option value="medium shot">Plan moyen</option>
                        <option value="full body shot">Plan pied</option>
                        <option value="wide shot">Plan large</option>
                        <option value="aerial drone shot">Vue aérienne / Drone</option>
                        <option value="bird's eye view">Plongée</option>
                        <option value="low angle">Contre-plongée</option>
                        <option value="over the shoulder">Par-dessus l'épaule</option>
                    </select>
                </div>
            </div>

            {{-- Extra options --}}
            <div class="bg-white rounded-xl border border-gray-200 p-5 space-y-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Véhicule / Cadre</label>
                    <input
                        x-model="vehicle"
                        type="text"
                        class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500"
                        placeholder="Ex: van aménagé, café, plage..."
                    >
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Animaux</label>
                    <input
                        x-model="animals"
                        type="text"
                        class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500"
                        placeholder="Ex: chien golden retriever, chat..."
                    >
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Nombre de prompts</label>
                    <select x-model="count" class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="1">1</option>
                        <option value="3">3</option>
                        <option value="5">5</option>
                        <option value="8">8</option>
                        <option value="10">10</option>
                    </select>
                </div>
                <div class="flex items-center gap-2">
                    <input type="checkbox" x-model="safeMode" id="safe_mode" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                    <label for="safe_mode" class="text-sm text-gray-700">Mode safe (tous publics)</label>
                </div>
            </div>

            {{-- Generate Button --}}
            <button
                @click="generate"
                :disabled="loading"
                class="w-full flex items-center justify-center gap-2 px-4 py-3 bg-indigo-600 text-white rounded-xl font-medium hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            >
                <svg x-show="!loading" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z" />
                </svg>
                <svg x-show="loading" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <span x-text="loading ? 'Génération en cours...' : 'Générer les prompts'"></span>
            </button>

            {{-- Random Button --}}
            <button
                @click="randomize(); generate()"
                :disabled="loading"
                class="w-full flex items-center justify-center gap-2 px-4 py-2.5 bg-white border border-gray-300 text-gray-700 rounded-xl text-sm font-medium hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 12c0-1.232-.046-2.453-.138-3.662a4.006 4.006 0 0 0-3.7-3.7 48.678 48.678 0 0 0-7.324 0 4.006 4.006 0 0 0-3.7 3.7c-.017.22-.032.441-.046.662M19.5 12l3-3m-3 3-3-3m-12 3c0 1.232.046 2.453.138 3.662a4.006 4.006 0 0 0 3.7 3.7 48.656 48.656 0 0 0 7.324 0 4.006 4.006 0 0 0 3.7-3.7c.017-.22.032-.441.046-.662M4.5 12l3 3m-3-3-3 3" />
                </svg>
                Aléatoire
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
                        <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z" />
                    </svg>
                    <h3 class="text-lg font-medium text-gray-600 mb-1">Aucun prompt généré</h3>
                    <p class="text-sm text-gray-400">Configurez vos options et cliquez sur "Générer" ou essayez le mode aléatoire.</p>
                </div>
            </template>

            {{-- Loading --}}
            <template x-if="loading">
                <div class="bg-white rounded-xl border border-gray-200 p-12 text-center">
                    <svg class="w-12 h-12 mx-auto text-indigo-400 animate-spin mb-4" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <p class="text-sm text-gray-500">L'IA génère vos prompts...</p>
                </div>
            </template>

            {{-- Prompts list --}}
            <div x-show="prompts.length > 0 && !loading" class="space-y-4">
                <template x-for="(prompt, index) in prompts" :key="index">
                    <div class="bg-white rounded-xl border border-gray-200 p-5 group hover:border-indigo-200 transition-colors">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex-1">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-indigo-100 text-indigo-700 mb-2"
                                    x-text="'Prompt #' + (index + 1)"></span>
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
function imagePromptGenerator() {
    return {
        description: '',
        contentType: '',
        season: '',
        timeOfDay: '',
        photoStyle: '',
        shotType: '',
        vehicle: '',
        animals: '',
        count: '3',
        safeMode: false,
        loading: false,
        error: null,
        prompts: [],
        copied: null,

        async generate() {
            this.loading = true;
            this.error = null;
            this.prompts = [];

            try {
                const response = await fetch('{{ route("prompts.image.generate") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        description: this.description,
                        content_type: this.contentType,
                        season: this.season,
                        time_of_day: this.timeOfDay,
                        photo_style: this.photoStyle,
                        shot_type: this.shotType,
                        vehicle: this.vehicle,
                        animals: this.animals,
                        safe_mode: this.safeMode,
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

        randomize() {
            const types = ['lifestyle', 'travel', 'portrait', 'nature', 'food', 'architecture', 'fashion', 'sport', 'vanlife', 'abstract'];
            const seasons = ['', 'spring', 'summer', 'autumn', 'winter'];
            const times = ['', 'golden hour sunrise', 'morning', 'afternoon', 'golden hour sunset', 'blue hour', 'night'];
            const styles = ['', 'cinematic', 'editorial', 'documentary', 'vintage film', 'minimalist', 'dramatic', 'dreamy soft', 'hyperrealistic'];
            const shots = ['', 'close-up', 'medium shot', 'wide shot', 'aerial drone shot', 'low angle'];

            this.contentType = types[Math.floor(Math.random() * types.length)];
            this.season = seasons[Math.floor(Math.random() * seasons.length)];
            this.timeOfDay = times[Math.floor(Math.random() * times.length)];
            this.photoStyle = styles[Math.floor(Math.random() * styles.length)];
            this.shotType = shots[Math.floor(Math.random() * shots.length)];
        },

        async copyPrompt(index) {
            await navigator.clipboard.writeText(this.prompts[index]);
            this.copied = index;
            setTimeout(() => this.copied = null, 2000);
        },

        async copyAll() {
            const text = this.prompts.map((p, i) => `--- Prompt #${i + 1} ---\n${p}`).join('\n\n');
            await navigator.clipboard.writeText(text);
        },
    };
}
</script>
@endsection
