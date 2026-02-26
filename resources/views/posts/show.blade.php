@extends('layouts.app')

@section('title', 'Détail du post')

@section('actions')
    <div class="flex items-center gap-3" x-data="{ publishingAll: false, publishResult: null }">
        {{-- Publish all button --}}
        @if($post->postPlatforms->whereIn('status', ['pending', 'failed'])->count() > 0)
            <button type="button"
                @click="
                    if (publishingAll) return;
                    if (!confirm('Publier sur toutes les plateformes en attente ?')) return;
                    publishingAll = true; publishResult = null;
                    fetch('{{ route('posts.publish', $post) }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                            'Accept': 'application/json',
                        },
                    })
                    .then(r => r.json())
                    .then(d => { publishResult = d; setTimeout(() => location.reload(), 2000); })
                    .catch(() => { publishResult = { success: false, message: 'Erreur de connexion.' }; })
                    .finally(() => { publishingAll = false; });
                "
                :disabled="publishingAll"
                class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm disabled:opacity-50">
                <svg x-show="!publishingAll" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                </svg>
                <svg x-show="publishingAll" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <span x-text="publishingAll ? 'Publication...' : 'Publier tout'"></span>
            </button>
        @endif

        <a href="{{ route('posts.edit', $post) }}"
           class="inline-flex items-center gap-2 px-4 py-2 bg-white text-gray-700 text-sm font-medium rounded-xl hover:bg-gray-50 transition-colors border border-gray-200 shadow-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
            </svg>
            Modifier
        </a>

        <form method="POST" action="{{ route('posts.destroy', $post) }}" onsubmit="return confirm('Supprimer cette publication ? Cette action est irréversible.')">
            @csrf
            @method('DELETE')
            <button type="submit"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-white text-red-600 text-sm font-medium rounded-xl hover:bg-red-50 transition-colors border border-red-200 shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                </svg>
                Supprimer
            </button>
        </form>
    </div>
@endsection

@section('content')
    {{-- Back link --}}
    <div class="mb-6">
        <a href="{{ route('posts.index') }}" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-700 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
            </svg>
            Retour aux publications
        </a>
    </div>

    {{-- Two-column layout: content left, media right --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- LEFT: Content + Stats + Logs --}}
        <div class="lg:col-span-2 space-y-6">

            {{-- ==================== CONTENT CARD ==================== --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 lg:p-8">
                {{-- Status + user + dates --}}
                <div class="flex flex-wrap items-center gap-3 mb-6">
                    <x-status-badge :status="$post->status" />
                    @if($post->user)
                        <span class="text-xs text-gray-400">par {{ $post->user->name }}</span>
                    @endif
                    <span class="text-xs text-gray-400">&middot; Créé le {{ $post->created_at->format('d/m/Y H:i') }}</span>
                    @if($post->published_at)
                        <span class="text-xs text-gray-400">&middot; Publié le {{ $post->published_at->format('d/m/Y H:i') }}</span>
                    @elseif($post->scheduled_at)
                        <span class="text-xs text-gray-400">&middot; Programmé le {{ $post->scheduled_at->format('d/m/Y H:i') }}</span>
                    @endif
                </div>

                <div class="space-y-6">
                    {{-- Contenu français --}}
                    <div>
                        <h3 class="text-sm font-medium text-gray-500 mb-2">Contenu français</h3>
                        <div class="text-sm text-gray-900 whitespace-pre-line bg-gray-50 rounded-xl p-4 leading-relaxed">{{ $post->content_fr }}</div>
                    </div>

                    {{-- Traductions --}}
                    @php
                        $langLabels = ['en' => 'Anglais', 'pt' => 'Portugais', 'es' => 'Espagnol', 'de' => 'Allemand', 'it' => 'Italien'];
                        $translations = $post->translations ?? [];
                        if ($post->content_en && empty($translations['en'])) {
                            $translations['en'] = $post->content_en;
                        }
                    @endphp
                    @foreach($translations as $lang => $text)
                        @if($text)
                            <div>
                                <h3 class="text-sm font-medium text-gray-500 mb-2">Contenu {{ $langLabels[$lang] ?? strtoupper($lang) }}</h3>
                                <div class="text-sm text-gray-900 whitespace-pre-line bg-gray-50 rounded-xl p-4 leading-relaxed">{{ $text }}</div>
                            </div>
                        @endif
                    @endforeach

                    {{-- Hashtags --}}
                    @if($post->hashtags)
                        <div>
                            <h3 class="text-sm font-medium text-gray-500 mb-2">Hashtags</h3>
                            <p class="text-sm text-indigo-600">{{ $post->hashtags }}</p>
                        </div>
                    @endif

                    {{-- Lien URL --}}
                    @if($post->link_url)
                        <div>
                            <h3 class="text-sm font-medium text-gray-500 mb-2">URL du lien</h3>
                            <a href="{{ $post->link_url }}" target="_blank" rel="noopener noreferrer"
                               class="inline-flex items-center gap-2 text-sm text-indigo-600 hover:text-indigo-700 transition-colors">
                                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />
                                </svg>
                                {{ $post->link_url }}
                            </a>
                        </div>
                    @endif

                    {{-- Location --}}
                    @if($post->location_name)
                        <div>
                            <h3 class="text-sm font-medium text-gray-500 mb-2">Localisation</h3>
                            <div class="inline-flex items-center gap-2 text-sm text-gray-700">
                                <svg class="w-4 h-4 text-indigo-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
                                </svg>
                                {{ $post->location_name }}
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- ==================== STATISTICS CARD ==================== --}}
            @php
                $publishedPlatforms = $post->postPlatforms->where('status', 'published');
                $platformsWithMetrics = $publishedPlatforms->filter(fn($pp) => !empty($pp->metrics));
                $hasStats = $platformsWithMetrics->count() > 0;
                $canSync = $publishedPlatforms->whereNotNull('external_id')->count() > 0;

                if ($hasStats) {
                    $totalViews = $platformsWithMetrics->sum(fn($pp) => $pp->metrics['views'] ?? 0);
                    $totalLikes = $platformsWithMetrics->sum(fn($pp) => $pp->metrics['likes'] ?? 0);
                    $totalComments = $platformsWithMetrics->sum(fn($pp) => $pp->metrics['comments'] ?? 0);
                    $totalShares = $platformsWithMetrics->sum(fn($pp) => $pp->metrics['shares'] ?? 0);
                    $totalEngagement = $totalLikes + $totalComments + $totalShares;
                    $engagementRate = $totalViews > 0 ? round(($totalEngagement / $totalViews) * 100, 2) : 0;
                }
            @endphp

            @if($hasStats || $canSync)
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 lg:p-8">
                    {{-- Header with sync button --}}
                    <div class="flex items-center justify-between mb-5">
                        <h2 class="text-base font-semibold text-gray-900">Statistiques</h2>
                        @if($canSync)
                            <div x-data="{ syncing: false, syncResult: null }">
                                <button type="button"
                                    @click="
                                        if (syncing) return;
                                        syncing = true; syncResult = null;
                                        fetch('{{ route('posts.syncStats', $post) }}', {
                                            method: 'POST',
                                            headers: {
                                                'Content-Type': 'application/json',
                                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                                                'Accept': 'application/json',
                                            },
                                        })
                                        .then(r => r.json())
                                        .then(d => {
                                            syncResult = d;
                                            if (d.success) setTimeout(() => location.reload(), 1500);
                                        })
                                        .catch(() => { syncResult = { success: false, error: 'Erreur de connexion.' }; })
                                        .finally(() => { syncing = false; });
                                    "
                                    :disabled="syncing"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border transition-colors"
                                    :class="syncResult?.success ? 'border-green-300 bg-green-50 text-green-700' : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50'"
                                >
                                    <svg x-show="syncing" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                    <svg x-show="!syncing && !syncResult" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182" />
                                    </svg>
                                    <svg x-show="syncResult?.success && !syncing" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                    </svg>
                                    <span x-text="syncing ? 'Sync...' : syncResult?.success ? 'Synchronisé' : 'Rafraîchir'"></span>
                                </button>
                            </div>
                        @endif
                    </div>

                    @if($hasStats)
                        {{-- Global summary --}}
                        <div class="grid grid-cols-2 sm:grid-cols-5 gap-4 mb-6">
                            <div>
                                <p class="text-2xl font-bold text-indigo-600">{{ number_format($totalViews, 0, ',', ' ') }}</p>
                                <p class="text-xs text-gray-500 mt-1">Vues</p>
                            </div>
                            <div>
                                <p class="text-2xl font-bold text-indigo-600">{{ number_format($totalLikes, 0, ',', ' ') }}</p>
                                <p class="text-xs text-gray-500 mt-1">Likes</p>
                            </div>
                            <div>
                                <p class="text-2xl font-bold text-indigo-600">{{ number_format($totalComments, 0, ',', ' ') }}</p>
                                <p class="text-xs text-gray-500 mt-1">Commentaires</p>
                            </div>
                            <div>
                                <p class="text-2xl font-bold text-indigo-600">{{ number_format($totalShares, 0, ',', ' ') }}</p>
                                <p class="text-xs text-gray-500 mt-1">Partages</p>
                            </div>
                            @if($totalViews > 0)
                                <div>
                                    <p class="text-2xl font-bold text-green-600">{{ $engagementRate }}%</p>
                                    <p class="text-xs text-gray-500 mt-1">Engagement</p>
                                </div>
                            @endif
                        </div>

                        {{-- Per-platform stats --}}
                        <div class="space-y-3">
                            @foreach($platformsWithMetrics as $pp)
                                @php
                                    $m = $pp->metrics;
                                    $ppViews = (int) ($m['views'] ?? 0);
                                    $ppLikes = (int) ($m['likes'] ?? 0);
                                    $ppComments = (int) ($m['comments'] ?? 0);
                                    $ppShares = (int) ($m['shares'] ?? 0);
                                @endphp
                                <div class="flex items-center gap-3 py-2">
                                    <x-platform-icon :platform="$pp->platform->slug" size="sm" />
                                    <span class="text-sm font-medium text-gray-700 w-28 truncate">{{ $pp->socialAccount->name ?? $pp->platform->name }}</span>
                                    <div class="flex items-center gap-x-4 text-xs flex-1">
                                        <span class="inline-flex items-center gap-1 text-gray-600" title="Vues">
                                            <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                            </svg>
                                            <span class="font-medium text-gray-900">{{ number_format($ppViews, 0, ',', ' ') }}</span>
                                        </span>
                                        <span class="inline-flex items-center gap-1 text-gray-600" title="Likes">
                                            <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z" />
                                            </svg>
                                            <span class="font-medium text-gray-900">{{ number_format($ppLikes, 0, ',', ' ') }}</span>
                                        </span>
                                        <span class="inline-flex items-center gap-1 text-gray-600" title="Commentaires">
                                            <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 20.25c4.97 0 9-3.694 9-8.25s-4.03-8.25-9-8.25S3 7.444 3 12c0 2.104.859 4.023 2.273 5.48.432.447.74 1.04.586 1.641a4.483 4.483 0 0 1-.923 1.785A5.969 5.969 0 0 0 6 21c1.282 0 2.47-.402 3.445-1.087.81.22 1.668.337 2.555.337Z" />
                                            </svg>
                                            <span class="font-medium text-gray-900">{{ number_format($ppComments, 0, ',', ' ') }}</span>
                                        </span>
                                        <span class="inline-flex items-center gap-1 text-gray-600" title="Partages">
                                            <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 1 0 0 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186 9.566-5.314m-9.566 7.5 9.566 5.314m0 0a2.25 2.25 0 1 0 3.935 2.186 2.25 2.25 0 0 0-3.935-2.186Zm0-12.814a2.25 2.25 0 1 0 3.933-2.185 2.25 2.25 0 0 0-3.933 2.185Z" />
                                            </svg>
                                            <span class="font-medium text-gray-900">{{ number_format($ppShares, 0, ',', ' ') }}</span>
                                        </span>
                                    </div>
                                    @if($pp->metrics_synced_at)
                                        <span class="text-xs text-gray-400 flex-shrink-0">{{ $pp->metrics_synced_at->diffForHumans() }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-sm text-gray-400">Cliquez sur "Rafraîchir" pour récupérer les statistiques.</p>
                    @endif
                </div>
            @endif

            {{-- ==================== LOGS CARD ==================== --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100">
                <div class="px-6 lg:px-8 py-5 border-b border-gray-100">
                    <h2 class="text-base font-semibold text-gray-900">Logs</h2>
                </div>

                <div class="divide-y divide-gray-100">
                    @forelse($post->postPlatforms as $pp)
                        <div class="px-6 lg:px-8 py-4" x-data="{ publishing: false, resetting: false, result: null, error: null }">
                            <div class="flex items-center justify-between gap-4">
                                {{-- Platform info + status --}}
                                <div class="flex items-center gap-3 min-w-0">
                                    <x-platform-icon :platform="$pp->platform->slug" size="sm" />
                                    <span class="text-sm font-medium text-gray-900">{{ $pp->socialAccount->name ?? $pp->platform->name }}</span>

                                    @php
                                        $ppStatusColors = [
                                            'pending'    => 'bg-gray-400',
                                            'publishing' => 'bg-yellow-400',
                                            'published'  => 'bg-green-400',
                                            'failed'     => 'bg-red-400',
                                        ];
                                        $ppStatusLabels = [
                                            'pending'    => 'En attente',
                                            'publishing' => 'En cours',
                                            'published'  => 'Publié',
                                            'failed'     => 'Erreur',
                                        ];
                                        $dotColor = $ppStatusColors[$pp->status] ?? 'bg-gray-400';
                                        $statusLabel = $ppStatusLabels[$pp->status] ?? ucfirst($pp->status);
                                    @endphp

                                    <div class="flex items-center gap-1.5">
                                        <span class="inline-block w-2 h-2 rounded-full {{ $dotColor }}"></span>
                                        <span class="text-xs text-gray-500">{{ $statusLabel }}</span>
                                    </div>

                                    @if($pp->published_at)
                                        <span class="text-xs text-gray-400">&middot; {{ $pp->published_at->format('d/m/Y H:i') }}</span>
                                    @endif
                                </div>

                                {{-- Action buttons --}}
                                <div class="flex items-center gap-2 flex-shrink-0">
                                    {{-- Publish button (for pending/failed) --}}
                                    @if(in_array($pp->status, ['pending', 'failed']))
                                        <button type="button"
                                            @click="
                                                if (publishing) return;
                                                publishing = true; result = null; error = null;
                                                fetch('{{ route('posts.publishOne', $pp) }}', {
                                                    method: 'POST',
                                                    headers: {
                                                        'Content-Type': 'application/json',
                                                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                                                        'Accept': 'application/json',
                                                    },
                                                })
                                                .then(r => r.json())
                                                .then(d => {
                                                    if (d.success) { result = d.message || 'OK'; setTimeout(() => location.reload(), 2000); }
                                                    else { error = d.error || 'Erreur'; }
                                                })
                                                .catch(() => { error = 'Erreur de connexion.'; })
                                                .finally(() => { publishing = false; });
                                            "
                                            :disabled="publishing"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg transition-colors"
                                            :class="result ? 'bg-green-50 text-green-700 border border-green-300' : error ? 'bg-red-50 text-red-700 border border-red-300' : 'bg-indigo-50 text-indigo-700 border border-indigo-200 hover:bg-indigo-100'"
                                        >
                                            <svg x-show="publishing" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                            </svg>
                                            <svg x-show="result && !publishing" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                            </svg>
                                            <svg x-show="!publishing && !result && !error" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                                            </svg>
                                            <span x-text="publishing ? 'Publication...' : result ? result : error ? 'Erreur' : 'Publier'"></span>
                                        </button>
                                    @endif

                                    {{-- Reset button (for published/failed) --}}
                                    @if(in_array($pp->status, ['published', 'failed']))
                                        <button type="button"
                                            @click="
                                                if (resetting) return;
                                                if (!confirm('Remettre en attente ?')) return;
                                                resetting = true;
                                                fetch('{{ route('posts.resetOne', $pp) }}', {
                                                    method: 'POST',
                                                    headers: {
                                                        'Content-Type': 'application/json',
                                                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                                                        'Accept': 'application/json',
                                                    },
                                                })
                                                .then(r => r.json())
                                                .then(d => { if (d.success) location.reload(); })
                                                .catch(() => {})
                                                .finally(() => { resetting = false; });
                                            "
                                            :disabled="resetting"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border border-gray-200 bg-white text-gray-600 hover:bg-gray-50 transition-colors"
                                            title="Remettre en attente"
                                        >
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182" />
                                            </svg>
                                            <span x-text="resetting ? '...' : 'Reset'"></span>
                                        </button>
                                    @endif
                                </div>
                            </div>

                            {{-- Error message --}}
                            @if($pp->status === 'failed' && $pp->error_message)
                                <div class="mt-2 ml-7 p-2.5 bg-red-50 rounded-lg border border-red-100">
                                    <p class="text-xs text-red-700">{{ $pp->error_message }}</p>
                                </div>
                            @endif

                            {{-- AJAX error display --}}
                            <div x-show="error" x-cloak class="mt-2 ml-7 p-2.5 bg-red-50 rounded-lg border border-red-100">
                                <p class="text-xs text-red-700" x-text="error"></p>
                            </div>

                            {{-- Logs --}}
                            @if($pp->logs->count())
                                <div class="mt-2 ml-7 space-y-1">
                                    @foreach($pp->logs->sortByDesc('created_at') as $log)
                                        <div class="flex items-center gap-2 text-xs text-gray-400">
                                            <span class="font-medium text-gray-500">{{ $log->action }}</span>
                                            <span>&middot;</span>
                                            <span>{{ $log->created_at->format('d/m/Y H:i:s') }}</span>
                                            @if($log->details)
                                                <span>&middot;</span>
                                                <span class="truncate max-w-xs">
                                                    @if(is_array($log->details))
                                                        {{ json_encode($log->details, JSON_UNESCAPED_UNICODE) }}
                                                    @else
                                                        {{ $log->details }}
                                                    @endif
                                                </span>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="px-6 lg:px-8 py-8 text-center">
                            <p class="text-sm text-gray-400">Aucune plateforme associée</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- RIGHT: Media preview --}}
        <div class="lg:col-span-1">
            <div class="lg:sticky lg:top-20 space-y-4">
                @if($post->media && count($post->media) > 0)
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4">
                        <h3 class="text-sm font-medium text-gray-500 mb-3">Médias ({{ count($post->media) }})</h3>
                        <div class="space-y-3">
                            @foreach($post->media as $item)
                                @php
                                    $url = is_string($item) ? $item : ($item['url'] ?? '');
                                    $mime = is_string($item) ? 'image/jpeg' : ($item['mimetype'] ?? 'image/jpeg');
                                    $title = is_string($item) ? basename($url) : ($item['title'] ?? basename($url));
                                    $size = is_string($item) ? null : ($item['size'] ?? null);
                                    $isVideo = str_starts_with($mime, 'video/');
                                @endphp

                                <div>
                                    @if($isVideo)
                                        <div class="rounded-xl overflow-hidden border border-gray-200 bg-gray-900">
                                            <video class="w-full" controls preload="metadata">
                                                <source src="{{ $url }}" type="{{ $mime }}">
                                            </video>
                                        </div>
                                    @else
                                        <a href="{{ $url }}" target="_blank" rel="noopener noreferrer"
                                           class="block rounded-xl overflow-hidden border border-gray-200 hover:border-indigo-300 transition-colors">
                                            <img src="{{ $url }}" alt="{{ $title }}" class="w-full object-cover" loading="lazy">
                                        </a>
                                    @endif
                                    <div class="mt-1.5 flex items-center justify-between text-xs text-gray-400 px-1">
                                        <span class="truncate" title="{{ $title }}">{{ $title }}</span>
                                        @if($size)
                                            <span class="flex-shrink-0 ml-2">{{ number_format($size / 1048576, 1) }} Mo</span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @else
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8 text-center">
                        <div class="w-12 h-12 rounded-xl bg-gray-50 flex items-center justify-center mx-auto mb-3">
                            <svg class="w-6 h-6 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M2.25 18.75h19.5" />
                            </svg>
                        </div>
                        <p class="text-sm text-gray-400">Aucun média</p>
                    </div>
                @endif
            </div>
        </div>

    </div>
@endsection
