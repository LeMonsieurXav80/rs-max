@extends('layouts.app')

@section('title', 'Nouveau post')

@section('content')
    <form method="POST" action="{{ route('posts.store') }}" class="max-w-3xl space-y-6" x-data="{
        publishMode: '{{ old('publish_now') ? 'now' : 'schedule' }}',
        showTelegramChannel: false,
        init() {
            this.checkTelegram();
        },
        checkTelegram() {
            const checked = document.querySelectorAll('input[name=\'accounts[]\']:checked');
            this.showTelegramChannel = false;
            checked.forEach(el => {
                if (el.dataset.platform === 'telegram') {
                    this.showTelegramChannel = true;
                }
            });
        }
    }">
        @csrf

        {{-- Validation errors summary --}}
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

        {{-- Section 1: Contenu --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 lg:p-8">
            <h2 class="text-base font-semibold text-gray-900 mb-6">Contenu</h2>

            <div class="space-y-6">
                {{-- Contenu français --}}
                <div>
                    <label for="content_fr" class="block text-sm font-medium text-gray-700 mb-2">
                        Contenu français <span class="text-red-500">*</span>
                    </label>
                    <textarea
                        id="content_fr"
                        name="content_fr"
                        rows="5"
                        required
                        class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm placeholder-gray-400 transition-colors"
                        placeholder="Rédigez votre publication en français..."
                    >{{ old('content_fr') }}</textarea>
                    @error('content_fr')
                        <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Contenu anglais --}}
                <div>
                    <label for="content_en" class="block text-sm font-medium text-gray-700 mb-2">
                        Contenu anglais
                    </label>
                    <textarea
                        id="content_en"
                        name="content_en"
                        rows="4"
                        class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm placeholder-gray-400 transition-colors"
                        placeholder="Rédigez votre publication en anglais..."
                    >{{ old('content_en') }}</textarea>
                    <p class="mt-1.5 text-xs text-gray-400">Sera auto-traduit si laissé vide</p>
                    @error('content_en')
                        <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Hashtags --}}
                <div>
                    <label for="hashtags" class="block text-sm font-medium text-gray-700 mb-2">
                        Hashtags
                    </label>
                    <input
                        type="text"
                        id="hashtags"
                        name="hashtags"
                        value="{{ old('hashtags') }}"
                        class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm placeholder-gray-400 transition-colors"
                        placeholder="#marketing #socialmedia #growth"
                    >
                    @error('hashtags')
                        <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Traduction automatique --}}
                <div class="flex items-center gap-3">
                    <input
                        type="checkbox"
                        id="auto_translate"
                        name="auto_translate"
                        value="1"
                        {{ old('auto_translate', true) ? 'checked' : '' }}
                        class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 transition-colors"
                    >
                    <label for="auto_translate" class="text-sm text-gray-700">
                        Traduction automatique
                    </label>
                </div>
            </div>
        </div>

        {{-- Section 2: Médias --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 lg:p-8">
            <h2 class="text-base font-semibold text-gray-900 mb-6">Médias</h2>

            <div class="space-y-6">
                {{-- Media URL --}}
                <div>
                    <label for="media_0" class="block text-sm font-medium text-gray-700 mb-2">
                        URL du média
                    </label>
                    <input
                        type="url"
                        id="media_0"
                        name="media[]"
                        value="{{ old('media.0') }}"
                        class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm placeholder-gray-400 transition-colors"
                        placeholder="https://exemple.com/image.jpg"
                    >
                    <p class="mt-1.5 text-xs text-gray-400">L'upload de fichiers sera disponible prochainement</p>
                    @error('media.*')
                        <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Link URL --}}
                <div>
                    <label for="link_url" class="block text-sm font-medium text-gray-700 mb-2">
                        URL du lien
                    </label>
                    <input
                        type="url"
                        id="link_url"
                        name="link_url"
                        value="{{ old('link_url') }}"
                        class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm placeholder-gray-400 transition-colors"
                        placeholder="https://exemple.com/mon-article"
                    >
                    @error('link_url')
                        <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </div>

        {{-- Section 3: Publication --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 lg:p-8">
            <h2 class="text-base font-semibold text-gray-900 mb-6">Publication</h2>

            <div class="space-y-8">
                {{-- Social accounts grouped by platform --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-4">
                        Comptes de publication <span class="text-red-500">*</span>
                    </label>

                    @if($accounts->count())
                        <div class="space-y-6">
                            @foreach($accounts as $platformName => $platformAccounts)
                                <div>
                                    <div class="flex items-center gap-2.5 mb-3">
                                        <x-platform-icon :platform="$platformAccounts->first()->platform" size="sm" />
                                        <span class="text-sm font-medium text-gray-900">{{ $platformName }}</span>
                                    </div>
                                    <div class="ml-7 space-y-2">
                                        @foreach($platformAccounts as $account)
                                            <label class="flex items-center gap-3 p-3 rounded-xl border border-gray-200 hover:border-indigo-300 hover:bg-indigo-50/30 transition-colors cursor-pointer">
                                                <input
                                                    type="checkbox"
                                                    name="accounts[]"
                                                    value="{{ $account->id }}"
                                                    data-platform="{{ $account->platform->slug }}"
                                                    @change="checkTelegram()"
                                                    {{ in_array($account->id, old('accounts', [])) ? 'checked' : '' }}
                                                    class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 transition-colors"
                                                >
                                                <span class="text-sm text-gray-700">{{ $account->name }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="text-sm text-gray-500 bg-gray-50 rounded-xl p-4">
                            Aucun compte actif disponible.
                            <a href="{{ route('accounts.create') }}" class="text-indigo-600 hover:text-indigo-700 font-medium">Ajouter un compte</a>
                        </div>
                    @endif

                    @error('accounts')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                    @error('accounts.*')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Telegram channel (shown when a Telegram account is selected) --}}
                <div x-show="showTelegramChannel" x-transition x-cloak>
                    <label for="telegram_channel" class="block text-sm font-medium text-gray-700 mb-2">
                        Canal Telegram
                    </label>
                    <input
                        type="text"
                        id="telegram_channel"
                        name="telegram_channel"
                        value="{{ old('telegram_channel') }}"
                        class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm placeholder-gray-400 transition-colors"
                        placeholder="@mon_canal"
                    >
                    @error('telegram_channel')
                        <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Publish mode --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-4">Mode de publication</label>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        {{-- Publier immédiatement --}}
                        <label class="relative flex items-start gap-3 p-4 rounded-xl border-2 cursor-pointer transition-colors"
                               :class="publishMode === 'now' ? 'border-indigo-600 bg-indigo-50/40' : 'border-gray-200 hover:border-gray-300'">
                            <input
                                type="radio"
                                x-model="publishMode"
                                value="now"
                                class="mt-0.5 border-gray-300 text-indigo-600 focus:ring-indigo-500"
                            >
                            <div>
                                <span class="block text-sm font-medium text-gray-900">Publier immédiatement</span>
                                <span class="block text-xs text-gray-500 mt-0.5">Le post sera publié dès sa création</span>
                            </div>
                        </label>

                        {{-- Programmer --}}
                        <label class="relative flex items-start gap-3 p-4 rounded-xl border-2 cursor-pointer transition-colors"
                               :class="publishMode === 'schedule' ? 'border-indigo-600 bg-indigo-50/40' : 'border-gray-200 hover:border-gray-300'">
                            <input
                                type="radio"
                                x-model="publishMode"
                                value="schedule"
                                class="mt-0.5 border-gray-300 text-indigo-600 focus:ring-indigo-500"
                            >
                            <div>
                                <span class="block text-sm font-medium text-gray-900">Programmer</span>
                                <span class="block text-xs text-gray-500 mt-0.5">Choisir la date et l'heure de publication</span>
                            </div>
                        </label>
                    </div>
                </div>

                {{-- Scheduled date --}}
                <div x-show="publishMode === 'schedule'" x-transition x-cloak>
                    <label for="scheduled_at" class="block text-sm font-medium text-gray-700 mb-2">
                        Date et heure de publication
                    </label>
                    <input
                        type="datetime-local"
                        id="scheduled_at"
                        name="scheduled_at"
                        value="{{ old('scheduled_at') }}"
                        class="w-full sm:w-auto rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm transition-colors"
                    >
                    @error('scheduled_at')
                        <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Hidden fields for controller compatibility --}}
                <input type="hidden" name="status" value="scheduled">
                <input type="hidden" name="publish_now" :value="publishMode === 'now' ? '1' : '0'">
            </div>
        </div>

        {{-- Submit --}}
        <div class="flex items-center justify-end gap-4">
            <a href="{{ route('posts.index') }}" class="px-4 py-2 text-sm font-medium text-gray-700 hover:text-gray-900 transition-colors">
                Annuler
            </a>
            <button type="submit"
                    class="inline-flex items-center gap-2 px-6 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Créer le post
            </button>
        </div>
    </form>
@endsection
