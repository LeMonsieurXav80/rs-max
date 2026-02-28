@extends('layouts.app')

@section('title', $thread->title ?: 'Fil #' . $thread->id)

@section('content')
    <div class="max-w-4xl space-y-6" x-data="threadShow()">
        {{-- Header --}}
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ $thread->title ?: 'Fil #' . $thread->id }}</h1>
                <div class="mt-1 flex items-center gap-3 text-sm text-gray-500">
                    <span>{{ $thread->segments->count() }} segments</span>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                        {{ match($thread->status) {
                            'draft' => 'bg-gray-100 text-gray-700',
                            'scheduled' => 'bg-blue-100 text-blue-700',
                            'publishing' => 'bg-yellow-100 text-yellow-700',
                            'published' => 'bg-green-100 text-green-700',
                            'partial' => 'bg-orange-100 text-orange-700',
                            'failed' => 'bg-red-100 text-red-700',
                            default => 'bg-gray-100 text-gray-700',
                        } }}">
                        {{ ucfirst($thread->status) }}
                    </span>
                    @if($thread->source_url)
                        <a href="{{ $thread->source_url }}" target="_blank" rel="noopener"
                           class="text-indigo-600 hover:text-indigo-700 truncate max-w-xs">
                            {{ parse_url($thread->source_url, PHP_URL_HOST) }}
                        </a>
                    @endif
                </div>
            </div>
            <div class="flex items-center gap-2">
                @if(in_array($thread->status, ['draft', 'failed', 'partial']))
                    <a href="{{ route('threads.edit', $thread) }}"
                       class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50 transition-colors">
                        Modifier
                    </a>
                @endif
                <form method="POST" action="{{ route('threads.destroy', $thread) }}" onsubmit="return confirm('Supprimer ce fil ?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-red-700 bg-white border border-red-300 rounded-xl hover:bg-red-50 transition-colors">
                        Supprimer
                    </button>
                </form>
            </div>
        </div>

        {{-- Flash messages --}}
        @if(session('success'))
            <div class="rounded-2xl bg-green-50 border border-green-200 p-4 text-sm text-green-700">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="rounded-2xl bg-red-50 border border-red-200 p-4 text-sm text-red-700">{{ session('error') }}</div>
        @endif

        {{-- Accounts status --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 lg:p-8">
            <h2 class="text-base font-semibold text-gray-900 mb-4">Comptes de publication</h2>

            <div class="space-y-3">
                @foreach($thread->socialAccounts as $account)
                    <div class="flex items-center gap-4 p-3 rounded-xl border border-gray-200">
                        <x-platform-icon :platform="$account->platform->slug" size="sm" />

                        @if($account->profile_picture_url)
                            <img src="{{ $account->profile_picture_url }}" alt="" class="w-8 h-8 rounded-full object-cover">
                        @endif

                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900 truncate">{{ $account->name }}</p>
                            <p class="text-xs text-gray-500">
                                {{ $account->pivot->publish_mode === 'thread' ? 'Fil (multi-posts)' : 'Compile (post unique)' }}
                            </p>
                        </div>

                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                            {{ match($account->pivot->status) {
                                'pending' => 'bg-gray-100 text-gray-700',
                                'publishing' => 'bg-yellow-100 text-yellow-700',
                                'published' => 'bg-green-100 text-green-700',
                                'partial' => 'bg-orange-100 text-orange-700',
                                'failed' => 'bg-red-100 text-red-700',
                                default => 'bg-gray-100 text-gray-700',
                            } }}">
                            {{ ucfirst($account->pivot->status) }}
                        </span>

                        <div class="flex items-center gap-2">
                            @if(in_array($account->pivot->status, ['pending', 'failed', 'partial']))
                                <button type="button"
                                        @click="publishAccount({{ $thread->id }}, {{ $account->id }}, '{{ $account->name }}')"
                                        :disabled="publishingAccount === {{ $account->id }}"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors">
                                    <template x-if="publishingAccount === {{ $account->id }}">
                                        <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                        </svg>
                                    </template>
                                    Publier
                                </button>
                            @endif

                            @if(in_array($account->pivot->status, ['failed', 'partial', 'published']))
                                <button type="button"
                                        @click="resetAccount({{ $thread->id }}, {{ $account->id }})"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg bg-gray-100 text-gray-600 hover:bg-gray-200 transition-colors">
                                    Reset
                                </button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Publish all button --}}
            @if($thread->socialAccounts->contains(fn ($a) => in_array($a->pivot->status, ['pending', 'failed', 'partial'])))
                <div class="mt-4 pt-4 border-t border-gray-100">
                    <button type="button" @click="publishAll({{ $thread->id }})"
                            :disabled="publishingAll"
                            class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-xl hover:bg-indigo-700 disabled:opacity-50 transition-colors shadow-sm">
                        <template x-if="publishingAll">
                            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                        </template>
                        Publier vers tous les comptes
                    </button>
                </div>
            @endif
        </div>

        {{-- Segments --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 lg:p-8">
            <h2 class="text-base font-semibold text-gray-900 mb-4">Segments</h2>

            <div class="space-y-4">
                @foreach($thread->segments as $segment)
                    <div class="border border-gray-200 rounded-xl overflow-hidden">
                        <div class="flex items-center gap-3 px-4 py-3 bg-gray-50 border-b border-gray-200">
                            <span class="flex items-center justify-center w-7 h-7 rounded-full bg-indigo-100 text-indigo-700 text-xs font-bold">{{ $segment->position }}</span>
                            <span class="text-sm font-medium text-gray-700">Segment {{ $segment->position }}</span>
                            <span class="text-xs text-gray-400 ml-auto">{{ mb_strlen($segment->content_fr) }} car.</span>
                        </div>

                        <div class="p-4">
                            <p class="text-sm text-gray-700 whitespace-pre-wrap">{{ $segment->content_fr }}</p>

                            {{-- Platform-specific content --}}
                            @if(!empty($segment->platform_contents))
                                <div class="mt-3 pt-3 border-t border-gray-100 space-y-2">
                                    @foreach($segment->platform_contents as $slug => $content)
                                        @if(!empty($content))
                                            <div>
                                                <div class="flex items-center gap-1.5 text-xs font-medium text-gray-500 mb-1">
                                                    <x-platform-icon :platform="$slug" size="xs" />
                                                    {{ ucfirst($slug) }}
                                                    <span class="text-gray-400">({{ mb_strlen($content) }} car.)</span>
                                                </div>
                                                <p class="text-xs text-gray-600 whitespace-pre-wrap bg-gray-50 rounded-lg p-2">{{ $content }}</p>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            @endif

                            {{-- Per-platform status --}}
                            <div class="mt-3 pt-3 border-t border-gray-100">
                                <div class="flex flex-wrap gap-2">
                                    @foreach($segment->segmentPlatforms as $sp)
                                        <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs
                                            {{ match($sp->status) {
                                                'pending' => 'bg-gray-50 text-gray-600',
                                                'publishing' => 'bg-yellow-50 text-yellow-700',
                                                'published' => 'bg-green-50 text-green-700',
                                                'failed' => 'bg-red-50 text-red-700',
                                                'skipped' => 'bg-gray-50 text-gray-400',
                                                default => 'bg-gray-50 text-gray-600',
                                            } }}">
                                            <x-platform-icon :platform="$sp->socialAccount->platform->slug" size="xs" />
                                            {{ $sp->socialAccount->name }}:
                                            {{ $sp->status }}
                                            @if($sp->external_id)
                                                <span class="text-gray-400">#{{ Str::limit($sp->external_id, 10) }}</span>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            {{-- Error messages --}}
                            @foreach($segment->segmentPlatforms as $sp)
                                @if($sp->error_message)
                                    <div class="mt-2 text-xs text-red-600 bg-red-50 rounded-lg p-2">
                                        <strong>{{ $sp->socialAccount->name }}:</strong> {{ $sp->error_message }}
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <script>
        function threadShow() {
            return {
                publishingAccount: null,
                publishingAll: false,

                async publishAccount(threadId, accountId, accountName) {
                    this.publishingAccount = accountId;

                    try {
                        const resp = await fetch(`/threads/${threadId}/publish/${accountId}`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                                'Accept': 'application/json',
                            },
                        });

                        const data = await resp.json();
                        if (data.success) {
                            location.reload();
                        } else {
                            alert('Erreur: ' + (data.error || JSON.stringify(data.results)));
                            location.reload();
                        }
                    } catch (err) {
                        alert('Erreur de connexion');
                    }

                    this.publishingAccount = null;
                },

                async publishAll(threadId) {
                    this.publishingAll = true;

                    try {
                        const resp = await fetch(`/threads/${threadId}/publish`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                                'Accept': 'application/json',
                            },
                        });

                        await resp.json();
                        location.reload();
                    } catch (err) {
                        alert('Erreur de connexion');
                        this.publishingAll = false;
                    }
                },

                async resetAccount(threadId, accountId) {
                    try {
                        await fetch(`/threads/${threadId}/reset/${accountId}`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                                'Accept': 'application/json',
                            },
                        });
                        location.reload();
                    } catch (err) {
                        alert('Erreur de connexion');
                    }
                },
            };
        }
    </script>
@endsection
