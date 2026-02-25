@extends('layouts.app')

@section('title', 'Dashboard')

@section('title_extra')
    @if($isAdmin)
        <span class="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-700">Admin</span>
    @endif
@endsection

@section('actions')
    <a href="{{ url('/posts/create') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
        </svg>
        Nouveau post
    </a>
@endsection

@section('content')
    {{-- Stat cards row --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-6 mb-8">
        {{-- Programmés --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-blue-50 flex items-center justify-center flex-shrink-0">
                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-500 truncate">Programmés</p>
                    <p class="text-2xl font-bold text-gray-900">{{ $scheduledCount }}</p>
                </div>
            </div>
        </div>

        {{-- Publiés --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-green-50 flex items-center justify-center flex-shrink-0">
                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-500 truncate">Publiés</p>
                    <p class="text-2xl font-bold text-gray-900">{{ $publishedCount }}</p>
                </div>
            </div>
        </div>

        {{-- Brouillons --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-gray-100 flex items-center justify-center flex-shrink-0">
                    <svg class="w-6 h-6 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                    </svg>
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-500 truncate">Brouillons</p>
                    <p class="text-2xl font-bold text-gray-900">{{ $draftCount }}</p>
                </div>
            </div>
        </div>

        {{-- Erreurs --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-red-50 flex items-center justify-center flex-shrink-0">
                    <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                    </svg>
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-500 truncate">Erreurs</p>
                    <p class="text-2xl font-bold text-gray-900">{{ $failedCount }}</p>
                </div>
            </div>
        </div>

        {{-- Comptes actifs --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-indigo-50 flex items-center justify-center flex-shrink-0">
                    <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                    </svg>
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-500 truncate">Comptes actifs</p>
                    <p class="text-2xl font-bold text-gray-900">{{ $activeAccountsCount }}</p>
                </div>
            </div>
        </div>
    </div>

    {{-- Two-column grid: upcoming and recent posts --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- Prochaines publications --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100">
            <div class="px-6 py-5 border-b border-gray-100 flex items-center justify-between">
                <h2 class="text-base font-semibold text-gray-900">Prochaines publications</h2>
                <span class="text-xs font-medium text-gray-400">{{ $upcomingPosts->count() }} programmée{{ $upcomingPosts->count() > 1 ? 's' : '' }}</span>
            </div>
            <div class="divide-y divide-gray-50">
                @forelse($upcomingPosts as $post)
                    @php
                        $thumb = null;
                        $thumbIsVideo = false;
                        if (!empty($post->media)) {
                            foreach ($post->media as $m) {
                                $mime = is_string($m) ? 'image/jpeg' : ($m['mimetype'] ?? '');
                                if (str_starts_with($mime, 'image/')) { $thumb = is_string($m) ? $m : ($m['url'] ?? null); break; }
                            }
                            if (!$thumb) { $first = $post->media[0]; $thumb = is_string($first) ? $first : ($first['url'] ?? null); $thumbIsVideo = true; }
                        }
                    @endphp
                    <a href="{{ url('/posts/' . $post->id) }}" class="flex items-center gap-4 px-6 py-4 hover:bg-gray-50/50 transition-colors group">
                        {{-- Thumbnail --}}
                        @if($thumb && !$thumbIsVideo)
                            <img src="{{ $thumb }}" alt="" class="w-12 h-12 rounded-lg object-cover border border-gray-100 flex-shrink-0" loading="lazy">
                        @elseif($thumb && $thumbIsVideo)
                            <div class="w-12 h-12 rounded-lg bg-gray-900 flex items-center justify-center flex-shrink-0">
                                <svg class="w-5 h-5 text-white/70" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                            </div>
                        @else
                            <div class="w-12 h-12 rounded-lg bg-gray-50 border border-gray-100 flex items-center justify-center flex-shrink-0">
                                <svg class="w-5 h-5 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z" /></svg>
                            </div>
                        @endif

                        <div class="min-w-0 flex-1">
                            <p class="text-sm text-gray-900 line-clamp-2 group-hover:text-indigo-600 transition-colors">{{ $post->content_preview }}</p>
                            <div class="flex items-center gap-2 mt-2">
                                @foreach($post->postPlatforms as $pp)
                                    <x-platform-icon :platform="$pp->platform" size="sm" />
                                @endforeach
                                <x-status-badge :status="$post->status" />
                            </div>
                        </div>
                        <div class="flex-shrink-0 text-right pt-0.5">
                            <p class="text-xs font-medium text-gray-600">{{ $post->scheduled_at?->format('d/m/Y') }}</p>
                            <p class="text-xs text-gray-400 mt-0.5">{{ $post->scheduled_at?->format('H:i') }}</p>
                        </div>
                    </a>
                @empty
                    <div class="px-6 py-12 text-center">
                        <div class="w-12 h-12 rounded-xl bg-gray-50 flex items-center justify-center mx-auto mb-3">
                            <svg class="w-6 h-6 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                        </div>
                        <p class="text-sm text-gray-400 mb-3">Aucune publication programmée</p>
                        <a href="{{ url('/posts/create') }}" class="inline-flex items-center gap-1.5 text-sm text-indigo-600 hover:text-indigo-700 font-medium transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                            Créer un post
                        </a>
                    </div>
                @endforelse
            </div>
            @if($upcomingPosts->isNotEmpty())
                <div class="px-6 py-3 border-t border-gray-100">
                    <a href="{{ url('/posts?status=scheduled') }}" class="text-sm text-indigo-600 hover:text-indigo-700 font-medium transition-colors">
                        Voir toutes les publications programmées &rarr;
                    </a>
                </div>
            @endif
        </div>

        {{-- Dernières publications --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100">
            <div class="px-6 py-5 border-b border-gray-100 flex items-center justify-between">
                <h2 class="text-base font-semibold text-gray-900">Dernières publications</h2>
                <span class="text-xs font-medium text-gray-400">{{ $recentPosts->count() }} récente{{ $recentPosts->count() > 1 ? 's' : '' }}</span>
            </div>
            <div class="divide-y divide-gray-50">
                @forelse($recentPosts as $post)
                    @php
                        $thumb = null;
                        $thumbIsVideo = false;
                        if (!empty($post->media)) {
                            foreach ($post->media as $m) {
                                $mime = is_string($m) ? 'image/jpeg' : ($m['mimetype'] ?? '');
                                if (str_starts_with($mime, 'image/')) { $thumb = is_string($m) ? $m : ($m['url'] ?? null); break; }
                            }
                            if (!$thumb) { $first = $post->media[0]; $thumb = is_string($first) ? $first : ($first['url'] ?? null); $thumbIsVideo = true; }
                        }
                    @endphp
                    <a href="{{ url('/posts/' . $post->id) }}" class="flex items-center gap-4 px-6 py-4 hover:bg-gray-50/50 transition-colors group">
                        {{-- Thumbnail --}}
                        @if($thumb && !$thumbIsVideo)
                            <img src="{{ $thumb }}" alt="" class="w-12 h-12 rounded-lg object-cover border border-gray-100 flex-shrink-0" loading="lazy">
                        @elseif($thumb && $thumbIsVideo)
                            <div class="w-12 h-12 rounded-lg bg-gray-900 flex items-center justify-center flex-shrink-0">
                                <svg class="w-5 h-5 text-white/70" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                            </div>
                        @else
                            <div class="w-12 h-12 rounded-lg bg-gray-50 border border-gray-100 flex items-center justify-center flex-shrink-0">
                                <svg class="w-5 h-5 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z" /></svg>
                            </div>
                        @endif

                        <div class="min-w-0 flex-1">
                            <p class="text-sm text-gray-900 line-clamp-2 group-hover:text-indigo-600 transition-colors">{{ $post->content_preview }}</p>
                            <div class="flex items-center gap-2 mt-2">
                                @foreach($post->postPlatforms as $pp)
                                    <x-platform-icon :platform="$pp->platform" size="sm" />
                                @endforeach
                                <x-status-badge :status="$post->status" />
                            </div>
                        </div>
                        <div class="flex-shrink-0 text-right pt-0.5">
                            <p class="text-xs font-medium text-gray-600">{{ $post->published_at?->format('d/m/Y') ?? $post->created_at->format('d/m/Y') }}</p>
                            <p class="text-xs text-gray-400 mt-0.5">{{ $post->published_at?->format('H:i') ?? $post->created_at->format('H:i') }}</p>
                        </div>
                    </a>
                @empty
                    <div class="px-6 py-12 text-center">
                        <div class="w-12 h-12 rounded-xl bg-gray-50 flex items-center justify-center mx-auto mb-3">
                            <svg class="w-6 h-6 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                        </div>
                        <p class="text-sm text-gray-400">Aucune publication récente</p>
                    </div>
                @endforelse
            </div>
            @if($recentPosts->isNotEmpty())
                <div class="px-6 py-3 border-t border-gray-100">
                    <a href="{{ url('/posts?status=published') }}" class="text-sm text-indigo-600 hover:text-indigo-700 font-medium transition-colors">
                        Voir toutes les publications &rarr;
                    </a>
                </div>
            @endif
        </div>
    </div>
@endsection
