@extends('layouts.app')

@section('title', 'Statistiques')

@section('content')
    <div class="space-y-6">

        {{-- Header + Filters --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
            <h1 class="text-xl font-bold text-gray-900 mb-6">📊 Tableau de bord statistiques</h1>

            <form method="GET" action="{{ route('stats.dashboard') }}" class="space-y-4">
                {{-- Filter by accounts --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Comptes sociaux</label>
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
                        @foreach($socialAccounts as $account)
                            <label class="flex items-center gap-2 p-3 rounded-xl border border-gray-200 hover:border-indigo-300 cursor-pointer transition-colors {{ in_array($account->id, $selectedAccounts) ? 'bg-indigo-50 border-indigo-300' : 'bg-white' }}">
                                <input type="checkbox" name="accounts[]" value="{{ $account->id }}" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" {{ in_array($account->id, $selectedAccounts) ? 'checked' : '' }}>
                                <div class="flex items-center gap-2 min-w-0 flex-1">
                                    <x-platform-icon :platform="$account->platform->slug" size="sm" />
                                    <span class="text-sm truncate">{{ $account->name }}</span>
                                </div>
                            </label>
                        @endforeach
                    </div>
                </div>

                {{-- Period filter --}}
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Période</label>
                        <select name="period" class="w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="7" {{ $period == '7' ? 'selected' : '' }}>7 derniers jours</option>
                            <option value="30" {{ $period == '30' ? 'selected' : '' }}>30 derniers jours</option>
                            <option value="90" {{ $period == '90' ? 'selected' : '' }}>90 derniers jours</option>
                            <option value="all" {{ $period == 'all' ? 'selected' : '' }}>Tout</option>
                            <option value="custom" {{ ($startDate && $endDate) ? 'selected' : '' }}>Personnalisé</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Date début</label>
                        <input type="date" name="start_date" value="{{ $startDate }}" class="w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Date fin</label>
                        <input type="date" name="end_date" value="{{ $endDate }}" class="w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                </div>

                {{-- Submit button --}}
                <div class="flex items-center gap-3">
                    <button type="submit" class="inline-flex items-center gap-2 px-6 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                        </svg>
                        Filtrer
                    </button>
                    <a href="{{ route('stats.dashboard') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-white text-gray-700 text-sm font-medium rounded-xl hover:bg-gray-50 transition-colors border border-gray-200">
                        Réinitialiser
                    </a>
                </div>
            </form>
        </div>

        @if($stats['posts_count'] > 0)
            {{-- KPIs --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-10 h-10 rounded-xl bg-indigo-50 flex items-center justify-center">
                            <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                            </svg>
                        </div>
                    </div>
                    <p class="text-3xl font-bold text-gray-900">{{ number_format($stats['posts_count']) }}</p>
                    <p class="text-sm text-gray-500 mt-1">Publications</p>
                </div>

                @if($stats['total_views'] > 0)
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <div class="flex items-center gap-3 mb-2">
                            <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center">
                                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                </svg>
                            </div>
                        </div>
                        <p class="text-3xl font-bold text-gray-900">{{ number_format($stats['total_views'], 0, ',', ' ') }}</p>
                        <p class="text-sm text-gray-500 mt-1">Vues</p>
                        <p class="text-xs text-gray-400 mt-1">Moy. {{ number_format($stats['avg_views_per_post']) }}/post</p>
                    </div>
                @endif

                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-10 h-10 rounded-xl bg-rose-50 flex items-center justify-center">
                            <svg class="w-5 h-5 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z" />
                            </svg>
                        </div>
                    </div>
                    <p class="text-3xl font-bold text-gray-900">{{ number_format($stats['total_likes'], 0, ',', ' ') }}</p>
                    <p class="text-sm text-gray-500 mt-1">Likes</p>
                    <p class="text-xs text-gray-400 mt-1">Moy. {{ number_format($stats['avg_likes_per_post']) }}/post</p>
                </div>

                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-10 h-10 rounded-xl bg-green-50 flex items-center justify-center">
                            <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 0 1 .865-.501 48.172 48.172 0 0 0 3.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" />
                            </svg>
                        </div>
                    </div>
                    <p class="text-3xl font-bold text-gray-900">{{ number_format($stats['total_engagement'], 0, ',', ' ') }}</p>
                    <p class="text-sm text-gray-500 mt-1">Engagement total</p>
                    @if($stats['total_views'] > 0)
                        <p class="text-xs text-gray-400 mt-1">Taux: {{ $stats['engagement_rate'] }}%</p>
                    @endif
                </div>
            </div>

            {{-- Stats by platform --}}
            @if(count($statsByPlatform) > 0)
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100">
                    <div class="px-6 py-5 border-b border-gray-100">
                        <h2 class="text-base font-semibold text-gray-900">Par plateforme</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-gray-100 text-left">
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Plateforme</th>
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right">Posts</th>
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right">Vues</th>
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right">Likes</th>
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right">Commentaires</th>
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right">Partages</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                @foreach($statsByPlatform as $platform)
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
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- Stats by account --}}
            @if(count($statsByAccount) > 0)
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100">
                    <div class="px-6 py-5 border-b border-gray-100">
                        <h2 class="text-base font-semibold text-gray-900">Par compte</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-gray-100 text-left">
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Compte</th>
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right">Posts</th>
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right">Vues</th>
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right">Likes</th>
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right">Commentaires</th>
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right">Engagement</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                @foreach($statsByAccount as $accountStat)
                                    @php
                                        $engagement = $accountStat['likes'] + $accountStat['comments'] + $accountStat['shares'];
                                    @endphp
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4">
                                            <div class="flex items-center gap-2">
                                                <x-platform-icon :platform="$accountStat['platform']->slug" size="sm" />
                                                <span class="text-sm font-medium text-gray-900">{{ $accountStat['account']->name }}</span>
                                            </div>
                                        </td>
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
                                        <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500">
                                            @if($postStat['views'] > 0)
                                                <span>{{ number_format($postStat['views']) }} vues</span>
                                            @endif
                                            <span>{{ number_format($postStat['likes']) }} likes</span>
                                            <span>{{ number_format($postStat['comments']) }} commentaires</span>
                                            @if($postStat['shares'] > 0)
                                                <span>{{ number_format($postStat['shares']) }} partages</span>
                                            @endif
                                            <span class="text-indigo-600 font-medium">{{ number_format($postStat['engagement']) }} engagement</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

        @else
            {{-- No data --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-12 text-center">
                <div class="w-16 h-16 rounded-2xl bg-gray-50 flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
                    </svg>
                </div>
                <h3 class="text-base font-medium text-gray-900 mb-2">Aucune statistique disponible</h3>
                <p class="text-sm text-gray-500">Sélectionnez des comptes et une période, ou publiez vos premiers posts !</p>
            </div>
        @endif

    </div>
@endsection
