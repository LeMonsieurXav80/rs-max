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
            <div x-data="scheduledCountdown({{ json_encode($scheduledInfo) }})"
                 x-init="startCountdown()"
                 x-show="pending > 0 || failed > 0"
                 x-transition>

                {{-- Pending replies banner --}}
                <template x-if="pending > 0">
                    <div class="bg-indigo-50 border border-indigo-200 rounded-2xl p-4" :class="{ 'mb-3': failed > 0 }">
                        <div class="flex items-center gap-4">
                            <div class="flex-shrink-0">
                                <svg class="w-6 h-6 text-indigo-600 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-indigo-900">
                                    <span x-text="pending"></span>/<span x-text="initialCount"></span> réponse(s) restante(s)
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
                        <div class="mt-3 w-full bg-indigo-100 rounded-full h-1.5">
                            <div class="bg-indigo-500 h-1.5 rounded-full transition-all duration-500"
                                 :style="'width: ' + Math.round(((initialCount - pending) / initialCount) * 100) + '%'"></div>
                        </div>
                    </div>
                </template>

                {{-- Failed replies banner --}}
                <template x-if="failed > 0">
                    <div class="bg-red-50 border border-red-200 rounded-2xl p-4">
                        <div class="flex items-center gap-4">
                            <div class="flex-shrink-0">
                                <svg class="w-6 h-6 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-red-900">
                                    <span x-text="failed"></span> réponse(s) en échec
                                </p>
                                <template x-for="item in failedItems" :key="item.id">
                                    <p class="text-xs text-red-700 mt-1">
                                        <span class="font-medium" x-text="item.author"></span> : <span class="italic" x-text="item.reply"></span>
                                    </p>
                                </template>
                            </div>
                            <div class="flex-shrink-0">
                                <button @click="dismissFailedInDb()" :disabled="dismissing"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium bg-red-100 text-red-700 hover:bg-red-200 transition-colors disabled:opacity-50">
                                    <span x-text="dismissing ? '...' : 'Supprimer'"></span>
                                </button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        @endif

        {{-- Account selector --}}
        <form method="GET" action="{{ route('inbox.index') }}" x-ref="filterForm" class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 space-y-3">
            @php $currentStatus = request('status', ''); @endphp
            <input type="hidden" name="status" x-ref="statusInput" value="{{ $currentStatus }}">
            @php $activeTypes = request('type', []); if (!is_array($activeTypes)) $activeTypes = [$activeTypes]; @endphp

            <x-account-selector
                :accounts="$socialAccounts"
                :selected-ids="$selectedAccountIds"
                :groups="$accountGroups"
                :auto-submit="true"
            />

            {{-- Status pills + sync button --}}
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-1 bg-gray-100 rounded-xl p-1">
                    @foreach(['' => 'Tous', 'new' => 'Nouveau', 'followup' => 'Relance', 'replied' => 'Répondus', 'ignored' => 'Ignorés', 'archived' => 'Archivés'] as $val => $label)
                        <button type="submit"
                                @click.prevent="$refs.statusInput.value = '{{ $val }}'; $refs.filterForm.submit()"
                                class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors {{ $currentStatus === $val ? 'bg-white shadow-sm' . ($val === 'replied' ? ' text-green-600' : ($val === 'archived' ? ' text-gray-600' : ($val === 'ignored' ? ' text-gray-600' : ($val === 'new' ? ' text-amber-600' : ($val === 'followup' ? ' text-orange-600' : ' text-indigo-600'))))) : 'text-gray-500 hover:text-gray-700' }}">
                            {{ $label }}
                            @if($val === 'ignored' && $counts['ignored'] > 0)
                                <span class="ml-1 text-xs text-gray-400">({{ $counts['ignored'] }})</span>
                            @endif
                        </button>
                    @endforeach
                </div>

                <div class="flex-1"></div>

                @if(auth()->user()->isManager())
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

            {{-- Type checkboxes --}}
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs text-gray-400 font-medium mr-1">Type :</span>
                @foreach(['comment' => 'Commentaires', 'reply' => 'Réponses', 'dm' => 'Messages privés'] as $val => $label)
                    <label class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium cursor-pointer transition-colors {{ in_array($val, $activeTypes) ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-500 hover:bg-gray-200' }}">
                        <input type="checkbox" name="type[]" value="{{ $val }}" class="sr-only"
                               {{ in_array($val, $activeTypes) ? 'checked' : '' }}
                               onchange="this.form.submit()">
                        {{ $label }}
                    </label>
                @endforeach
            </div>
        </form>

        {{-- Bulk action bar --}}
        <div x-show="selectedIds.length > 0" x-cloak x-transition class="bg-indigo-50 rounded-2xl border border-indigo-200 p-4 flex flex-wrap items-center justify-between gap-3">
            <span class="text-sm font-medium text-indigo-700" x-text="selectedIds.length + ' élément(s) sélectionné(s)'"></span>
            <div class="flex flex-wrap items-center gap-2">
                {{-- Bulk dropdown (WordPress-style) --}}
                <select x-ref="bulkAction" class="rounded-xl border-gray-200 text-sm py-1.5 pl-3 pr-8 bg-white">
                    <option value="">-- Action --</option>
                    <option value="markread">Marquer lu</option>
                    <option value="ignore">Ignorer</option>
                    <option value="archive">Archiver</option>
                </select>
                <button @click="executeBulkAction()" class="px-3 py-1.5 bg-white text-sm font-medium text-gray-700 rounded-xl border border-gray-200 hover:bg-gray-50 transition-colors">Appliquer</button>

                {{-- Primary action: AI reply --}}
                <button @click="startBulkAiReply()" :disabled="bulkLoading" class="px-3 py-1.5 bg-indigo-600 text-sm font-medium text-white rounded-xl hover:bg-indigo-700 transition-colors disabled:opacity-50">
                    <span x-show="!bulkLoading">Répondre avec IA</span>
                    <span x-show="bulkLoading" x-cloak>Génération...</span>
                </button>
            </div>
        </div>

        {{-- Conversation list --}}
        @php $selectableIdsByConvo = []; @endphp
        <div class="space-y-3">
            @if($conversations->count() > 0)
                @php
                    $convoIndex = 0;
                @endphp
                @foreach($conversations as $convo)
                    @php
                        $items = $convo->items;
                        $isSingle = $items->count() === 1;
                        $previewItem = $items->last();
                        $firstItem = $items->first();
                        $latestItem = $items->sortByDesc('posted_at')->first();
                        $allItemIds = $items->pluck('id')->toArray();
                        $itemIds = match($currentStatus) {
                            'new', 'followup' => array_filter([$items->whereIn('status', ['unread', 'read'])->last()?->id]),
                            '' => $items->where('status', '!=', 'archived')->pluck('id')->toArray(),
                            default => $items->where('status', $currentStatus)->pluck('id')->toArray(),
                        };
                        $convoKey = 'convo_' . md5($convo->key);
                        $selectableIdsByConvo[] = $itemIds;
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
                                            <span class="text-xs text-gray-400">{{'@'}}{{ $firstItem->author_username }}</span>
                                        @endif
                                        @if($firstItem->type === 'reply')
                                            <span class="px-1.5 py-0.5 rounded-full bg-blue-100 text-blue-700 text-xs font-medium">Réponse</span>
                                        @endif
                                        @if($firstItem->status === 'replied')
                                            <span class="px-1.5 py-0.5 rounded-full bg-green-100 text-green-700 text-xs font-medium">Répondu</span>
                                        @elseif($firstItem->status === 'reply_failed')
                                            <span class="px-1.5 py-0.5 rounded-full bg-red-100 text-red-700 text-xs font-medium">Échec envoi</span>
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
                                    {{-- Multi-item: show last author + preview of latest message --}}
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="text-sm font-medium text-gray-700">{{ $previewItem->author_name ?: $previewItem->author_username ?: 'Anonyme' }}</span>
                                        @if($previewItem->author_username && $previewItem->author_name && $previewItem->author_username !== $previewItem->author_name)
                                            <span class="text-xs text-gray-400">{{'@'}}{{ $previewItem->author_username }}</span>
                                        @endif
                                    </div>
                                    @if($previewItem->content)
                                        <p class="text-sm text-gray-500 line-clamp-1">{{ Str::limit($previewItem->content, 100) }}</p>
                                    @elseif($previewItem->media_url)
                                        <p class="text-sm text-gray-400 italic">[{{ $previewItem->media_type === 'gif' ? 'GIF' : ($previewItem->media_type === 'sticker' ? 'Sticker' : 'Image') }}]</p>
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
                                                            <span class="text-xs text-gray-400">{{'@'}}{{ $item->author_username }}</span>
                                                        @endif
                                                        <span class="text-xs text-gray-400">{{ $item->posted_at?->diffForHumans() }}</span>
                                                        @if($item->status === 'unread')
                                                            <span class="w-2 h-2 rounded-full bg-indigo-500 flex-shrink-0"></span>
                                                        @endif
                                                        @if($item->status === 'replied')
                                                            <span class="px-1.5 py-0.5 rounded-full bg-green-100 text-green-700 text-xs font-medium">Répondu</span>
                                                        @elseif($item->status === 'reply_failed')
                                                            <span class="px-1.5 py-0.5 rounded-full bg-red-100 text-red-700 text-xs font-medium">Échec envoi</span>
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
                                                                        @elseif($reply->status === 'reply_failed')
                                                                            <span class="px-1 py-0.5 rounded-full bg-red-100 text-red-700 text-xs font-medium">Échec</span>
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
                                                        @elseif($item->status === 'reply_failed')
                                                            <span class="px-1.5 py-0.5 rounded-full bg-red-100 text-red-700 text-xs font-medium">Échec envoi</span>
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
                    @foreach([15, 25, 50, 100, 250] as $opt)
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
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">Réponses IA générées</h3>
                            <p class="text-sm text-gray-500 mt-1">
                                <template x-if="bulkGenerating">
                                    <span><span x-text="bulkProgress"></span> / <span x-text="bulkSuggestions.length"></span> traités…</span>
                                </template>
                                <template x-if="!bulkGenerating">
                                    <span><span x-text="bulkSuggestions.filter(s => s.reply && s.reply.trim()).length"></span> / <span x-text="bulkSuggestions.length"></span> réponse(s) générée(s)</span>
                                </template>
                            </p>
                        </div>
                        <button x-show="bulkGenerating" @click="bulkAborted = true"
                                class="px-3 py-1.5 text-sm font-medium text-red-600 bg-red-50 rounded-xl hover:bg-red-100 transition-colors">
                            Stop
                        </button>
                    </div>
                    <div x-show="bulkGenerating" class="mt-3 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                        <div class="h-full bg-indigo-600 transition-all"
                             :style="`width: ${bulkSuggestions.length ? (bulkProgress / bulkSuggestions.length * 100) : 0}%`"></div>
                    </div>
                </div>

                <div class="flex-1 overflow-y-auto p-6 space-y-4">
                    <template x-for="(suggestion, index) in bulkSuggestions" :key="suggestion.id">
                        <div class="border border-gray-200 rounded-xl p-4">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="text-sm font-medium text-gray-900" x-text="suggestion.author"></span>
                                <span class="text-xs text-gray-400" x-text="suggestion.platform"></span>
                            </div>
                            <p class="text-sm text-gray-600 mb-3 line-clamp-2" x-text="suggestion.content"></p>
                            <template x-if="suggestion.loading">
                                <div class="flex items-center gap-2 text-sm text-gray-400">
                                    <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                    </svg>
                                    Génération…
                                </div>
                            </template>
                            <template x-if="!suggestion.loading && suggestion.reply">
                                <textarea x-model="suggestion.reply" rows="2"
                                    class="w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                            </template>
                            <template x-if="!suggestion.loading && suggestion.error">
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
                        <span class="text-xs text-gray-400 ml-1" x-show="spreadHours > 0 || spreadMinutes > 0" x-cloak
                              x-text="(() => {
                                  const count = Math.max(bulkSuggestions.filter(s => s.reply && s.reply.trim()).length, 1);
                                  const totalSec = (spreadHours * 60 + spreadMinutes) * 60 / count;
                                  if (totalSec >= 60) return '(1 réponse toutes les ' + Math.round(totalSec / 60) + ' min)';
                                  return '(1 réponse toutes les ' + Math.round(totalSec) + 's)';
                              })()">
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
        allConvoIds: @json($selectableIdsByConvo),
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
        bulkGenerating: false,
        bulkProgress: 0,
        bulkAborted: false,
        spreadHours: 0,
        spreadMinutes: 0,

        csrfToken: document.querySelector('meta[name="csrf-token"]').getAttribute('content'),

        async refreshCsrfToken() {
            try {
                const resp = await fetch(window.location.href, { headers: { 'Accept': 'text/html' } });
                const html = await resp.text();
                const match = html.match(/meta\s+name="csrf-token"\s+content="([^"]+)"/);
                if (match) {
                    this.csrfToken = match[1];
                    const meta = document.querySelector('meta[name="csrf-token"]');
                    if (meta) meta.setAttribute('content', match[1]);
                    return true;
                }
            } catch(e) {}
            return false;
        },

        async csrfFetch(url, options = {}) {
            options.headers = {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': this.csrfToken,
                ...(options.headers || {}),
            };
            let resp = await fetch(url, options);
            if (resp.status === 419) {
                const refreshed = await this.refreshCsrfToken();
                if (refreshed) {
                    options.headers['X-CSRF-TOKEN'] = this.csrfToken;
                    resp = await fetch(url, options);
                } else {
                    window.location.reload();
                }
            }
            return resp;
        },

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
                const resp = await this.csrfFetch(`/inbox/${this.replyToId}/reply`, {
                    method: 'POST',
                    body: JSON.stringify({ reply_text: this.replyText }),
                });

                if (!resp.ok) {
                    const text = await resp.text();
                    let msg = 'Erreur serveur (' + resp.status + ')';
                    try { msg = JSON.parse(text).error || JSON.parse(text).message || msg; } catch {}
                    alert(msg);
                    return;
                }

                const data = await resp.json();

                if (data.success) {
                    this.replyModalOpen = false;
                    location.reload();
                } else {
                    alert('Erreur : ' + (data.error || 'Échec de l\'envoi'));
                }
            } catch (e) {
                alert('Erreur de connexion : ' + e.message);
            } finally {
                this.sendingReply = false;
            }
        },

        async generateAiForModal() {
            if (!this.replyToId) return;
            this.aiLoading = true;

            try {
                const resp = await this.csrfFetch(`/inbox/${this.replyToId}/ai-suggest`, {
                    method: 'POST',
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
                const resp = await this.csrfFetch(`/inbox/${id}/ai-suggest`, {
                    method: 'POST',
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

        executeBulkAction() {
            const action = this.$refs.bulkAction.value;
            if (!action) return alert('Choisissez une action');
            if (action === 'markread') this.markReadBulk();
            else if (action === 'ignore') this.ignoreBulk();
            else if (action === 'archive') this.archiveBulk();
        },

        async ignoreBulk() {
            try {
                await this.csrfFetch('/inbox/ignore', {
                    method: 'POST',
                    body: JSON.stringify({ ids: this.selectedIds }),
                });
                location.reload();
            } catch (e) {
                alert('Erreur de connexion');
            }
        },

        async markReadBulk() {
            try {
                await this.csrfFetch('/inbox/mark-read', {
                    method: 'POST',
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
                await this.csrfFetch('/inbox/archive', {
                    method: 'POST',
                    body: JSON.stringify({ ids: this.selectedIds }),
                });
                location.reload();
            } catch (e) {
                alert('Erreur de connexion');
            }
        },

        async startBulkAiReply() {
            if (this.selectedIds.length === 0) return;
            this.bulkLoading = true;
            this.bulkAborted = false;
            this.bulkProgress = 0;

            // Phase 1: fetch metadata for all selected items (fast, no AI call)
            try {
                const resp = await this.csrfFetch('/inbox/bulk-ai-prepare', {
                    method: 'POST',
                    body: JSON.stringify({ ids: this.selectedIds }),
                });
                const data = await resp.json();

                if (!resp.ok) {
                    const msg = data.message || Object.values(data.errors || {}).flat().join('\n') || 'Erreur serveur';
                    alert(msg);
                    return;
                }

                this.bulkSuggestions = data.items || [];
                this.bulkModalOpen = true;
            } catch (e) {
                alert('Erreur de connexion : ' + e.message);
                return;
            } finally {
                this.bulkLoading = false;
            }

            if (this.bulkSuggestions.length === 0) return;

            // Phase 2: generate AI replies sequentially with delay and retry
            this.bulkGenerating = true;

            const delay = ms => new Promise(r => setTimeout(r, ms));

            for (let i = 0; i < this.bulkSuggestions.length && !this.bulkAborted; i++) {
                const item = this.bulkSuggestions[i];
                let success = false;

                for (let attempt = 0; attempt < 3 && !success; attempt++) {
                    try {
                        if (attempt > 0) await delay(2000 * attempt);
                        const resp = await this.csrfFetch(`/inbox/${item.id}/ai-suggest`, { method: 'POST' });
                        const data = await resp.json();
                        if (resp.ok && data.reply) {
                            item.reply = data.reply;
                            success = true;
                        } else if (resp.status === 429) {
                            // Rate limited — wait and retry
                            await delay(5000);
                        } else {
                            item.error = data.error || 'Échec de la génération';
                            success = true; // Don't retry business errors
                        }
                    } catch (e) {
                        if (attempt === 2) item.error = 'Erreur réseau';
                    }
                }

                item.loading = false;
                this.bulkProgress++;

                // Small pause between items to avoid overwhelming the server
                if (i < this.bulkSuggestions.length - 1 && !this.bulkAborted) {
                    await delay(300);
                }
            }

            // Mark any remaining (aborted) items as cancelled
            this.bulkSuggestions.forEach(s => {
                if (s.loading) {
                    s.loading = false;
                    s.error = 'Annulé';
                }
            });

            this.bulkGenerating = false;
        },

        async sendBulkReplies() {
            const items = this.bulkSuggestions
                .filter(s => s.reply && s.reply.trim())
                .map(s => ({ id: s.id, reply_text: s.reply }));

            if (items.length === 0) return;
            this.bulkSending = true;

            const spreadMinutes = (this.spreadHours * 60) + this.spreadMinutes;

            try {
                const resp = await this.csrfFetch('/inbox/bulk-send', {
                    method: 'POST',
                    body: JSON.stringify({ items, spread_minutes: spreadMinutes }),
                });

                if (!resp.ok) {
                    const text = await resp.text();
                    let msg = 'Erreur serveur (' + resp.status + ')';
                    try { msg = JSON.parse(text).error || JSON.parse(text).message || msg; } catch {}
                    alert(msg);
                    return;
                }

                const data = await resp.json();

                this.bulkModalOpen = false;

                if (data.scheduled) {
                    alert(`${items.length} reponses planifiees sur ${spreadMinutes} minutes.`);
                }

                location.reload();
            } catch (e) {
                alert('Erreur de connexion : ' + e.message);
            } finally {
                this.bulkSending = false;
            }
        },

        async syncInbox() {
            this.syncing = true;

            try {
                const params = new URLSearchParams(window.location.search);
                const accountIds = params.getAll('accounts[]').map(Number).filter(Boolean);
                const body = {};

                if (accountIds.length === 1) {
                    body.account_id = accountIds[0];
                } else if (accountIds.length > 1) {
                    // Sync each selected account
                    for (const id of accountIds) {
                        await this.csrfFetch('/inbox/sync', {
                            method: 'POST',
                            body: JSON.stringify({ account_id: id }),
                        });
                    }
                    location.reload();
                    return;
                }

                await this.csrfFetch('/inbox/sync', {
                    method: 'POST',
                    body: JSON.stringify(body),
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
        initialCount: info.pending || 1,
        failed: info.failed || 0,
        failedItems: info.failed_items || [],
        dismissing: false,
        nextAt: info.next_at ? new Date(info.next_at) : null,
        lastAt: info.last_at ? new Date(info.last_at) : null,
        nextIn: '',
        lastIn: '',
        countdownTimer: null,
        pollTimer: null,

        startCountdown() {
            this.updateLabels();
            if (this.pending > 0) {
                this.pollStatus();
                this.countdownTimer = setInterval(() => this.updateLabels(), 10000);
                this.pollTimer = setInterval(() => this.pollStatus(), 15000);
            }
        },

        updateLabels() {
            if (this.nextAt) this.nextIn = this.formatDiff(this.nextAt);
            if (this.lastAt) this.lastIn = this.formatDiff(this.lastAt);
        },

        async pollStatus() {
            try {
                const resp = await fetch('/inbox/scheduled-status', {
                    headers: { 'Accept': 'application/json' },
                });
                const data = await resp.json();
                this.pending = data.pending;
                this.failed = data.failed || 0;
                if (data.pending > 0) {
                    this.nextAt = new Date(data.next_at);
                    this.lastAt = new Date(data.last_at);
                    this.updateLabels();
                } else {
                    if (this.countdownTimer) clearInterval(this.countdownTimer);
                    if (this.pollTimer) clearInterval(this.pollTimer);
                    if (this.failed === 0) {
                        setTimeout(() => location.reload(), 1500);
                    }
                }
            } catch (e) { /* silent */ }
        },

        async dismissFailedInDb() {
            this.dismissing = true;
            try {
                const ids = this.failedItems.map(i => i.id);
                const token = document.querySelector('meta[name="csrf-token"]').content;
                let resp = await fetch('{{ route("inbox.dismissFailed") }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify({ ids }),
                });
                if (resp.status === 419) window.location.reload();
                this.failed = 0;
                this.failedItems = [];
            } catch (e) { /* silent */ }
            this.dismissing = false;
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
            if (this.countdownTimer) clearInterval(this.countdownTimer);
            if (this.pollTimer) clearInterval(this.pollTimer);
        }
    }
}
</script>
@endpush
