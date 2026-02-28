@extends('layouts.app')

@section('title', 'Publier un fil')

@section('content')
    <form method="POST" action="{{ route('threads.store') }}" class="max-w-4xl space-y-6" x-data="threadForm()" @submit="handleSubmit($event)">
        @csrf

        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold text-gray-900">Publier un fil de discussion</h1>
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

        {{-- Section 1: Source URL + Persona + Generate --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 lg:p-8">
            <h2 class="text-base font-semibold text-gray-900 mb-4">Source et generation</h2>

            <div class="space-y-4">
                <div>
                    <label for="source_url" class="block text-sm font-medium text-gray-700 mb-1">URL source</label>
                    <div class="flex gap-3">
                        <input type="url" name="source_url" id="source_url" x-model="sourceUrl"
                               placeholder="https://example.com/article..."
                               class="flex-1 rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                        <button type="button" @click="generateFromUrl()"
                                :disabled="generating || !sourceUrl || !selectedPersonaId || selectedAccounts.length === 0"
                                class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-medium bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors shadow-sm">
                            <template x-if="!generating">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 0 0-2.455 2.456Z" />
                                </svg>
                            </template>
                            <template x-if="generating">
                                <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                            </template>
                            <span x-text="generating ? 'Generation...' : 'Generer le fil'"></span>
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="persona_id" class="block text-sm font-medium text-gray-700 mb-1">Persona</label>
                        <select id="persona_id" x-model="selectedPersonaId"
                                class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                            <option value="">-- Choisir une persona --</option>
                            @foreach($personas as $persona)
                                <option value="{{ $persona->id }}">{{ $persona->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="title" class="block text-sm font-medium text-gray-700 mb-1">Titre (interne)</label>
                        <input type="text" name="title" id="title" x-model="title"
                               placeholder="Titre du fil..."
                               class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    </div>
                </div>

                <template x-if="generateError">
                    <div class="rounded-xl bg-red-50 border border-red-200 p-4 text-sm text-red-700" x-text="generateError"></div>
                </template>
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
                                                data-platform="{{ $slug }}"
                                                @change="updateSelectedAccounts()"
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
                <p class="mt-3 text-xs text-gray-500">
                    <span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-indigo-400"></span> Fil</span> = chaque segment est un post distinct (thread).
                    <span class="inline-flex items-center gap-1 ml-3"><span class="w-2 h-2 rounded-full bg-amber-400"></span> Compile</span> = tous les segments sont fusionnes en un seul long post.
                </p>
            @else
                <div class="text-sm text-gray-500 bg-gray-50 rounded-xl p-4">
                    Aucun compte actif disponible.
                    <a href="{{ route('platforms.facebook') }}" class="text-indigo-600 hover:text-indigo-700 font-medium">Configurer les plateformes</a>
                </div>
            @endif

            @error('accounts')
                <p class="mt-3 text-sm text-red-600">{{ $message }}</p>
            @enderror
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

            <template x-if="segments.length === 0">
                <div class="text-sm text-gray-500 bg-gray-50 rounded-xl p-8 text-center">
                    <svg class="w-10 h-10 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.076-4.076a1.526 1.526 0 0 1 1.037-.443 48.282 48.282 0 0 0 5.68-.494c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" />
                    </svg>
                    Saisissez une URL et cliquez sur "Generer le fil" ou ajoutez des segments manuellement.
                </div>
            </template>

            <div class="space-y-4">
                <template x-for="(segment, index) in segments" :key="index">
                    <div class="border border-gray-200 rounded-xl overflow-hidden">
                        {{-- Segment header --}}
                        <div class="flex items-center gap-3 px-4 py-3 bg-gray-50 border-b border-gray-200">
                            <span class="flex items-center justify-center w-7 h-7 rounded-full bg-indigo-100 text-indigo-700 text-xs font-bold" x-text="index + 1"></span>
                            <span class="text-sm font-medium text-gray-700">Segment <span x-text="index + 1"></span></span>

                            <div class="ml-auto flex items-center gap-2">
                                {{-- Move up --}}
                                <button type="button" @click="moveSegment(index, -1)" :disabled="index === 0"
                                        class="p-1 rounded text-gray-400 hover:text-gray-600 disabled:opacity-30">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15.75 7.5-7.5 7.5 7.5" />
                                    </svg>
                                </button>
                                {{-- Move down --}}
                                <button type="button" @click="moveSegment(index, 1)" :disabled="index === segments.length - 1"
                                        class="p-1 rounded text-gray-400 hover:text-gray-600 disabled:opacity-30">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </button>
                                {{-- Regenerate --}}
                                <button type="button" @click="regenerateSegment(index)"
                                        :disabled="!sourceUrl || !selectedPersonaId || segment.regenerating"
                                        class="p-1 rounded text-gray-400 hover:text-indigo-600 disabled:opacity-30">
                                    <svg class="w-4 h-4" :class="segment.regenerating && 'animate-spin'" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182" />
                                    </svg>
                                </button>
                                {{-- Delete --}}
                                <button type="button" @click="removeSegment(index)" :disabled="segments.length <= 1"
                                        class="p-1 rounded text-gray-400 hover:text-red-600 disabled:opacity-30">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        {{-- Segment content --}}
                        <div class="p-4 space-y-3">
                            {{-- Main content (content_fr) --}}
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

                            {{-- Platform-specific overrides (collapsible) --}}
                            <div x-data="{ showOverrides: Object.keys(segment.platform_contents || {}).length > 0 }">
                                <button type="button" @click="showOverrides = !showOverrides"
                                        class="text-xs text-indigo-600 hover:text-indigo-700 font-medium flex items-center gap-1">
                                    <svg class="w-3 h-3 transition-transform" :class="showOverrides && 'rotate-90'" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                                    </svg>
                                    Contenu par plateforme
                                </button>

                                <div x-show="showOverrides" x-collapse class="mt-2 space-y-2">
                                    {{-- Twitter override --}}
                                    <div>
                                        <label class="flex items-center gap-1.5 text-xs font-medium text-gray-500 mb-1">
                                            <x-platform-icon platform="twitter" size="xs" />
                                            Twitter (280 car.)
                                        </label>
                                        <textarea x-model="segment.platform_contents.twitter"
                                                  :name="'segments[' + index + '][platform_contents][twitter]'"
                                                  rows="2"
                                                  class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-xs resize-y"
                                                  placeholder="Version Twitter (optionnel)..."></textarea>
                                        <div class="flex justify-end">
                                            <span class="text-xs" :class="(segment.platform_contents.twitter || '').length > 280 ? 'text-red-500' : 'text-gray-400'"
                                                  x-text="(segment.platform_contents.twitter || '').length + '/280'"></span>
                                        </div>
                                    </div>

                                    {{-- Threads override --}}
                                    <div>
                                        <label class="flex items-center gap-1.5 text-xs font-medium text-gray-500 mb-1">
                                            <x-platform-icon platform="threads" size="xs" />
                                            Threads (500 car.)
                                        </label>
                                        <textarea x-model="segment.platform_contents.threads"
                                                  :name="'segments[' + index + '][platform_contents][threads]'"
                                                  rows="2"
                                                  class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-xs resize-y"
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

        {{-- Section 4: Compiled preview (for Facebook/Telegram) --}}
        <template x-if="segments.length > 0 && hasCompiledPlatforms">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 lg:p-8" x-data="{ showCompiled: false }">
                <button type="button" @click="showCompiled = !showCompiled"
                        class="flex items-center gap-2 text-base font-semibold text-gray-900">
                    <svg class="w-4 h-4 transition-transform" :class="showCompiled && 'rotate-90'" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                    </svg>
                    Apercu compile (Facebook / Telegram)
                </button>

                <div x-show="showCompiled" x-collapse class="mt-4">
                    <div class="bg-gray-50 rounded-xl p-4 text-sm text-gray-700 whitespace-pre-wrap"
                         x-text="compiledPreview"></div>
                    <p class="mt-2 text-xs text-gray-400" x-text="compiledPreview.length + ' caracteres'"></p>
                </div>
            </div>
        </template>

        {{-- Section 5: Publication mode --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 lg:p-8">
            <h2 class="text-base font-semibold text-gray-900 mb-4">Mode de publication</h2>

            <div class="flex gap-6">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="radio" name="publish_mode_radio" value="schedule" x-model="publishMode"
                           class="text-indigo-600 focus:ring-indigo-500">
                    <span class="text-sm text-gray-700">Programmer</span>
                </label>
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="radio" name="publish_mode_radio" value="now" x-model="publishMode"
                           class="text-indigo-600 focus:ring-indigo-500">
                    <span class="text-sm text-gray-700">Publier maintenant</span>
                </label>
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="radio" name="publish_mode_radio" value="draft" x-model="publishMode"
                           class="text-indigo-600 focus:ring-indigo-500">
                    <span class="text-sm text-gray-700">Brouillon</span>
                </label>
            </div>

            <template x-if="publishMode === 'schedule'">
                <div class="mt-4">
                    <label for="scheduled_at" class="block text-sm font-medium text-gray-700 mb-1">Date et heure</label>
                    <input type="datetime-local" name="scheduled_at" id="scheduled_at"
                           class="rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                </div>
            </template>

            {{-- Hidden fields for form state --}}
            <input type="hidden" name="status" :value="publishMode === 'draft' ? 'draft' : 'scheduled'">
            <input type="hidden" name="publish_now" :value="publishMode === 'now' ? '1' : '0'">
            <input type="hidden" name="source_type" value="manual">
        </div>

        {{-- Submit buttons --}}
        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('threads.index') }}" class="px-4 py-2.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50 transition-colors">
                Annuler
            </a>
            <button type="submit" :disabled="isPublishing || segments.length === 0"
                    class="inline-flex items-center gap-2 px-6 py-2.5 text-sm font-medium text-white bg-indigo-600 rounded-xl hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors shadow-sm">
                <span x-text="publishMode === 'now' ? 'Publier maintenant' : (publishMode === 'draft' ? 'Enregistrer le brouillon' : 'Programmer')"></span>
            </button>
        </div>

        {{-- Publishing progress overlay --}}
        <template x-if="isPublishing">
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50">
                <div class="bg-white rounded-2xl shadow-2xl p-8 max-w-md w-full mx-4">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Publication en cours...</h3>

                    <div class="space-y-3">
                        <template x-for="(acc, i) in publishAccounts" :key="i">
                            <div class="flex items-center gap-3">
                                <template x-if="acc.status === 'pending'">
                                    <div class="w-5 h-5 rounded-full border-2 border-gray-300"></div>
                                </template>
                                <template x-if="acc.status === 'publishing'">
                                    <svg class="w-5 h-5 text-indigo-600 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                </template>
                                <template x-if="acc.status === 'published'">
                                    <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                    </svg>
                                </template>
                                <template x-if="acc.status === 'failed'">
                                    <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                    </svg>
                                </template>
                                <span class="text-sm text-gray-700" x-text="acc.name"></span>
                                <span class="text-xs text-gray-400 ml-auto" x-text="acc.publish_mode === 'thread' ? 'Fil' : 'Compile'"></span>
                            </div>
                        </template>
                    </div>

                    <template x-if="publishDone">
                        <div class="mt-6">
                            <template x-if="publishErrors.length > 0">
                                <div class="rounded-xl bg-red-50 border border-red-200 p-3 mb-4">
                                    <template x-for="err in publishErrors" :key="err">
                                        <p class="text-sm text-red-700" x-text="err"></p>
                                    </template>
                                </div>
                            </template>
                            <a :href="publishShowUrl"
                               class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-xl hover:bg-indigo-700 transition-colors">
                                Voir le fil
                            </a>
                        </div>
                    </template>
                </div>
            </div>
        </template>
    </form>

    <script>
        function threadForm() {
            return {
                sourceUrl: '',
                title: '',
                selectedPersonaId: '',
                segments: [],
                generating: false,
                generateError: '',
                publishMode: 'draft',
                isPublishing: false,
                publishDone: false,
                publishShowUrl: '',
                publishAccounts: [],
                publishErrors: [],
                selectedAccounts: [],

                get hasCompiledPlatforms() {
                    const compiledSlugs = ['facebook', 'telegram'];
                    return this.selectedAccounts.some(el => compiledSlugs.includes(el.dataset.platform));
                },

                get compiledPreview() {
                    return this.segments.map(s => s.content_fr || '').join('\n\n---\n\n');
                },

                updateSelectedAccounts() {
                    this.selectedAccounts = [...document.querySelectorAll('input[name="accounts[]"]:checked')];
                },

                addSegment() {
                    this.segments.push({
                        content_fr: '',
                        platform_contents: { twitter: '', threads: '' },
                        regenerating: false,
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
                    // Force reactivity
                    this.segments = [...this.segments];
                },

                async generateFromUrl() {
                    if (!this.sourceUrl || !this.selectedPersonaId) return;
                    this.updateSelectedAccounts();
                    if (this.selectedAccounts.length === 0) {
                        this.generateError = 'Veuillez selectionner au moins un compte.';
                        return;
                    }

                    this.generating = true;
                    this.generateError = '';

                    const accountIds = this.selectedAccounts.map(el => el.value);

                    try {
                        const resp = await fetch('{{ route("threads.generateFromUrl") }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({
                                source_url: this.sourceUrl,
                                persona_id: this.selectedPersonaId,
                                accounts: accountIds,
                            }),
                        });

                        const data = await resp.json();

                        if (data.success) {
                            this.title = data.data.title || this.title;
                            this.segments = data.data.segments.map(s => ({
                                content_fr: s.content_fr || '',
                                platform_contents: {
                                    twitter: s.platform_contents?.twitter || '',
                                    threads: s.platform_contents?.threads || '',
                                },
                                regenerating: false,
                            }));
                        } else {
                            this.generateError = data.error || 'Erreur inconnue.';
                        }
                    } catch (err) {
                        this.generateError = 'Erreur de connexion.';
                    }

                    this.generating = false;
                },

                async regenerateSegment(index) {
                    if (!this.sourceUrl || !this.selectedPersonaId) return;
                    this.updateSelectedAccounts();

                    const segment = this.segments[index];
                    segment.regenerating = true;

                    const accountIds = this.selectedAccounts.map(el => el.value);
                    const prevContent = index > 0 ? (this.segments[index - 1].content_fr || '') : '';
                    const nextContent = index < this.segments.length - 1 ? (this.segments[index + 1].content_fr || '') : '';

                    try {
                        const resp = await fetch('{{ route("threads.regenerateSegment") }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({
                                source_url: this.sourceUrl,
                                persona_id: this.selectedPersonaId,
                                position: index + 1,
                                total_segments: this.segments.length,
                                previous_content: prevContent,
                                next_content: nextContent,
                                accounts: accountIds,
                            }),
                        });

                        const data = await resp.json();
                        if (data.success && data.data) {
                            segment.content_fr = data.data.content_fr || segment.content_fr;
                            segment.platform_contents.twitter = data.data.twitter || '';
                            segment.platform_contents.threads = data.data.threads || '';
                        }
                    } catch (err) {
                        // Silent fail
                    }

                    segment.regenerating = false;
                },

                async handleSubmit(e) {
                    if (this.publishMode !== 'now') return;
                    e.preventDefault();

                    this.isPublishing = true;
                    this.publishDone = false;
                    this.publishErrors = [];

                    const form = e.target;
                    const formData = new FormData(form);

                    try {
                        const resp = await fetch(form.action, {
                            method: 'POST',
                            headers: { 'Accept': 'application/json' },
                            body: formData,
                        });

                        if (!resp.ok) {
                            const err = await resp.json();
                            this.isPublishing = false;
                            if (err.errors) {
                                let msgs = [];
                                Object.values(err.errors).forEach(v => msgs.push(...v));
                                alert(msgs.join('\n'));
                            }
                            return;
                        }

                        const data = await resp.json();
                        this.publishShowUrl = data.show_url;
                        this.publishAccounts = data.accounts.map(a => ({
                            ...a,
                            status: 'pending',
                        }));

                        // Publish each account sequentially
                        for (let i = 0; i < this.publishAccounts.length; i++) {
                            const acc = this.publishAccounts[i];
                            acc.status = 'publishing';

                            try {
                                const pubResp = await fetch(acc.publish_url, {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                                        'Accept': 'application/json',
                                    },
                                });

                                const pubData = await pubResp.json();
                                if (pubData.success) {
                                    acc.status = 'published';
                                } else {
                                    acc.status = 'failed';
                                    this.publishErrors.push(acc.name + ': ' + (pubData.error || 'Erreur'));
                                }
                            } catch (err) {
                                acc.status = 'failed';
                                this.publishErrors.push(acc.name + ': Erreur de connexion');
                            }
                        }

                        this.publishDone = true;
                    } catch (err) {
                        this.isPublishing = false;
                        alert('Erreur lors de la creation du fil.');
                    }
                },
            };
        }
    </script>
@endsection
