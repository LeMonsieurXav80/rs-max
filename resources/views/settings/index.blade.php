@extends('layouts.app')

@section('title', 'Paramètres')

@section('content')
    <div class="max-w-3xl space-y-6">

        @if (session('status') === 'settings-updated')
            <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 3000)"
                 class="rounded-xl bg-green-50 border border-green-200 p-4 flex items-center gap-3">
                <svg class="w-5 h-5 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
                <p class="text-sm text-green-700 font-medium">Paramètres enregistrés.</p>
            </div>
        @endif

        <form method="POST" action="{{ route('settings.update') }}" class="space-y-6"
              x-data="{ activeTab: '{{ request('tab', 'ia') }}' }">
            @csrf
            @method('patch')
            <input type="hidden" name="_active_tab" :value="activeTab">

            {{-- Tab navigation --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm">
                <nav class="flex border-b border-gray-100 px-2 overflow-x-auto" aria-label="Tabs">
                    <button type="button" @click="activeTab = 'ia'"
                            :class="activeTab === 'ia' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="px-4 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap">
                        Contenu IA
                    </button>
                    <button type="button" @click="activeTab = 'ia_libre'"
                            :class="activeTab === 'ia_libre' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="px-4 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap">
                        IA Gratuite
                    </button>
                    <button type="button" @click="activeTab = 'platforms'"
                            :class="activeTab === 'platforms' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="px-4 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap">
                        Plateformes
                    </button>
                    <button type="button" @click="activeTab = 'compression'"
                            :class="activeTab === 'compression' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="px-4 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap">
                        Compression
                    </button>
                    <button type="button" @click="activeTab = 'stats'"
                            :class="activeTab === 'stats' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="px-4 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap">
                        Statistiques
                    </button>
                    <button type="button" @click="activeTab = 'inbox'"
                            :class="activeTab === 'inbox' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="px-4 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap">
                        Messagerie
                    </button>
                    <button type="button" @click="activeTab = 'studio'"
                            :class="activeTab === 'studio' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="px-4 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap">
                        Studio
                    </button>
                    <button type="button" @click="activeTab = 'notifications'"
                            :class="activeTab === 'notifications' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="px-4 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap">
                        Notifications
                    </button>
                    <button type="button" @click="activeTab = 'external'"
                            :class="activeTab === 'external' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="px-4 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap">
                        Services externes
                    </button>
                </nav>
            </div>

            {{-- ═══════════════════════════════════════ --}}
            {{-- TAB: Contenu IA                        --}}
            {{-- ═══════════════════════════════════════ --}}
            <div x-show="activeTab === 'ia'" x-cloak>
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                    <div class="flex items-center gap-3 mb-1">
                        <svg class="w-5 h-5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 0 0-2.455 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z" />
                        </svg>
                        <h2 class="text-base font-semibold text-gray-900">Contenu IA</h2>
                    </div>
                    <p class="text-sm text-gray-500 mb-5">Configuration de l'IA pour la generation de contenu, la reecriture et la traduction automatique.</p>

                    <div>
                        <label for="openai_api_key" class="block text-sm font-medium text-gray-700 mb-1">Cle API OpenAI</label>
                        <input type="password" id="openai_api_key" name="openai_api_key"
                               class="w-full sm:w-2/3 rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                               placeholder="{{ $hasOpenaiKey ? '••••••••••••••••' : 'sk-...' }}">
                        @if($hasOpenaiKey)
                            <p class="text-xs text-green-600 mt-1">Cle configuree. Laissez vide pour conserver la cle actuelle.</p>
                        @else
                            <p class="text-xs text-gray-400 mt-1">Necessaire pour la generation IA et la traduction. Obtenez votre cle sur platform.openai.com</p>
                        @endif
                        @error('openai_api_key')
                            <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- AI Models --}}
                    @php
                        $aiModelSettings = [
                            ['key' => 'ai_model_text', 'label' => 'Redaction de contenu', 'desc' => 'Generation et reecriture de texte pour les publications'],
                            ['key' => 'ai_model_vision', 'label' => 'Analyse d\'images/videos', 'desc' => 'Analyse visuelle des medias pour generer du contenu'],
                            ['key' => 'ai_model_translation', 'label' => 'Traduction', 'desc' => 'Traduction automatique entre langues'],
                            ['key' => 'ai_model_rss', 'label' => 'Generation depuis flux RSS', 'desc' => 'Creation de publications a partir d\'articles RSS'],
                        ];
                    @endphp

                    @if(!empty($availableModels))
                    <div class="border-t border-gray-100 mt-6 pt-6">
                        <h3 class="text-sm font-semibold text-gray-900 mb-4">Modeles IA par fonctionnalite</h3>
                        <p class="text-xs text-gray-400 mb-4">{{ count($availableModels) }} modeles disponibles avec votre cle API</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            @foreach($aiModelSettings as $m)
                                <div>
                                    <label for="{{ $m['key'] }}" class="block text-sm font-medium text-gray-700 mb-1">{{ $m['label'] }}</label>
                                    <select id="{{ $m['key'] }}" name="{{ $m['key'] }}"
                                            class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                        @foreach($availableModels as $model)
                                            <option value="{{ $model }}" {{ $settings[$m['key']] === $model ? 'selected' : '' }}>{{ $model }}</option>
                                        @endforeach
                                    </select>
                                    <p class="text-xs text-gray-400 mt-1">{{ $m['desc'] }}</p>
                                    @error($m['key'])
                                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                                    @enderror
                                </div>
                            @endforeach
                        </div>
                    </div>
                    @else
                        {{-- Hidden inputs to preserve default AI model values when no OpenAI key is configured --}}
                        @foreach($aiModelSettings as $m)
                            <input type="hidden" name="{{ $m['key'] }}" value="{{ $settings[$m['key']] }}">
                        @endforeach
                    @endif

                    {{-- Prompts vision éditables --}}
                    <div class="border-t border-gray-100 mt-6 pt-6 space-y-6">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900 mb-1">Prompts vision IA</h3>
                            <p class="text-xs text-gray-400">Personnalise les instructions envoyees a l'API Vision. Les variables entre accolades (ex: <code class="bg-gray-100 px-1 rounded">{langue}</code>) sont substituees au runtime.</p>
                        </div>

                        <div>
                            <label for="ai_prompt_publication_from_photo" class="block text-sm font-medium text-gray-700 mb-1">Generation de texte de publication depuis une photo</label>
                            <p class="text-xs text-gray-400 mb-2">Variables : <code class="bg-gray-100 px-1 rounded">{contexte_utilisateur}</code>, <code class="bg-gray-100 px-1 rounded">{langue}</code>, <code class="bg-gray-100 px-1 rounded">{liste_plateformes}</code>, <code class="bg-gray-100 px-1 rounded">{liste_plateformes_json}</code></p>
                            <textarea id="ai_prompt_publication_from_photo" name="ai_prompt_publication_from_photo" rows="14"
                                class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-xs font-mono">{{ $settings['ai_prompt_publication_from_photo'] }}</textarea>
                            @error('ai_prompt_publication_from_photo')
                                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="ai_prompt_metadata_extraction" class="block text-sm font-medium text-gray-700 mb-1">Extraction de metadonnees depuis une photo (catalogue media)</label>
                            <p class="text-xs text-gray-400 mb-2">Variables : <code class="bg-gray-100 px-1 rounded">{contexte}</code>, <code class="bg-gray-100 px-1 rounded">{personnes_attendues}</code>. Doit retourner du JSON parsable avec les cles : <code class="bg-gray-100 px-1 rounded">description_fr, thematic_tags, people_ids, person_count, city, region, country, brands, event, taken_at</code>.</p>
                            <textarea id="ai_prompt_metadata_extraction" name="ai_prompt_metadata_extraction" rows="20"
                                class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-xs font-mono">{{ $settings['ai_prompt_metadata_extraction'] }}</textarea>
                            @error('ai_prompt_metadata_extraction')
                                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>
            </div>

            {{-- ═══════════════════════════════════════ --}}
            {{-- TAB: IA Gratuite                       --}}
            {{-- ═══════════════════════════════════════ --}}
            <div x-show="activeTab === 'ia_libre'" x-cloak>
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 space-y-6">
                    <div>
                        <div class="flex items-center gap-3 mb-1">
                            <svg class="w-5 h-5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75Z" />
                            </svg>
                            <h2 class="text-lg font-semibold text-gray-800">IA Gratuite (Groq, OpenRouter, Google AI…)</h2>
                        </div>
                        <p class="text-sm text-gray-600 mb-4">
                            Configure des fournisseurs LLM gratuits pour alimenter les bots et la generation de contenu sans payer OpenAI.
                        </p>

                        @if (session('status') && str_starts_with(session('status'), 'free-llms-refreshed:'))
                            <div class="rounded-xl bg-emerald-50 border border-emerald-200 p-3 text-sm text-emerald-800 mb-4">
                                Modeles mis a jour — {{ str_replace('free-llms-refreshed: ', '', session('status')) }}
                            </div>
                        @endif

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            @php
                                $providers = [
                                    ['key' => 'groq_api_key', 'label' => 'Cle API Groq', 'has' => $freeLlm['has_groq_key'], 'help' => 'console.groq.com/keys'],
                                    ['key' => 'openrouter_api_key', 'label' => 'Cle API OpenRouter', 'has' => $freeLlm['has_openrouter_key'], 'help' => 'openrouter.ai/keys'],
                                    ['key' => 'google_ai_api_key', 'label' => 'Cle API Google AI Studio', 'has' => $freeLlm['has_google_ai_key'], 'help' => 'aistudio.google.com/apikey'],
                                    ['key' => 'mistral_api_key', 'label' => 'Cle API Mistral', 'has' => $freeLlm['has_mistral_key'], 'help' => 'console.mistral.ai/api-keys'],
                                    ['key' => 'together_api_key', 'label' => 'Cle API Together AI', 'has' => $freeLlm['has_together_key'], 'help' => 'api.together.xyz/settings/api-keys'],
                                ];
                            @endphp
                            @foreach ($providers as $p)
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">
                                        {{ $p['label'] }}
                                        @if ($p['has'])
                                            <span class="ml-2 text-xs text-emerald-600 font-normal">configuree</span>
                                        @endif
                                    </label>
                                    <input type="password" name="{{ $p['key'] }}" autocomplete="off"
                                           placeholder="{{ $p['has'] ? '•••••••• (laisser vide pour conserver)' : 'sk-...' }}"
                                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                    <p class="text-xs text-gray-500 mt-1">{{ $p['help'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="border-t border-gray-100 pt-6">
                        <div class="flex items-center justify-between mb-3">
                            <div>
                                <h3 class="text-sm font-semibold text-gray-800">Modeles disponibles</h3>
                                <p class="text-xs text-gray-500">
                                    @if ($freeLlm['last_refresh_at'])
                                        Derniere mise a jour : {{ \Carbon\Carbon::parse($freeLlm['last_refresh_at'])->diffForHumans() }}
                                    @else
                                        Jamais mis a jour
                                    @endif
                                </p>
                            </div>
                            <button type="button" onclick="refreshFreeLlms(this)"
                                    class="inline-flex items-center gap-2 px-3 py-2 bg-emerald-600 hover:bg-emerald-700 disabled:bg-emerald-400 text-white text-sm font-medium rounded-md">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                                </svg>
                                Mettre a jour les LLM gratuits
                            </button>
                        </div>

                        @if ($freeLlm['models']->isEmpty())
                            <p class="text-sm text-gray-500 italic">Aucun modele decouvert. Ajoute au moins une cle API ci-dessus, sauvegarde, puis clique sur "Mettre a jour les LLM gratuits".</p>
                        @else
                            <div class="overflow-x-auto rounded-md border border-gray-200">
                                <table class="min-w-full text-sm">
                                    <thead class="bg-gray-50 text-xs text-gray-600 uppercase">
                                        <tr>
                                            <th class="px-3 py-2 text-left">Provider</th>
                                            <th class="px-3 py-2 text-left">Modele</th>
                                            <th class="px-3 py-2 text-center">Vision</th>
                                            <th class="px-3 py-2 text-right">Contexte</th>
                                            <th class="px-3 py-2 text-left">Identifiant</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @foreach ($freeLlm['models'] as $m)
                                            <tr>
                                                <td class="px-3 py-2 font-medium text-gray-700">{{ $m->provider }}</td>
                                                <td class="px-3 py-2 text-gray-800">{{ $m->display_name }}</td>
                                                <td class="px-3 py-2 text-center">
                                                    @if ($m->supports_vision)
                                                        <span class="text-emerald-600">oui</span>
                                                    @else
                                                        <span class="text-gray-400">non</span>
                                                    @endif
                                                </td>
                                                <td class="px-3 py-2 text-right text-gray-600">
                                                    {{ $m->context_length ? number_format($m->context_length) : '—' }}
                                                </td>
                                                <td class="px-3 py-2 font-mono text-xs text-gray-500">{{ $m->qualified_name }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>

                    <div class="border-t border-gray-100 pt-6 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Modele texte par defaut</label>
                            <select name="free_llms_default_text_model"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                <option value="">— OpenAI (configure dans "Contenu IA") —</option>
                                @foreach ($freeLlm['models']->where('supports_text', true) as $m)
                                    <option value="{{ $m->qualified_name }}" @selected($settings['free_llms_default_text_model'] === $m->qualified_name)>
                                        {{ $m->qualified_name }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="text-xs text-gray-500 mt-1">Utilise pour les commentaires bot et les reponses de l'inbox.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Modele vision par defaut</label>
                            <select name="free_llms_default_vision_model"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                <option value="">— OpenAI (configure dans "Contenu IA") —</option>
                                @foreach ($freeLlm['models']->where('supports_vision', true) as $m)
                                    <option value="{{ $m->qualified_name }}" @selected($settings['free_llms_default_vision_model'] === $m->qualified_name)>
                                        {{ $m->qualified_name }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="text-xs text-gray-500 mt-1">Utilise pour analyser les images des posts (commentaires bot avec image).</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ═══════════════════════════════════════ --}}
            {{-- TAB: Plateformes                       --}}
            {{-- ═══════════════════════════════════════ --}}
            <div x-show="activeTab === 'platforms'" x-cloak>
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                    <div class="flex items-center gap-3 mb-1">
                        <svg class="w-5 h-5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 0 0 6 3.75v16.5a2.25 2.25 0 0 0 2.25 2.25h7.5A2.25 2.25 0 0 0 18 20.25V3.75a2.25 2.25 0 0 0-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3" />
                        </svg>
                        <h2 class="text-base font-semibold text-gray-900">Limites de caractères par plateforme</h2>
                    </div>
                    <p class="text-sm text-gray-500 mb-5">Ces limites sont injectees automatiquement dans les instructions IA lors de la generation de contenu.</p>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-100">
                                    <th class="text-left font-medium text-gray-700 pb-3 pr-4">Plateforme</th>
                                    <th class="text-left font-medium text-gray-700 pb-3 px-4">Limite configuree</th>
                                    <th class="text-left font-medium text-gray-400 pb-3 pl-4">Limite officielle</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                @php
                                    $charPlatforms = [
                                        ['slug' => 'twitter', 'name' => 'Twitter / X', 'official' => '280'],
                                        ['slug' => 'facebook', 'name' => 'Facebook', 'official' => '63 206'],
                                        ['slug' => 'instagram', 'name' => 'Instagram', 'official' => '2 200'],
                                        ['slug' => 'threads', 'name' => 'Threads', 'official' => '500'],
                                        ['slug' => 'youtube', 'name' => 'YouTube (description)', 'official' => '5 000'],
                                        ['slug' => 'telegram', 'name' => 'Telegram', 'official' => '4 096'],
                                        ['slug' => 'bluesky', 'name' => 'Bluesky', 'official' => '300'],
                                    ];
                                @endphp
                                @foreach($charPlatforms as $p)
                                    <tr>
                                        <td class="py-3 pr-4 font-medium text-gray-900">{{ $p['name'] }}</td>
                                        <td class="py-3 px-4">
                                            <input type="number" name="platform_char_limit_{{ $p['slug'] }}"
                                                   value="{{ old('platform_char_limit_'.$p['slug'], $settings['platform_char_limit_'.$p['slug']]) }}"
                                                   min="1" max="100000" step="1"
                                                   class="w-28 rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                            @error('platform_char_limit_'.$p['slug'])
                                                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                                            @enderror
                                        </td>
                                        <td class="py-3 pl-4 text-xs text-gray-400">{{ $p['official'] }} car.</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="text-xs text-gray-400 mt-3">Modifiez ces valeurs pour ajuster les limites utilisees par l'IA. Les limites officielles sont affichees a titre indicatif.</p>
                </div>
            </div>

            {{-- ═══════════════════════════════════════ --}}
            {{-- TAB: Compression                       --}}
            {{-- ═══════════════════════════════════════ --}}
            <div x-show="activeTab === 'compression'" x-cloak class="space-y-6">

                {{-- Image compression --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                    <div class="flex items-center gap-3 mb-1">
                        <svg class="w-5 h-5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z" />
                        </svg>
                        <h2 class="text-base font-semibold text-gray-900">Compression d'images</h2>
                    </div>
                    <p class="text-sm text-gray-500 mb-5">L'algorithme ajuste automatiquement la qualité pour atteindre le poids cible, sans descendre en dessous de la qualité minimale.</p>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <div>
                            <label for="image_target_min_kb" class="block text-sm font-medium text-gray-700 mb-1">Poids min par image (KB)</label>
                            <input type="number" id="image_target_min_kb" name="image_target_min_kb"
                                   value="{{ old('image_target_min_kb', $settings['image_target_min_kb']) }}"
                                   min="50" max="500" step="50"
                                   class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                            <p class="text-xs text-gray-400 mt-1">En dessous, l'algo garde la qualité haute. Recommandé : 200 KB</p>
                            @error('image_target_min_kb')
                                <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="image_target_max_kb" class="block text-sm font-medium text-gray-700 mb-1">Poids max par image (KB)</label>
                            <input type="number" id="image_target_max_kb" name="image_target_max_kb"
                                   value="{{ old('image_target_max_kb', $settings['image_target_max_kb']) }}"
                                   min="200" max="2000" step="50"
                                   class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                            <p class="text-xs text-gray-400 mt-1">Au-dessus, l'algo baisse la qualité. Recommandé : 500 KB</p>
                            @error('image_target_max_kb')
                                <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="image_min_quality" class="block text-sm font-medium text-gray-700 mb-1">Qualité minimale (%)</label>
                            <input type="number" id="image_min_quality" name="image_min_quality"
                                   value="{{ old('image_min_quality', $settings['image_min_quality']) }}"
                                   min="30" max="90" step="5"
                                   class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                            <p class="text-xs text-gray-400 mt-1">Plancher : ne descendra jamais en dessous. Recommandé : 60%</p>
                            @error('image_min_quality')
                                <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="image_max_dimension" class="block text-sm font-medium text-gray-700 mb-1">Dimension maximale (px)</label>
                            <input type="number" id="image_max_dimension" name="image_max_dimension"
                                   value="{{ old('image_max_dimension', $settings['image_max_dimension']) }}"
                                   min="512" max="4096" step="1"
                                   class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                            <p class="text-xs text-gray-400 mt-1">Plus grand côté. Recommandé : 2048 px</p>
                            @error('image_max_dimension')
                                <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="image_max_upload_mb" class="block text-sm font-medium text-gray-700 mb-1">Taille max upload (MB)</label>
                            <input type="number" id="image_max_upload_mb" name="image_max_upload_mb"
                                   value="{{ old('image_max_upload_mb', $settings['image_max_upload_mb']) }}"
                                   min="1" max="50" step="1"
                                   class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                            @error('image_max_upload_mb')
                                <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

                {{-- Video compression --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                    <div class="flex items-center gap-3 mb-1">
                        <svg class="w-5 h-5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z" />
                        </svg>
                        <h2 class="text-base font-semibold text-gray-900">Compression vidéo</h2>
                    </div>
                    <p class="text-sm text-gray-500 mb-5">Paramètres appliqués lors de la compression des vidéos avant publication.</p>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <div>
                            <label for="video_bitrate_1080p" class="block text-sm font-medium text-gray-700 mb-1">Bitrate 1080p (kbps)</label>
                            <input type="number" id="video_bitrate_1080p" name="video_bitrate_1080p"
                                   value="{{ old('video_bitrate_1080p', $settings['video_bitrate_1080p']) }}"
                                   min="1000" max="20000" step="100"
                                   class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                            <p class="text-xs text-gray-400 mt-1">Recommandé : 6000 kbps</p>
                            @error('video_bitrate_1080p')
                                <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="video_bitrate_720p" class="block text-sm font-medium text-gray-700 mb-1">Bitrate 720p (kbps)</label>
                            <input type="number" id="video_bitrate_720p" name="video_bitrate_720p"
                                   value="{{ old('video_bitrate_720p', $settings['video_bitrate_720p']) }}"
                                   min="500" max="10000" step="100"
                                   class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                            <p class="text-xs text-gray-400 mt-1">Recommandé : 2500 kbps</p>
                            @error('video_bitrate_720p')
                                <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="video_codec" class="block text-sm font-medium text-gray-700 mb-1">Codec vidéo</label>
                            <select id="video_codec" name="video_codec"
                                    class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                <option value="h264" {{ $settings['video_codec'] === 'h264' ? 'selected' : '' }}>H.264 (universel)</option>
                            </select>
                            <p class="text-xs text-gray-400 mt-1">H.264 est supporté par toutes les plateformes</p>
                            @error('video_codec')
                                <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="video_audio_bitrate" class="block text-sm font-medium text-gray-700 mb-1">Bitrate audio (kbps)</label>
                            <input type="number" id="video_audio_bitrate" name="video_audio_bitrate"
                                   value="{{ old('video_audio_bitrate', $settings['video_audio_bitrate']) }}"
                                   min="64" max="320" step="16"
                                   class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                            <p class="text-xs text-gray-400 mt-1">AAC. Recommandé : 128 kbps</p>
                            @error('video_audio_bitrate')
                                <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="video_max_upload_mb" class="block text-sm font-medium text-gray-700 mb-1">Taille max upload (MB)</label>
                            <input type="number" id="video_max_upload_mb" name="video_max_upload_mb"
                                   value="{{ old('video_max_upload_mb', $settings['video_max_upload_mb']) }}"
                                   min="10" max="500" step="10"
                                   class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                            @error('video_max_upload_mb')
                                <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>
            </div>

            {{-- ═══════════════════════════════════════ --}}
            {{-- TAB: Statistiques                      --}}
            {{-- ═══════════════════════════════════════ --}}
            <div x-show="activeTab === 'stats'" x-cloak>
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                    <div class="flex items-center gap-3 mb-1">
                        <svg class="w-5 h-5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
                        </svg>
                        <h2 class="text-base font-semibold text-gray-900">Synchronisation des statistiques</h2>
                    </div>
                    <p class="text-sm text-gray-500 mb-5">Configure la frequence de mise a jour automatique des stats depuis les API des plateformes.</p>

                    <div class="mb-6">
                        <label for="stats_sync_frequency" class="block text-sm font-medium text-gray-700 mb-1">Frequence du cron</label>
                        <select id="stats_sync_frequency" name="stats_sync_frequency"
                                class="w-full sm:w-1/2 rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                            <option value="every_15_min" {{ $settings['stats_sync_frequency'] === 'every_15_min' ? 'selected' : '' }}>Toutes les 15 minutes</option>
                            <option value="every_30_min" {{ $settings['stats_sync_frequency'] === 'every_30_min' ? 'selected' : '' }}>Toutes les 30 minutes</option>
                            <option value="hourly" {{ $settings['stats_sync_frequency'] === 'hourly' ? 'selected' : '' }}>Toutes les heures</option>
                            <option value="every_2_hours" {{ $settings['stats_sync_frequency'] === 'every_2_hours' ? 'selected' : '' }}>Toutes les 2 heures</option>
                            <option value="every_6_hours" {{ $settings['stats_sync_frequency'] === 'every_6_hours' ? 'selected' : '' }}>Toutes les 6 heures</option>
                            <option value="every_12_hours" {{ $settings['stats_sync_frequency'] === 'every_12_hours' ? 'selected' : '' }}>Toutes les 12 heures</option>
                            <option value="daily" {{ $settings['stats_sync_frequency'] === 'daily' ? 'selected' : '' }}>Une fois par jour</option>
                        </select>
                        <p class="text-xs text-gray-400 mt-1">A quelle frequence le systeme verifie et met a jour les stats. Recommande : toutes les heures</p>
                        @error('stats_sync_frequency')
                            <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-100">
                                    <th class="text-left font-medium text-gray-700 pb-3 pr-4">Plateforme</th>
                                    <th class="text-left font-medium text-gray-700 pb-3 px-4">Intervalle (heures)</th>
                                    <th class="text-left font-medium text-gray-700 pb-3 px-4">Age max (jours)</th>
                                    <th class="text-left font-medium text-gray-400 pb-3 pl-4">Limite API</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                @php
                                    $platforms = [
                                        ['slug' => 'facebook', 'name' => 'Facebook', 'limit' => '200 appels/h'],
                                        ['slug' => 'instagram', 'name' => 'Instagram', 'limit' => '200 appels/h'],
                                        ['slug' => 'twitter', 'name' => 'Twitter/X', 'limit' => '15K lectures/mois (Basic)'],
                                        ['slug' => 'youtube', 'name' => 'YouTube', 'limit' => '10K unites/jour'],
                                        ['slug' => 'threads', 'name' => 'Threads', 'limit' => '~200 appels/h'],
                                        ['slug' => 'bluesky', 'name' => 'Bluesky', 'limit' => 'API publique (pas de limite stricte)'],
                                    ];
                                @endphp
                                @foreach($platforms as $p)
                                    <tr>
                                        <td class="py-3 pr-4 font-medium text-gray-900">{{ $p['name'] }}</td>
                                        <td class="py-3 px-4">
                                            <input type="number" name="stats_{{ $p['slug'] }}_interval"
                                                   value="{{ old('stats_'.$p['slug'].'_interval', $settings['stats_'.$p['slug'].'_interval']) }}"
                                                   min="1" max="168" step="1"
                                                   class="w-20 rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                            @error('stats_'.$p['slug'].'_interval')
                                                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                                            @enderror
                                        </td>
                                        <td class="py-3 px-4">
                                            <input type="number" name="stats_{{ $p['slug'] }}_max_days"
                                                   value="{{ old('stats_'.$p['slug'].'_max_days', $settings['stats_'.$p['slug'].'_max_days']) }}"
                                                   min="1" max="365" step="1"
                                                   class="w-20 rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                            @error('stats_'.$p['slug'].'_max_days')
                                                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                                            @enderror
                                        </td>
                                        <td class="py-3 pl-4 text-xs text-gray-400">{{ $p['limit'] }}</td>
                                    </tr>
                                @endforeach
                                <tr>
                                    <td class="py-3 pr-4 font-medium text-gray-400">Telegram</td>
                                    <td class="py-3 px-4 text-xs text-gray-400" colspan="3">Stats non disponibles via le Bot API</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-xs text-gray-400 mt-3">Intervalle : temps minimum entre deux syncs pour un meme post. Age max : les posts plus anciens ne sont plus synchronises automatiquement.</p>
                </div>
            </div>

            {{-- ═══════════════════════════════════════ --}}
            {{-- TAB: Messagerie                       --}}
            {{-- ═══════════════════════════════════════ --}}
            <div x-show="activeTab === 'inbox'" x-cloak>
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                    <div class="flex items-center gap-3 mb-1">
                        <svg class="w-5 h-5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 13.5h3.86a2.25 2.25 0 0 1 2.012 1.244l.256.512a2.25 2.25 0 0 0 2.013 1.244h3.218a2.25 2.25 0 0 0 2.013-1.244l.256-.512a2.25 2.25 0 0 1 2.013-1.244h3.859m-19.5.338V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 0 0-2.15-1.588H6.911a2.25 2.25 0 0 0-2.15 1.588L2.35 13.177a2.25 2.25 0 0 0-.1.661Z" />
                        </svg>
                        <h2 class="text-base font-semibold text-gray-900">Messagerie / Inbox</h2>
                    </div>
                    <p class="text-sm text-gray-500 mb-5">Configuration de la synchronisation des commentaires, reponses et messages prives depuis les plateformes sociales.</p>

                    {{-- Platform toggles + per-platform sync frequency --}}
                    <div class="mb-6">
                        <h3 class="text-sm font-semibold text-gray-900 mb-1">Plateformes actives & frequences</h3>
                        <p class="text-xs text-gray-400 mb-4">Activez/desactivez chaque plateforme et definissez sa frequence de synchronisation independamment.</p>
                        <div class="space-y-3">
                            @php
                                $inboxPlatforms = [
                                    ['slug' => 'facebook', 'name' => 'Facebook', 'desc' => 'Commentaires sur les publications de la Page'],
                                    ['slug' => 'instagram', 'name' => 'Instagram', 'desc' => 'Commentaires sur les publications et reels'],
                                    ['slug' => 'threads', 'name' => 'Threads', 'desc' => 'Reponses aux threads publies'],
                                    ['slug' => 'youtube', 'name' => 'YouTube', 'desc' => 'Commentaires sur les videos'],
                                    ['slug' => 'bluesky', 'name' => 'Bluesky', 'desc' => 'Commentaires et messages prives'],
                                    ['slug' => 'telegram', 'name' => 'Telegram', 'desc' => 'Messages recus par le bot'],
                                    ['slug' => 'reddit', 'name' => 'Reddit', 'desc' => 'Commentaires et messages prives'],
                                    ['slug' => 'twitter', 'name' => 'X / Twitter', 'desc' => 'Mentions et reponses aux tweets (API payante)'],
                                ];
                                $freqOptions = [
                                    'every_15_min' => '15 min',
                                    'every_30_min' => '30 min',
                                    'hourly' => '1 heure',
                                    'every_2_hours' => '2 heures',
                                    'every_6_hours' => '6 heures',
                                    'every_12_hours' => '12 heures',
                                    'daily' => '1 fois/jour',
                                ];
                            @endphp
                            @foreach($inboxPlatforms as $p)
                                <div class="flex items-center gap-3 py-1">
                                    <input type="checkbox" name="inbox_platform_{{ $p['slug'] }}_enabled" value="1"
                                           {{ $settings['inbox_platform_'.$p['slug'].'_enabled'] ? 'checked' : '' }}
                                           class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                                    <div class="flex-1 min-w-0">
                                        <span class="text-sm font-medium text-gray-900">{{ $p['name'] }}</span>
                                        <span class="text-xs text-gray-400 ml-1 hidden sm:inline">{{ $p['desc'] }}</span>
                                    </div>
                                    <select name="inbox_sync_freq_{{ $p['slug'] }}"
                                            class="w-32 rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-xs">
                                        @foreach($freqOptions as $val => $label)
                                            <option value="{{ $val }}" {{ $settings['inbox_sync_freq_'.$p['slug']] === $val ? 'selected' : '' }}>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Persona toggle --}}
                    <div class="border-t border-gray-100 pt-6 mt-6">
                        <div class="flex items-center gap-3">
                            <input type="checkbox" name="inbox_use_persona" value="1" id="inbox_use_persona"
                                   {{ $settings['inbox_use_persona'] ? 'checked' : '' }}
                                   class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                            <div>
                                <label for="inbox_use_persona" class="text-sm font-medium text-gray-900 cursor-pointer">Utiliser le persona du compte</label>
                                <p class="text-xs text-gray-400">Si active, le system prompt du persona est injecte dans les reponses IA. Si desactive, seules les instructions ci-dessous sont utilisees.</p>
                            </div>
                        </div>
                    </div>

                    {{-- AI model for inbox --}}
                    @if(!empty($availableModels))
                    <div class="border-t border-gray-100 pt-6">
                        <h3 class="text-sm font-semibold text-gray-900 mb-4">Modele IA pour les reponses</h3>
                        <div class="w-full sm:w-1/2">
                            <select id="ai_model_inbox" name="ai_model_inbox"
                                    class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                @foreach($availableModels as $model)
                                    <option value="{{ $model }}" {{ $settings['ai_model_inbox'] === $model ? 'selected' : '' }}>{{ $model }}</option>
                                @endforeach
                            </select>
                            <p class="text-xs text-gray-400 mt-1">Modele utilise pour generer les suggestions de reponses IA dans la messagerie</p>
                            @error('ai_model_inbox')
                                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                    @else
                        <input type="hidden" name="ai_model_inbox" value="{{ $settings['ai_model_inbox'] }}">
                    @endif

                    {{-- Inbox reply prompt --}}
                    <div class="border-t border-gray-100 pt-6 mt-6">
                        <h3 class="text-sm font-semibold text-gray-900 mb-1">Instructions pour les reponses IA</h3>
                        <p class="text-xs text-gray-400 mb-3">Ce texte est ajoute au prompt systeme du persona pour adapter les reponses au contexte des commentaires et messages. Il definit comment l'IA doit ajuster la longueur et le style selon le type de message recu.</p>
                        <textarea id="inbox_reply_prompt" name="inbox_reply_prompt" rows="10"
                                  class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm font-mono"
                                  placeholder="Instructions pour adapter les reponses IA aux commentaires...">{{ $settings['inbox_reply_prompt'] }}</textarea>
                        @error('inbox_reply_prompt')
                            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            {{-- ═══════════════════════════════════════ --}}
            {{-- TAB: Studio                            --}}
            {{-- ═══════════════════════════════════════ --}}
            <div x-show="activeTab === 'studio'" x-cloak class="space-y-6">

                {{-- Video encoding --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                    <div class="flex items-center gap-3 mb-1">
                        <svg class="w-5 h-5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z" />
                        </svg>
                        <h3 class="text-sm font-semibold text-gray-900">Encodage video Studio</h3>
                    </div>
                    <p class="text-xs text-gray-400 mb-4">Parametres utilises lors du traitement video dans le Media Studio</p>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="studio_video_crf" class="block text-xs font-medium text-gray-700 mb-1">CRF (qualite)</label>
                            <input type="number" id="studio_video_crf" name="studio_video_crf"
                                   value="{{ old('studio_video_crf', $settings['studio_video_crf'] ?? 28) }}"
                                   min="18" max="40" step="1"
                                   class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                            <p class="text-xs text-gray-400 mt-1">18 = meilleure qualite, 40 = plus petit fichier (defaut: 28)</p>
                        </div>
                        <div>
                            <label for="studio_audio_bitrate" class="block text-xs font-medium text-gray-700 mb-1">Bitrate audio (kbps)</label>
                            <input type="number" id="studio_audio_bitrate" name="studio_audio_bitrate"
                                   value="{{ old('studio_audio_bitrate', $settings['studio_audio_bitrate'] ?? 96) }}"
                                   min="64" max="320" step="32"
                                   class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                        </div>
                    </div>
                </div>

                {{-- Logo overlay --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                    <div class="flex items-center gap-3 mb-1">
                        <svg class="w-5 h-5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z" />
                        </svg>
                        <h3 class="text-sm font-semibold text-gray-900">Logo en superposition</h3>
                    </div>
                    <p class="text-xs text-gray-400 mb-4">Image qui sera superposee sur les videos traitees dans le Studio</p>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label for="studio_logo_size" class="block text-xs font-medium text-gray-700 mb-1">Taille du logo (px)</label>
                            <input type="number" id="studio_logo_size" name="studio_logo_size"
                                   value="{{ old('studio_logo_size', $settings['studio_logo_size'] ?? 50) }}"
                                   min="16" max="200" step="1"
                                   class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                        </div>
                        <div>
                            <label for="studio_logo_x" class="block text-xs font-medium text-gray-700 mb-1">Position X</label>
                            <input type="number" id="studio_logo_x" name="studio_logo_x"
                                   value="{{ old('studio_logo_x', $settings['studio_logo_x'] ?? 20) }}"
                                   min="0" max="1000" step="1"
                                   class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                        </div>
                        <div>
                            <label for="studio_logo_y" class="block text-xs font-medium text-gray-700 mb-1">Position Y</label>
                            <input type="number" id="studio_logo_y" name="studio_logo_y"
                                   value="{{ old('studio_logo_y', $settings['studio_logo_y'] ?? 35) }}"
                                   min="0" max="2000" step="1"
                                   class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                        </div>
                    </div>

                    @php $logoPath = \App\Models\Setting::get('studio_logo_path'); @endphp
                    <div class="mt-4 flex items-center gap-4">
                        @if($logoPath && \Illuminate\Support\Facades\Storage::disk('local')->exists($logoPath))
                            <div class="flex items-center gap-2">
                                <div class="w-10 h-10 rounded bg-gray-100 flex items-center justify-center overflow-hidden">
                                    <img src="data:image/png;base64,{{ base64_encode(\Illuminate\Support\Facades\Storage::disk('local')->get($logoPath)) }}" class="w-full h-full object-contain">
                                </div>
                                <span class="text-xs text-green-600 font-medium">Logo configure</span>
                            </div>
                        @else
                            <span class="text-xs text-gray-400">Aucun logo configure</span>
                        @endif
                        <p class="text-xs text-gray-400">Uploadez le logo depuis la page <a href="{{ route('media.studio') }}" class="text-indigo-600 hover:underline">Studio</a></p>
                    </div>
                </div>

                {{-- Text overlay --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                    <div class="flex items-center gap-3 mb-1">
                        <svg class="w-5 h-5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 0 1 .865-.501 48.172 48.172 0 0 0 3.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" />
                        </svg>
                        <h3 class="text-sm font-semibold text-gray-900">Texte en superposition</h3>
                    </div>
                    <p class="text-xs text-gray-400 mb-4">Parametres par defaut du texte affiche sur les videos</p>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label for="studio_text_font_size" class="block text-xs font-medium text-gray-700 mb-1">Taille police</label>
                            <input type="number" id="studio_text_font_size" name="studio_text_font_size"
                                   value="{{ old('studio_text_font_size', $settings['studio_text_font_size'] ?? 28) }}"
                                   min="10" max="100" step="1"
                                   class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                        </div>
                        <div>
                            <label for="studio_text_x" class="block text-xs font-medium text-gray-700 mb-1">Position X</label>
                            <input type="number" id="studio_text_x" name="studio_text_x"
                                   value="{{ old('studio_text_x', $settings['studio_text_x'] ?? 65) }}"
                                   min="0" max="1000" step="1"
                                   class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                        </div>
                        <div>
                            <label for="studio_text_y" class="block text-xs font-medium text-gray-700 mb-1">Position Y</label>
                            <input type="number" id="studio_text_y" name="studio_text_y"
                                   value="{{ old('studio_text_y', $settings['studio_text_y'] ?? 35) }}"
                                   min="0" max="2000" step="1"
                                   class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                        </div>
                    </div>
                </div>
            </div>

            {{-- ═══════════════════════════════════════ --}}
            {{-- TAB: Notifications                    --}}
            {{-- ═══════════════════════════════════════ --}}
            <div x-show="activeTab === 'notifications'" x-cloak class="space-y-4">

                {{-- Telegram alerts --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                    <div class="flex items-center gap-3 mb-1">
                        <svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
                        </svg>
                        <h3 class="text-sm font-semibold text-gray-900">Alertes Telegram</h3>
                    </div>
                    <p class="text-xs text-gray-400 mb-4">Recevez une notification Telegram quand une publication echoue.</p>

                    {{-- Enable toggle --}}
                    <label class="flex items-center gap-3 mb-4 cursor-pointer">
                        <input type="checkbox" name="notify_publish_error" value="1"
                               {{ old('notify_publish_error', $settings['notify_publish_error'] ?? false) ? 'checked' : '' }}
                               class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                        <span class="text-sm text-gray-700">Activer les alertes d'erreur de publication</span>
                    </label>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="notify_telegram_bot_token" class="block text-xs font-medium text-gray-700 mb-1">Bot Token</label>
                            <input type="password" id="notify_telegram_bot_token" name="notify_telegram_bot_token"
                                   placeholder="{{ $hasNotifyBotToken ? '••••••••••••••••' : 'Collez le token du bot' }}"
                                   class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                            @if($hasNotifyBotToken)
                                <p class="text-xs text-green-600 mt-1">Token configure. Laissez vide pour ne pas changer.</p>
                            @endif
                        </div>
                        <div>
                            <label for="notify_telegram_chat_id" class="block text-xs font-medium text-gray-700 mb-1">Chat ID</label>
                            <input type="text" id="notify_telegram_chat_id" name="notify_telegram_chat_id"
                                   value="{{ old('notify_telegram_chat_id', $settings['notify_telegram_chat_id'] ?? '') }}"
                                   placeholder="Votre chat ID personnel"
                                   class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                            <p class="text-xs text-gray-400 mt-1">Envoyez /start a @userinfobot sur Telegram pour obtenir votre ID.</p>
                        </div>
                    </div>

                    {{-- Test button --}}
                    @if($hasNotifyBotToken && ($settings['notify_telegram_chat_id'] ?? ''))
                        <div class="mt-4 pt-4 border-t border-gray-100">
                            <button type="button" onclick="testNotification()"
                                    class="px-4 py-2 bg-blue-50 text-blue-700 text-xs font-medium rounded-lg hover:bg-blue-100 transition-colors">
                                Envoyer une notification test
                            </button>
                            <span id="test-notify-result" class="text-xs ml-2"></span>
                        </div>
                        <script>
                            function testNotification() {
                                const btn = event.target;
                                const result = document.getElementById('test-notify-result');
                                btn.disabled = true;
                                btn.textContent = 'Envoi...';
                                result.textContent = '';

                                fetch('{{ route("settings.testNotification") }}', {
                                    method: 'POST',
                                    headers: {
                                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                                        'Accept': 'application/json',
                                    },
                                }).then(r => r.json()).then(data => {
                                    result.textContent = data.success ? 'Notification envoyee !' : 'Erreur: ' + (data.error || 'inconnue');
                                    result.className = 'text-xs ml-2 ' + (data.success ? 'text-green-600' : 'text-red-600');
                                }).catch(() => {
                                    result.textContent = 'Erreur reseau';
                                    result.className = 'text-xs ml-2 text-red-600';
                                }).finally(() => {
                                    btn.disabled = false;
                                    btn.textContent = 'Envoyer une notification test';
                                });
                            }
                        </script>
                    @endif
                </div>
            </div>

            {{-- ═══════════════════════════════════════ --}}
            {{-- TAB: Services externes                 --}}
            {{-- ═══════════════════════════════════════ --}}
            <div x-show="activeTab === 'external'" x-cloak class="space-y-6">
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                    <h2 class="text-lg font-semibold text-gray-900 mb-1">Banques d'images</h2>
                    <p class="text-sm text-gray-500 mb-5">Clés API pour rechercher des images de stock libres de droits depuis l'éditeur de threads. Les images ne sont jamais stockées sur le serveur — elles sont référencées par URL.</p>

                    <div class="space-y-5">
                        @php
                            $stockKeys = [
                                ['key' => 'pexels_api_key', 'label' => 'Pexels — API key', 'url' => 'https://www.pexels.com/api/', 'help' => 'Demande la clé sur pexels.com/api (gratuit, illimité).'],
                                ['key' => 'pixabay_api_key', 'label' => 'Pixabay — API key', 'url' => 'https://pixabay.com/api/docs/', 'help' => 'Inscription sur pixabay.com puis clé immédiate (gratuit, ~5000 requêtes/jour).'],
                                ['key' => 'unsplash_access_key', 'label' => 'Unsplash — Access key', 'url' => 'https://unsplash.com/developers', 'help' => 'Crée une "Application" sur unsplash.com/developers (50 requêtes/h en démo, jusqu\'à 5000/h en production).'],
                            ];
                        @endphp

                        @foreach ($stockKeys as $sk)
                            @php
                                $hasKey = (bool) \App\Models\Setting::getEncrypted($sk['key']);
                            @endphp
                            <div>
                                <label for="{{ $sk['key'] }}" class="block text-sm font-medium text-gray-700 mb-1">{{ $sk['label'] }}</label>
                                <input type="password" id="{{ $sk['key'] }}" name="{{ $sk['key'] }}"
                                       class="w-full sm:w-2/3 rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                       placeholder="{{ $hasKey ? '••••••••••••••••' : '' }}">
                                @if ($hasKey)
                                    <p class="text-xs text-green-600 mt-1">Clé configurée. Laissez vide pour conserver la clé actuelle.</p>
                                @else
                                    <p class="text-xs text-gray-400 mt-1">{{ $sk['help'] }} <a href="{{ $sk['url'] }}" target="_blank" class="text-indigo-600 hover:underline">Documentation →</a></p>
                                @endif
                                @error($sk['key'])
                                    <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-6 pt-5 border-t border-gray-100">
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" name="stock_photos_auto_fallback" value="1"
                                   {{ \App\Models\Setting::get('stock_photos_auto_fallback') ? 'checked' : '' }}
                                   class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            <span class="text-sm text-gray-700">Fallback automatique sur le stock</span>
                        </label>
                        <p class="text-xs text-gray-400 mt-1 ml-6">Si activé, quand l'auto-attachement de photos ne trouve rien dans ta médiathèque, une image de stock pertinente sera proposée automatiquement.</p>
                    </div>
                </div>
            </div>

            {{-- Save button --}}
            <div class="flex justify-end">
                <button type="submit"
                    class="px-5 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                    Enregistrer les paramètres
                </button>
            </div>
        </form>

        {{-- Form isole pour le bouton "Mettre a jour les LLM gratuits" (evite le conflit method spoofing PATCH) --}}
        <form id="refresh-free-llms-form" method="POST" action="{{ route('settings.refreshFreeLlms') }}" class="hidden">
            @csrf
        </form>
        <script>
            function refreshFreeLlms(btn) {
                btn.disabled = true;
                const originalHtml = btn.innerHTML;
                btn.innerHTML = 'Mise a jour en cours...';
                document.getElementById('refresh-free-llms-form').submit();
            }
        </script>
    </div>
@endsection
