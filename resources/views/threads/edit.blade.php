@extends('layouts.app')

@section('title', 'Modifier le fil')

@section('content')
    <form method="POST" action="{{ route('threads.update', $thread) }}" class="max-w-4xl space-y-6" x-data="threadEditForm()">
        @csrf
        @method('PUT')

        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold text-gray-900">Modifier le fil de discussion</h1>
        </div>

        {{-- Validation errors --}}
        @if($errors->any())
            <div class="rounded-2xl bg-red-50 border border-red-200 p-6">
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                    </svg>
                    <div>
                        <h3 class="text-sm font-medium text-red-800">Veuillez corriger les erreurs suivantes :</h3>
                        <ul class="mt-2 text-sm text-red-700 list-disc list-inside space-y-1">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        @endif

        {{-- Section 1: Metadata --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 lg:p-8">
            <h2 class="text-base font-semibold text-gray-900 mb-4">Informations</h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="title" class="block text-sm font-medium text-gray-700 mb-1">Titre (interne)</label>
                    <input type="text" name="title" id="title"
                           value="{{ old('title', $thread->title) }}"
                           class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                </div>
                <div>
                    <label for="source_url" class="block text-sm font-medium text-gray-700 mb-1">URL source</label>
                    <input type="url" name="source_url" id="source_url"
                           value="{{ old('source_url', $thread->source_url) }}"
                           class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                </div>
            </div>
        </div>

        {{-- Section 2: Account selection --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 lg:p-8">
            <h2 class="text-base font-semibold text-gray-900 mb-4">Comptes de publication <span class="text-red-500">*</span></h2>

            @if($accounts->count())
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    @php
                        $platformLabels = [
                            'facebook' => 'Facebook',
                            'threads' => 'Threads',
                            'twitter' => 'Twitter / X',
                            'telegram' => 'Telegram',
                        ];
                        $platformOrder = ['twitter', 'threads', 'facebook', 'telegram'];
                        $threadPlatforms = ['twitter', 'threads'];
                    @endphp

                    @foreach($platformOrder as $slug)
                        @if(isset($accounts[$slug]))
                            <div class="border border-gray-200 rounded-xl overflow-hidden">
                                <div class="flex items-center gap-2.5 px-4 py-3 bg-gray-50 border-b border-gray-200">
                                    <x-platform-icon :platform="$slug" size="sm" />
                                    <span class="text-sm font-medium text-gray-900">{{ $platformLabels[$slug] ?? $slug }}</span>
                                    <span class="ml-auto px-2 py-0.5 text-xs rounded-full {{ in_array($slug, $threadPlatforms) ? 'bg-indigo-100 text-indigo-700' : 'bg-amber-100 text-amber-700' }}">
                                        {{ in_array($slug, $threadPlatforms) ? 'Fil' : 'Compile' }}
                                    </span>
                                </div>
                                <div class="p-3 space-y-1.5">
                                    @foreach($accounts[$slug] as $account)
                                        <label class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-indigo-50/50 transition-colors cursor-pointer">
                                            <input
                                                type="checkbox"
                                                name="accounts[]"
                                                value="{{ $account->id }}"
                                                {{ in_array($account->id, old('accounts', $selectedAccountIds)) ? 'checked' : '' }}
                                                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 transition-colors"
                                            >
                                            @if($account->profile_picture_url)
                                                <img src="{{ $account->profile_picture_url }}" alt="" class="w-6 h-6 rounded-full object-cover flex-shrink-0">
                                            @endif
                                            <span class="text-sm text-gray-700 truncate">{{ $account->name }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Section 3: Segments editor --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 lg:p-8">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-base font-semibold text-gray-900">
                    Segments
                    <span class="text-sm font-normal text-gray-500" x-text="'(' + segments.length + ')'"></span>
                </h2>
                <button type="button" @click="addSegment()"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-gray-100 text-gray-600 hover:bg-gray-200 transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Ajouter un segment
                </button>
            </div>

            <div class="space-y-4">
                <template x-for="(segment, index) in segments" :key="index">
                    <div class="border border-gray-200 rounded-xl overflow-hidden">
                        <div class="flex items-center gap-3 px-4 py-3 bg-gray-50 border-b border-gray-200">
                            <span class="flex items-center justify-center w-7 h-7 rounded-full bg-indigo-100 text-indigo-700 text-xs font-bold" x-text="index + 1"></span>
                            <span class="text-sm font-medium text-gray-700">Segment <span x-text="index + 1"></span></span>

                            <div class="ml-auto flex items-center gap-2">
                                <button type="button" @click="moveSegment(index, -1)" :disabled="index === 0"
                                        class="p-1 rounded text-gray-400 hover:text-gray-600 disabled:opacity-30">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15.75 7.5-7.5 7.5 7.5" /></svg>
                                </button>
                                <button type="button" @click="moveSegment(index, 1)" :disabled="index === segments.length - 1"
                                        class="p-1 rounded text-gray-400 hover:text-gray-600 disabled:opacity-30">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                                </button>
                                <button type="button" @click="removeSegment(index)" :disabled="segments.length <= 1"
                                        class="p-1 rounded text-gray-400 hover:text-red-600 disabled:opacity-30">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                </button>
                            </div>
                        </div>

                        <div class="p-4 space-y-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Contenu principal</label>
                                <textarea x-model="segment.content_fr"
                                          :name="'segments[' + index + '][content_fr]'"
                                          rows="3"
                                          class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm resize-y"
                                          placeholder="Contenu du segment..."></textarea>
                                <div class="flex justify-end mt-1">
                                    <span class="text-xs" :class="(segment.content_fr || '').length > 500 ? 'text-red-500' : 'text-gray-400'"
                                          x-text="(segment.content_fr || '').length + ' car.'"></span>
                                </div>
                            </div>

                            <div x-data="{ showOverrides: Object.values(segment.platform_contents || {}).some(v => v) }">
                                <button type="button" @click="showOverrides = !showOverrides"
                                        class="text-xs text-indigo-600 hover:text-indigo-700 font-medium flex items-center gap-1">
                                    <svg class="w-3 h-3 transition-transform" :class="showOverrides && 'rotate-90'" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                                    </svg>
                                    Contenu par plateforme
                                </button>

                                <div x-show="showOverrides" x-collapse class="mt-2 space-y-2">
                                    <div>
                                        <label class="flex items-center gap-1.5 text-xs font-medium text-gray-500 mb-1">
                                            <x-platform-icon platform="twitter" size="xs" /> Twitter (280 car.)
                                        </label>
                                        <textarea x-model="segment.platform_contents.twitter"
                                                  :name="'segments[' + index + '][platform_contents][twitter]'"
                                                  rows="2" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-xs resize-y"
                                                  placeholder="Version Twitter (optionnel)..."></textarea>
                                        <div class="flex justify-end">
                                            <span class="text-xs" :class="(segment.platform_contents.twitter || '').length > 280 ? 'text-red-500' : 'text-gray-400'"
                                                  x-text="(segment.platform_contents.twitter || '').length + '/280'"></span>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="flex items-center gap-1.5 text-xs font-medium text-gray-500 mb-1">
                                            <x-platform-icon platform="threads" size="xs" /> Threads (500 car.)
                                        </label>
                                        <textarea x-model="segment.platform_contents.threads"
                                                  :name="'segments[' + index + '][platform_contents][threads]'"
                                                  rows="2" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-xs resize-y"
                                                  placeholder="Version Threads (optionnel)..."></textarea>
                                        <div class="flex justify-end">
                                            <span class="text-xs" :class="(segment.platform_contents.threads || '').length > 500 ? 'text-red-500' : 'text-gray-400'"
                                                  x-text="(segment.platform_contents.threads || '').length + '/500'"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        {{-- Section 4: Status --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 lg:p-8">
            <h2 class="text-base font-semibold text-gray-900 mb-4">Statut</h2>

            <div class="flex gap-6">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="radio" name="status" value="draft" {{ $thread->status === 'draft' ? 'checked' : '' }}
                           class="text-indigo-600 focus:ring-indigo-500">
                    <span class="text-sm text-gray-700">Brouillon</span>
                </label>
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="radio" name="status" value="scheduled" {{ $thread->status === 'scheduled' ? 'checked' : '' }}
                           class="text-indigo-600 focus:ring-indigo-500">
                    <span class="text-sm text-gray-700">Programme</span>
                </label>
            </div>

            <div class="mt-4">
                <label for="scheduled_at" class="block text-sm font-medium text-gray-700 mb-1">Date et heure</label>
                <input type="datetime-local" name="scheduled_at" id="scheduled_at"
                       value="{{ $thread->scheduled_at?->format('Y-m-d\TH:i') }}"
                       class="rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
            </div>
        </div>

        {{-- Submit --}}
        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('threads.show', $thread) }}" class="px-4 py-2.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50 transition-colors">
                Annuler
            </a>
            <button type="submit" class="inline-flex items-center gap-2 px-6 py-2.5 text-sm font-medium text-white bg-indigo-600 rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                Enregistrer
            </button>
        </div>
    </form>

    <script>
        function threadEditForm() {
            return {
                segments: @json($thread->segments->map(fn ($s) => [
                    'content_fr' => $s->content_fr,
                    'platform_contents' => [
                        'twitter' => $s->platform_contents['twitter'] ?? '',
                        'threads' => $s->platform_contents['threads'] ?? '',
                    ],
                ])->values()),

                addSegment() {
                    this.segments.push({
                        content_fr: '',
                        platform_contents: { twitter: '', threads: '' },
                    });
                },

                removeSegment(index) {
                    if (this.segments.length > 1) {
                        this.segments.splice(index, 1);
                    }
                },

                moveSegment(index, direction) {
                    const newIndex = index + direction;
                    if (newIndex < 0 || newIndex >= this.segments.length) return;
                    const temp = this.segments[index];
                    this.segments[index] = this.segments[newIndex];
                    this.segments[newIndex] = temp;
                    this.segments = [...this.segments];
                },
            };
        }
    </script>
@endsection
