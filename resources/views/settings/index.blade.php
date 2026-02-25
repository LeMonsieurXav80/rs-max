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
