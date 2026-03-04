@extends('layouts.app')

@section('title', 'Messagerie')

@section('content')
    <div x-data="inboxManager()" class="space-y-6">

        {{-- KPI cards --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                <p class="text-3xl font-bold text-gray-900">{{ number_format($counts['total']) }}</p>
                <p class="text-sm text-gray-500 mt-1">Total</p>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                <p class="text-3xl font-bold text-indigo-600">{{ number_format($counts['unread']) }}</p>
                <p class="text-sm text-gray-500 mt-1">Non lus</p>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                <p class="text-3xl font-bold text-gray-900">{{ number_format($counts['read']) }}</p>
                <p class="text-sm text-gray-500 mt-1">Lus</p>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                <p class="text-3xl font-bold text-green-600">{{ number_format($counts['replied']) }}</p>
                <p class="text-sm text-gray-500 mt-1">Répondus</p>
            </div>
        </div>

        {{-- Filters + Actions --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4">
            <div class="flex flex-wrap items-center gap-3">
                {{-- Status pills --}}
                <div class="flex items-center gap-1 bg-gray-100 rounded-xl p-1">
                    <a href="{{ route('inbox.index') }}" class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors {{ !request('status') ? 'bg-white text-indigo-600 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}">Tous</a>
                    <a href="{{ route('inbox.index', ['status' => 'unread'] + request()->except('status', 'page')) }}" class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors {{ request('status') === 'unread' ? 'bg-white text-indigo-600 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}">Non lus</a>
                    <a href="{{ route('inbox.index', ['status' => 'read'] + request()->except('status', 'page')) }}" class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors {{ request('status') === 'read' ? 'bg-white text-indigo-600 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}">Lus</a>
                    <a href="{{ route('inbox.index', ['status' => 'replied'] + request()->except('status', 'page')) }}" class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors {{ request('status') === 'replied' ? 'bg-white text-green-600 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}">Répondus</a>
                    <a href="{{ route('inbox.index', ['status' => 'archived'] + request()->except('status', 'page')) }}" class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors {{ request('status') === 'archived' ? 'bg-white text-gray-600 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}">Archivés</a>
                </div>

                {{-- Type filter --}}
                <select onchange="window.location.href=this.value || '{{ route('inbox.index', request()->except('type', 'page')) }}'" class="rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="{{ route('inbox.index', request()->except('type', 'page')) }}">Tous types</option>
                    <option value="{{ route('inbox.index', ['type' => 'comment'] + request()->except('type', 'page')) }}" {{ request('type') === 'comment' ? 'selected' : '' }}>Commentaires</option>
                    <option value="{{ route('inbox.index', ['type' => 'reply'] + request()->except('type', 'page')) }}" {{ request('type') === 'reply' ? 'selected' : '' }}>Réponses</option>
                    <option value="{{ route('inbox.index', ['type' => 'dm'] + request()->except('type', 'page')) }}" {{ request('type') === 'dm' ? 'selected' : '' }}>Messages privés</option>
                </select>

                {{-- Platform filter --}}
                <select onchange="window.location.href=this.value || '{{ route('inbox.index', request()->except('platform', 'page')) }}'" class="rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="{{ route('inbox.index', request()->except('platform', 'page')) }}">Toutes plateformes</option>
                    @foreach(['facebook', 'instagram', 'threads', 'youtube', 'bluesky', 'telegram', 'reddit'] as $slug)
                        <option value="{{ route('inbox.index', ['platform' => $slug] + request()->except('platform', 'page')) }}" {{ request('platform') === $slug ? 'selected' : '' }}>{{ ucfirst($slug) }}</option>
                    @endforeach
                </select>

                {{-- Spacer --}}
                <div class="flex-1"></div>

                {{-- Admin sync button --}}
                @if(auth()->user()->is_admin)
                    <button @click="syncInbox()" :disabled="syncing" class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors disabled:opacity-50">
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
        </div>

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

        {{-- Items list --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            @if($items->count() > 0)
                <div class="divide-y divide-gray-100">
                    @foreach($items as $item)
                        <div class="p-4 flex items-start gap-3 hover:bg-gray-50/60 transition-colors"
                             :class="{ 'bg-indigo-50/30': selectedIds.includes({{ $item->id }}) }">

                            {{-- Checkbox --}}
                            <input type="checkbox" class="mt-1 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                   @change="toggleSelect({{ $item->id }})" :checked="selectedIds.includes({{ $item->id }})">

                            {{-- Platform icon --}}
                            <div class="flex-shrink-0 mt-0.5">
                                <x-platform-icon :platform="$item->platform->slug" size="sm" />
                            </div>

                            {{-- Content --}}
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-1">
                                    <span class="font-medium text-sm text-gray-900">{{ $item->author_name ?: $item->author_username ?: 'Anonyme' }}</span>
                                    @if($item->author_username && $item->author_name && $item->author_username !== $item->author_name)
                                        <span class="text-xs text-gray-400">@{{ $item->author_username }}</span>
                                    @endif
                                    <span class="text-xs text-gray-400">{{ $item->posted_at?->diffForHumans() }}</span>
                                    @if($item->type === 'dm')
                                        <span class="px-1.5 py-0.5 rounded-full bg-purple-100 text-purple-700 text-xs font-medium">MP</span>
                                    @elseif($item->type === 'reply')
                                        <span class="px-1.5 py-0.5 rounded-full bg-blue-100 text-blue-700 text-xs font-medium">Réponse</span>
                                    @endif
                                    @if($item->status === 'unread')
                                        <span class="w-2 h-2 rounded-full bg-indigo-500 flex-shrink-0"></span>
                                    @endif
                                    @if($item->status === 'replied')
                                        <span class="px-1.5 py-0.5 rounded-full bg-green-100 text-green-700 text-xs font-medium">Répondu</span>
                                    @endif
                                </div>
                                <p class="text-sm text-gray-700 line-clamp-2">{{ $item->content }}</p>
                                @if($item->reply_content)
                                    <div class="mt-2 pl-3 border-l-2 border-indigo-200">
                                        <p class="text-xs text-indigo-600 font-medium mb-0.5">Votre réponse :</p>
                                        <p class="text-sm text-gray-600 line-clamp-2">{{ $item->reply_content }}</p>
                                    </div>
                                @endif
                                <div class="mt-1 text-xs text-gray-400">
                                    {{ $item->socialAccount->name }}
                                </div>
                            </div>

                            {{-- Actions --}}
                            <div class="flex items-center gap-1 flex-shrink-0">
                                @if($item->status !== 'replied')
                                    <button @click="openReplyModal({{ $item->id }}, {{ json_encode($item->content) }}, {{ json_encode($item->author_name ?: $item->author_username) }})"
                                            class="p-1.5 text-gray-400 hover:text-indigo-600 transition-colors rounded-lg hover:bg-indigo-50" title="Répondre">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                                        </svg>
                                    </button>
                                    <button @click="generateAiSingle({{ $item->id }})"
                                            class="p-1.5 text-gray-400 hover:text-indigo-600 transition-colors rounded-lg hover:bg-indigo-50" title="Suggestion IA">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 0 0-2.455 2.456Z" />
                                        </svg>
                                    </button>
                                @endif
                                @if($item->post_url)
                                    <a href="{{ $item->post_url }}" target="_blank" class="p-1.5 text-gray-400 hover:text-gray-600 transition-colors rounded-lg hover:bg-gray-100" title="Voir sur la plateforme">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                        </svg>
                                    </a>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="p-12 text-center">
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

        {{-- Pagination --}}
        @if($items->hasPages())
            <div class="mt-4">
                {{ $items->withQueryString()->links() }}
            </div>
        @endif

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

                <div class="p-6 border-t border-gray-100 flex justify-end gap-2">
                    <button @click="bulkModalOpen = false" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-xl hover:bg-gray-200 transition-colors">Annuler</button>
                    <button @click="sendBulkReplies()" :disabled="bulkSending" class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-xl hover:bg-indigo-700 transition-colors disabled:opacity-50">
                        <span x-show="!bulkSending">Envoyer tout</span>
                        <span x-show="bulkSending" x-cloak>Envoi...</span>
                    </button>
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

        csrfToken: document.querySelector('meta[name="csrf-token"]').getAttribute('content'),

        toggleSelect(id) {
            const idx = this.selectedIds.indexOf(id);
            if (idx > -1) {
                this.selectedIds.splice(idx, 1);
            } else {
                this.selectedIds.push(id);
            }
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

            // Find the item content from the DOM
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

            try {
                const resp = await fetch('/inbox/bulk-send', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ items }),
                });
                const data = await resp.json();

                this.bulkModalOpen = false;
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
                const resp = await fetch('/inbox/sync', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'Accept': 'application/json',
                    },
                });
                const data = await resp.json();

                location.reload();
            } catch (e) {
                alert('Erreur de connexion');
            } finally {
                this.syncing = false;
            }
        },
    }
}
</script>
@endpush
