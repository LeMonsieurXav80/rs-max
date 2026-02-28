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

        {{-- Audience overview --}}
        @php
            $totalFollowers = $socialAccounts->sum('followers_count');
            $importablePlatforms = ['facebook', 'instagram', 'twitter', 'youtube', 'threads'];
        @endphp
        @if($socialAccounts->isNotEmpty())
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-base font-semibold text-gray-900">Audience</h2>
                    <div class="text-right">
                        <span class="text-2xl font-bold text-gray-900">{{ number_format($totalFollowers, 0, ',', ' ') }}</span>
                        <span class="text-sm text-gray-500 ml-1">abonnés au total</span>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    @foreach($socialAccounts->sortByDesc('followers_count') as $account)
                        @php
                            $canImport = in_array($account->platform->slug, $importablePlatforms);
                            $hasImported = $account->last_history_import_at !== null;
                        @endphp
                        <div
                            x-data="{
                                importing: false,
                                progress: 0,
                                result: null,
                                error: null,
                                interval: null,
                                startImport() {
                                    this.importing = true;
                                    this.progress = 0;
                                    this.result = null;
                                    this.error = null;

                                    // Simulated progress bar
                                    this.interval = setInterval(() => {
                                        if (this.progress < 85) {
                                            this.progress += Math.random() * 8 + 2;
                                            if (this.progress > 85) this.progress = 85;
                                        }
                                    }, 500);

                                    fetch('{{ route('accounts.import', $account) }}', {
                                        method: 'POST',
                                        headers: {
                                            'Content-Type': 'application/json',
                                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                                            'Accept': 'application/json',
                                        },
                                        body: JSON.stringify({ limit: 50 }),
                                    })
                                    .then(r => r.json())
                                    .then(data => {
                                        clearInterval(this.interval);
                                        this.progress = 100;
                                        if (data.success) {
                                            this.result = data.message || (data.imported + ' publication(s) importée(s)');
                                            if (data.followers) {
                                                const followerEl = this.$refs.followers;
                                                if (followerEl) followerEl.textContent = new Intl.NumberFormat('fr-FR').format(data.followers);
                                            }
                                        } else {
                                            this.error = data.error || 'Erreur inconnue';
                                        }
                                        setTimeout(() => { this.importing = false; }, 2000);
                                    })
                                    .catch(e => {
                                        clearInterval(this.interval);
                                        this.progress = 100;
                                        this.error = 'Erreur réseau';
                                        setTimeout(() => { this.importing = false; }, 2000);
                                    });
                                }
                            }"
                            class="p-4 rounded-xl bg-gray-50 border border-gray-100"
                        >
                            <div class="flex items-center gap-3">
                                {{-- Profile picture with platform logo overlay --}}
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

                                {{-- Account info --}}
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-gray-900 truncate">{{ $account->name }}</p>
                                    <p class="text-sm font-bold text-gray-900" x-ref="followers">
                                        {{ $account->followers_count !== null ? number_format($account->followers_count, 0, ',', ' ') : '-' }}
                                        <span class="text-xs font-normal text-gray-500">abonnés</span>
                                    </p>
                                </div>

                                {{-- Import/Update button --}}
                                @if($canImport)
                                    <button
                                        type="button"
                                        @click="startImport()"
                                        :disabled="importing"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                                        :class="importing ? 'bg-gray-100 text-gray-500' : 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100'"
                                    >
                                        <svg x-show="!importing" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182" />
                                        </svg>
                                        <svg x-show="importing" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                        </svg>
                                        <span x-text="importing ? 'Import...' : '{{ $hasImported ? 'Mettre à jour' : 'Importer' }}'"></span>
                                    </button>
                                @endif
                            </div>

                            {{-- Last update info --}}
                            <div class="mt-2 text-xs text-gray-400">
                                @if($canImport)
                                    @if($account->followers_synced_at)
                                        Dernière MAJ : {{ $account->followers_synced_at->diffForHumans() }}
                                    @elseif($hasImported)
                                        Dernière MAJ : {{ $account->last_history_import_at->diffForHumans() }}
                                    @else
                                        Jamais importé
                                    @endif
                                @else
                                    Stats non disponibles ({{ $account->platform->name }})
                                @endif
                            </div>

                            {{-- Progress bar --}}
                            <div x-show="importing" x-transition class="mt-2">
                                <div class="w-full bg-gray-200 rounded-full h-1.5 overflow-hidden">
                                    <div
                                        class="h-1.5 rounded-full transition-all duration-300"
                                        :class="error ? 'bg-red-500' : result ? 'bg-green-500' : 'bg-indigo-500'"
                                        :style="'width: ' + Math.min(progress, 100) + '%'"
                                    ></div>
                                </div>
                                <p x-show="result" x-text="result" class="text-xs text-green-600 mt-1"></p>
                                <p x-show="error" x-text="error" class="text-xs text-red-600 mt-1"></p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

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
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right" title="Vues">
                                        <div class="flex items-center justify-end gap-1"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg><span>Vues</span></div>
                                    </th>
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right" title="Likes">
                                        <div class="flex items-center justify-end gap-1"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z" /></svg><span>Likes</span></div>
                                    </th>
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right" title="Commentaires">
                                        <div class="flex items-center justify-end gap-1"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 0 1 .865-.501 48.172 48.172 0 0 0 3.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" /></svg><span>Com.</span></div>
                                    </th>
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right" title="Partages">
                                        <div class="flex items-center justify-end gap-1"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 1 0 0 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186 9.566-5.314m-9.566 7.5 9.566 5.314m0 0a2.25 2.25 0 1 0 3.935 2.186 2.25 2.25 0 0 0-3.935-2.186Zm0-12.814a2.25 2.25 0 1 0 3.933-2.185 2.25 2.25 0 0 0-3.933 2.185Z" /></svg><span>Partages</span></div>
                                    </th>
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
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right">Abonnés</th>
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right">Posts</th>
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right" title="Vues">
                                        <div class="flex items-center justify-end gap-1"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg><span>Vues</span></div>
                                    </th>
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right" title="Likes">
                                        <div class="flex items-center justify-end gap-1"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z" /></svg><span>Likes</span></div>
                                    </th>
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right" title="Commentaires">
                                        <div class="flex items-center justify-end gap-1"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 0 1 .865-.501 48.172 48.172 0 0 0 3.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" /></svg><span>Com.</span></div>
                                    </th>
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right" title="Engagement">
                                        <div class="flex items-center justify-end gap-1"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m3.75 13.5 10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75Z" /></svg><span>Engage.</span></div>
                                    </th>
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
                                                <span class="flex items-center gap-1" title="Vues"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>{{ number_format($postStat['views']) }}</span>
                                                <span class="text-gray-300">|</span>
                                            @endif
                                            <span class="flex items-center gap-1" title="Likes"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z" /></svg>{{ number_format($postStat['likes']) }}</span>
                                            <span class="text-gray-300">|</span>
                                            <span class="flex items-center gap-1" title="Commentaires"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 0 1 .865-.501 48.172 48.172 0 0 0 3.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" /></svg>{{ number_format($postStat['comments']) }}</span>
                                            @if($postStat['shares'] > 0)
                                                <span class="text-gray-300">|</span>
                                                <span class="flex items-center gap-1" title="Partages"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 1 0 0 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186 9.566-5.314m-9.566 7.5 9.566 5.314m0 0a2.25 2.25 0 1 0 3.935 2.186 2.25 2.25 0 0 0-3.935-2.186Zm0-12.814a2.25 2.25 0 1 0 3.933-2.185 2.25 2.25 0 0 0-3.933 2.185Z" /></svg>{{ number_format($postStat['shares']) }}</span>
                                            @endif
                                            <span class="text-gray-300">|</span>
                                            <span class="flex items-center gap-1 text-indigo-600 font-medium" title="Engagement"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m3.75 13.5 10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75Z" /></svg>{{ number_format($postStat['engagement']) }}</span>
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
