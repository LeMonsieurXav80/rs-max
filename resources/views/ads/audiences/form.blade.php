@extends('layouts.app')

@section('title', $audience->exists ? 'Modifier l\'audience' : 'Nouvelle audience')

@section('content')

    @include('ads._flash')

    @php
        $spec = $audience->spec ?? [];
        $placements = config('meta_ads.placements');
        // Etat initial d'Alpine : uniquement des tableaux (pas de closure dans
        // @json — erreurs de parse Blade, cf. commit 91501f1).
        $initial = [
            'countries' => implode(', ', $spec['countries'] ?? []),
            'excluded_countries' => implode(', ', $spec['excluded_countries'] ?? []),
            'age_min' => $spec['age_min'] ?? 18,
            'age_max' => $spec['age_max'] ?? 65,
            'genders' => array_map('intval', $spec['genders'] ?? []),
            'cities' => $spec['cities'] ?? [],
            'interests' => $spec['interests'] ?? [],
            'behaviors' => $spec['behaviors'] ?? [],
            'excluded_interests' => $spec['excluded_interests'] ?? [],
            'locales' => $spec['locales'] ?? [],
            'custom_audiences' => $spec['custom_audiences'] ?? [],
            'excluded_custom_audiences' => $spec['excluded_custom_audiences'] ?? [],
            'advantage_audience' => (bool) ($spec['advantage_audience'] ?? false),
        ];
    @endphp

    <div class="mb-6">
        <a href="{{ route('ads.audiences.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← Audiences</a>
        <h1 class="text-xl font-semibold text-gray-900 mt-2">
            {{ $audience->exists ? 'Modifier l\'audience' : 'Nouvelle audience' }}
        </h1>
    </div>

    <form method="POST"
          action="{{ $audience->exists ? route('ads.audiences.update', $audience) : route('ads.audiences.store') }}"
          x-data="audienceForm({
              initial: {{ Illuminate\Support\Js::from($initial) }},
              searchUrl: @js(route('ads.audiences.search')),
              estimateUrl: @js(route('ads.audiences.estimate')),
              csrf: @js(csrf_token()),
          })">
        @csrf
        @if($audience->exists) @method('PUT') @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 space-y-6">

                {{-- Identité --}}
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nom <span class="text-red-500">*</span></label>
                        <input type="text" name="name" value="{{ old('name', $audience->name) }}" required maxlength="120"
                               placeholder="Ex : Portugal — surf 25-45"
                               class="block w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <textarea name="description" rows="2" maxlength="1000"
                                  class="block w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('description', $audience->description) }}</textarea>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="is_default" value="1" @checked($audience->is_default)
                               class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        Audience proposée par défaut lors d'une sponsorisation
                    </label>
                </div>

                {{-- Géographie --}}
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-4">
                    <h2 class="text-sm font-semibold text-gray-900">Géographie</h2>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Pays (codes ISO, séparés par des virgules)</label>
                            <input type="text" x-model="countries" placeholder="PT, FR"
                                   class="block w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <template x-for="(code, i) in countryList" :key="i">
                                <input type="hidden" name="spec[countries][]" :value="code">
                            </template>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Pays exclus</label>
                            <input type="text" x-model="excludedCountries" placeholder="ES"
                                   class="block w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <template x-for="(code, i) in excludedCountryList" :key="i">
                                <input type="hidden" name="spec[excluded_countries][]" :value="code">
                            </template>
                        </div>
                    </div>

                    {{-- Villes : recherche Meta, jamais de saisie libre --}}
                    @include('ads.audiences._picker', [
                        'field' => 'cities',
                        'type' => 'geo',
                        'label' => 'Villes et régions',
                        'help' => 'Recherche chez Meta : les identifiants de lieu ne s\'inventent pas.',
                    ])
                </div>

                {{-- Démographie --}}
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-4">
                    <h2 class="text-sm font-semibold text-gray-900">Démographie</h2>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Âge minimum</label>
                            <input type="number" name="spec[age_min]" x-model.number="ageMin" min="13" max="65"
                                   class="block w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Âge maximum</label>
                            <input type="number" name="spec[age_max]" x-model.number="ageMax" min="13" max="65"
                                   class="block w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Genre</label>
                            <select x-model.number="gender" class="block w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option :value="0">Tous</option>
                                <option :value="1">Hommes</option>
                                <option :value="2">Femmes</option>
                            </select>
                            <template x-if="gender">
                                <input type="hidden" name="spec[genders][]" :value="gender">
                            </template>
                        </div>
                    </div>

                    @include('ads.audiences._picker', [
                        'field' => 'locales',
                        'type' => 'locale',
                        'label' => 'Langues',
                        'help' => 'Vide = toutes les langues. Utile quand la publication est écrite dans une seule.',
                    ])
                </div>

                {{-- Centres d'intérêt --}}
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-4">
                    <h2 class="text-sm font-semibold text-gray-900">Centres d'intérêt et comportements</h2>
                    <p class="text-xs text-gray-500">
                        Les entrées d'un même bloc se combinent en OU. Plus il y en a, plus l'audience est large.
                    </p>

                    @include('ads.audiences._picker', [
                        'field' => 'interests',
                        'type' => 'interest',
                        'label' => 'Centres d\'intérêt',
                        'help' => null,
                    ])

                    @include('ads.audiences._picker', [
                        'field' => 'behaviors',
                        'type' => 'behavior',
                        'label' => 'Comportements',
                        'help' => 'Liste fermée côté Meta : tapez pour filtrer.',
                    ])

                    @include('ads.audiences._picker', [
                        'field' => 'excluded_interests',
                        'type' => 'interest',
                        'label' => 'Centres d\'intérêt exclus',
                        'help' => null,
                    ])
                </div>

                {{-- Audiences personnalisées du compte --}}
                @if(! empty($customAudiences))
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-4">
                        <h2 class="text-sm font-semibold text-gray-900">Audiences personnalisées du compte</h2>
                        <p class="text-xs text-gray-500">
                            RS-Max n'en crée pas — elles portent des données personnelles et se créent
                            dans le Gestionnaire de publicités — mais sait les réutiliser.
                        </p>
                        @php
                            // La spec peut stocker des ids nus ou des {id,name} :
                            // on ramène aux ids pour cocher les bonnes cases.
                            $pickedCustom = array_map(
                                fn ($item) => (string) (is_array($item) ? ($item['id'] ?? '') : $item),
                                $spec['custom_audiences'] ?? [],
                            );
                        @endphp
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            @foreach($customAudiences as $ca)
                                <label class="flex items-start gap-2 text-sm text-gray-700 border border-gray-200 rounded-xl p-3">
                                    <input type="checkbox" name="spec[custom_audiences][]"
                                           value="{{ $ca['id'] }}"
                                           @checked(in_array((string) $ca['id'], $pickedCustom, true))
                                           class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                    <span>
                                        {{ $ca['name'] ?? $ca['id'] }}
                                        <span class="block text-xs text-gray-400">
                                            {{ $ca['subtype'] ?? '' }}
                                            @if($ca['approximate_count_lower_bound'] ?? null)
                                                · ~{{ number_format($ca['approximate_count_lower_bound'], 0, ',', ' ') }}
                                            @endif
                                        </span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Placements --}}
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-4">
                    <h2 class="text-sm font-semibold text-gray-900">Placements</h2>
                    <p class="text-xs text-gray-500">
                        Ne rien cocher = placements automatiques, ce que Meta recommande dans la quasi-totalité
                        des cas. Une position n'est envoyée que si sa régie est cochée.
                    </p>

                    @foreach(['publisher_platforms' => 'Régies', 'facebook_positions' => 'Positions Facebook', 'instagram_positions' => 'Positions Instagram', 'device_platforms' => 'Appareils'] as $key => $label)
                        <div>
                            <p class="text-sm font-medium text-gray-700 mb-2">{{ $label }}</p>
                            <div class="flex flex-wrap gap-3">
                                @foreach($placements[$key] as $value => $optionLabel)
                                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" name="spec[{{ $key }}][]" value="{{ $value }}"
                                               @checked(in_array($value, $spec[$key] ?? []))
                                               class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                        {{ $optionLabel }}
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach

                    <label class="flex items-start gap-2 text-sm text-gray-700 pt-2 border-t border-gray-100">
                        <input type="checkbox" name="spec[advantage_audience]" value="1" @checked($spec['advantage_audience'] ?? false)
                               class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        <span>
                            Laisser Meta élargir l'audience (Advantage+)
                            <span class="block text-xs text-gray-500">
                                Meta diffuse au-delà du ciblage s'il pense y trouver mieux. Utile sur une audience
                                étroite, gênant quand le ciblage est le sujet même du test.
                            </span>
                        </span>
                    </label>
                </div>
            </div>

            {{-- Estimation + enregistrement --}}
            <div class="space-y-6">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 lg:sticky lg:top-6">
                    <h2 class="text-sm font-semibold text-gray-900 mb-3">Portée estimée</h2>

                    <button type="button" @click="estimate()"
                            class="w-full px-4 py-2.5 rounded-xl bg-gray-900 text-white text-sm font-medium hover:bg-gray-800 disabled:opacity-40"
                            :disabled="estimating">
                        <span x-show="!estimating">Estimer</span>
                        <span x-show="estimating" x-cloak>Calcul…</span>
                    </button>

                    <template x-if="estimation">
                        <p class="mt-3 text-sm text-gray-700">
                            <strong x-text="formatNumber(estimation.lower)"></strong> à
                            <strong x-text="formatNumber(estimation.upper)"></strong> personnes
                        </p>
                    </template>
                    <template x-if="estimationError">
                        <p class="mt-3 text-sm text-red-700" x-text="estimationError"></p>
                    </template>

                    <p class="text-xs text-gray-500 mt-3">
                        Une audience de quelques milliers de personnes ne diffusera pratiquement pas,
                        sans qu'aucune erreur ne le dise.
                    </p>

                    <button type="submit"
                            class="w-full mt-4 px-4 py-2.5 rounded-xl bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700">
                        {{ $audience->exists ? 'Enregistrer' : 'Créer l\'audience' }}
                    </button>
                    <p class="text-xs text-gray-500 mt-2">
                        Enregistrer une audience ne dépense rien : c'est l'utiliser dans une campagne qui coûte.
                    </p>
                </div>
            </div>
        </div>
    </form>

@push('scripts')
<script>
function audienceForm(config) {
    return {
        countries: config.initial.countries,
        excludedCountries: config.initial.excluded_countries,
        ageMin: config.initial.age_min,
        ageMax: config.initial.age_max,
        gender: config.initial.genders.length === 1 ? config.initial.genders[0] : 0,
        // Listes riches : tenues en mémoire, transportées en JSON par un champ
        // caché. Les identifiants viennent tous de Meta, jamais d'une saisie.
        picked: {
            cities: config.initial.cities,
            interests: config.initial.interests,
            behaviors: config.initial.behaviors,
            excluded_interests: config.initial.excluded_interests,
            locales: config.initial.locales,
        },
        query: {},
        results: {},
        searching: {},
        estimating: false,
        estimation: null,
        estimationError: null,

        get countryList() {
            return this.splitCodes(this.countries);
        },

        get excludedCountryList() {
            return this.splitCodes(this.excludedCountries);
        },

        splitCodes(value) {
            return (value || '').split(',')
                .map(c => c.trim().toUpperCase())
                .filter(c => c.length === 2);
        },

        json(field) {
            return JSON.stringify(this.picked[field] ?? []);
        },

        async search(field, type) {
            const q = (this.query[field] || '').trim();
            if (q.length < 2) { this.results[field] = []; return; }

            this.searching[field] = true;
            try {
                const url = new URL(config.searchUrl, window.location.origin);
                url.searchParams.set('type', type);
                url.searchParams.set('q', q);
                const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
                const json = await response.json();
                this.results[field] = json.results || [];
            } catch (e) {
                this.results[field] = [];
            } finally {
                this.searching[field] = false;
            }
        },

        pick(field, item) {
            const list = this.picked[field] ?? [];
            const key = item.id ?? item.key;

            if (! list.some(existing => (existing.id ?? existing.key) === key)) {
                // Les villes portent un rayon ; les autres n'en ont pas besoin.
                list.push(field === 'cities'
                    ? { key: item.key, name: item.name, radius: 25, distance_unit: 'kilometer' }
                    : { id: item.id ?? item.key, key: item.key, name: item.name });
                this.picked[field] = list;
            }

            this.query[field] = '';
            this.results[field] = [];
        },

        remove(field, index) {
            this.picked[field].splice(index, 1);
        },

        formatNumber(value) {
            return value == null ? '?' : Number(value).toLocaleString('fr-FR');
        },

        // La spec envoyée à l'estimation est la même que celle qui sera
        // enregistrée : estimer autre chose que ce qu'on enregistre n'aurait
        // aucune valeur.
        currentSpec() {
            const spec = {
                countries: this.countryList,
                excluded_countries: this.excludedCountryList,
                age_min: this.ageMin,
                age_max: this.ageMax,
                genders: this.gender ? [this.gender] : [],
                cities: this.picked.cities,
                locales: this.picked.locales,
                interests: this.picked.interests,
                behaviors: this.picked.behaviors,
                excluded_interests: this.picked.excluded_interests,
                advantage_audience: document.querySelector('input[name="spec[advantage_audience]"]')?.checked ?? false,
            };

            ['publisher_platforms', 'facebook_positions', 'instagram_positions', 'device_platforms'].forEach(key => {
                spec[key] = [...document.querySelectorAll('input[name="spec[' + key + '][]"]:checked')].map(el => el.value);
            });

            return spec;
        },

        async estimate() {
            this.estimating = true;
            this.estimation = null;
            this.estimationError = null;

            try {
                const response = await fetch(config.estimateUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': config.csrf,
                    },
                    body: JSON.stringify({ spec: this.currentSpec() }),
                });
                const json = await response.json();

                if (! response.ok || json.error) {
                    this.estimationError = json.error || 'Estimation indisponible.';
                    return;
                }

                this.estimation = json;
            } catch (e) {
                this.estimationError = e.message;
            } finally {
                this.estimating = false;
            }
        },
    };
}
</script>
@endpush

@endsection
