@extends('layouts.app')

@section('title', 'Sponsoriser une publication')

@section('content')

    @include('ads._flash')

    @php
        // Sérialisation pour Alpine. Uniquement des tableaux : pas de closure
        // dans @json (erreurs de parse Blade, cf. commit 91501f1).
        $objectivesData = collect($objectives)->map(fn ($o, $key) => [
            'key' => $key,
            'label' => $o['label'],
            'description' => $o['description'],
            'needs_link' => (bool) $o['needs_link'],
            'needs_pixel' => (bool) $o['needs_pixel'],
            'default_goal' => $o['default_goal'],
            'goals' => $o['goals'],
        ])->values()->all();

        $currency = $account['currency'] ?? '';
        $maxDaily = (float) config('meta_ads.max_daily_budget');
        $maxDays = (int) config('meta_ads.max_days');
    @endphp

    <div class="mb-6">
        <a href="{{ route('ads.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← Publicités</a>
        <h1 class="text-xl font-semibold text-gray-900 mt-2">Sponsoriser une publication</h1>
        <p class="text-sm text-gray-500 mt-1">
            La publication d'origine est promue <strong>avec ses likes et ses commentaires</strong> :
            rien n'est republié.
        </p>
    </div>

    {{-- Ce qui va être sponsorisé --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6">
        <div class="flex items-start justify-between gap-6">
            <div class="min-w-0">
                <p class="text-[11px] uppercase tracking-wide text-gray-400 font-semibold">Publication</p>
                <p class="text-sm text-gray-900 mt-1">{{ \Illuminate\Support\Str::limit($pp->post?->content_fr ?? '(sans texte)', 200) }}</p>
                <p class="text-xs text-gray-500 mt-2">
                    {{ $pp->platform?->name }} · {{ $pp->socialAccount?->name }}
                    @if($pp->published_at) · publié {{ $pp->published_at->diffForHumans() }} @endif
                </p>
                @if(isset($reference['error']))
                    <p class="text-xs text-red-600 mt-2">{{ $reference['error'] }}</p>
                @else
                    <p class="text-xs text-gray-400 mt-1 font-mono">
                        {{ $reference['object_story_id'] ?? $reference['instagram_media_id'] ?? '' }}
                    </p>
                @endif
            </div>
            @if($pp->platform_url)
                <a href="{{ $pp->platform_url }}" target="_blank" rel="noopener"
                   class="text-xs text-indigo-600 hover:underline shrink-0">Voir la publication ↗</a>
            @endif
        </div>
    </div>

    @if(! $publishable || isset($reference['error']))
        <div class="rounded-xl bg-amber-50 border border-amber-200 p-4 text-sm text-amber-800">
            Cette diffusion ne peut pas être sponsorisée : seule une publication Facebook ou Instagram
            réellement publiée peut être promue.
        </div>
    @else

        @if(! $writeEnabled)
            <div class="mb-6 rounded-xl bg-amber-50 border border-amber-200 p-4 text-sm text-amber-800">
                L'écriture est fermée (<code>META_ADS_WRITE_ENABLED=false</code>) : la simulation elle-même
                est refusée. C'est délibéré — l'interrupteur qui autorise à dépenser ne s'ouvre pas depuis
                une session web.
            </div>
        @endif

        <form method="POST" action="{{ route('ads.boost', $pp) }}"
              x-data="boostForm({
                  objectives: {{ Illuminate\Support\Js::from($objectivesData) }},
                  maxDaily: {{ $maxDaily }},
                  maxDays: {{ $maxDays }},
                  currency: @js($currency),
                  simulateUrl: @js(route('ads.boost', $pp)),
                  csrf: @js(csrf_token()),
              })"
              @submit="if (! confirmLaunch()) $event.preventDefault()">
            @csrf
            <input type="hidden" name="dry_run" value="0">

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 space-y-6">

                    {{-- 1. Objectif --}}
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <h2 class="text-sm font-semibold text-gray-900 mb-1">1. Objectif</h2>
                        <p class="text-xs text-gray-500 mb-4">
                            Ce que Meta doit chercher à obtenir. Il détermine à qui la publication est montrée,
                            bien plus que le budget.
                        </p>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <template x-for="o in objectives" :key="o.key">
                                <label class="block border rounded-xl p-3 cursor-pointer transition-colors"
                                       :class="objective === o.key ? 'border-indigo-500 bg-indigo-50/50' : 'border-gray-200 hover:border-gray-300'">
                                    <input type="radio" name="objective" :value="o.key" x-model="objective" class="sr-only">
                                    <span class="text-sm font-medium text-gray-900" x-text="o.label"></span>
                                    <span class="block text-xs text-gray-500 mt-1" x-text="o.description"></span>
                                </label>
                            </template>
                        </div>

                        <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Optimiser pour</label>
                                <select name="optimization_goal" x-model="goal"
                                        class="block w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <template x-for="(label, key) in currentGoals" :key="key">
                                        <option :value="key" x-text="label"></option>
                                    </template>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nom de la campagne</label>
                                <input type="text" name="name" x-model="name" maxlength="120"
                                       placeholder="Laisser vide : nommée d'après la publication"
                                       class="block w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                        </div>

                        <p class="text-xs text-amber-700 mt-3" x-show="currentObjective?.needs_pixel" x-cloak>
                            Objectif de conversion : sans pixel renseigné dans les paramètres, la campagne
                            diffusera mais n'aura aucun signal à optimiser.
                        </p>
                        <p class="text-xs text-amber-700 mt-1" x-show="currentObjective?.needs_link" x-cloak>
                            Cet objectif suppose que la publication contient un lien.
                        </p>
                    </div>

                    {{-- 2. Audience --}}
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <div class="flex items-center justify-between mb-1">
                            <h2 class="text-sm font-semibold text-gray-900">2. Audience</h2>
                            <a href="{{ route('ads.audiences.create') }}" target="_blank"
                               class="text-xs text-indigo-600 hover:underline">Créer une audience ↗</a>
                        </div>
                        <p class="text-xs text-gray-500 mb-4">
                            Une audience trop étroite ne renvoie aucune erreur : elle ne diffuse simplement pas.
                            Vérifiez l'estimation avant de lancer.
                        </p>

                        <select name="audience_id" x-model="audienceId"
                                class="block w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">Ciblage large par défaut ({{ implode(', ', config('meta_ads.default_targeting.countries')) }}, {{ config('meta_ads.default_targeting.age_min') }}-{{ config('meta_ads.default_targeting.age_max') }} ans)</option>
                            @foreach($audiences as $audience)
                                <option value="{{ $audience->id }}" @selected($audience->is_default)>
                                    {{ $audience->name }}{{ $audience->is_default ? ' (par défaut)' : '' }} — {{ $audience->summary() }}
                                </option>
                            @endforeach
                        </select>

                        @foreach($audiences as $audience)
                            @if($audience->estimated_reach)
                                <p class="text-xs text-gray-500 mt-2" x-show="audienceId === '{{ $audience->id }}'" x-cloak>
                                    Dernière estimation : ~{{ number_format($audience->estimated_reach, 0, ',', ' ') }} personnes
                                    ({{ $audience->estimated_at?->diffForHumans() }})
                                </p>
                            @endif
                        @endforeach

                        <div class="mt-4 pt-4 border-t border-gray-100">
                            <p class="text-sm font-medium text-gray-700 mb-2">Catégorie publicitaire spéciale</p>
                            <p class="text-xs text-gray-500 mb-3">
                                Obligatoire pour le logement, le crédit, l'emploi et la politique. La déclarer
                                interdit le ciblage par âge, genre et centres d'intérêt — RS-Max les retire alors
                                du ciblage. Ne rien cocher est le cas normal.
                            </p>
                            <div class="flex flex-wrap gap-3">
                                @foreach(config('meta_ads.special_ad_categories') as $key => $label)
                                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" name="special_ad_categories[]" value="{{ $key }}"
                                               class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                        {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    {{-- 3. Budget et durée --}}
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <h2 class="text-sm font-semibold text-gray-900 mb-1">3. Budget et durée</h2>
                        <p class="text-xs text-gray-500 mb-4">
                            Le plafond porte sur le budget <strong>quotidien équivalent</strong> :
                            « 300 {{ $currency }} sur 3 jours » compte comme 100 {{ $currency }} par jour.
                        </p>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Type de budget</label>
                                <select name="budget_type" x-model="budgetType"
                                        class="block w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="lifetime">Total sur la période</option>
                                    <option value="daily">Quotidien</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Montant ({{ $currency ?: 'devise du compte' }})
                                </label>
                                <input type="number" name="budget" x-model.number="budget" min="1" step="0.01" required
                                       class="block w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Durée (jours)</label>
                                <input type="number" name="days" x-model.number="days" min="1" max="{{ $maxDays }}" required
                                       class="block w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                        </div>

                        <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Début</label>
                                <input type="datetime-local" name="start_date" x-model="startDate"
                                       class="block w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <p class="text-xs text-gray-500 mt-1">Vide = dès le lancement. La fin est calculée depuis la durée.</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Stratégie d'enchère</label>
                                <select name="bid_strategy" x-model="bidStrategy"
                                        class="block w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    @foreach(config('meta_ads.bid_strategies') as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <div x-show="bidStrategy !== 'LOWEST_COST_WITHOUT_CAP'" x-cloak class="mt-2">
                                    <input type="number" name="bid_amount" x-model.number="bidAmount" min="0.01" step="0.01"
                                           placeholder="Plafond par résultat ({{ $currency }})"
                                           class="block w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <p class="text-xs text-amber-700 mt-1">
                                        Un plafond trop bas ne dépense rien du tout — ce qui ressemble à une panne.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 rounded-xl bg-gray-50 border border-gray-100 p-4 text-sm text-gray-700">
                            <p>
                                Dépense maximale : <strong x-text="totalExposure.toFixed(2)"></strong> {{ $currency }}
                                sur <strong x-text="days"></strong> jour(s) —
                                soit <strong x-text="dailyEquivalent.toFixed(2)"></strong> {{ $currency }} par jour.
                            </p>
                            <p class="text-red-600 mt-1" x-show="dailyEquivalent > maxDaily" x-cloak>
                                Au-dessus du plafond quotidien de {{ $maxDaily }} {{ $currency }} : la demande sera refusée.
                                Ce plafond n'est pas contournable.
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Colonne de droite : simulation puis lancement --}}
                <div class="space-y-6">
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 lg:sticky lg:top-6">
                        <h2 class="text-sm font-semibold text-gray-900 mb-3">Vérifier puis créer</h2>

                        <button type="button" @click="simulate()"
                                :disabled="loading || {{ $writeEnabled ? 'false' : 'true' }}"
                                class="w-full px-4 py-2.5 rounded-xl bg-gray-900 text-white text-sm font-medium hover:bg-gray-800 disabled:opacity-40">
                            <span x-show="!loading">Simuler (rien n'est créé)</span>
                            <span x-show="loading" x-cloak>Vérification…</span>
                        </button>

                        <p class="text-xs text-gray-500 mt-2">
                            La simulation demande à <strong>Meta</strong> de valider la publication, les droits
                            et le ciblage — pas à RS-Max de deviner.
                        </p>

                        <template x-if="result">
                            <div class="mt-4 space-y-3">
                                <div class="rounded-xl p-3 text-sm"
                                     :class="result.promotable ? 'bg-green-50 border border-green-200 text-green-800' : 'bg-red-50 border border-red-200 text-red-700'">
                                    <span x-text="result.promotable ? 'Publication promouvable.' : (result.error || 'Meta refuse cette publication.')"></span>
                                </div>

                                <template x-if="result.audience_estimate && result.audience_estimate.upper">
                                    <p class="text-sm text-gray-700">
                                        Audience estimée :
                                        <strong x-text="formatNumber(result.audience_estimate.lower)"></strong>
                                        à
                                        <strong x-text="formatNumber(result.audience_estimate.upper)"></strong>
                                        personnes
                                    </p>
                                </template>

                                <template x-for="(w, i) in (result.warnings || [])" :key="i">
                                    <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg p-2" x-text="w"></p>
                                </template>

                                <details class="text-xs text-gray-500">
                                    <summary class="cursor-pointer">Plan détaillé</summary>
                                    <pre class="mt-2 p-2 bg-gray-50 rounded-lg overflow-x-auto" x-text="JSON.stringify(result.plan, null, 2)"></pre>
                                </details>
                            </div>
                        </template>

                        <template x-if="error">
                            <p class="mt-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-xl p-3" x-text="error"></p>
                        </template>

                        <button type="submit"
                                :disabled="{{ $writeEnabled ? 'false' : 'true' }}"
                                class="w-full mt-4 px-4 py-2.5 rounded-xl bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 disabled:opacity-40">
                            Créer la campagne (en pause)
                        </button>
                        <p class="text-xs text-gray-500 mt-2">
                            Rien n'est dépensé à la création. La diffusion se lance ensuite explicitement
                            depuis l'écran de la campagne.
                        </p>
                    </div>

                    @if($existing->isNotEmpty())
                        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                            <h2 class="text-sm font-semibold text-gray-900 mb-3">Déjà sponsorisée</h2>
                            <ul class="space-y-2">
                                @foreach($existing as $boost)
                                    <li class="text-xs text-gray-600 flex items-center justify-between gap-2">
                                        <span>{{ $boost->created_at->format('d/m/Y') }} — {{ $boost->budget }} {{ $currency }}</span>
                                        @if($boost->campaign_id)
                                            <a href="{{ route('ads.campaign', $boost->campaign_id) }}" class="text-indigo-600 hover:underline">voir</a>
                                        @else
                                            <span class="text-red-600">échec</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            </div>
        </form>
    @endif

@push('scripts')
<script>
function boostForm(config) {
    return {
        objectives: config.objectives,
        maxDaily: config.maxDaily,
        maxDays: config.maxDays,
        objective: config.objectives[0]?.key ?? 'OUTCOME_ENGAGEMENT',
        goal: config.objectives[0]?.default_goal ?? 'POST_ENGAGEMENT',
        name: '',
        audienceId: '{{ optional($audiences->firstWhere('is_default', true))->id }}',
        budgetType: 'lifetime',
        budget: 20,
        days: 7,
        startDate: '',
        bidStrategy: 'LOWEST_COST_WITHOUT_CAP',
        bidAmount: null,
        loading: false,
        result: null,
        error: null,

        get currentObjective() {
            return this.objectives.find(o => o.key === this.objective);
        },

        get currentGoals() {
            return this.currentObjective?.goals ?? {};
        },

        // Le couple objectif / optimisation doit rester valide : changer
        // d'objectif sans reposer l'optimisation envoie un couple que Meta
        // refuse avec un « Invalid parameter » qui n'explique rien.
        init() {
            this.$watch('objective', () => {
                this.goal = this.currentObjective?.default_goal ?? Object.keys(this.currentGoals)[0];
                this.result = null;
            });
        },

        get dailyEquivalent() {
            const budget = Number(this.budget) || 0;
            const days = Math.max(1, Number(this.days) || 1);
            return this.budgetType === 'daily' ? budget : budget / days;
        },

        get totalExposure() {
            const budget = Number(this.budget) || 0;
            const days = Math.max(1, Number(this.days) || 1);
            return this.budgetType === 'daily' ? budget * days : budget;
        },

        formatNumber(value) {
            return value == null ? '?' : Number(value).toLocaleString('fr-FR');
        },

        payload(dryRun) {
            const data = new FormData();
            data.append('_token', config.csrf);
            data.append('dry_run', dryRun ? '1' : '0');
            data.append('objective', this.objective);
            data.append('optimization_goal', this.goal);
            data.append('name', this.name);
            data.append('audience_id', this.audienceId);
            data.append('budget_type', this.budgetType);
            data.append('budget', this.budget);
            data.append('days', this.days);
            data.append('start_date', this.startDate);
            data.append('bid_strategy', this.bidStrategy);
            if (this.bidAmount) data.append('bid_amount', this.bidAmount);
            document.querySelectorAll('input[name="special_ad_categories[]"]:checked')
                .forEach(el => data.append('special_ad_categories[]', el.value));
            return data;
        },

        async simulate() {
            this.loading = true;
            this.error = null;
            this.result = null;

            try {
                const response = await fetch(config.simulateUrl, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: this.payload(true),
                });
                const json = await response.json();

                if (! response.ok) {
                    this.error = json.error || 'La simulation a échoué.';
                    return;
                }

                this.result = json;
            } catch (e) {
                this.error = e.message;
            } finally {
                this.loading = false;
            }
        },

        // Dernier filet avant la création : le montant réellement engagé, en
        // clair, parce que « 20 par jour sur 30 jours » ne se lit pas comme 600.
        confirmLaunch() {
            return confirm(
                'Créer la campagne ?\n\n'
                + 'Dépense maximale : ' + this.totalExposure.toFixed(2) + ' ' + config.currency
                + ' sur ' + this.days + ' jour(s).\n'
                + 'La campagne est créée EN PAUSE : elle ne dépensera qu\'une fois lancée.'
            );
        },
    };
}
</script>
@endpush

@endsection
