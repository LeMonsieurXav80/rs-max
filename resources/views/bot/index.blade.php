@extends('layouts.app')

@section('title', 'Bot Actions')

@section('content')
    <div class="max-w-4xl space-y-6">

        @if (session('success'))
            <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)"
                 class="rounded-xl bg-green-50 border border-green-200 p-4 flex items-center gap-3">
                <svg class="w-5 h-5 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
                <p class="text-sm text-green-700 font-medium">{{ session('success') }}</p>
            </div>
        @endif

        {{-- Stats du jour --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-4">
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 text-center">
                <p class="text-2xl font-bold text-indigo-600">{{ $todayStats->get('like_post', 0) }}</p>
                <p class="text-xs text-gray-500 mt-1">Posts likes (BS)</p>
            </div>
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 text-center">
                <p class="text-2xl font-bold text-indigo-600">{{ $todayStats->get('like_reply', 0) }}</p>
                <p class="text-xs text-gray-500 mt-1">Replies likes (BS)</p>
            </div>
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 text-center">
                <p class="text-2xl font-bold text-purple-600">{{ $todayStats->get('like_back', 0) }}</p>
                <p class="text-xs text-gray-500 mt-1">Like-backs (BS)</p>
            </div>
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 text-center">
                <p class="text-2xl font-bold text-purple-600">{{ $todayStats->get('prospect_like', 0) }}</p>
                <p class="text-xs text-gray-500 mt-1">Prospect likes</p>
            </div>
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 text-center">
                <p class="text-2xl font-bold text-purple-600">{{ $todayStats->get('prospect_follow', 0) }}</p>
                <p class="text-xs text-gray-500 mt-1">Prospect follows</p>
            </div>
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 text-center">
                <p class="text-2xl font-bold text-blue-600">{{ $todayStats->get('like_comment', 0) }}</p>
                <p class="text-xs text-gray-500 mt-1">Commentaires (FB)</p>
            </div>
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 text-center">
                <p class="text-2xl font-bold text-gray-900">{{ $todayStats->values()->sum() }}</p>
                <p class="text-xs text-gray-500 mt-1">Total aujourd'hui</p>
            </div>
        </div>

        {{-- Tabs --}}
        <div x-data="{ activeTab: 'bluesky' }">

            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm">
                <nav class="flex border-b border-gray-100 px-2 overflow-x-auto" aria-label="Tabs">
                    <button type="button" @click="activeTab = 'bluesky'"
                            :class="activeTab === 'bluesky' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="px-4 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap">
                        Bluesky - Auto-like
                    </button>
                    <button type="button" @click="activeTab = 'facebook'"
                            :class="activeTab === 'facebook' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="px-4 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap">
                        Facebook - Auto-like commentaires
                    </button>
                    <button type="button" @click="activeTab = 'logs'"
                            :class="activeTab === 'logs' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="px-4 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap">
                        Historique
                    </button>
                </nav>
            </div>

            {{-- ═══════════ TAB: Bluesky ═══════════ --}}
            <div x-show="activeTab === 'bluesky'" x-cloak class="mt-6 space-y-6">

                @forelse ($blueskyAccounts as $bsAccount)
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-3">
                            @if ($bsAccount->profile_picture_url)
                                <img src="{{ $bsAccount->profile_picture_url }}" class="w-8 h-8 rounded-full" alt="">
                            @endif
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900">{{ $bsAccount->name }}</h3>
                                <p class="text-xs text-gray-500">{{ $bsAccount->credentials['handle'] ?? '' }}</p>
                            </div>
                            {{-- API Status check --}}
                            <div x-data="{ checking: false, result: null }" class="ml-2">
                                <button type="button"
                                        @click="checking = true; result = null; fetch('{{ route('bot.apiStatus', $bsAccount) }}').then(r => r.json()).then(d => { result = d; checking = false; }).catch(() => { result = { error: 'Network error' }; checking = false; })"
                                        :disabled="checking"
                                        class="inline-flex items-center gap-1 px-2 py-1 text-[10px] font-medium rounded-md transition-colors"
                                        :class="result === null ? 'bg-gray-100 text-gray-500 hover:bg-gray-200' : (result.auth && result.api && !result.rate_limited ? 'bg-green-100 text-green-700' : (result.rate_limited ? 'bg-red-100 text-red-600' : 'bg-yellow-100 text-yellow-700'))">
                                    <template x-if="checking">
                                        <svg class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                    </template>
                                    <template x-if="!checking && result === null">
                                        <span>API Status</span>
                                    </template>
                                    <template x-if="!checking && result !== null && result.auth && result.api && !result.rate_limited">
                                        <span>API OK</span>
                                    </template>
                                    <template x-if="!checking && result !== null && result.rate_limited">
                                        <span>Rate Limited</span>
                                    </template>
                                    <template x-if="!checking && result !== null && !result.rate_limited && (!result.auth || !result.api)">
                                        <span x-text="result.error ? result.error.substring(0, 40) : 'Erreur'"></span>
                                    </template>
                                </button>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <div x-data="freqSelector('bluesky', {{ $bsAccount->id }}, '{{ $botFrequencies["bluesky_{$bsAccount->id}"] ?? 'every_30_min' }}')">
                                <select x-model="freq" @change="save()" class="rounded-lg border-gray-300 text-xs py-1.5 pr-8 focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="disabled">Desactive</option>
                                    <option value="every_15_min">Toutes les 15 min</option>
                                    <option value="every_30_min">Toutes les 30 min</option>
                                    <option value="hourly">Toutes les heures</option>
                                    <option value="every_2_hours">Toutes les 2h</option>
                                    <option value="every_6_hours">Toutes les 6h</option>
                                    <option value="every_12_hours">Toutes les 12h</option>
                                    <option value="daily">1x par jour</option>
                                </select>
                            </div>
                            <div x-data="botButton('bluesky', {{ $bsAccount->id }}, {{ ($botActiveStates["bluesky_{$bsAccount->id}"] ?? false) ? 'true' : 'false' }}, '{{ route('bot.runBluesky') }}')" x-init="init()">
                                <template x-if="!active">
                                    <button type="button" @click="activate()" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-indigo-600 text-white text-xs font-medium rounded-lg hover:bg-indigo-700 transition-colors">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z" />
                                        </svg>
                                        Activer le bot
                                    </button>
                                </template>
                                <template x-if="active">
                                    <div class="flex items-center gap-2">
                                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg"
                                              :class="running ? 'bg-green-100 text-green-700' : 'bg-emerald-50 text-emerald-600'">
                                            <template x-if="running">
                                                <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                                </svg>
                                            </template>
                                            <template x-if="!running">
                                                <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                            </template>
                                            <span x-text="running ? 'En cours...' : 'Actif'"></span>
                                        </span>
                                        <button type="button" @click="stop()" class="inline-flex items-center gap-1 px-2 py-1.5 bg-red-100 text-red-700 text-xs font-medium rounded-lg hover:bg-red-200 transition-colors">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 7.5A2.25 2.25 0 0 1 7.5 5.25h9a2.25 2.25 0 0 1 2.25 2.25v9a2.25 2.25 0 0 1-2.25 2.25h-9a2.25 2.25 0 0 1-2.25-2.25v-9Z" />
                                            </svg>
                                            Désactiver
                                        </button>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    {{-- Termes de recherche existants --}}
                    @php
                        $accountTerms = $searchTerms->where('social_account_id', $bsAccount->id);
                    @endphp

                    @if ($accountTerms->isNotEmpty())
                        <div class="flex flex-wrap gap-2 mb-4">
                            @foreach ($accountTerms as $term)
                                <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-medium {{ $term->is_active ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-400' }}">
                                    <span>{{ $term->term }}</span>
                                    <span class="text-xs opacity-60">({{ $term->max_likes_per_run }})</span>

                                    {{-- Toggle --}}
                                    <button type="button"
                                            class="hover:text-indigo-900 transition-colors"
                                            onclick="fetch('{{ route('bot.toggleTerm', $term) }}', { method: 'PATCH', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' } }).then(() => location.reload())"
                                            title="{{ $term->is_active ? 'Desactiver' : 'Activer' }}">
                                        @if ($term->is_active)
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25v13.5m-7.5-13.5v13.5" />
                                            </svg>
                                        @else
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z" />
                                            </svg>
                                        @endif
                                    </button>

                                    {{-- Remove --}}
                                    <form method="POST" action="{{ route('bot.removeTerm', $term) }}" class="inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="hover:text-red-600 transition-colors">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </form>
                                </div>
                            @endforeach
                        </div>

                        @if ($accountTerms->whereNotNull('last_run_at')->isNotEmpty())
                            <p class="text-xs text-gray-400 mb-4">
                                Dernier run : {{ $accountTerms->max('last_run_at')?->diffForHumans() }}
                            </p>
                        @endif
                    @endif

                    {{-- Ajouter un terme --}}
                    <div x-data="termInput({{ $bsAccount->id }})" class="border-t border-gray-100 pt-4">
                        <form method="POST" action="{{ route('bot.addTerm') }}" class="space-y-3">
                            @csrf
                            <input type="hidden" name="social_account_id" value="{{ $bsAccount->id }}">

                            <div class="flex items-center gap-2">
                                <div class="flex-1 flex items-center flex-wrap gap-2 border border-gray-300 rounded-xl px-3 py-2 focus-within:border-indigo-500 focus-within:ring-1 focus-within:ring-indigo-500 min-h-[42px]">
                                    <template x-for="(tag, index) in pendingTags" :key="index">
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-indigo-100 text-indigo-700 rounded-full text-sm">
                                            <span x-text="tag"></span>
                                            <button type="button" @click="pendingTags.splice(index, 1)" class="hover:text-indigo-900">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </span>
                                    </template>
                                    <input type="text"
                                           x-model="inputValue"
                                           @keydown.enter.prevent="addTag"
                                           @keydown.comma.prevent="addTag"
                                           class="flex-1 min-w-[120px] border-0 focus:ring-0 p-0 text-sm placeholder-gray-400"
                                           placeholder="Ajouter un terme de recherche...">
                                </div>
                            </div>

                            {{-- Hidden input to send the term --}}
                            <input type="hidden" name="term" :value="pendingTags.length ? pendingTags[0] : inputValue">

                            <div class="flex items-center gap-4">
                                <div class="flex items-center gap-2">
                                    <label class="text-xs text-gray-600">Max likes/run :</label>
                                    <input type="number" name="max_likes_per_run" value="10" min="1" max="50"
                                           class="w-16 rounded-lg border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                </div>
                                <label class="flex items-center gap-2 text-xs text-gray-600">
                                    <input type="checkbox" name="like_replies" value="1" checked
                                           class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                    Liker les replies
                                </label>
                                <button type="submit" class="ml-auto inline-flex items-center gap-1.5 px-3 py-1.5 bg-indigo-600 text-white text-xs font-medium rounded-lg hover:bg-indigo-700 transition-colors">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                    </svg>
                                    Ajouter
                                </button>
                            </div>
                        </form>
                    </div>

                    {{-- Comptes cibles (prospection) pour ce compte --}}
                    <div class="border-t border-gray-100 pt-4 mt-4">
                        <div class="flex items-center gap-2 mb-3">
                            <svg class="w-4 h-4 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
                            </svg>
                            <h3 class="text-sm font-semibold text-gray-700">Prospection - Comptes cibles</h3>
                        </div>

                        @php
                            $accountTargets = $targetAccounts->where('social_account_id', $bsAccount->id);
                        @endphp

                        @if ($accountTargets->isNotEmpty())
                            <div class="space-y-2 mb-4">
                                @foreach ($accountTargets as $target)
                                    <div class="flex items-center justify-between py-2 px-3 rounded-xl {{ $target->status === 'completed' ? 'bg-green-50' : ($target->status === 'running' ? 'bg-blue-50' : ($target->status === 'paused' ? 'bg-yellow-50' : 'bg-gray-50')) }}"
                                         @if($target->status === 'running')
                                             x-data="prospectProgress({{ $target->id }}, {{ json_encode(['likers' => $target->likers_processed, 'likes' => $target->likes_given, 'follows' => $target->follows_given, 'status' => $target->status]) }})"
                                             x-init="startPolling()"
                                         @endif
                                    >
                                        <div class="flex items-center gap-3 min-w-0 flex-wrap">
                                            <span class="text-sm font-medium text-gray-900 cursor-pointer hover:text-purple-600"
                                                  onclick="document.getElementById('target-handle-{{ $bsAccount->id }}').value = '{{ $target->handle }}'"
                                                  title="Cliquer pour remettre dans le champ">{{ $target->handle }}</span>
                                            @if ($target->status === 'completed')
                                                <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded-full text-[10px] font-medium">Termine</span>
                                            @elseif ($target->status === 'running')
                                                <span class="px-2 py-0.5 bg-blue-100 text-blue-700 rounded-full text-[10px] font-medium animate-pulse" x-text="statusLabel">En cours</span>
                                            @elseif ($target->status === 'paused')
                                                <span class="px-2 py-0.5 bg-yellow-100 text-yellow-700 rounded-full text-[10px] font-medium">En pause</span>
                                            @else
                                                <span class="px-2 py-0.5 bg-gray-100 text-gray-600 rounded-full text-[10px] font-medium">En attente</span>
                                            @endif
                                            @if ($target->status === 'running')
                                                <span class="text-[10px] text-gray-400">
                                                    <span x-text="likers"></span> likers &middot; <span x-text="likes"></span> likes &middot; <span x-text="follows"></span> follows
                                                </span>
                                            @else
                                                <span class="text-[10px] text-gray-400">
                                                    {{ $target->likers_processed }} likers &middot; {{ $target->likes_given }} likes &middot; {{ $target->follows_given }} follows
                                                </span>
                                            @endif
                                        </div>
                                        <div class="flex items-center gap-1">
                                            @if (in_array($target->status, ['pending', 'paused', 'completed']))
                                                <button type="button"
                                                        onclick="fetch('{{ route('bot.runTarget', $target) }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' } }).then(() => location.reload())"
                                                        class="p-1.5 text-indigo-600 hover:text-indigo-800 transition-colors"
                                                        title="{{ $target->status === 'completed' ? 'Relancer' : 'Demarrer' }}">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z" />
                                                    </svg>
                                                </button>
                                            @endif
                                            @if ($target->status === 'running')
                                                <button type="button"
                                                        onclick="fetch('{{ route('bot.stopTarget', $target) }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' } }).then(() => location.reload())"
                                                        class="p-1.5 text-red-600 hover:text-red-800 transition-colors"
                                                        title="Arreter">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 7.5A2.25 2.25 0 0 1 7.5 5.25h9a2.25 2.25 0 0 1 2.25 2.25v9a2.25 2.25 0 0 1-2.25 2.25h-9a2.25 2.25 0 0 1-2.25-2.25v-9Z" />
                                                    </svg>
                                                </button>
                                            @endif
                                            @if ($target->status === 'completed')
                                                <form method="POST" action="{{ route('bot.resetTarget', $target) }}" class="inline">
                                                    @csrf
                                                    <button type="submit" class="p-1.5 text-gray-400 hover:text-gray-600 transition-colors" title="Reinitialiser les compteurs">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182" />
                                                        </svg>
                                                    </button>
                                                </form>
                                            @endif
                                            <form method="POST" action="{{ route('bot.removeTarget', $target) }}" class="inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="p-1.5 text-gray-400 hover:text-red-600 transition-colors" title="Supprimer">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                                    </svg>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        <form method="POST" action="{{ route('bot.addTarget') }}" class="flex items-center gap-3">
                            @csrf
                            <input type="hidden" name="social_account_id" value="{{ $bsAccount->id }}">
                            <div class="flex-1">
                                <input type="text" name="handle" id="target-handle-{{ $bsAccount->id }}"
                                       class="w-full rounded-xl border-gray-300 shadow-sm text-sm focus:border-purple-500 focus:ring-purple-500"
                                       placeholder="handle.bsky.social">
                            </div>
                            <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-2 bg-purple-600 text-white text-xs font-medium rounded-lg hover:bg-purple-700 transition-colors whitespace-nowrap">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                </svg>
                                Ajouter cible
                            </button>
                        </form>
                    </div>
                </div>
                @empty
                    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 text-center">
                        <p class="text-sm text-gray-500">Aucun compte Bluesky connecte. Ajoutez-en un depuis la page
                            <a href="{{ route('platforms.bluesky') }}" class="text-indigo-600 hover:underline">Bluesky</a>.
                        </p>
                    </div>
                @endforelse
            </div>

            {{-- ═══════════ TAB: Facebook ═══════════ --}}
            <div x-show="activeTab === 'facebook'" x-cloak class="mt-6 space-y-6">

                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                    <div class="flex items-center gap-3 mb-1">
                        <svg class="w-5 h-5 text-blue-600" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                        </svg>
                        <h2 class="text-base font-semibold text-gray-900">Auto-like des commentaires Facebook</h2>
                    </div>
                    <p class="text-sm text-gray-500 mb-5">Like automatiquement tous les commentaires recus sur vos pages Facebook. Signal d'engagement positif pour l'algorithme.</p>

                    @forelse ($facebookAccounts as $fbAccount)
                        <div class="flex items-center justify-between py-3 {{ !$loop->last ? 'border-b border-gray-100' : '' }}">
                            <div class="flex items-center gap-3">
                                @if ($fbAccount->profile_picture_url)
                                    <img src="{{ $fbAccount->profile_picture_url }}" class="w-8 h-8 rounded-full" alt="">
                                @endif
                                <div>
                                    <p class="text-sm font-medium text-gray-900">{{ $fbAccount->name }}</p>
                                    <p class="text-xs text-gray-500">Page ID: {{ $fbAccount->credentials['page_id'] ?? 'N/A' }}</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <div x-data="freqSelector('facebook', {{ $fbAccount->id }}, '{{ $botFrequencies["facebook_{$fbAccount->id}"] ?? 'every_30_min' }}')">
                                    <select x-model="freq" @change="save()" class="rounded-lg border-gray-300 text-xs py-1.5 pr-8 focus:border-indigo-500 focus:ring-indigo-500">
                                        <option value="disabled">Desactive</option>
                                        <option value="every_15_min">Toutes les 15 min</option>
                                        <option value="every_30_min">Toutes les 30 min</option>
                                        <option value="hourly">Toutes les heures</option>
                                        <option value="every_2_hours">Toutes les 2h</option>
                                        <option value="every_6_hours">Toutes les 6h</option>
                                        <option value="every_12_hours">Toutes les 12h</option>
                                        <option value="daily">1x par jour</option>
                                    </select>
                                </div>
                                <div x-data="botButton('facebook', {{ $fbAccount->id }}, {{ ($botActiveStates["facebook_{$fbAccount->id}"] ?? false) ? 'true' : 'false' }}, '{{ route('bot.runFacebook') }}')" x-init="init()">
                                    <template x-if="!active">
                                        <button type="button" @click="activate()" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-600 text-white text-xs font-medium rounded-lg hover:bg-blue-700 transition-colors">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.633 10.25c.806 0 1.533-.446 2.031-1.08a9.041 9.041 0 0 1 2.861-2.4c.723-.384 1.35-.956 1.653-1.715a4.498 4.498 0 0 0 .322-1.672V2.75a.75.75 0 0 1 .75-.75 2.25 2.25 0 0 1 2.25 2.25c0 1.152-.26 2.243-.723 3.218-.266.558.107 1.282.725 1.282m0 0h3.126c1.026 0 1.945.694 2.054 1.715.045.422.068.85.068 1.285a11.95 11.95 0 0 1-2.649 7.521c-.388.482-.987.729-1.605.729H13.48c-.483 0-.964-.078-1.423-.23l-3.114-1.04a4.501 4.501 0 0 0-1.423-.23H5.904m7.846 2.354-3.114-1.04a4.501 4.501 0 0 0-1.423-.23H3.75a.75.75 0 0 1-.75-.75V8.322a.75.75 0 0 1 .75-.75h2.154c.489 0 .954-.21 1.282-.579l.218-.273a4.5 4.5 0 0 0 .729-3.469" />
                                            </svg>
                                            Activer le bot
                                        </button>
                                    </template>
                                    <template x-if="active">
                                        <div class="flex items-center gap-2">
                                            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg"
                                                  :class="running ? 'bg-green-100 text-green-700' : 'bg-emerald-50 text-emerald-600'">
                                                <template x-if="running">
                                                    <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                                    </svg>
                                                </template>
                                                <template x-if="!running">
                                                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                                </template>
                                                <span x-text="running ? 'En cours...' : 'Actif'"></span>
                                            </span>
                                            <button type="button" @click="stop()" class="inline-flex items-center gap-1 px-2 py-1.5 bg-red-100 text-red-700 text-xs font-medium rounded-lg hover:bg-red-200 transition-colors">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 7.5A2.25 2.25 0 0 1 7.5 5.25h9a2.25 2.25 0 0 1 2.25 2.25v9a2.25 2.25 0 0 1-2.25 2.25h-9a2.25 2.25 0 0 1-2.25-2.25v-9Z" />
                                                </svg>
                                                Désactiver
                                            </button>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500 text-center py-4">
                            Aucune page Facebook connectee. Ajoutez-en une depuis la page
                            <a href="{{ route('platforms.facebook') }}" class="text-indigo-600 hover:underline">Facebook</a>.
                        </p>
                    @endforelse
                </div>
            </div>

            {{-- ═══════════ TAB: Historique ═══════════ --}}
            <div x-show="activeTab === 'logs'" x-cloak class="mt-6 space-y-4">

                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-900">Dernieres actions ({{ $logs->count() }})</h2>
                    @if ($logs->isNotEmpty())
                        <form method="POST" action="{{ route('bot.clearLogs') }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs text-red-600 hover:text-red-700 font-medium"
                                    onclick="return confirm('Vider tout l\'historique ?')">
                                Vider l'historique
                            </button>
                        </form>
                    @endif
                </div>

                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                    @forelse ($logs as $log)
                        <div class="flex items-start gap-3 px-4 py-3 {{ !$loop->last ? 'border-b border-gray-50' : '' }}">
                            {{-- Icon --}}
                            @if ($log->success)
                                <span class="mt-0.5 w-5 h-5 rounded-full bg-green-100 text-green-600 flex items-center justify-center flex-shrink-0">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                    </svg>
                                </span>
                            @else
                                <span class="mt-0.5 w-5 h-5 rounded-full bg-red-100 text-red-600 flex items-center justify-center flex-shrink-0">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </span>
                            @endif

                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 text-xs">
                                    <span class="font-medium text-gray-900">
                                        @switch($log->action_type)
                                            @case('like_post') Like post @break
                                            @case('like_reply') Like reply @break
                                            @case('like_back') Like-back @break
                                            @case('prospect_like') Prospect like @break
                                            @case('prospect_follow') Prospect follow @break
                                            @case('like_comment') Like commentaire @break
                                            @default {{ $log->action_type }}
                                        @endswitch
                                    </span>
                                    @if ($log->target_author)
                                        <span class="text-gray-400">par</span>
                                        <span class="text-indigo-600 font-medium">{{ $log->target_author }}</span>
                                    @endif
                                    @if ($log->search_term)
                                        <span class="px-1.5 py-0.5 bg-gray-100 text-gray-600 rounded text-[10px]">{{ $log->search_term }}</span>
                                    @endif
                                    <span class="text-gray-400 ml-auto flex-shrink-0">{{ $log->created_at->diffForHumans() }}</span>
                                </div>
                                @if ($log->target_text)
                                    <p class="text-xs text-gray-500 mt-0.5 truncate">{{ Str::limit($log->target_text, 120) }}</p>
                                @endif
                                @if ($log->error)
                                    <p class="text-xs text-red-500 mt-0.5">{{ $log->error }}</p>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="p-8 text-center">
                            <p class="text-sm text-gray-500">Aucune action enregistree.</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
function termInput(accountId) {
    return {
        pendingTags: [],
        inputValue: '',

        addTag() {
            const value = this.inputValue.trim().toLowerCase();
            if (value && !this.pendingTags.includes(value)) {
                this.pendingTags.push(value);
                this.inputValue = '';
            }
        }
    };
}

function freqSelector(platform, accountId, initial) {
    return {
        freq: initial,
        save() {
            fetch('{{ route('bot.updateFrequency') }}', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' },
                body: JSON.stringify({ platform, account_id: accountId, frequency: this.freq })
            });
        }
    };
}

function botButton(platform, accountId, initialActive, runUrl) {
    return {
        active: initialActive,
        running: false,
        interval: null,

        init() {
            // Check current execution status
            this.checkStatus();
            // Poll periodically if bot is active
            if (this.active) {
                this.startPolling();
            }
        },

        checkStatus() {
            fetch(`{{ route('bot.status') }}?platform=${platform}&account_id=${accountId}`)
                .then(r => r.json())
                .then(data => {
                    this.active = data.active;
                    this.running = data.running;
                })
                .catch(() => {});
        },

        activate() {
            this.active = true;
            this.running = true;
            this.startPolling();
            // Trigger the bot run via AJAX
            fetch(runUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' },
                body: JSON.stringify({ social_account_id: accountId })
            }).catch(() => {});
        },

        startPolling() {
            if (this.interval) return;
            this.interval = setInterval(() => {
                this.checkStatus();
            }, 5000);
        },

        stop() {
            fetch('{{ route('bot.stop') }}', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' },
                body: JSON.stringify({ platform, account_id: accountId })
            }).then(() => {
                this.active = false;
                this.running = false;
                if (this.interval) {
                    clearInterval(this.interval);
                    this.interval = null;
                }
            });
        }
    };
}

function prospectProgress(targetId, initial) {
    return {
        likers: initial.likers,
        likes: initial.likes,
        follows: initial.follows,
        status: initial.status,
        statusLabel: 'En cours',
        timer: null,

        startPolling() {
            this.timer = setInterval(() => this.poll(), 10000);
        },

        async poll() {
            try {
                const resp = await fetch('/bot/target-status/' + targetId, {
                    headers: { 'Accept': 'application/json' },
                });
                const data = await resp.json();
                this.likers = data.likers_processed;
                this.likes = data.likes_given;
                this.follows = data.follows_given;
                this.status = data.status;

                if (data.status !== 'running') {
                    clearInterval(this.timer);
                    setTimeout(() => location.reload(), 1000);
                }
            } catch (e) { /* silent */ }
        },

        destroy() {
            if (this.timer) clearInterval(this.timer);
        }
    }
}
</script>
@endpush
