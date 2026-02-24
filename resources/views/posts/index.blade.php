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
                                    <a href="{{ route('posts.show', $post) }}"
                                       class="block px-1.5 py-1 rounded-lg text-xs hover:bg-indigo-50 transition-colors group"
                                       title="{{ Str::limit($post->content_fr, 60) }}">
                                        <div class="flex items-center gap-1">
                                            {{-- Time --}}
                                            <span class="font-medium text-gray-600 group-hover:text-indigo-600 whitespace-nowrap">
                                                {{ $post->scheduled_at->format('H:i') }}
                                            </span>
                                            {{-- Platform icons --}}
                                            <div class="flex items-center gap-0.5 flex-shrink-0">
                                                @foreach($post->postPlatforms->take(3) as $pp)
                                                    <x-platform-icon :platform="$pp->platform->slug" size="sm" />
                                                @endforeach
                                            </div>
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

                                {{-- Platform icons + date --}}
                                <div class="flex items-center gap-4">
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
                                    @elseif($post->published_at)
                                        <div class="flex items-center gap-1.5 text-xs text-gray-500">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                            </svg>
                                            {{ $post->published_at->format('d/m/Y H:i') }}
                                        </div>
                                    @endif
                                </div>
                            </a>

                            {{-- Right: edit action --}}
                            <div class="flex items-center gap-1 flex-shrink-0">
                                <a href="{{ route('posts.edit', $post) }}"
                                   class="p-2 text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors"
                                   title="Modifier">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                                    </svg>
                                </a>
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
