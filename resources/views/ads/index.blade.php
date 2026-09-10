@extends('layouts.app')

@section('title', 'Publicités Meta')

@section('actions')
    <a href="{{ route('ads.audiences.index') }}"
       class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-200 text-gray-700 text-sm font-medium rounded-xl hover:bg-gray-50 transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Z" />
        </svg>
        Audiences
    </a>
@endsection

@section('content')

    @include('ads._flash')

    {{-- Compte publicitaire non configuré : rien d'autre n'a de sens. --}}
    @if(! $account)
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-16 text-center">
            <h3 class="text-base font-semibold text-gray-900 mb-2">Compte publicitaire non configuré</h3>
            <p class="text-sm text-gray-500 mb-8 max-w-lg mx-auto">
                Renseignez le jeton « utilisateur système » et l'identifiant du compte publicitaire
                dans les paramètres, onglet Statistiques.
            </p>
            <a href="{{ route('settings.index') }}"
               class="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700">
                Aller aux paramètres
            </a>
        </div>
    @else

        {{-- Bandeau d'état : devise, écriture ouverte ou non, plafonds. --}}
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4">
                <p class="text-[11px] uppercase tracking-wide text-gray-400 font-semibold">Compte</p>
                <p class="text-sm font-medium text-gray-900 mt-1">{{ $account['name'] ?? '—' }}</p>
                <p class="text-xs text-gray-500">{{ $account['currency'] ?? '' }} · {{ $account['timezone'] ?? '' }}</p>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4">
                <p class="text-[11px] uppercase tracking-wide text-gray-400 font-semibold">Écriture</p>
                @if($writeEnabled)
                    <p class="text-sm font-medium text-green-600 mt-1">Ouverte</p>
                    <p class="text-xs text-gray-500">Les actions dépensent réellement.</p>
                @else
                    <p class="text-sm font-medium text-amber-600 mt-1">Fermée</p>
                    <p class="text-xs text-gray-500">META_ADS_WRITE_ENABLED=false</p>
                @endif
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4">
                <p class="text-[11px] uppercase tracking-wide text-gray-400 font-semibold">Plafond quotidien</p>
                <p class="text-sm font-medium text-gray-900 mt-1">{{ config('meta_ads.max_daily_budget') }} {{ $account['currency'] ?? '' }}</p>
                <p class="text-xs text-gray-500">Jamais contournable.</p>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4">
                <p class="text-[11px] uppercase tracking-wide text-gray-400 font-semibold">Hausse max</p>
                <p class="text-sm font-medium text-gray-900 mt-1">{{ config('meta_ads.max_budget_increase_pct') }} %</p>
                <p class="text-xs text-gray-500">En une fois, sans forçage.</p>
            </div>
        </div>

        @if($error)
            <div class="mb-6 rounded-xl bg-red-50 border border-red-200 p-4 text-sm text-red-700">{{ $error }}</div>
        @endif

        {{-- Campagnes --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden mb-6">
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-900">Campagnes</h2>
                <span class="text-xs text-gray-500">Performances sur {{ $days }} jours — fiables à J+4</span>
            </div>

            @if(empty($campaigns))
                <p class="px-6 py-12 text-center text-sm text-gray-500">
                    Aucune campagne. Une campagne se crée en sponsorisant une publication déjà publiée,
                    depuis la fiche du post.
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-[11px] uppercase tracking-wide text-gray-400">
                                <th class="px-6 py-3 font-semibold">Campagne</th>
                                <th class="px-4 py-3 font-semibold">Objectif</th>
                                <th class="px-4 py-3 font-semibold">Statut</th>
                                <th class="px-4 py-3 font-semibold text-right">Budget</th>
                                <th class="px-4 py-3 font-semibold text-right">Dépensé</th>
                                <th class="px-4 py-3 font-semibold text-right">Impressions</th>
                                <th class="px-4 py-3 font-semibold text-right">CPM</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @foreach($campaigns as $c)
                                @php $row = $insights[$c['id']] ?? []; @endphp
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-3">
                                        <a href="{{ route('ads.campaign', $c['id']) }}" class="text-sm font-medium text-indigo-600 hover:underline">
                                            {{ $c['name'] ?? $c['id'] }}
                                        </a>
                                        <p class="text-xs text-gray-400">{{ $c['id'] }}</p>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-600">
                                        {{ config('meta_ads.objectives.'.($c['objective'] ?? '').'.label', $c['objective'] ?? '—') }}
                                    </td>
                                    <td class="px-4 py-3">
                                        @include('ads._status-badge', ['status' => $c['effective_status'] ?? $c['status'] ?? null])
                                    </td>
                                    <td class="px-4 py-3 text-sm text-right text-gray-700">
                                        @if($c['daily_budget'] ?? null)
                                            {{ $c['daily_budget'] }} /j
                                        @elseif($c['lifetime_budget'] ?? null)
                                            {{ $c['lifetime_budget'] }} total
                                        @else
                                            <span class="text-gray-400">ad set</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-sm text-right text-gray-700">{{ $row['spend'] ?? '—' }}</td>
                                    <td class="px-4 py-3 text-sm text-right text-gray-700">{{ number_format((int) ($row['impressions'] ?? 0), 0, ',', ' ') ?: '—' }}</td>
                                    <td class="px-4 py-3 text-sm text-right text-gray-700">{{ $row['cpm'] ?? '—' }}</td>
                                    <td class="px-4 py-3 text-right">
                                        @if($writeEnabled)
                                            @php $isActive = ($c['status'] ?? null) === 'ACTIVE'; @endphp
                                            <form method="POST" action="{{ route('ads.status', $c['id']) }}" class="inline"
                                                  onsubmit="return confirm('{{ $isActive ? 'Mettre cette campagne en pause ?' : 'Lancer la diffusion ? Cette campagne va commencer à dépenser.' }}')">
                                                @csrf
                                                <input type="hidden" name="status" value="{{ $isActive ? 'PAUSED' : 'ACTIVE' }}">
                                                <input type="hidden" name="object_type" value="campaign">
                                                <button class="text-xs px-3 py-1.5 rounded-lg border {{ $isActive ? 'border-amber-200 text-amber-700 hover:bg-amber-50' : 'border-green-200 text-green-700 hover:bg-green-50' }}">
                                                    {{ $isActive ? 'Mettre en pause' : 'Lancer' }}
                                                </button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {{-- Sponsorisations lancées depuis RS-Max --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-sm font-semibold text-gray-900">Publications sponsorisées</h2>
                </div>
                @if($recentBoosts->isEmpty())
                    <p class="px-6 py-8 text-center text-sm text-gray-500">Aucune sponsorisation pour l'instant.</p>
                @else
                    <ul class="divide-y divide-gray-50">
                        @foreach($recentBoosts as $boost)
                            <li class="px-6 py-3 flex items-center justify-between gap-4">
                                <div class="min-w-0">
                                    <p class="text-sm text-gray-900 truncate">{{ $boost->name ?: 'Sponsorisation #'.$boost->id }}</p>
                                    <p class="text-xs text-gray-500">
                                        {{ $boost->platform }} ·
                                        {{ $boost->budget }} {{ $boost->budget_type === 'daily' ? '/jour' : 'au total' }} ·
                                        {{ $boost->days }} j ·
                                        {{ $boost->created_at->diffForHumans() }}
                                    </p>
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    @include('ads._status-badge', ['status' => strtoupper($boost->status)])
                                    @if($boost->campaign_id)
                                        <a href="{{ route('ads.campaign', $boost->campaign_id) }}" class="text-xs text-indigo-600 hover:underline">Voir</a>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- Journal : ce qui a déjà été tenté, dry-run compris. --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-sm font-semibold text-gray-900">Journal des actions</h2>
                </div>
                @if($logs->isEmpty())
                    <p class="px-6 py-8 text-center text-sm text-gray-500">Aucune action enregistrée.</p>
                @else
                    <ul class="divide-y divide-gray-50">
                        @foreach($logs as $log)
                            <li class="px-6 py-3">
                                <div class="flex items-center justify-between gap-3">
                                    <p class="text-sm text-gray-900 truncate">
                                        {{ $log->action }} — {{ $log->object_name ?: $log->object_id }}
                                    </p>
                                    <div class="flex items-center gap-2 shrink-0">
                                        @if($log->dry_run)
                                            <span class="text-[10px] px-2 py-0.5 rounded bg-gray-100 text-gray-600">simulation</span>
                                        @endif
                                        <span class="text-[10px] px-2 py-0.5 rounded {{ $log->success ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700' }}">
                                            {{ $log->success ? 'ok' : 'échec' }}
                                        </span>
                                    </div>
                                </div>
                                <p class="text-xs text-gray-500 mt-0.5">
                                    {{ $log->user?->name ?? 'API' }} · {{ $log->created_at->diffForHumans() }}
                                    @if($log->error) — <span class="text-red-600">{{ $log->error }}</span> @endif
                                </p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    @endif
@endsection
