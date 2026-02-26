@extends('layouts.app')

@section('title', 'Publications')

@section('actions')
    <a href="{{ route('posts.create') }}"
       class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
        </svg>
        Nouveau post
    </a>
@endsection

@section('content')
<div x-data="{ view: localStorage.getItem('postsView') || 'calendar' }" x-init="$watch('view', v => localStorage.setItem('postsView', v))">

    {{-- Top bar: filters + view toggle --}}
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        {{-- Filter pills --}}
        <div class="flex flex-wrap gap-2">
            @php
                $filters = [
                    'all'       => 'Tous',
                    'scheduled' => 'Programmé',
                    'published' => 'Publié',
                    'draft'     => 'Brouillon',
                    'failed'    => 'Erreur',
                ];
                $currentStatus = request('status', 'all');
            @endphp

            @foreach($filters as $key => $label)
                <a href="{{ route('posts.index', array_merge(
                        $key !== 'all' ? ['status' => $key] : [],
                        request('month') ? ['month' => request('month')] : []
                    )) }}"
                   class="px-4 py-2 text-sm font-medium rounded-xl transition-colors
                       {{ $currentStatus === $key
                           ? 'bg-indigo-600 text-white shadow-sm'
                           : 'bg-white text-gray-600 hover:bg-gray-100 border border-gray-200' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>

        {{-- View toggle --}}
        <div class="flex items-center bg-white border border-gray-200 rounded-xl p-1">
            <button @click="view = 'calendar'"
                    :class="view === 'calendar' ? 'bg-indigo-600 text-white shadow-sm' : 'text-gray-500 hover:text-gray-700'"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-lg transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
                </svg>
                Agenda
            </button>
            <button @click="view = 'list'"
                    :class="view === 'list' ? 'bg-indigo-600 text-white shadow-sm' : 'text-gray-500 hover:text-gray-700'"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-lg transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM3.75 12h.007v.008H3.75V12Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm-.375 5.25h.007v.008H3.75v-.008Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
                </svg>
                Liste
            </button>
        </div>
    </div>

    {{-- ============================================================== --}}
    {{-- CALENDAR VIEW                                                   --}}
    {{-- ============================================================== --}}
    <div x-show="view === 'calendar'" x-cloak>
        {{-- Month navigation --}}
        <div class="flex items-center justify-between mb-6">
            <a href="{{ route('posts.index', array_merge(request()->except('month', 'page'), ['month' => $prevMonth])) }}"
               class="inline-flex items-center gap-1 px-3 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                </svg>
            </a>

            <h2 class="text-lg font-semibold text-gray-900 capitalize">
                {{ $startOfMonth->translatedFormat('F Y') }}
            </h2>

            <a href="{{ route('posts.index', array_merge(request()->except('month', 'page'), ['month' => $nextMonth])) }}"
               class="inline-flex items-center gap-1 px-3 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                </svg>
            </a>
        </div>

        {{-- Calendar grid --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            {{-- Day headers --}}
            <div class="grid grid-cols-7 border-b border-gray-100">
                @foreach(['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'] as $day)
                    <div class="px-2 py-3 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">
                        {{ $day }}
                    </div>
                @endforeach
            </div>

            {{-- Day cells --}}
            @php
                $today = now()->format('Y-m-d');
                // Start from Monday of the week containing the 1st
                $calStart = $startOfMonth->copy()->startOfWeek(\Carbon\Carbon::MONDAY);
                // End on Sunday of the week containing the last day
                $calEnd = $endOfMonth->copy()->endOfWeek(\Carbon\Carbon::SUNDAY);
                $weeks = [];
                $current = $calStart->copy();
                while ($current->lte($calEnd)) {
                    $week = [];
                    for ($i = 0; $i < 7; $i++) {
                        $week[] = $current->copy();
                        $current->addDay();
                    }
                    $weeks[] = $week;
                }
            @endphp

            @foreach($weeks as $week)
                <div class="grid grid-cols-7 border-b border-gray-50 last:border-b-0">
                    @foreach($week as $date)
                        @php
                            $dateKey = $date->format('Y-m-d');
                            $isCurrentMonth = $date->month === $startOfMonth->month;
                            $isToday = $dateKey === $today;
                            $dayPosts = $calendarPosts->get($dateKey, collect());
                        @endphp
                        <div class="min-h-[100px] p-2 border-r border-gray-50 last:border-r-0 {{ !$isCurrentMonth ? 'bg-gray-50/50' : '' }}">
                            {{-- Day number --}}
                            <div class="flex items-center justify-center mb-1">
                                <span class="inline-flex items-center justify-center w-7 h-7 text-xs font-medium rounded-full
                                    {{ $isToday ? 'bg-indigo-600 text-white' : ($isCurrentMonth ? 'text-gray-700' : 'text-gray-300') }}">
                                    {{ $date->day }}
                                </span>
                            </div>

                            {{-- Posts for this day --}}
                            <div class="space-y-1">
                                @foreach($dayPosts->take(3) as $post)
                                    @php
                                        $calPpWithMetrics = $post->postPlatforms->where('status', 'published')->filter(fn($pp) => !empty($pp->metrics));
                                        $calLikes = $calPpWithMetrics->sum(fn($pp) => $pp->metrics['likes'] ?? 0);
                                        $calViews = $calPpWithMetrics->sum(fn($pp) => $pp->metrics['views'] ?? 0);
                                        $isPublished = $post->status === 'published';
                                    @endphp
                                    <a href="{{ route('posts.show', $post) }}"
                                       class="block px-1.5 py-1 rounded-lg text-xs hover:bg-indigo-50 transition-colors group"
                                       title="{{ Str::limit($post->content_fr, 60) }}">
                                        <div class="flex items-center gap-1">
                                            {{-- Status dot + Time --}}
                                            @if($isPublished)
                                                <span class="inline-block w-1.5 h-1.5 rounded-full bg-green-400 flex-shrink-0"></span>
                                            @endif
                                            <span class="font-medium {{ $isPublished ? 'text-green-700' : 'text-gray-600' }} group-hover:text-indigo-600 whitespace-nowrap">
                                                {{ ($post->scheduled_at ?? $post->published_at)->format('H:i') }}
                                            </span>
                                            {{-- Platform icons --}}
                                            <div class="flex items-center gap-0.5 flex-shrink-0">
                                                @foreach($post->postPlatforms as $pp)
                                                    <x-platform-icon :platform="$pp->platform->slug" size="sm" />
                                                @endforeach
                                            </div>
                                            {{-- Compact stats --}}
                                            @if($calPpWithMetrics->count() > 0 && ($calViews > 0 || $calLikes > 0))
                                                <span class="text-gray-400 whitespace-nowrap ml-auto" title="{{ number_format($calViews) }} vues, {{ number_format($calLikes) }} likes">
                                                    @if($calViews > 0)
                                                        {{ $calViews >= 1000 ? number_format($calViews / 1000, 1) . 'k' : $calViews }}
                                                        <svg class="w-2.5 h-2.5 inline -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                                        </svg>
                                                    @else
                                                        {{ $calLikes >= 1000 ? number_format($calLikes / 1000, 1) . 'k' : $calLikes }}
                                                        <svg class="w-2.5 h-2.5 inline -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z" />
                                                        </svg>
                                                    @endif
                                                </span>
                                            @endif
                                        </div>
                                    </a>
                                @endforeach

                                @if($dayPosts->count() > 3)
                                    <span class="block px-1.5 text-xs text-gray-400 font-medium">
                                        +{{ $dayPosts->count() - 3 }} autres
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>

    {{-- ============================================================== --}}
    {{-- LIST VIEW                                                       --}}
    {{-- ============================================================== --}}
    <div x-show="view === 'list'" x-cloak>
        @if($posts->count())
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="divide-y divide-gray-100">
                    @foreach($posts as $post)
                        <div class="flex items-center gap-4 p-5 hover:bg-gray-50/60 transition-colors">
                            {{-- Thumbnail --}}
                            @php
                                $thumb = null;
                                if (!empty($post->media)) {
                                    foreach ($post->media as $m) {
                                        $mime = is_string($m) ? 'image/jpeg' : ($m['mimetype'] ?? '');
                                        if (str_starts_with($mime, 'image/')) {
                                            $thumb = is_string($m) ? $m : ($m['url'] ?? null);
                                            break;
                                        }
                                    }
                                    if (!$thumb) {
                                        $first = $post->media[0];
                                        $thumb = is_string($first) ? $first : ($first['url'] ?? null);
                                    }
                                }
                            @endphp

                            <a href="{{ route('posts.show', $post) }}" class="flex-shrink-0">
                                @if($thumb && str_starts_with(is_string($post->media[0] ?? '') ? '' : ($post->media[0]['mimetype'] ?? ''), 'video/') && !str_starts_with(is_string($post->media[0] ?? '') ? '' : ($post->media[0]['mimetype'] ?? ''), 'image/'))
                                    <div class="w-16 h-16 rounded-xl bg-gray-900 flex items-center justify-center overflow-hidden">
                                        <svg class="w-6 h-6 text-white/70" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M8 5v14l11-7z"/>
                                        </svg>
                                    </div>
                                @elseif($thumb)
                                    <img src="{{ $thumb }}" alt="" class="w-16 h-16 rounded-xl object-cover border border-gray-100" loading="lazy">
                                @else
                                    <div class="w-16 h-16 rounded-xl bg-gray-50 border border-gray-100 flex items-center justify-center">
                                        <svg class="w-6 h-6 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                                        </svg>
                                    </div>
                                @endif
                            </a>

                            {{-- Content & meta --}}
                            <a href="{{ route('posts.show', $post) }}" class="min-w-0 flex-1 group">
                                {{-- Status + user --}}
                                <div class="flex items-center gap-3 mb-1.5">
                                    <x-status-badge :status="$post->status" />
                                    @if(auth()->user()->is_admin && $post->user)
                                        <span class="text-xs text-gray-400">{{ $post->user->name }}</span>
                                    @endif
                                </div>

                                {{-- Content preview --}}
                                <p class="text-sm text-gray-900 group-hover:text-indigo-600 transition-colors line-clamp-2 mb-2">
                                    {{ Str::limit($post->content_fr, 80) }}
                                </p>

                                {{-- Platform icons + dates --}}
                                <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                                    <div class="flex items-center gap-1.5">
                                        @foreach($post->postPlatforms as $pp)
                                            <x-platform-icon :platform="$pp->platform->slug" size="sm" />
                                        @endforeach
                                    </div>

                                    @if($post->scheduled_at)
                                        <div class="flex items-center gap-1.5 text-xs text-gray-500">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                            </svg>
                                            {{ $post->scheduled_at->format('d/m/Y H:i') }}
                                        </div>
                                    @endif

                                    @if($post->published_at)
                                        <div class="flex items-center gap-1.5 text-xs {{ $post->scheduled_at ? 'text-green-600' : 'text-gray-500' }}">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                            </svg>
                                            {{ $post->published_at->format('d/m/Y H:i') }}
                                        </div>
                                    @endif

                                    {{-- Compact stats for published posts --}}
                                    @php
                                        $ppWithMetrics = $post->postPlatforms->where('status', 'published')->filter(fn($pp) => !empty($pp->metrics));
                                        $listTotalViews = $ppWithMetrics->sum(fn($pp) => $pp->metrics['views'] ?? 0);
                                        $listTotalLikes = $ppWithMetrics->sum(fn($pp) => $pp->metrics['likes'] ?? 0);
                                        $listTotalComments = $ppWithMetrics->sum(fn($pp) => $pp->metrics['comments'] ?? 0);
                                    @endphp
                                    @if($ppWithMetrics->count() > 0)
                                        <div class="flex items-center gap-3 text-xs text-gray-500 border-l border-gray-200 pl-4">
                                            @if($listTotalViews > 0)
                                                <span title="Vues">
                                                    <svg class="w-3 h-3 inline -mt-0.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                                    </svg>
                                                    {{ number_format($listTotalViews, 0, ',', ' ') }}
                                                </span>
                                            @endif
                                            <span title="Likes">
                                                <svg class="w-3 h-3 inline -mt-0.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z" />
                                                </svg>
                                                {{ number_format($listTotalLikes, 0, ',', ' ') }}
                                            </span>
                                            <span title="Commentaires">
                                                <svg class="w-3 h-3 inline -mt-0.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 20.25c4.97 0 9-3.694 9-8.25s-4.03-8.25-9-8.25S3 7.444 3 12c0 2.104.859 4.023 2.273 5.48.432.447.74 1.04.586 1.641a4.483 4.483 0 0 1-.923 1.785A5.969 5.969 0 0 0 6 21c1.282 0 2.47-.402 3.445-1.087.81.22 1.668.337 2.555.337Z" />
                                                </svg>
                                                {{ number_format($listTotalComments, 0, ',', ' ') }}
                                            </span>
                                        </div>
                                    @endif
                                </div>
                            </a>

                            {{-- Right: actions --}}
                            <div class="flex items-center gap-1 flex-shrink-0">
                                <a href="{{ route('posts.edit', $post) }}"
                                   class="p-2 text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors"
                                   title="Modifier">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                                    </svg>
                                </a>
                                <form method="POST" action="{{ route('posts.destroy', $post) }}" onsubmit="return confirm('Supprimer cette publication ?')" class="inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            class="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                                            title="Supprimer">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                        </svg>
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Pagination --}}
            @if($posts->hasPages())
                <div class="mt-6">
                    {{ $posts->links() }}
                </div>
            @endif
        @else
            {{-- Empty state --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-12 text-center">
                <div class="w-16 h-16 rounded-2xl bg-gray-50 flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                    </svg>
                </div>
                <h3 class="text-base font-semibold text-gray-900 mb-1">Aucune publication</h3>
                <p class="text-sm text-gray-500 mb-6">Commencez par créer votre premier post.</p>
                <a href="{{ route('posts.create') }}"
                   class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Créer votre premier post
                </a>
            </div>
        @endif
    </div>

</div>
@endsection
