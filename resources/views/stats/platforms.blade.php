@extends('layouts.app')

@section('title', 'Statistiques — Plateformes')

@section('content')
    <div class="space-y-6">

        {{-- Filters --}}
        @include('stats._filters', ['action' => route('stats.platforms')])

        @if(count($statsByPlatform) > 0)
            {{-- Platform comparison chart --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                <h2 class="text-base font-semibold text-gray-900 mb-4">Comparaison par plateforme</h2>
                <div class="relative" style="height: 350px;">
                    <canvas id="platformChart"></canvas>
                </div>
            </div>

            {{-- Platform table --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100">
                <div class="px-6 py-5 border-b border-gray-100">
                    <h2 class="text-base font-semibold text-gray-900">Détail par plateforme</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-gray-100 text-left">
                                <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Plateforme</th>
                                <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right">Posts</th>
                                <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right">Vues</th>
                                <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right">Likes</th>
                                <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right">Com.</th>
                                <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right">Partages</th>
                                <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right">Engage.</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @foreach($statsByPlatform as $platform)
                                @php $engagement = $platform['likes'] + $platform['comments'] + $platform['shares']; @endphp
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-2">
                                            <x-platform-icon :platform="$platform['slug']" size="sm" />
                                            <span class="text-sm font-medium text-gray-900">{{ $platform['name'] }}</span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-700 text-right">{{ number_format($platform['count']) }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-700 text-right">{{ $platform['views'] > 0 ? number_format($platform['views']) : '-' }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-700 text-right">{{ number_format($platform['likes']) }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-700 text-right">{{ number_format($platform['comments']) }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-700 text-right">{{ $platform['shares'] > 0 ? number_format($platform['shares']) : '-' }}</td>
                                    <td class="px-6 py-4 text-sm font-medium text-indigo-600 text-right">{{ number_format($engagement) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Account table --}}
            @if(count($statsByAccount) > 0)
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100">
                    <div class="px-6 py-5 border-b border-gray-100">
                        <h2 class="text-base font-semibold text-gray-900">Détail par compte</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-gray-100 text-left">
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Compte</th>
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right">Abonnés</th>
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right">Posts</th>
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right">Vues</th>
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right">Likes</th>
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right">Com.</th>
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right">Engage.</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                @foreach($statsByAccount as $accountStat)
                                    @php $engagement = $accountStat['likes'] + $accountStat['comments'] + $accountStat['shares']; @endphp
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4">
                                            <div class="flex items-center gap-2">
                                                <x-platform-icon :platform="$accountStat['platform']->slug" size="sm" />
                                                <span class="text-sm font-medium text-gray-900">{{ $accountStat['account']->name }}</span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-700 text-right">{{ $accountStat['account']->followers_count ? number_format($accountStat['account']->followers_count) : '-' }}</td>
                                        <td class="px-6 py-4 text-sm text-gray-700 text-right">{{ number_format($accountStat['count']) }}</td>
                                        <td class="px-6 py-4 text-sm text-gray-700 text-right">{{ $accountStat['views'] > 0 ? number_format($accountStat['views']) : '-' }}</td>
                                        <td class="px-6 py-4 text-sm text-gray-700 text-right">{{ number_format($accountStat['likes']) }}</td>
                                        <td class="px-6 py-4 text-sm text-gray-700 text-right">{{ number_format($accountStat['comments']) }}</td>
                                        <td class="px-6 py-4 text-sm font-medium text-indigo-600 text-right">{{ number_format($engagement) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

        @else
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-12 text-center">
                <div class="w-16 h-16 rounded-2xl bg-gray-50 flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 16.875h3.375m0 0h3.375m-3.375 0V13.5m0 3.375v3.375M6 10.5h2.25a2.25 2.25 0 0 0 2.25-2.25V6a2.25 2.25 0 0 0-2.25-2.25H6A2.25 2.25 0 0 0 3.75 6v2.25A2.25 2.25 0 0 0 6 10.5Zm0 9.75h2.25A2.25 2.25 0 0 0 10.5 18v-2.25a2.25 2.25 0 0 0-2.25-2.25H6a2.25 2.25 0 0 0-2.25 2.25V18A2.25 2.25 0 0 0 6 20.25Zm9.75-9.75H18a2.25 2.25 0 0 0 2.25-2.25V6A2.25 2.25 0 0 0 18 3.75h-2.25A2.25 2.25 0 0 0 13.5 6v2.25a2.25 2.25 0 0 0 2.25 2.25Z" />
                    </svg>
                </div>
                <h3 class="text-base font-medium text-gray-900 mb-2">Aucune donnée par plateforme</h3>
                <p class="text-sm text-gray-500">Publiez du contenu sur plusieurs plateformes pour comparer leurs performances.</p>
            </div>
        @endif

    </div>
@endsection

@push('scripts')
@if(count($statsByPlatform) > 0)
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const platforms = @json($statsByPlatform);

        const platformColors = {
            facebook: '#1877F2',
            instagram: '#E4405F',
            twitter: '#1DA1F2',
            youtube: '#FF0000',
            threads: '#000000',
            bluesky: '#0085FF',
            telegram: '#26A5E4',
            reddit: '#FF4500',
        };

        new Chart(document.getElementById('platformChart'), {
            type: 'bar',
            data: {
                labels: platforms.map(p => p.name),
                datasets: [
                    {
                        label: 'Likes',
                        data: platforms.map(p => p.likes),
                        backgroundColor: '#f43f5e80',
                        borderColor: '#f43f5e',
                        borderWidth: 1,
                    },
                    {
                        label: 'Commentaires',
                        data: platforms.map(p => p.comments),
                        backgroundColor: '#3b82f680',
                        borderColor: '#3b82f6',
                        borderWidth: 1,
                    },
                    {
                        label: 'Partages',
                        data: platforms.map(p => p.shares),
                        backgroundColor: '#10b98180',
                        borderColor: '#10b981',
                        borderWidth: 1,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 12 } },
                    },
                    y: {
                        grid: { color: '#f3f4f6' },
                        ticks: {
                            font: { size: 11 },
                            callback: function(value) {
                                return value >= 1000 ? (value / 1000).toFixed(0) + 'k' : value;
                            },
                        },
                    },
                },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { usePointStyle: true, padding: 20 },
                    },
                },
            },
        });
    });
</script>
@endif
@endpush
