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

        <form method="POST" action="{{ route('settings.update') }}" class="space-y-6">
            @csrf
            @method('patch')

            {{-- Traduction automatique --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <div class="flex items-center gap-3 mb-1">
                    <svg class="w-5 h-5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m10.5 21 5.25-11.25L21 21m-9-3h7.5M3 5.621a48.474 48.474 0 0 1 6-.371m0 0c1.12 0 2.233.038 3.334.114M9 5.25V3m3.334 2.364C11.176 10.658 7.69 15.08 3 17.502m9.334-12.138c.896.061 1.785.147 2.666.257m-4.589 8.495a18.023 18.023 0 0 1-3.827-5.802" />
                    </svg>
                    <h2 class="text-base font-semibold text-gray-900">Traduction automatique</h2>
                </div>
                <p class="text-sm text-gray-500 mb-5">Traduit automatiquement le contenu vers les langues configurees sur chaque compte social via GPT-4o-mini.</p>

                <div>
                    <label for="openai_api_key" class="block text-sm font-medium text-gray-700 mb-1">Cle API OpenAI</label>
                    <input type="password" id="openai_api_key" name="openai_api_key"
                           class="w-full sm:w-2/3 rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                           placeholder="{{ $hasOpenaiKey ? '••••••••••••••••' : 'sk-...' }}">
                    @if($hasOpenaiKey)
                        <p class="text-xs text-green-600 mt-1">Cle configuree. Laissez vide pour conserver la cle actuelle.</p>
                    @else
                        <p class="text-xs text-gray-400 mt-1">Necessaire pour la traduction automatique. Obtenez votre cle sur platform.openai.com</p>
                    @endif
                    @error('openai_api_key')
                        <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            {{-- Stats sync --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <div class="flex items-center gap-3 mb-1">
                    <svg class="w-5 h-5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
                    </svg>
                    <h2 class="text-base font-semibold text-gray-900">Synchronisation des statistiques</h2>
                </div>
                <p class="text-sm text-gray-500 mb-5">Configure la frequence de mise a jour automatique des stats depuis les API des plateformes.</p>

                {{-- Cron frequency --}}
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

                {{-- Per-platform settings --}}
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
                    {{-- Target min size --}}
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

                    {{-- Target max size --}}
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

                    {{-- Min quality --}}
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

                    {{-- Max dimension --}}
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

                    {{-- Max upload size --}}
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
                    {{-- Bitrate 1080p --}}
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

                    {{-- Bitrate 720p --}}
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

                    {{-- Video codec --}}
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

                    {{-- Audio bitrate --}}
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

                    {{-- Max upload size --}}
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

            {{-- Save button --}}
            <div class="flex justify-end">
                <button type="submit"
                    class="px-5 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                    Enregistrer les paramètres
                </button>
            </div>
        </form>
    </div>
@endsection
