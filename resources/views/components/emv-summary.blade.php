@props(['emv', 'title' => 'Valeur média acquise (EMV)', 'compact' => false])

@php
    $currency = $emv['currency'] ?? 'EUR';
    $symbol = ['EUR' => '€', 'USD' => '$', 'GBP' => '£'][$currency] ?? $currency;
    $coverage = $emv['coverage'] ?? ['items' => 0, 'valued' => 0, 'measurable' => 0, 'uncovered_platforms' => []];
    $fmt = fn ($v) => number_format((float) $v, 2, ',', ' ') . ' ' . $symbol;
@endphp

<div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
    <div class="flex items-start justify-between gap-4">
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">{{ $title }}</p>
            <p class="mt-1 text-2xl font-semibold text-gray-900 tabular-nums">{{ $fmt($emv['total'] ?? 0) }}</p>
        </div>
        <span class="shrink-0 text-[11px] text-gray-400 tabular-nums">
            {{ $coverage['valued'] }}/{{ $coverage['items'] }} diffusions valorisées
        </span>
    </div>

    <div class="mt-4 grid grid-cols-2 gap-3">
        <div class="rounded-xl bg-gray-50 px-3 py-2">
            @php
                // Le CPM peut venir du barème ou de la dépense Meta réelle :
                // un chiffre montré à un partenaire doit dire d'où il sort.
                $observed = collect($emv['by_platform'] ?? [])->contains(fn ($r) => ($r['cpm_source'] ?? null) === 'meta_observed');
            @endphp
            <p class="text-[11px] font-medium text-gray-500" title="(vues ÷ 1000) × CPM">
                Méthode impressions
                @if($observed)
                    <span class="ml-1 px-1 py-px rounded bg-green-50 text-green-700 font-semibold"
                          title="CPM réellement payé sur Meta Ads, pas un barème">constaté</span>
                @endif
            </p>
            <p class="text-sm font-semibold text-gray-900 tabular-nums">{{ $fmt($emv['cpm'] ?? 0) }}</p>
        </div>
        <div class="rounded-xl bg-gray-50 px-3 py-2">
            <p class="text-[11px] font-medium text-gray-500" title="Σ (nombre d'actions × valeur unitaire)">
                Méthode engagement
            </p>
            <p class="text-sm font-semibold text-gray-900 tabular-nums">{{ $fmt($emv['ayzenberg'] ?? 0) }}</p>
        </div>
    </div>

    @if(! $compact && ! empty($emv['by_platform']))
        <div class="mt-4 space-y-1.5">
            @foreach($emv['by_platform'] as $row)
                <div class="flex items-center justify-between text-xs">
                    <span class="text-gray-600">
                        {{ $row['slug'] }}
                        <span class="text-gray-400 tabular-nums">({{ $row['items'] }})</span>
                        @unless($row['views_available'])
                            <span class="text-amber-600" title="L'API de ce réseau n'expose aucune vue : seule la méthode engagement s'applique.">·&nbsp;sans vues</span>
                        @endunless
                    </span>
                    <span class="text-gray-900 tabular-nums">{{ $fmt($row['cpm'] + $row['ayzenberg']) }}</span>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Afficher un total sans dire ce qu'il ne couvre pas serait le rendre faux. --}}
    @if(! empty($coverage['uncovered_platforms']))
        <p class="mt-3 text-[11px] leading-relaxed text-amber-700">
            Méthode impressions non calculable sur
            {{ implode(', ', $coverage['uncovered_platforms']) }} :
            ces API n'exposent pas de vues.
        </p>
    @endif

    <p class="mt-2 text-[11px] leading-relaxed text-gray-400">
        Estimation à partir des tarifs de référence
        (<a href="{{ route('settings.index') }}" class="underline hover:text-gray-600">réglables</a>),
        recalculée à chaque affichage. Hors fils de discussion.
    </p>
</div>
