@extends('layouts.app')

@section('title', 'Fils de discussion')

@section('actions')
    <a href="{{ route('threads.create') }}"
       class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
        </svg>
        Nouveau fil
    </a>
@endsection

@section('content')
{{-- Type tabs: Posts | Fils --}}
<div class="flex items-center gap-1 mb-6 bg-white border border-gray-200 rounded-xl p-1 w-fit">
    <a href="{{ route('posts.index') }}"
       class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg text-gray-500 hover:text-gray-700 hover:bg-gray-50 transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
        </svg>
        Posts
    </a>
    <span class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg bg-indigo-600 text-white shadow-sm">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" />
        </svg>
        Fils
    </span>
</div>

@php
    $statusFilters = [
        'all'       => 'Tous',
        'draft'     => 'Brouillon',
        'scheduled' => 'Programmé',
        'published' => 'Publié',
        'partial'   => 'Partiel',
        'failed'    => 'Erreur',
    ];
    $currentStatus = request('status', 'all');
@endphp

<div>
    {{-- Status filter pills --}}
    <div class="flex flex-wrap gap-2 mb-6">
        @foreach($statusFilters as $key => $label)
            <a href="{{ route('threads.index', $key !== 'all' ? ['status' => $key] : []) }}"
               class="px-4 py-2 text-sm font-medium rounded-xl transition-colors
                   {{ $currentStatus === $key
                       ? 'bg-indigo-600 text-white shadow-sm'
                       : 'bg-white text-gray-600 hover:bg-gray-100 border border-gray-200' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    @if($threads->count())
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="divide-y divide-gray-100">
                @foreach($threads as $thread)
                    <div class="flex items-center gap-4 p-5 hover:bg-gray-50/60 transition-colors">
                        {{-- Thread icon --}}
                        <a href="{{ route('threads.show', $thread) }}" class="flex-shrink-0">
                            <div class="w-16 h-16 rounded-xl bg-indigo-50 border border-indigo-100 flex items-center justify-center">
                                <svg class="w-7 h-7 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" />
                                </svg>
                            </div>
                        </a>

                        {{-- Content & meta --}}
                        <a href="{{ route('threads.show', $thread) }}" class="min-w-0 flex-1 group">
                            {{-- Status + segments count + user --}}
                            <div class="flex items-center gap-3 mb-1.5">
                                <x-status-badge :status="$thread->status" />
                                <span class="text-xs text-gray-400">{{ $thread->segments_count }} segments</span>
                                @if(auth()->user()->is_admin && $thread->user)
                                    <span class="text-xs text-gray-400">{{ $thread->user->name }}</span>
                                @endif
                            </div>

                            {{-- Title --}}
                            <p class="text-sm font-medium text-gray-900 group-hover:text-indigo-600 transition-colors line-clamp-2 mb-2">
                                {{ $thread->title ?: 'Fil #' . $thread->id }}
                            </p>

                            {{-- Platform icons + source + dates --}}
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                                <div class="flex items-center gap-1.5">
                                    @foreach($thread->socialAccounts as $account)
                                        <x-platform-icon :platform="$account->platform->slug" size="sm" />
                                    @endforeach
                                </div>

                                @if($thread->source_url)
                                    <span class="text-xs text-gray-400 truncate max-w-[200px]" title="{{ $thread->source_url }}">
                                        {{ parse_url($thread->source_url, PHP_URL_HOST) }}
                                    </span>
                                @endif

                                @if($thread->scheduled_at)
                                    <div class="flex items-center gap-1.5 text-xs text-gray-500">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                        </svg>
                                        {{ $thread->scheduled_at->format('d/m/Y H:i') }}
                                    </div>
                                @endif

                                @if($thread->published_at)
                                    <div class="flex items-center gap-1.5 text-xs {{ $thread->scheduled_at ? 'text-green-600' : 'text-gray-500' }}">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                        </svg>
                                        {{ $thread->published_at->format('d/m/Y H:i') }}
                                    </div>
                                @endif
                            </div>
                        </a>

                        {{-- Actions --}}
                        <div class="flex items-center gap-1 flex-shrink-0">
                            @if(in_array($thread->status, ['draft', 'failed', 'partial']))
                                <a href="{{ route('threads.edit', $thread) }}"
                                   class="p-2 text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors"
                                   title="Modifier">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                                    </svg>
                                </a>
                            @endif
                            <form method="POST" action="{{ route('threads.destroy', $thread) }}" onsubmit="return confirm('Supprimer ce fil ?')" class="inline">
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

        @if($threads->hasPages())
            <div class="mt-6">
                {{ $threads->links() }}
            </div>
        @endif
    @else
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-12 text-center">
            <div class="w-16 h-16 rounded-2xl bg-gray-50 flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" />
                </svg>
            </div>
            <h3 class="text-base font-semibold text-gray-900 mb-1">Aucun fil de discussion</h3>
            <p class="text-sm text-gray-500 mb-6">Commencez par créer votre premier fil.</p>
            <a href="{{ route('threads.create') }}"
               class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Créer votre premier fil
            </a>
        </div>
    @endif
</div>
@endsection
