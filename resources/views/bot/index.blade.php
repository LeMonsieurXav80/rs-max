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
        <div class="grid grid-cols-2 sm:grid-cols-5 lg:grid-cols-10 gap-3">
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-3 text-center">
                <p class="text-xl font-bold text-indigo-600">{{ $todayStats->get('like_post', 0) }}</p>
                <p class="text-[10px] text-gray-500 mt-1">Posts (BS)</p>
            </div>
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-3 text-center">
                <p class="text-xl font-bold text-indigo-600">{{ $todayStats->get('like_reply', 0) }}</p>
                <p class="text-[10px] text-gray-500 mt-1">Replies (BS)</p>
            </div>
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-3 text-center">
                <p class="text-xl font-bold text-purple-600">{{ $todayStats->get('like_back', 0) }}</p>
                <p class="text-[10px] text-gray-500 mt-1">Like-backs</p>
            </div>
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-3 text-center">
                <p class="text-xl font-bold text-teal-600">{{ $todayStats->get('like_own_comment', 0) }}</p>
                <p class="text-[10px] text-gray-500 mt-1">Comm. own</p>
            </div>
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-3 text-center">
                <p class="text-xl font-bold text-cyan-600">{{ $todayStats->get('like_feed', 0) }}</p>
                <p class="text-[10px] text-gray-500 mt-1">Feed likes</p>
            </div>
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-3 text-center">
                <p class="text-xl font-bold text-orange-600">{{ $todayStats->get('unfollow', 0) }}</p>
                <p class="text-[10px] text-gray-500 mt-1">Unfollows</p>
            </div>
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-3 text-center">
                <p class="text-xl font-bold text-purple-600">{{ $todayStats->get('prospect_like', 0) }}</p>
                <p class="text-[10px] text-gray-500 mt-1">Prospect likes</p>
            </div>
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-3 text-center">
                <p class="text-xl font-bold text-purple-600">{{ $todayStats->get('prospect_follow', 0) }}</p>
                <p class="text-[10px] text-gray-500 mt-1">Prospect follows</p>
            </div>
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-3 text-center">
                <p class="text-xl font-bold text-blue-600">{{ $todayStats->get('like_comment', 0) }}</p>
                <p class="text-[10px] text-gray-500 mt-1">Comm. (FB)</p>
            </div>
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-3 text-center">
                <p class="text-xl font-bold text-gray-900">{{ $todayStats->values()->sum() }}</p>
                <p class="text-[10px] text-gray-500 mt-1">Total</p>
            </div>
        </div>

        {{-- Tabs --}}
        <div x-data="{ activeTab: new URLSearchParams(window.location.search).get('tab') || 'bluesky' }">

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
                                <p class="text-xs text-gray-500">{{ rescue(fn () => $bsAccount->credentials['handle'] ?? '', '') }}</p>
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

                    {{-- Options supplementaires --}}
                    <div class="border-t border-gray-100 pt-4 mt-4">
                        <div class="flex items-center gap-2 mb-3">
                            <svg class="w-4 h-4 text-teal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                            </svg>
                            <h3 class="text-sm font-semibold text-gray-700">Options automatiques</h3>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            {{-- Like comments on own posts --}}
                            <div x-data="botToggle('like_comments', {{ $bsAccount->id }}, {{ \App\Models\Setting::get("bot_like_comments_bluesky_{$bsAccount->id}") === '1' ? 'true' : 'false' }})"
                                 class="border rounded-xl p-3 transition-colors"
                                 :class="enabled ? 'border-teal-200 bg-teal-50/30' : 'border-gray-200'">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" x-model="enabled" @change="save()"
                                           class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                                    <div>
                                        <p class="text-xs font-medium text-gray-900">Liker les commentaires</p>
                                        <p class="text-[10px] text-gray-500">Like les replies sur vos posts</p>
                                    </div>
                                </label>
                            </div>

                            {{-- Random feed likes --}}
                            <div x-data="botToggle('feed_likes', {{ $bsAccount->id }}, {{ \App\Models\Setting::get("bot_feed_likes_bluesky_{$bsAccount->id}") === '1' ? 'true' : 'false' }})"
                                 class="border rounded-xl p-3 transition-colors"
                                 :class="enabled ? 'border-cyan-200 bg-cyan-50/30' : 'border-gray-200'">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" x-model="enabled" @change="save()"
                                           class="rounded border-gray-300 text-cyan-600 focus:ring-cyan-500">
                                    <div>
                                        <p class="text-xs font-medium text-gray-900">Liker le feed</p>
                                        <p class="text-[10px] text-gray-500">Like random dans votre timeline</p>
                                    </div>
                                </label>
                            </div>

                            {{-- Unfollow non-followers --}}
                            <div x-data="botToggle('unfollow', {{ $bsAccount->id }}, {{ \App\Models\Setting::get("bot_unfollow_bluesky_{$bsAccount->id}") === '1' ? 'true' : 'false' }})"
                                 class="border rounded-xl p-3 transition-colors"
                                 :class="enabled ? 'border-orange-200 bg-orange-50/30' : 'border-gray-200'">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" x-model="enabled" @change="save()"
                                           class="rounded border-gray-300 text-orange-600 focus:ring-orange-500">
                                    <div>
                                        <p class="text-xs font-medium text-gray-900">Unfollow non-followers</p>
                                        <p class="text-[10px] text-gray-500">Defollow ceux qui ne follow pas (grace 7j)</p>
                                    </div>
                                </label>
                                {{-- Max unfollows per run --}}
                                <div x-show="enabled" x-data="{ max: {{ $botUnfollowMax['bluesky_' . $bsAccount->id] ?? 10 }}, saved: false }" class="mt-2 flex items-center gap-2">
                                    <label class="text-[10px] text-gray-500 whitespace-nowrap">Max / run :</label>
                                    <input type="number" x-model="max" min="1" max="100"
                                           class="w-16 rounded-lg border-gray-300 text-xs py-1 px-2"
                                           @change="
                                            fetch('{{ route('bot.updateUnfollowMax') }}', {
                                                method: 'POST',
                                                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                                                body: JSON.stringify({ account_id: {{ $bsAccount->id }}, max: parseInt(max) })
                                            }).then(() => { saved = true; setTimeout(() => saved = false, 1500) })
                                           ">
                                    <span x-show="saved" class="text-[10px] text-green-600">Sauvegarde</span>
                                </div>
                            </div>
                        </div>
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
                                    <div class="flex items-center justify-between py-2 px-3 rounded-xl"
                                         :class="bgClass"
                                         x-data="targetRow({{ $target->id }}, {{ json_encode([
                                             'status' => $target->status,
                                             'likers' => $target->likers_processed,
                                             'likes' => $target->likes_given,
                                             'follows' => $target->follows_given,
                                             'runUrl' => route('bot.runTarget', $target),
                                             'stopUrl' => route('bot.stopTarget', $target),
                                             'removeUrl' => route('bot.removeTarget', $target),
                                         ]) }})">
                                        <div class="flex items-center gap-3 min-w-0 flex-wrap">
                                            <span class="text-sm font-medium text-gray-900 cursor-pointer hover:text-purple-600"
                                                  onclick="document.getElementById('target-handle-{{ $bsAccount->id }}').value = '{{ $target->handle }}'"
                                                  title="Cliquer pour remettre dans le champ">{{ $target->handle }}</span>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-medium" :class="badgeClass" x-text="badgeLabel"></span>
                                            <span class="text-[10px] text-gray-400">
                                                <span x-text="likers"></span> likers &middot; <span x-text="likes"></span> likes &middot; <span x-text="follows"></span> follows
                                            </span>
                                        </div>
                                        <div class="flex items-center gap-1">
                                            {{-- Play button --}}
                                            <button type="button" x-show="canPlay" x-cloak @click="play()"
                                                    class="p-1.5 text-indigo-600 hover:text-indigo-800 transition-colors" title="Lancer">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z" />
                                                </svg>
                                            </button>
                                            {{-- Stop button --}}
                                            <button type="button" x-show="canStop" x-cloak @click="stop()"
                                                    class="p-1.5 text-red-600 hover:text-red-800 transition-colors" title="Arreter">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 7.5A2.25 2.25 0 0 1 7.5 5.25h9a2.25 2.25 0 0 1 2.25 2.25v9a2.25 2.25 0 0 1-2.25 2.25h-9a2.25 2.25 0 0 1-2.25-2.25v-9Z" />
                                                </svg>
                                            </button>
                                            {{-- Delete button --}}
                                            <button type="button" x-show="canDelete" x-cloak @click="remove()"
                                                    class="p-1.5 text-gray-400 hover:text-red-600 transition-colors" title="Supprimer">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </button>
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
                                    <p class="text-xs text-gray-500">Page ID: {{ rescue(fn () => $fbAccount->credentials['page_id'] ?? 'N/A', 'N/A') }}</p>
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

                <div class="flex flex-wrap items-center gap-3 justify-between">
                    <h2 class="text-sm font-semibold text-gray-900">
                        Historique 7 jours
                        <span class="text-gray-400 font-normal">({{ $logs->count() }} actions)</span>
                    </h2>
                    <div class="flex items-center gap-2 flex-wrap">
                        {{-- Filters --}}
                        <form method="GET" action="{{ route('bot.index') }}" class="flex items-center gap-2">
                            <input type="hidden" name="tab" value="logs">
                            <select name="log_account" class="rounded-lg border-gray-300 text-xs py-1.5" onchange="this.form.submit()">
                                <option value="">Tous les comptes</option>
                                @foreach($allBotAccounts as $acc)
                                <option value="{{ $acc->id }}" {{ $filterAccount == $acc->id ? 'selected' : '' }}>{{ $acc->name }}</option>
                                @endforeach
                            </select>
                            <select name="log_type" class="rounded-lg border-gray-300 text-xs py-1.5" onchange="this.form.submit()">
                                <option value="">Tous les types</option>
                                <option value="like_post" {{ $filterType === 'like_post' ? 'selected' : '' }}>Like post</option>
                                <option value="like_reply" {{ $filterType === 'like_reply' ? 'selected' : '' }}>Like reply</option>
                                <option value="like_back" {{ $filterType === 'like_back' ? 'selected' : '' }}>Like-back</option>
                                <option value="like_feed" {{ $filterType === 'like_feed' ? 'selected' : '' }}>Like feed</option>
                                <option value="like_own_comment" {{ $filterType === 'like_own_comment' ? 'selected' : '' }}>Like commentaire</option>
                                <option value="unfollow" {{ $filterType === 'unfollow' ? 'selected' : '' }}>Unfollow</option>
                                <option value="follow_active_user" {{ $filterType === 'follow_active_user' ? 'selected' : '' }}>Follow</option>
                                <option value="prospect_like" {{ $filterType === 'prospect_like' ? 'selected' : '' }}>Prospect like</option>
                                <option value="prospect_follow" {{ $filterType === 'prospect_follow' ? 'selected' : '' }}>Prospect follow</option>
                                <option value="like_comment" {{ $filterType === 'like_comment' ? 'selected' : '' }}>Like commentaire FB</option>
                            </select>
                            @if($filterAccount || $filterType)
                            <a href="{{ route('bot.index') }}" class="text-xs text-gray-500 hover:text-gray-700">Effacer</a>
                            @endif
                        </form>
                        @if ($logs->isNotEmpty())
                        <form method="POST" action="{{ route('bot.clearLogs') }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs text-red-500 hover:text-red-700 font-medium"
                                    onclick="return confirm('Vider tout l\'historique ?')">
                                Vider
                            </button>
                        </form>
                        @endif
                    </div>
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
                                <div class="flex items-center gap-2 text-xs flex-wrap">
                                    {{-- Action type badge --}}
                                    <span class="font-semibold
                                        @switch($log->action_type)
                                            @case('unfollow') text-orange-600 @break
                                            @case('follow_active_user') text-green-600 @break
                                            @case('prospect_follow') text-purple-600 @break
                                            @case('prospect_like') text-purple-500 @break
                                            @case('like_back') text-blue-600 @break
                                            @default text-gray-800
                                        @endswitch
                                    ">
                                        @switch($log->action_type)
                                            @case('like_post') Like post @break
                                            @case('like_reply') Like reply @break
                                            @case('like_back') Like-back @break
                                            @case('like_own_comment') Like comm. @break
                                            @case('like_feed') Like feed @break
                                            @case('unfollow') Unfollow @break
                                            @case('follow_active_user') Follow @break
                                            @case('prospect_like') Prospect like @break
                                            @case('prospect_follow') Prospect follow @break
                                            @case('like_comment') Like comm. FB @break
                                            @default {{ $log->action_type }}
                                        @endswitch
                                    </span>
                                    @if ($log->target_author)
                                        <span class="text-indigo-600 font-medium truncate max-w-[150px]">{{ $log->target_author }}</span>
                                    @endif
                                    @if ($log->search_term)
                                        <span class="px-1.5 py-0.5 bg-gray-100 text-gray-600 rounded text-[10px]">{{ $log->search_term }}</span>
                                    @endif
                                    {{-- Account badge --}}
                                    <span class="px-1.5 py-0.5 bg-indigo-50 text-indigo-500 rounded text-[10px] flex-shrink-0">
                                        {{ $log->socialAccount?->name ?? '—' }}
                                    </span>
                                    {{-- Timestamp precise --}}
                                    <span class="text-gray-400 ml-auto flex-shrink-0 font-mono text-[10px]"
                                          title="{{ $log->created_at->format('d/m/Y H:i:s') }}">
                                        {{ $log->created_at->format('d/m H:i') }}
                                    </span>
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

// Central status manager — single batch request for all bot buttons
const botStatusManager = {
    listeners: {},
    polling: false,
    interval: null,

    register(key, callback) {
        this.listeners[key] = callback;
        if (!this.polling) {
            this.polling = true;
            this.fetchAll();
            this.interval = setInterval(() => this.fetchAll(), 10000);
        }
    },

    fetchAll() {
        const accounts = Object.keys(this.listeners).map(key => {
            const [platform, account_id] = key.split('_', 2);
            return { platform, account_id };
        });
        if (accounts.length === 0) return;

        fetch('{{ route('bot.statusBatch') }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify({ accounts })
        })
        .then(r => r.json())
        .then(data => {
            for (const [key, status] of Object.entries(data)) {
                if (this.listeners[key]) {
                    this.listeners[key](status);
                }
            }
        })
        .catch(() => {});
    }
};

function botToggle(feature, accountId, initial) {
    return {
        enabled: initial,
        save() {
            fetch('{{ route('bot.updateOption') }}', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' },
                body: JSON.stringify({ feature, account_id: accountId, enabled: this.enabled })
            });
        }
    };
}

function botButton(platform, accountId, initialActive, runUrl) {
    return {
        active: initialActive,
        running: false,

        init() {
            const key = `${platform}_${accountId}`;
            botStatusManager.register(key, (status) => {
                this.active = status.active;
                this.running = status.running;
            });
        },

        activate() {
            this.active = true;
            this.running = true;
            fetch(runUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' },
                body: JSON.stringify({ social_account_id: accountId })
            }).catch(() => {});
        },

        stop() {
            fetch('{{ route('bot.stop') }}', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' },
                body: JSON.stringify({ platform, account_id: accountId })
            }).then(() => {
                this.active = false;
                this.running = false;
            });
        }
    };
}

function targetRow(targetId, opts) {
    return {
        status: opts.status,
        likers: opts.likers,
        likes: opts.likes,
        follows: opts.follows,
        timer: null,

        get bgClass() {
            return {
                'bg-green-50': this.status === 'completed',
                'bg-blue-50': this.status === 'running',
                'bg-yellow-50': this.status === 'paused',
                'bg-gray-50': !['completed','running','paused'].includes(this.status),
            };
        },
        get badgeClass() {
            return {
                'bg-green-100 text-green-700': this.status === 'completed',
                'bg-blue-100 text-blue-700 animate-pulse': this.status === 'running',
                'bg-yellow-100 text-yellow-700': this.status === 'paused',
                'bg-indigo-100 text-indigo-700 animate-pulse': this.status === 'starting',
                'bg-orange-100 text-orange-700 animate-pulse': this.status === 'stopping',
                'bg-gray-100 text-gray-600': this.status === 'pending',
            };
        },
        get badgeLabel() {
            return { pending: 'En attente', running: 'En cours', paused: 'En pause', completed: 'Termine', starting: 'Lancement...', stopping: 'Arret...' }[this.status] || this.status;
        },
        get canPlay() {
            return ['pending', 'paused', 'completed'].includes(this.status);
        },
        get canStop() {
            return ['running', 'starting'].includes(this.status);
        },
        get canDelete() {
            return !['running', 'starting', 'stopping'].includes(this.status);
        },

        init() {
            if (['running', 'starting', 'stopping'].includes(this.status)) {
                this.startPolling();
            }
        },

        play() {
            this.status = 'starting';
            this.startPolling();
            fetch(opts.runUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' },
            }).catch(() => {});
        },

        stop() {
            this.status = 'stopping';
            fetch(opts.stopUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' },
            }).catch(() => {});
        },

        remove() {
            if (!confirm('Supprimer ce compte cible ?')) return;
            fetch(opts.removeUrl, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' },
            }).then(() => this.$el.remove());
        },

        startPolling() {
            if (this.timer) return;
            this.timer = setInterval(() => this.poll(), 8000);
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

                const prev = this.status;

                // Don't overwrite transitional states until DB reflects the change
                if (prev === 'stopping' && data.status === 'running') {
                    // Still waiting for service to process the stop signal
                } else if (prev === 'starting' && data.status === 'pending') {
                    // Still waiting for process to start
                } else {
                    this.status = data.status;
                }

                // Stop polling when we've settled into a final state
                if (['completed', 'paused'].includes(this.status)) {
                    clearInterval(this.timer);
                    this.timer = null;
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
