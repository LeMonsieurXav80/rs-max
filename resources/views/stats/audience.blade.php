@extends('layouts.app')

@section('title', 'Statistiques — Audience')

@section('content')
    <div class="space-y-6">

        {{-- Filters (no custom date range for audience, just period) --}}
        @include('stats._filters', ['action' => route('stats.audience'), 'startDate' => null, 'endDate' => null])

        {{-- Total followers --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold text-gray-900">Audience totale</h2>
                <div class="text-right">
                    <span class="text-3xl font-bold text-gray-900">{{ number_format($totalFollowers, 0, ',', ' ') }}</span>
                    <span class="text-sm text-gray-500 ml-1">abonnés</span>
                </div>
            </div>
        </div>

        {{-- Followers chart --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
            <h2 class="text-base font-semibold text-gray-900 mb-4">Évolution des abonnés</h2>

            @if(collect($chartData)->sum(fn($d) => count($d['data'])) > 0)
                <div class="relative" style="height: 400px;">
                    <canvas id="followersChart"></canvas>
                </div>
            @else
                <div class="text-center py-12">
                    <div class="w-16 h-16 rounded-2xl bg-gray-50 flex items-center justify-center mx-auto mb-4">
                        <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18 9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941" />
                        </svg>
                    </div>
                    <h3 class="text-base font-medium text-gray-900 mb-2">Pas encore de données</h3>
                    <p class="text-sm text-gray-500">Les snapshots quotidiens seront enregistrés automatiquement chaque matin à 6h00.</p>
                </div>
            @endif
        </div>

        {{-- Current followers by account --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100" x-data="{ deltaPeriod: 1 }">
            <div class="px-6 py-5 border-b border-gray-100 flex items-center justify-between flex-wrap gap-3">
                <h2 class="text-base font-semibold text-gray-900">Abonnés par compte</h2>
                <div class="flex items-center gap-1 bg-gray-100 rounded-lg p-0.5">
                    @foreach([1 => '1J', 7 => '7J', 14 => '14J', 28 => '28J'] as $days => $label)
                        <button type="button"
                                @click="deltaPeriod = {{ $days }}"
                                :class="deltaPeriod === {{ $days }} ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'"
                                class="px-3 py-1 text-xs font-medium rounded-md transition-all">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                    @foreach($socialAccounts->sortByDesc('followers_count') as $account)
                        @if(empty($selectedAccounts) || in_array($account->id, $selectedAccounts))
                        <div class="border border-gray-100 rounded-xl p-4 hover:border-gray-200 transition-colors">
                            <div class="flex items-center gap-3 mb-3">
                                {{-- Profile picture --}}
                                <div class="relative flex-shrink-0">
                                    @if($account->profile_picture_url)
                                        <img src="{{ $account->profile_picture_url }}" alt="" class="w-10 h-10 rounded-full object-cover">
                                    @else
                                        <div class="w-10 h-10 rounded-full bg-gray-200 flex items-center justify-center">
                                            <x-platform-icon :platform="$account->platform->slug" size="sm" />
                                        </div>
                                    @endif
                                    <div class="absolute -bottom-0.5 -right-0.5 w-4.5 h-4.5 rounded-full bg-white shadow flex items-center justify-center">
                                        <x-platform-icon :platform="$account->platform->slug" size="xs" />
                                    </div>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-gray-900 truncate">{{ $account->name }}</p>
                                    <p class="text-xs text-gray-500">{{ $account->platform->name }}</p>
                                </div>
                            </div>

                            <div class="flex items-end justify-between">
                                <div>
                                    <p class="text-2xl font-bold text-gray-900">
                                        {{ $account->followers_count !== null ? number_format($account->followers_count, 0, ',', ' ') : '-' }}
                                    </p>
                                    <p class="text-xs text-gray-400 mt-0.5">
                                        @if($account->followers_synced_at)
                                            MAJ {{ $account->followers_synced_at->diffForHumans() }}
                                        @endif
                                    </p>
                                </div>

                                {{-- Delta badges --}}
                                @if(isset($deltas[$account->id]))
                                    @foreach([1, 7, 14, 28] as $days)
                                        @php $delta = $deltas[$account->id][$days] ?? null; @endphp
                                        <div x-show="deltaPeriod === {{ $days }}" x-cloak>
                                            @if($delta !== null)
                                                <span class="inline-flex items-center gap-0.5 px-2 py-1 rounded-lg text-xs font-semibold
                                                    {{ $delta > 0 ? 'bg-green-50 text-green-700' : ($delta < 0 ? 'bg-red-50 text-red-700' : 'bg-gray-50 text-gray-500') }}">
                                                    @if($delta > 0)
                                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 19.5l15-15m0 0H8.25m11.25 0v11.25" /></svg>
                                                        +{{ number_format($delta, 0, ',', ' ') }}
                                                    @elseif($delta < 0)
                                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 4.5l15 15m0 0V8.25m0 11.25H8.25" /></svg>
                                                        {{ number_format($delta, 0, ',', ' ') }}
                                                    @else
                                                        =
                                                    @endif
                                                </span>
                                            @else
                                                <span class="text-xs text-gray-300">--</span>
                                            @endif
                                        </div>
                                    @endforeach
                                @endif
                            </div>
                        </div>
                        @endif
                    @endforeach
                </div>

                {{-- Total row --}}
                <div class="mt-4 pt-4 border-t border-gray-100 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-gray-900 flex items-center justify-center">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
                            </svg>
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-gray-900">Total</p>
                            <p class="text-2xl font-bold text-gray-900">{{ number_format($totalFollowers, 0, ',', ' ') }}</p>
                        </div>
                    </div>

                    @foreach([1, 7, 14, 28] as $days)
                        @php $delta = $totalDeltas[$days] ?? null; @endphp
                        <div x-show="deltaPeriod === {{ $days }}" x-cloak>
                            @if($delta !== null)
                                <span class="inline-flex items-center gap-0.5 px-2.5 py-1.5 rounded-lg text-sm font-semibold
                                    {{ $delta > 0 ? 'bg-green-50 text-green-700' : ($delta < 0 ? 'bg-red-50 text-red-700' : 'bg-gray-50 text-gray-500') }}">
                                    @if($delta > 0)
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 19.5l15-15m0 0H8.25m11.25 0v11.25" /></svg>
                                        +{{ number_format($delta, 0, ',', ' ') }}
                                    @elseif($delta < 0)
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 4.5l15 15m0 0V8.25m0 11.25H8.25" /></svg>
                                        {{ number_format($delta, 0, ',', ' ') }}
                                    @else
                                        =
                                    @endif
                                </span>
                            @else
                                <span class="text-sm text-gray-300">--</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

    </div>
@endsection

@push('scripts')
@if(collect($chartData)->sum(fn($d) => count($d['data'])) > 0)
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const chartData = @json($chartData);

        const colors = [
            '#6366f1', '#f43f5e', '#3b82f6', '#10b981', '#f59e0b',
            '#8b5cf6', '#ec4899', '#14b8a6', '#f97316', '#06b6d4',
        ];

        const datasets = chartData.map((item, index) => ({
            label: item.account.platform.name + ' — ' + item.account.name,
            data: item.labels.map((label, i) => ({
                x: label,
                y: item.data[i],
            })),
            borderColor: colors[index % colors.length],
            backgroundColor: colors[index % colors.length] + '20',
            borderWidth: 2,
            tension: 0.3,
            fill: false,
            pointRadius: item.data.length > 60 ? 0 : 3,
            pointHoverRadius: 5,
        }));

        // Collect all unique dates and sort
        const allDates = [...new Set(chartData.flatMap(item => item.labels))].sort();

        new Chart(document.getElementById('followersChart'), {
            type: 'line',
            data: { labels: allDates, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                scales: {
                    x: {
                        type: 'category',
                        grid: { display: false },
                        ticks: {
                            maxTicksLimit: 15,
                            font: { size: 11 },
                        },
                    },
                    y: {
                        beginAtZero: false,
                        grid: { color: '#f3f4f6' },
                        ticks: {
                            font: { size: 11 },
                            callback: function(value) {
                                return value >= 1000 ? (value / 1000).toFixed(value >= 10000 ? 0 : 1) + 'k' : value;
                            },
                        },
                    },
                },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { usePointStyle: true, padding: 20 },
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + new Intl.NumberFormat('fr-FR').format(context.parsed.y);
                            },
                        },
                    },
                },
            },
        });
    });
</script>
@endif
@endpush
