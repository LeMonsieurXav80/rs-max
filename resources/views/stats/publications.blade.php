@extends('layouts.app')

@section('title', 'Statistiques — Publications')

@section('content')
    <div class="space-y-6">

        {{-- Filters --}}
        @include('stats._filters', ['action' => route('stats.publications')])

        @if($stats['posts_count'] > 0)
            {{-- KPIs --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <p class="text-3xl font-bold text-gray-900">{{ number_format($stats['posts_count']) }}</p>
                    <p class="text-sm text-gray-500 mt-1">Publications</p>
                </div>
                @if($stats['total_views'] > 0)
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <p class="text-3xl font-bold text-gray-900">{{ number_format($stats['avg_views_per_post']) }}</p>
                        <p class="text-sm text-gray-500 mt-1">Vues moy. / post</p>
                    </div>
                @endif
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <p class="text-3xl font-bold text-gray-900">{{ number_format($stats['avg_likes_per_post']) }}</p>
                    <p class="text-sm text-gray-500 mt-1">Likes moy. / post</p>
                </div>
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <p class="text-3xl font-bold text-gray-900">{{ $stats['engagement_rate'] }}%</p>
                    <p class="text-sm text-gray-500 mt-1">Taux d'engagement</p>
                </div>
            </div>

            {{-- Timeline chart --}}
            @if(count($timeline) > 1)
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <h2 class="text-base font-semibold text-gray-900 mb-4">Activité dans le temps</h2>
                    <div class="relative" style="height: 300px;">
                        <canvas id="timelineChart"></canvas>
                    </div>
                </div>
            @endif

            {{-- Top posts --}}
            @if(count($topPosts) > 0)
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100">
                    <div class="px-6 py-5 border-b border-gray-100">
                        <h2 class="text-base font-semibold text-gray-900">Top 10 publications</h2>
                    </div>
                    <div class="divide-y divide-gray-50">
                        @foreach($topPosts as $index => $postStat)
                            <div class="px-6 py-4 hover:bg-gray-50">
                                <div class="flex items-start gap-4">
                                    <div class="flex-shrink-0 w-8 h-8 rounded-full bg-indigo-50 flex items-center justify-center">
                                        <span class="text-sm font-bold text-indigo-600">{{ $index + 1 }}</span>
                                    </div>
                                    @if(!empty($postStat['thumbnail']))
                                        <img src="{{ $postStat['thumbnail'] }}" class="w-12 h-12 rounded-lg object-cover flex-shrink-0" alt="" loading="lazy" onerror="this.style.display='none'">
                                    @endif
                                    <div class="flex-1 min-w-0">
                                        @if($postStat['is_external'] && $postStat['url'])
                                            <a href="{{ $postStat['url'] }}" target="_blank" class="text-sm font-medium text-gray-900 hover:text-indigo-600 transition-colors truncate block">
                                                {{ Str::limit($postStat['content'], 80) ?: '(sans texte)' }}
                                            </a>
                                        @elseif($postStat['post'])
                                            <a href="{{ route('posts.show', $postStat['post']) }}" class="text-sm font-medium text-gray-900 hover:text-indigo-600 transition-colors truncate block">
                                                {{ Str::limit($postStat['content'], 80) }}
                                            </a>
                                        @else
                                            <span class="text-sm font-medium text-gray-900 truncate block">
                                                {{ Str::limit($postStat['content'], 80) ?: '(sans texte)' }}
                                            </span>
                                        @endif
                                        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500">
                                            @if($postStat['views'] > 0)
                                                <span title="Vues">{{ number_format($postStat['views']) }} vues</span>
                                                <span class="text-gray-300">|</span>
                                            @endif
                                            <span title="Likes">{{ number_format($postStat['likes']) }} likes</span>
                                            <span class="text-gray-300">|</span>
                                            <span title="Commentaires">{{ number_format($postStat['comments']) }} com.</span>
                                            @if($postStat['shares'] > 0)
                                                <span class="text-gray-300">|</span>
                                                <span title="Partages">{{ number_format($postStat['shares']) }} partages</span>
                                            @endif
                                            <span class="text-gray-300">|</span>
                                            <span class="text-indigo-600 font-medium" title="Engagement">{{ number_format($postStat['engagement']) }} engage.</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

        @else
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-12 text-center">
                <div class="w-16 h-16 rounded-2xl bg-gray-50 flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                    </svg>
                </div>
                <h3 class="text-base font-medium text-gray-900 mb-2">Aucune publication avec métriques</h3>
                <p class="text-sm text-gray-500">Publiez du contenu et attendez la synchronisation des statistiques.</p>
            </div>
        @endif

    </div>
@endsection

@push('scripts')
@if(isset($timeline) && count($timeline) > 1)
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const timeline = @json($timeline);

        new Chart(document.getElementById('timelineChart'), {
            type: 'bar',
            data: {
                labels: timeline.map(d => d.date),
                datasets: [
                    {
                        label: 'Likes',
                        data: timeline.map(d => d.likes),
                        backgroundColor: '#f43f5e40',
                        borderColor: '#f43f5e',
                        borderWidth: 1,
                    },
                    {
                        label: 'Commentaires',
                        data: timeline.map(d => d.comments),
                        backgroundColor: '#3b82f640',
                        borderColor: '#3b82f6',
                        borderWidth: 1,
                    },
                    {
                        label: 'Partages',
                        data: timeline.map(d => d.shares),
                        backgroundColor: '#10b98140',
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
                        stacked: true,
                        grid: { display: false },
                        ticks: { maxTicksLimit: 15, font: { size: 11 } },
                    },
                    y: {
                        stacked: true,
                        grid: { color: '#f3f4f6' },
                        ticks: { font: { size: 11 } },
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
