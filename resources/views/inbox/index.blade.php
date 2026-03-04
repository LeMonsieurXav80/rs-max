@extends('layouts.app')

@section('title', 'Messagerie')

@section('content')
    <div x-data="inboxManager()" class="space-y-6">

        {{-- KPI cards --}}
        <div class="grid grid-cols-3 gap-4">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                <p class="text-3xl font-bold text-gray-900">{{ number_format($counts['total']) }}</p>
                <p class="text-sm text-gray-500 mt-1">Total</p>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                <p class="text-3xl font-bold text-amber-600">{{ number_format($counts['unreplied']) }}</p>
                <p class="text-sm text-gray-500 mt-1">Non répondus</p>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                <p class="text-3xl font-bold text-green-600">{{ number_format($counts['replied']) }}</p>
                <p class="text-sm text-gray-500 mt-1">Répondus</p>
            </div>
        </div>

        {{-- Scheduled replies progress banner --}}
        @if($scheduledInfo)
            <div class="bg-indigo-50 border border-indigo-200 rounded-2xl p-4 flex items-center gap-4"
                 x-data="scheduledCountdown({{ json_encode($scheduledInfo) }})"
                 x-init="startCountdown()">
                <div class="flex-shrink-0">
                    <svg class="w-6 h-6 text-indigo-600 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold text-indigo-900">
                        <span x-text="pending"></span> réponse(s) planifiée(s) en attente
                    </p>
                    <p class="text-xs text-indigo-700 mt-0.5">
                        Prochaine dans <span x-text="nextIn" class="font-medium"></span>
                        <template x-if="pending > 1">
                            <span> &middot; Dernière dans <span x-text="lastIn" class="font-medium"></span></span>
                        </template>
                    </p>
                </div>
                <div class="flex-shrink-0">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-700">
                        <span class="w-2 h-2 rounded-full bg-indigo-500 animate-pulse"></span>
                        En cours
                    </span>
                </div>
            </div>
        @endif

        {{-- Filters --}}
        <form method="GET" action="{{ route('inbox.index') }}" x-ref="filterForm" class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 space-y-3">
            {{-- Row 1: Status pills + sync button --}}
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-1 bg-gray-100 rounded-xl p-1">
                    @php $currentStatus = request('status', ''); @endphp
                    <input type="hidden" name="status" x-ref="statusInput" value="{{ $currentStatus }}">
                    @foreach(['' => 'Tous', 'unreplied' => 'Non répondus', 'replied' => 'Répondus', 'archived' => 'Archivés'] as $val => $label)
                        <button type="submit"
                                @click.prevent="$refs.statusInput.value = '{{ $val }}'; $refs.filterForm.submit()"
                                class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors {{ $currentStatus === $val ? 'bg-white shadow-sm' . ($val === 'replied' ? ' text-green-600' : ($val === 'archived' ? ' text-gray-600' : ($val === 'unreplied' ? ' text-amber-600' : ' text-indigo-600'))) : 'text-gray-500 hover:text-gray-700' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                <div class="flex-1"></div>

                @if(auth()->user()->is_admin)
                    <button type="button" @click="syncInbox()" :disabled="syncing" class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors disabled:opacity-50">
                        <svg x-show="!syncing" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182M2.985 19.644l3.181-3.183" />
                        </svg>
                        <svg x-show="syncing" x-cloak class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        <span x-text="syncing ? 'Sync...' : 'Synchroniser'"></span>
                    </button>
                @endif
            </div>

            {{-- Row 2: Type checkboxes --}}
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs text-gray-400 font-medium mr-1">Type :</span>
                @php $activeTypes = request('type', []); if (!is_array($activeTypes)) $activeTypes = [$activeTypes]; @endphp
                @foreach(['comment' => 'Commentaires', 'reply' => 'Réponses', 'dm' => 'Messages privés'] as $val => $label)
                    <label class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium cursor-pointer transition-colors {{ in_array($val, $activeTypes) ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-500 hover:bg-gray-200' }}">
                        <input type="checkbox" name="type[]" value="{{ $val }}" class="sr-only"
                               {{ in_array($val, $activeTypes) ? 'checked' : '' }}
                               onchange="this.form.submit()">
                        {{ $label }}
                    </label>
                @endforeach
            </div>

            {{-- Row 3: Platform checkboxes --}}
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs text-gray-400 font-medium mr-1">Plateforme :</span>
                @php $activePlatforms = request('platform', []); if (!is_array($activePlatforms)) $activePlatforms = [$activePlatforms]; @endphp
                @foreach(['facebook' => 'Facebook', 'instagram' => 'Instagram', 'threads' => 'Threads', 'youtube' => 'YouTube', 'bluesky' => 'Bluesky', 'telegram' => 'Telegram', 'reddit' => 'Reddit'] as $slug => $name)
                    @if($enabledSlugs->contains($slug))
                    <label class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium cursor-pointer transition-colors {{ in_array($slug, $activePlatforms) ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-500 hover:bg-gray-200' }}">
                        <input type="checkbox" name="platform[]" value="{{ $slug }}" class="sr-only"
                               {{ in_array($slug, $activePlatforms) ? 'checked' : '' }}
                               onchange="this.form.submit()">
                        {{ $name }}
                    </label>
                    @endif
                @endforeach
            </div>
        </form>

        {{-- Bulk action bar --}}
        <div x-show="selectedIds.length > 0" x-cloak x-transition class="bg-indigo-50 rounded-2xl border border-indigo-200 p-4 flex flex-wrap items-center justify-between gap-3">
            <span class="text-sm font-medium text-indigo-700" x-text="selectedIds.length + ' élément(s) sélectionné(s)'"></span>
            <div class="flex flex-wrap gap-2">
                <button @click="markReadBulk()" class="px-3 py-1.5 bg-white text-sm font-medium text-gray-700 rounded-xl border border-gray-200 hover:bg-gray-50 transition-colors">Marquer lu</button>
                <button @click="archiveBulk()" class="px-3 py-1.5 bg-white text-sm font-medium text-gray-700 rounded-xl border border-gray-200 hover:bg-gray-50 transition-colors">Archiver</button>
                <button @click="startBulkAiReply()" :disabled="bulkLoading" class="px-3 py-1.5 bg-indigo-600 text-sm font-medium text-white rounded-xl hover:bg-indigo-700 transition-colors disabled:opacity-50">
                    <span x-show="!bulkLoading">Répondre avec IA</span>
                    <span x-show="bulkLoading" x-cloak>Génération...</span>
                </button>
            </div>
        </div>

        {{-- Conversation list --}}
        <div class="space-y-3">
            @if($conversations->count() > 0)
                @php $convoIndex = 0; @endphp
                @foreach($conversations as $convo)
                    @php
                        $items = $convo->items;
                        $isSingle = $items->count() === 1;
                        $firstItem = $items->first();
                        $latestItem = $items->sortByDesc('posted_at')->first();
                        $itemIds = $items->pluck('id')->toArray();
                        $convoKey = 'convo_' . md5($convo->key);
                    @endphp
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden"
                         x-data="{ open: {{ $isSingle ? 'true' : 'false' }} }">

                        {{-- Conversation header --}}
                        <div class="p-4 flex items-start gap-3 cursor-pointer hover:bg-gray-50/60 transition-colors"
                             @click="open = !open"
                             :class="{ 'border-b border-gray-100': open && !{{ $isSingle ? 'true' : 'false' }} }">

                            {{-- Checkbox --}}
                            <input type="checkbox" class="mt-1 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                   @click.stop="toggleConversation({{ json_encode($itemIds) }}, {{ $convoIndex }}, $event)"
                                   :checked="{{ json_encode($itemIds) }}.every(id => selectedIds.includes(id))">

                            {{-- Platform icon --}}
                            <div class="flex-shrink-0 mt-0.5">
                                <x-platform-icon :platform="$convo->platform->slug" size="sm" />
                            </div>

                            {{-- Conversation summary --}}
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-1">
                                    <span class="font-medium text-sm text-gray-900">{{ $convo->socialAccount->name }}</span>
                                    @if(!$isSingle)
                                        <span class="text-xs text-gray-400">{{ $convo->total_count }} message{{ $convo->total_count > 1 ? 's' : '' }}</span>
                                    @endif
                                    <span class="text-xs text-gray-400">{{ $convo->latest_at?->diffForHumans() }}</span>
                                    @if($convo->unread_count > 0)
                                        <span class="inline-flex items-center justify-center px-1.5 py-0.5 rounded-full text-xs font-bold bg-indigo-500 text-white">{{ $convo->unread_count }}</span>
                                    @endif
                                    @if($firstItem->type === 'dm')
                                        <span class="px-1.5 py-0.5 rounded-full bg-purple-100 text-purple-700 text-xs font-medium">MP</span>
                                    @endif
                                </div>
                                @if($isSingle)
                                    {{-- Single item: show full details inline --}}
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="text-sm font-medium text-gray-700">{{ $firstItem->author_name ?: $firstItem->author_username ?: 'Anonyme' }}</span>
                                        @if($firstItem->author_username && $firstItem->author_name && $firstItem->author_username !== $firstItem->author_name)
                                            <span class="text-xs text-gray-400">@{{ $firstItem->author_username }}</span>
                                        @endif
                                        @if($firstItem->type === 'reply')
                                            <span class="px-1.5 py-0.5 rounded-full bg-blue-100 text-blue-700 text-xs font-medium">Réponse</span>
                                        @endif
                                        @if($firstItem->status === 'replied')
                                            <span class="px-1.5 py-0.5 rounded-full bg-green-100 text-green-700 text-xs font-medium">Répondu</span>
                                        @endif
                                    </div>
                                    @if($firstItem->content)
                                        <p class="text-sm text-gray-700 line-clamp-2">{{ $firstItem->content }}</p>
                                    @endif
                                    @if($firstItem->media_url)
                                        <div class="mt-1">
                                            <img src="{{ $firstItem->media_url }}" alt="{{ $firstItem->media_type }}" class="max-h-20 rounded-lg object-cover">
                                        </div>
                                    @elseif(! $firstItem->content)
                                        <p class="text-sm text-gray-400 italic">Media sans texte</p>
                                    @endif
                                    @if($firstItem->reply_content)
                                        <div class="mt-2 pl-3 border-l-2 border-indigo-200">
                                            <p class="text-xs text-indigo-600 font-medium mb-0.5">Votre réponse :</p>
                                            <p class="text-sm text-gray-600 line-clamp-2">{{ $firstItem->reply_content }}</p>
                                        </div>
                                    @endif
                                @else
                                    {{-- Multi-item: show preview --}}
                                    @if($firstItem->content)
                                        <p class="text-sm text-gray-500 line-clamp-1">{{ Str::limit($firstItem->content, 100) }}</p>
                                    @elseif($firstItem->media_url)
                                        <p class="text-sm text-gray-400 italic">[{{ $firstItem->media_type === 'gif' ? 'GIF' : ($firstItem->media_type === 'sticker' ? 'Sticker' : 'Image') }}]</p>
                                    @endif
                                @endif
                            </div>

                            {{-- Actions --}}
                            <div class="flex items-center gap-1 flex-shrink-0" @click.stop>
                                @if($isSingle && $firstItem->status !== 'replied')
                                    <button @click="openReplyModal({{ $firstItem->id }}, {{ json_encode($firstItem->content) }}, {{ json_encode($firstItem->author_name ?: $firstItem->author_username) }})"
                                            class="p-1.5 text-gray-400 hover:text-indigo-600 transition-colors rounded-lg hover:bg-indigo-50" title="Répondre">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                                        </svg>
                                    </button>
                                @endif
                                @if($convo->post_url)
                                    <a href="{{ $convo->post_url }}" target="_blank" class="p-1.5 text-gray-400 hover:text-gray-600 transition-colors rounded-lg hover:bg-gray-100" title="Voir sur la plateforme">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                        </svg>
                                    </a>
                                @endif
                                @if(!$isSingle)
                                    <button class="p-1.5 text-gray-400 hover:text-gray-600 transition-colors rounded-lg hover:bg-gray-100" @click.stop="open = !open">
                                        <svg class="w-5 h-5 transition-transform" :class="open && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                        </svg>
                                    </button>
                                @endif
                            </div>
                        </div>

                        {{-- Conversation messages (collapsible for multi-item) --}}
                        @if(!$isSingle)
                            <div x-show="open" x-collapse>
                                <div class="divide-y divide-gray-50">
                                    @php
                                        // Separate top-level and nested items
                                        $topLevel = $items->filter(fn($i) => empty($i->parent_id));
                                        $nested = $items->filter(fn($i) => !empty($i->parent_id))->groupBy('parent_id');
                                    @endphp
                                    @foreach($topLevel as $item)
                                        {{-- Top-level message --}}
                                        <div class="px-4 py-3 pl-12 hover:bg-gray-50/60 transition-colors">
                                            <div class="flex items-start gap-3">
                                                <div class="flex-1 min-w-0">
                                                    <div class="flex items-center gap-2 mb-1">
                                                        <span class="font-medium text-sm text-gray-900">{{ $item->author_name ?: $item->author_username ?: 'Anonyme' }}</span>
                                                        @if($item->author_username && $item->author_name && $item->author_username !== $item->author_name)
                                                            <span class="text-xs text-gray-400">@{{ $item->author_username }}</span>
                                                        @endif
                                                        <span class="text-xs text-gray-400">{{ $item->posted_at?->diffForHumans() }}</span>
                                                        @if($item->status === 'unread')
                                                            <span class="w-2 h-2 rounded-full bg-indigo-500 flex-shrink-0"></span>
                                                        @endif
                                                        @if($item->status === 'replied')
                                                            <span class="px-1.5 py-0.5 rounded-full bg-green-100 text-green-700 text-xs font-medium">Répondu</span>
                                                        @endif
                                                    </div>
                                                    @if($item->content)
                                                        <p class="text-sm text-gray-700">{{ $item->content }}</p>
                                                    @endif
                                                    @if($item->media_url)
                                                        <div class="mt-1">
                                                            <img src="{{ $item->media_url }}" alt="{{ $item->media_type }}" class="max-h-32 rounded-lg object-cover cursor-pointer hover:opacity-90 transition-opacity" onclick="window.open(this.src, '_blank')">
                                                        </div>
                                                    @elseif(! $item->content)
                                                        <p class="text-sm text-gray-400 italic">Media sans texte</p>
                                                    @endif
                                                    @if($item->reply_content)
                                                        <div class="mt-2 pl-3 border-l-2 border-indigo-200">
                                                            <p class="text-xs text-indigo-600 font-medium mb-0.5">Votre réponse :</p>
                                                            <p class="text-sm text-gray-600">{{ $item->reply_content }}</p>
                                                        </div>
                                                    @endif
                                                </div>
                                                @if($item->status !== 'replied')
                                                    <div class="flex items-center gap-1 flex-shrink-0">
                                                        <button @click="openReplyModal({{ $item->id }}, {{ json_encode($item->content) }}, {{ json_encode($item->author_name ?: $item->author_username) }})"
                                                                class="p-1.5 text-gray-400 hover:text-indigo-600 transition-colors rounded-lg hover:bg-indigo-50" title="Répondre">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                                                            </svg>
                                                        </button>
                                                        <button @click="generateAiSingle({{ $item->id }})"
                                                                class="p-1.5 text-gray-400 hover:text-indigo-600 transition-colors rounded-lg hover:bg-indigo-50" title="Suggestion IA">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 0 0-2.455 2.456Z" />
                                                            </svg>
                                                        </button>
                                                    </div>
                                                @endif
                                            </div>

                                            {{-- Nested replies --}}
                                            @if($nested->has($item->external_id))
                                                <div class="mt-2 ml-4 border-l-2 border-gray-200 pl-3 space-y-2">
                                                    @foreach($nested->get($item->external_id) as $reply)
                                                        <div class="py-2">
                                                            <div class="flex items-start gap-3">
                                                                <div class="flex-1 min-w-0">
                                                                    <div class="flex items-center gap-2 mb-1">
                                                                        <span class="font-medium text-xs text-gray-800">{{ $reply->author_name ?: $reply->author_username ?: 'Anonyme' }}</span>
                                                                        <span class="text-xs text-gray-400">{{ $reply->posted_at?->diffForHumans() }}</span>
                                                                        @if($reply->status === 'unread')
                                                                            <span class="w-1.5 h-1.5 rounded-full bg-indigo-500"></span>
                                                                        @endif
                                                                        @if($reply->status === 'replied')
                                                                            <span class="px-1 py-0.5 rounded-full bg-green-100 text-green-700 text-xs font-medium">Répondu</span>
                                                                        @endif
                                                                    </div>
                                                                    <p class="text-sm text-gray-600">{{ $reply->content }}</p>
                                                                    @if($reply->reply_content)
                                                                        <div class="mt-1 pl-3 border-l-2 border-indigo-200">
                                                                            <p class="text-xs text-indigo-600 font-medium mb-0.5">Votre réponse :</p>
                                                                            <p class="text-xs text-gray-600">{{ $reply->reply_content }}</p>
                                                                        </div>
                                                                    @endif
                                                                </div>
                                                                @if($reply->status !== 'replied')
                                                                    <button @click="openReplyModal({{ $reply->id }}, {{ json_encode($reply->content) }}, {{ json_encode($reply->author_name ?: $reply->author_username) }})"
                                                                            class="p-1 text-gray-400 hover:text-indigo-600 transition-colors rounded-lg hover:bg-indigo-50 flex-shrink-0" title="Répondre">
                                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                                                                        </svg>
                                                                    </button>
                                                                @endif
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach

                                    {{-- Items with parent_id that don't match any top-level external_id (orphaned nested items) --}}
                                    @php
                                        $topLevelExternalIds = $topLevel->pluck('external_id')->filter()->toArray();
                                        $orphanedNested = $items->filter(fn($i) => !empty($i->parent_id) && !in_array($i->parent_id, $topLevelExternalIds));
                                    @endphp
                                    @foreach($orphanedNested as $item)
                                        <div class="px-4 py-3 pl-12 hover:bg-gray-50/60 transition-colors">
                                            <div class="flex items-start gap-3">
                                                <div class="flex-1 min-w-0">
                                                    <div class="flex items-center gap-2 mb-1">
                                                        <span class="font-medium text-sm text-gray-900">{{ $item->author_name ?: $item->author_username ?: 'Anonyme' }}</span>
                                                        <span class="text-xs text-gray-400">{{ $item->posted_at?->diffForHumans() }}</span>
                                                        @if($item->type === 'reply')
                                                            <span class="px-1.5 py-0.5 rounded-full bg-blue-100 text-blue-700 text-xs font-medium">Réponse</span>
                                                        @endif
                                                        @if($item->status === 'unread')
                                                            <span class="w-2 h-2 rounded-full bg-indigo-500 flex-shrink-0"></span>
                                                        @endif
                                                        @if($item->status === 'replied')
                                                            <span class="px-1.5 py-0.5 rounded-full bg-green-100 text-green-700 text-xs font-medium">Répondu</span>
                                                        @endif
                                                    </div>
                                                    @if($item->content)
                                                        <p class="text-sm text-gray-700">{{ $item->content }}</p>
                                                    @endif
                                                    @if($item->media_url)
                                                        <div class="mt-1">
                                                            <img src="{{ $item->media_url }}" alt="{{ $item->media_type }}" class="max-h-32 rounded-lg object-cover cursor-pointer hover:opacity-90 transition-opacity" onclick="window.open(this.src, '_blank')">
                                                        </div>
                                                    @elseif(! $item->content)
                                                        <p class="text-sm text-gray-400 italic">Media sans texte</p>
                                                    @endif
                                                    @if($item->reply_content)
                                                        <div class="mt-2 pl-3 border-l-2 border-indigo-200">
                                                            <p class="text-xs text-indigo-600 font-medium mb-0.5">Votre réponse :</p>
                                                            <p class="text-sm text-gray-600">{{ $item->reply_content }}</p>
                                                        </div>
                                                    @endif
                                                </div>
                                                @if($item->status !== 'replied')
                                                    <div class="flex items-center gap-1 flex-shrink-0">
                                                        <button @click="openReplyModal({{ $item->id }}, {{ json_encode($item->content) }}, {{ json_encode($item->author_name ?: $item->author_username) }})"
                                                                class="p-1.5 text-gray-400 hover:text-indigo-600 transition-colors rounded-lg hover:bg-indigo-50" title="Répondre">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                                                            </svg>
                                                        </button>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                    @php $convoIndex++; @endphp
                @endforeach
            @else
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-12 text-center">
                    <div class="w-16 h-16 rounded-2xl bg-gray-50 flex items-center justify-center mx-auto mb-4">
                        <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 13.5h3.86a2.25 2.25 0 0 1 2.012 1.244l.256.512a2.25 2.25 0 0 0 2.013 1.244h2.21a2.25 2.25 0 0 0 2.013-1.244l.256-.512a2.25 2.25 0 0 1 2.013-1.244h3.859m-17.06 0a1.772 1.772 0 0 0-1.543 2.575l1.838 3.524A2.25 2.25 0 0 0 5.8 21h12.4c.856 0 1.638-.486 2.018-1.257l1.838-3.524a1.772 1.772 0 0 0-1.543-2.575m-17.06 0V4.932c0-.67.588-1.182 1.25-1.12a59.015 59.015 0 0 1 11.57 0c.662.063 1.25.45 1.25 1.12v8.568" />
                        </svg>
                    </div>
                    <h3 class="text-base font-medium text-gray-900 mb-2">Aucun message</h3>
                    <p class="text-sm text-gray-500">Les commentaires et messages de vos réseaux sociaux apparaîtront ici après la synchronisation.</p>
                </div>
            @endif
        </div>

        {{-- Pagination + per page --}}
        <div class="mt-4 flex items-center justify-between gap-4">
            <div class="flex-1">
                @if($conversations->hasPages())
                    {{ $conversations->withQueryString()->links() }}
                @endif
            </div>
            <div class="flex items-center gap-2 text-sm text-gray-500">
                <span>Par page :</span>
                <select onchange="var p = new URLSearchParams(window.location.search); p.set('per_page', this.value); p.delete('page'); window.location.href = window.location.pathname + '?' + p.toString();"
                        class="rounded-lg border-gray-200 text-sm py-1 pl-2 pr-7">
                    @foreach([15, 25, 50, 100] as $opt)
                        <option value="{{ $opt }}" {{ (int) request('per_page', 15) === $opt ? 'selected' : '' }}>{{ $opt }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- Reply modal --}}
        <div x-show="replyModalOpen" x-cloak
             class="fixed inset-0 z-50 flex items-center justify-center"
             @keydown.escape.window="replyModalOpen = false">
            <div class="fixed inset-0 bg-gray-500/75" @click="replyModalOpen = false"></div>
            <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-lg mx-4 p-6" @click.stop>
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Répondre</h3>

                {{-- Original message --}}
                <div class="mb-4 p-3 bg-gray-50 rounded-xl">
                    <p class="text-xs text-gray-500 mb-1 font-medium" x-text="'De : ' + replyToAuthor"></p>
                    <p class="text-sm text-gray-600 line-clamp-3" x-text="replyToContent"></p>
                </div>

                {{-- Reply textarea --}}
                <textarea x-model="replyText" rows="3"
                    class="w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    placeholder="Votre réponse..."></textarea>

                <div class="flex items-center justify-between mt-4">
                    <button @click="generateAiForModal()" :disabled="aiLoading" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium disabled:opacity-50">
                        <span x-show="!aiLoading">Générer avec IA</span>
                        <span x-show="aiLoading" x-cloak>Génération...</span>
                    </button>
                    <div class="flex gap-2">
                        <button @click="replyModalOpen = false" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-xl hover:bg-gray-200 transition-colors">Annuler</button>
                        <button @click="sendReply()" :disabled="sendingReply || !replyText.trim()" class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-xl hover:bg-indigo-700 transition-colors disabled:opacity-50">
                            <span x-show="!sendingReply">Envoyer</span>
                            <span x-show="sendingReply" x-cloak>Envoi...</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Bulk AI reply modal --}}
        <div x-show="bulkModalOpen" x-cloak
             class="fixed inset-0 z-50 flex items-center justify-center"
             @keydown.escape.window="bulkModalOpen = false">
            <div class="fixed inset-0 bg-gray-500/75" @click="bulkModalOpen = false"></div>
            <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-2xl mx-4 max-h-[80vh] flex flex-col" @click.stop>
                <div class="p-6 border-b border-gray-100">
                    <h3 class="text-lg font-semibold text-gray-900">Réponses IA générées</h3>
                    <p class="text-sm text-gray-500 mt-1" x-text="bulkSuggestions.length + ' réponse(s) générée(s)'"></p>
                </div>

                <div class="flex-1 overflow-y-auto p-6 space-y-4">
                    <template x-for="(suggestion, index) in bulkSuggestions" :key="suggestion.id">
                        <div class="border border-gray-200 rounded-xl p-4">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="text-sm font-medium text-gray-900" x-text="suggestion.author"></span>
                                <span class="text-xs text-gray-400" x-text="suggestion.platform"></span>
                            </div>
                            <p class="text-sm text-gray-600 mb-3 line-clamp-2" x-text="suggestion.content"></p>
                            <template x-if="suggestion.reply">
                                <textarea x-model="suggestion.reply" rows="2"
                                    class="w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                            </template>
                            <template x-if="suggestion.error">
                                <p class="text-sm text-red-600" x-text="suggestion.error"></p>
                            </template>
                        </div>
                    </template>
                </div>

                <div class="p-6 border-t border-gray-100">
                    <div class="flex items-center gap-3 mb-4">
                        <span class="text-sm text-gray-600 whitespace-nowrap">Etaler sur :</span>
                        <div class="flex items-center gap-1">
                            <input type="number" x-model.number="spreadHours" min="0" max="24" class="w-16 rounded-lg border-gray-300 text-sm text-center shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <span class="text-sm text-gray-500">h</span>
                        </div>
                        <div class="flex items-center gap-1">
                            <input type="number" x-model.number="spreadMinutes" min="0" max="59" class="w-16 rounded-lg border-gray-300 text-sm text-center shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <span class="text-sm text-gray-500">min</span>
                        </div>
                        <span class="text-xs text-gray-400 ml-1" x-show="spreadHours > 0 || spreadMinutes > 0" x-cloak>
                            (1 reponse toutes les <span x-text="Math.round((spreadHours * 60 + spreadMinutes) / Math.max(bulkSuggestions.filter(s => s.reply && s.reply.trim()).length, 1))"></span> min)
                        </span>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button @click="bulkModalOpen = false" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-xl hover:bg-gray-200 transition-colors">Annuler</button>
                        <button @click="sendBulkReplies()" :disabled="bulkSending" class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-xl hover:bg-indigo-700 transition-colors disabled:opacity-50">
                            <span x-show="!bulkSending">
                                <span x-show="spreadHours > 0 || spreadMinutes > 0">Planifier</span>
                                <span x-show="spreadHours === 0 && spreadMinutes === 0">Envoyer tout</span>
                            </span>
                            <span x-show="bulkSending" x-cloak>Envoi...</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

    </div>
@endsection

@push('scripts')
<script>
function inboxManager() {
    return {
        selectedIds: [],
        lastClickedConvoIndex: null,
        allConvoIds: @json($conversations->map(fn ($c) => $c->items->pluck('id')->toArray())->values()->toArray()),
        replyModalOpen: false,
        replyToId: null,
        replyToContent: '',
        replyToAuthor: '',
        replyText: '',
        aiLoading: false,
        sendingReply: false,
        syncing: false,
        bulkLoading: false,
        bulkModalOpen: false,
        bulkSuggestions: [],
        bulkSending: false,
        spreadHours: 0,
        spreadMinutes: 0,

        csrfToken: document.querySelector('meta[name="csrf-token"]').getAttribute('content'),

        toggleConversation(ids, index, event) {
            // Shift+Click: select range between last clicked and current
            if (event && event.shiftKey && this.lastClickedConvoIndex !== null) {
                const from = Math.min(this.lastClickedConvoIndex, index);
                const to = Math.max(this.lastClickedConvoIndex, index);
                for (let i = from; i <= to; i++) {
                    if (this.allConvoIds[i]) {
                        this.allConvoIds[i].forEach(id => {
                            if (!this.selectedIds.includes(id)) {
                                this.selectedIds.push(id);
                            }
                        });
                    }
                }
                this.lastClickedConvoIndex = index;
                return;
            }

            const allSelected = ids.every(id => this.selectedIds.includes(id));
            if (allSelected) {
                this.selectedIds = this.selectedIds.filter(id => !ids.includes(id));
            } else {
                ids.forEach(id => {
                    if (!this.selectedIds.includes(id)) {
                        this.selectedIds.push(id);
                    }
                });
            }
            this.lastClickedConvoIndex = index;
        },

        openReplyModal(id, content, author) {
            this.replyToId = id;
            this.replyToContent = content;
            this.replyToAuthor = author || 'Anonyme';
            this.replyText = '';
            this.replyModalOpen = true;
        },

        async sendReply() {
            if (!this.replyText.trim() || !this.replyToId) return;
            this.sendingReply = true;

            try {
                const resp = await fetch(`/inbox/${this.replyToId}/reply`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ reply_text: this.replyText }),
                });
                const data = await resp.json();

                if (data.success) {
                    this.replyModalOpen = false;
                    location.reload();
                } else {
                    alert('Erreur : ' + (data.error || 'Échec de l\'envoi'));
                }
            } catch (e) {
                alert('Erreur de connexion');
            } finally {
                this.sendingReply = false;
            }
        },

        async generateAiForModal() {
            if (!this.replyToId) return;
            this.aiLoading = true;

            try {
                const resp = await fetch(`/inbox/${this.replyToId}/ai-suggest`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'Accept': 'application/json',
                    },
                });
                const data = await resp.json();

                if (data.reply) {
                    this.replyText = data.reply;
                } else {
                    alert(data.error || 'Impossible de générer une suggestion');
                }
            } catch (e) {
                alert('Erreur de connexion');
            } finally {
                this.aiLoading = false;
            }
        },

        async generateAiSingle(id) {
            this.openReplyModal(id, '', '');
            this.aiLoading = true;

            try {
                const resp = await fetch(`/inbox/${id}/ai-suggest`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'Accept': 'application/json',
                    },
                });
                const data = await resp.json();

                if (data.reply) {
                    this.replyText = data.reply;
                } else {
                    alert(data.error || 'Impossible de générer une suggestion');
                }
            } catch (e) {
                alert('Erreur de connexion');
            } finally {
                this.aiLoading = false;
            }
        },

        async markReadBulk() {
            try {
                await fetch('/inbox/mark-read', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ ids: this.selectedIds }),
                });
                location.reload();
            } catch (e) {
                alert('Erreur de connexion');
            }
        },

        async archiveBulk() {
            if (!confirm('Archiver les éléments sélectionnés ?')) return;

            try {
                await fetch('/inbox/archive', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ ids: this.selectedIds }),
                });
                location.reload();
            } catch (e) {
                alert('Erreur de connexion');
            }
        },

        async startBulkAiReply() {
            this.bulkLoading = true;

            try {
                const resp = await fetch('/inbox/bulk-ai-reply', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ ids: this.selectedIds }),
                });
                const data = await resp.json();

                if (!resp.ok) {
                    const msg = data.message || Object.values(data.errors || {}).flat().join('\n') || 'Erreur serveur';
                    alert(msg);
                    return;
                }

                if (data.suggestions) {
                    this.bulkSuggestions = data.suggestions;
                    this.bulkModalOpen = true;
                }
            } catch (e) {
                alert('Erreur de connexion');
            } finally {
                this.bulkLoading = false;
            }
        },

        async sendBulkReplies() {
            const items = this.bulkSuggestions
                .filter(s => s.reply && s.reply.trim())
                .map(s => ({ id: s.id, reply_text: s.reply }));

            if (items.length === 0) return;
            this.bulkSending = true;

            const spreadMinutes = (this.spreadHours * 60) + this.spreadMinutes;

            try {
                const resp = await fetch('/inbox/bulk-send', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ items, spread_minutes: spreadMinutes }),
                });

                const data = await resp.json();

                this.bulkModalOpen = false;

                if (data.scheduled) {
                    alert(`${items.length} reponses planifiees sur ${spreadMinutes} minutes.`);
                }

                location.reload();
            } catch (e) {
                alert('Erreur de connexion');
            } finally {
                this.bulkSending = false;
            }
        },

        async syncInbox() {
            this.syncing = true;

            try {
                await fetch('/inbox/sync', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'Accept': 'application/json',
                    },
                });
                location.reload();
            } catch (e) {
                alert('Erreur de connexion');
            } finally {
                this.syncing = false;
            }
        },
    }
}

function scheduledCountdown(info) {
    return {
        pending: info.pending,
        nextAt: new Date(info.next_at),
        lastAt: new Date(info.last_at),
        nextIn: '',
        lastIn: '',
        timer: null,

        startCountdown() {
            this.updateLabels();
            this.timer = setInterval(() => this.updateLabels(), 30000); // update every 30s
        },

        updateLabels() {
            this.nextIn = this.formatDiff(this.nextAt);
            this.lastIn = this.formatDiff(this.lastAt);
        },

        formatDiff(date) {
            const diff = Math.max(0, Math.floor((date - Date.now()) / 1000));
            if (diff <= 0) return 'quelques secondes';
            const h = Math.floor(diff / 3600);
            const m = Math.floor((diff % 3600) / 60);
            if (h > 0) return h + 'h ' + m + 'min';
            if (m > 0) return m + ' min';
            return diff + 's';
        },

        destroy() {
            if (this.timer) clearInterval(this.timer);
        }
    }
}
</script>
@endpush
