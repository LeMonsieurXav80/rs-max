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
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100">
            <div class="px-6 py-5 border-b border-gray-100">
                <h2 class="text-base font-semibold text-gray-900">Abonnés par compte</h2>
            </div>
            <div class="divide-y divide-gray-50">
                @foreach($socialAccounts->sortByDesc('followers_count') as $account)
                    <div class="px-6 py-4 flex items-center gap-4">
                        {{-- Profile picture --}}
                        <div class="relative flex-shrink-0">
                            @if($account->profile_picture_url)
                                <img src="{{ $account->profile_picture_url }}" alt="" class="w-10 h-10 rounded-full object-cover">
                            @else
                                <div class="w-10 h-10 rounded-full bg-gray-200 flex items-center justify-center">
                                    <x-platform-icon :platform="$account->platform->slug" size="sm" />
                                </div>
                            @endif
                            <div class="absolute bottom-0 right-0 w-5 h-5 rounded-full bg-white shadow flex items-center justify-center">
                                <x-platform-icon :platform="$account->platform->slug" size="xs" />
                            </div>
                        </div>

                        {{-- Name --}}
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900 truncate">{{ $account->name }}</p>
                            <p class="text-xs text-gray-500">{{ $account->platform->name }}</p>
                        </div>

                        {{-- Followers --}}
                        <div class="text-right">
                            <p class="text-lg font-bold text-gray-900">
                                {{ $account->followers_count !== null ? number_format($account->followers_count, 0, ',', ' ') : '-' }}
                            </p>
                            <p class="text-xs text-gray-400">
                                @if($account->followers_synced_at)
                                    MAJ {{ $account->followers_synced_at->diffForHumans() }}
                                @endif
                            </p>
                        </div>
                    </div>
                @endforeach
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
            label: item.account.name,
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
