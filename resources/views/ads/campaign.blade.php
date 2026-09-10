@extends('layouts.app')

@section('title', $campaign['name'] ?? 'Campagne')

@section('content')

    @include('ads._flash')

    <div class="mb-6">
        <a href="{{ route('ads.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← Publicités</a>
    </div>

    @if($error)
        <div class="rounded-xl bg-red-50 border border-red-200 p-4 text-sm text-red-700">{{ $error }}</div>
    @else

        {{-- En-tête de campagne --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6">
            <div class="flex items-start justify-between gap-6">
                <div>
                    <div class="flex items-center gap-3">
                        <h1 class="text-lg font-semibold text-gray-900">{{ $campaign['name'] ?? $campaign['id'] }}</h1>
                        @include('ads._status-badge', ['status' => $campaign['effective_status'] ?? $campaign['status'] ?? null])
                    </div>
                    <p class="text-xs text-gray-400 mt-1 font-mono">{{ $campaign['id'] }}</p>

                    @if($boost)
                        <p class="text-sm text-gray-600 mt-3">
                            Sponsorise :
                            <a href="{{ route('ads.boost.form', $boost->post_platform_id) }}" class="text-indigo-600 hover:underline">
                                {{ \Illuminate\Support\Str::limit($boost->postPlatform?->post?->content_fr ?? 'publication', 80) }}
                            </a>
                        </p>
                        <p class="text-xs text-gray-500 mt-1">
                            Objectif {{ config('meta_ads.objectives.'.$boost->objective.'.label', $boost->objective) }}
                            · optimisation {{ $boost->optimization_goal }}
                            · {{ $boost->budget }} {{ $boost->budget_type === 'daily' ? 'par jour' : 'au total' }}
                            sur {{ $boost->days }} jours
                        </p>
                    @endif
                </div>

                @if($writeEnabled)
                    @php $isActive = ($campaign['status'] ?? null) === 'ACTIVE'; @endphp
                    <form method="POST" action="{{ route('ads.status', $campaign['id']) }}"
                          onsubmit="return confirm('{{ $isActive ? 'Mettre en pause ?' : 'Lancer la diffusion ? La dépense commence maintenant.' }}')">
                        @csrf
                        <input type="hidden" name="status" value="{{ $isActive ? 'PAUSED' : 'ACTIVE' }}">
                        <input type="hidden" name="object_type" value="campaign">
                        <button class="px-4 py-2 rounded-xl text-sm font-medium {{ $isActive ? 'bg-amber-50 text-amber-700 border border-amber-200 hover:bg-amber-100' : 'bg-green-600 text-white hover:bg-green-700' }}">
                            {{ $isActive ? 'Mettre en pause' : 'Lancer la diffusion' }}
                        </button>
                    </form>
                @endif
            </div>
        </div>

        {{-- Ad sets : budget, ciblage, dates, chiffres --}}
        <div class="space-y-6">
            @foreach($adSets as $adSet)
                @php $row = $insights[$adSet['id']] ?? []; @endphp
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-4">
                        <div>
                            <h2 class="text-sm font-semibold text-gray-900">{{ $adSet['name'] ?? $adSet['id'] }}</h2>
                            <p class="text-xs text-gray-500 mt-0.5">
                                {{ $adSet['optimization_goal'] ?? '—' }} · facturé à l'{{ strtolower($adSet['billing_event'] ?? 'impression') }}
                            </p>
                        </div>
                        @include('ads._status-badge', ['status' => $adSet['effective_status'] ?? $adSet['status'] ?? null])
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-5 divide-x divide-gray-50 border-b border-gray-50">
                        @foreach([
                            'Budget' => ($adSet['daily_budget'] ?? null) ? $adSet['daily_budget'].' /j' : (($adSet['lifetime_budget'] ?? null) ? $adSet['lifetime_budget'].' total' : '—'),
                            'Dépensé' => $row['spend'] ?? '—',
                            'Impressions' => isset($row['impressions']) ? number_format((int) $row['impressions'], 0, ',', ' ') : '—',
                            'Clics' => $row['clicks'] ?? '—',
                            'CPM' => $row['cpm'] ?? '—',
                        ] as $label => $value)
                            <div class="px-4 py-3">
                                <p class="text-[11px] uppercase tracking-wide text-gray-400 font-semibold">{{ $label }}</p>
                                <p class="text-sm text-gray-900 mt-0.5">{{ $value }}</p>
                            </div>
                        @endforeach
                    </div>

                    @if($writeEnabled)
                        <div class="px-6 py-4 grid grid-cols-1 lg:grid-cols-3 gap-6">
                            {{-- Budget --}}
                            <form method="POST" action="{{ route('ads.budget', $adSet['id']) }}" class="space-y-2">
                                @csrf
                                <input type="hidden" name="object_type" value="adset">
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide">Budget</label>
                                <div class="flex gap-2">
                                    <input type="number" name="amount" step="0.01" min="1" required
                                           value="{{ $adSet['daily_budget'] ?? $adSet['lifetime_budget'] ?? '' }}"
                                           class="flex-1 rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <button class="px-3 py-2 rounded-xl bg-gray-900 text-white text-xs hover:bg-gray-800">Modifier</button>
                                </div>
                                <label class="flex items-center gap-2 text-xs text-gray-600">
                                    <input type="checkbox" name="lifetime" value="1" @checked(! ($adSet['daily_budget'] ?? null))
                                           class="rounded border-gray-300 text-indigo-600">
                                    Budget total (sinon quotidien)
                                </label>
                                <label class="flex items-center gap-2 text-xs text-gray-600">
                                    <input type="checkbox" name="force" value="1" class="rounded border-gray-300 text-indigo-600">
                                    Forcer une hausse &gt; {{ config('meta_ads.max_budget_increase_pct') }} %
                                </label>
                            </form>

                            {{-- Audience --}}
                            <form method="POST" action="{{ route('ads.adset', $adSet['id']) }}" class="space-y-2">
                                @csrf
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide">Remplacer l'audience</label>
                                <select name="audience_id" class="block w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="">— choisir —</option>
                                    @foreach($audiences as $audience)
                                        <option value="{{ $audience->id }}">{{ $audience->name }}</option>
                                    @endforeach
                                </select>
                                <button class="w-full px-3 py-2 rounded-xl bg-gray-900 text-white text-xs hover:bg-gray-800">Appliquer</button>
                                <p class="text-[11px] text-gray-500">
                                    Une audience qui ne marche pas se remplace : inutile de relancer une campagne.
                                </p>
                            </form>

                            {{-- Dates --}}
                            <form method="POST" action="{{ route('ads.adset', $adSet['id']) }}" class="space-y-2">
                                @csrf
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide">Prolonger / avancer</label>
                                <input type="datetime-local" name="start_time"
                                       class="block w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <input type="datetime-local" name="end_time"
                                       class="block w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <button class="w-full px-3 py-2 rounded-xl bg-gray-900 text-white text-xs hover:bg-gray-800">Appliquer</button>
                            </form>
                        </div>

                        <div class="px-6 pb-4">
                            @php $adSetActive = ($adSet['status'] ?? null) === 'ACTIVE'; @endphp
                            <form method="POST" action="{{ route('ads.status', $adSet['id']) }}"
                                  onsubmit="return confirm('{{ $adSetActive ? 'Mettre cet ad set en pause ?' : 'Lancer cet ad set ? La dépense commence maintenant.' }}')">
                                @csrf
                                <input type="hidden" name="status" value="{{ $adSetActive ? 'PAUSED' : 'ACTIVE' }}">
                                <input type="hidden" name="object_type" value="adset">
                                <button class="text-xs px-3 py-1.5 rounded-lg border {{ $adSetActive ? 'border-amber-200 text-amber-700 hover:bg-amber-50' : 'border-green-200 text-green-700 hover:bg-green-50' }}">
                                    {{ $adSetActive ? 'Mettre l\'ad set en pause' : 'Lancer l\'ad set' }}
                                </button>
                            </form>
                        </div>
                    @endif

                    {{-- Ciblage réellement diffusé --}}
                    @if(! empty($adSet['targeting']))
                        <details class="px-6 py-3 border-t border-gray-50">
                            <summary class="text-xs text-gray-500 cursor-pointer">Ciblage envoyé à Meta</summary>
                            <pre class="mt-2 p-3 bg-gray-50 rounded-xl text-[11px] text-gray-600 overflow-x-auto">{{ json_encode($adSet['targeting'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                        </details>
                    @endif
                </div>
            @endforeach

            @if(empty($adSets))
                <p class="text-sm text-gray-500">Aucun ad set dans cette campagne.</p>
            @endif
        </div>

        <p class="text-xs text-gray-400 mt-6">
            Performances sur {{ $days }} jours. Les chiffres Meta ne sont fiables qu'à J+4 ; des impressions
            à zéro signalent le plus souvent une campagne qui ne diffuse pas (ciblage vide, plafond atteint),
            pas une enchère trop basse.
        </p>
    @endif
@endsection
