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
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-4">
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
                <p class="text-2xl font-bold text-blue-600">{{ $todayStats->get('like_comment', 0) }}</p>
                <p class="text-xs text-gray-500 mt-1">Commentaires likes (FB)</p>
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
                        </div>
                        <form method="POST" action="{{ route('bot.runBluesky') }}">
                            @csrf
                            <input type="hidden" name="social_account_id" value="{{ $bsAccount->id }}">
                            <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-indigo-600 text-white text-xs font-medium rounded-lg hover:bg-indigo-700 transition-colors">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z" />
                                </svg>
                                Lancer maintenant
                            </button>
                        </form>
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
                            <form method="POST" action="{{ route('bot.runFacebook') }}">
                                @csrf
                                <input type="hidden" name="social_account_id" value="{{ $fbAccount->id }}">
                                <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-600 text-white text-xs font-medium rounded-lg hover:bg-blue-700 transition-colors">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.633 10.25c.806 0 1.533-.446 2.031-1.08a9.041 9.041 0 0 1 2.861-2.4c.723-.384 1.35-.956 1.653-1.715a4.498 4.498 0 0 0 .322-1.672V2.75a.75.75 0 0 1 .75-.75 2.25 2.25 0 0 1 2.25 2.25c0 1.152-.26 2.243-.723 3.218-.266.558.107 1.282.725 1.282m0 0h3.126c1.026 0 1.945.694 2.054 1.715.045.422.068.85.068 1.285a11.95 11.95 0 0 1-2.649 7.521c-.388.482-.987.729-1.605.729H13.48c-.483 0-.964-.078-1.423-.23l-3.114-1.04a4.501 4.501 0 0 0-1.423-.23H5.904m7.846 2.354-3.114-1.04a4.501 4.501 0 0 0-1.423-.23H3.75a.75.75 0 0 1-.75-.75V8.322a.75.75 0 0 1 .75-.75h2.154c.489 0 .954-.21 1.282-.579l.218-.273a4.5 4.5 0 0 0 .729-3.469" />
                                    </svg>
                                    Liker les commentaires
                                </button>
                            </form>
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
</script>
@endpush
