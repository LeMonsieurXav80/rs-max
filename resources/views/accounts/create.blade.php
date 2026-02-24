@extends('layouts.app')

@section('title', 'Ajouter un compte')

@section('content')
    <div class="max-w-2xl" x-data="{
        platformId: '{{ old('platform_id', '') }}',
        platforms: @js($platforms->keyBy('id')),
        get credentialFields() {
            if (!this.platformId || !this.platforms[this.platformId]) return [];
            return this.platforms[this.platformId].config?.credential_fields || [];
        }
    }">
        <form method="POST" action="{{ route('accounts.store') }}">
            @csrf

            {{-- Section : Informations du compte --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6">
                <h2 class="text-base font-semibold text-gray-900 mb-6">Informations du compte</h2>

                {{-- Plateforme --}}
                <div class="mb-5">
                    <label for="platform_id" class="block text-sm font-medium text-gray-700 mb-1.5">
                        Plateforme <span class="text-red-500">*</span>
                    </label>
                    <select
                        id="platform_id"
                        name="platform_id"
                        x-model="platformId"
                        required
                        class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                    >
                        <option value="">-- Choisir une plateforme --</option>
                        @foreach($platforms as $platform)
                            <option value="{{ $platform->id }}" {{ old('platform_id') == $platform->id ? 'selected' : '' }}>
                                {{ $platform->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('platform_id')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Nom du compte --}}
                <div class="mb-5">
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-1.5">
                        Nom du compte <span class="text-red-500">*</span>
                    </label>
                    <input
                        type="text"
                        id="name"
                        name="name"
                        value="{{ old('name') }}"
                        required
                        class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                        placeholder="Ex: Mon compte Instagram principal"
                    />
                    @error('name')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Langue --}}
                <div class="mb-5">
                    <label for="language" class="block text-sm font-medium text-gray-700 mb-1.5">Langue</label>
                    <select
                        id="language"
                        name="language"
                        class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                    >
                        <option value="fr" {{ old('language', 'fr') === 'fr' ? 'selected' : '' }}>Francais</option>
                        <option value="en" {{ old('language') === 'en' ? 'selected' : '' }}>Anglais</option>
                        <option value="both" {{ old('language') === 'both' ? 'selected' : '' }}>Les deux</option>
                    </select>
                    @error('language')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Branding --}}
                <div class="mb-5">
                    <label for="branding" class="block text-sm font-medium text-gray-700 mb-1.5">Branding / Signature</label>
                    <textarea
                        id="branding"
                        name="branding"
                        rows="3"
                        class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                        placeholder="Texte ajouté automatiquement à vos publications..."
                    >{{ old('branding') }}</textarea>
                    @error('branding')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Afficher le branding --}}
                <div>
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input
                            type="checkbox"
                            name="show_branding"
                            value="1"
                            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                            {{ old('show_branding') ? 'checked' : '' }}
                        />
                        <span class="text-sm text-gray-700">Afficher le branding dans les publications</span>
                    </label>
                    @error('show_branding')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            {{-- Section : Identifiants de connexion --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6" x-show="credentialFields.length > 0" x-transition>
                <h2 class="text-base font-semibold text-gray-900 mb-2">Identifiants de connexion</h2>
                <p class="text-xs text-gray-400 mb-6">Ces informations sont chiffrées et stockées en toute sécurité.</p>

                <div class="space-y-5">
                    <template x-for="(field, index) in credentialFields" :key="field.key">
                        <div>
                            <label :for="'credential_' + field.key" class="block text-sm font-medium text-gray-700 mb-1.5" x-text="field.label"></label>
                            <input
                                :type="field.type || 'text'"
                                :id="'credential_' + field.key"
                                :name="'credentials[' + field.key + ']'"
                                class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                :placeholder="field.label"
                            />
                        </div>
                    </template>
                </div>

                @error('credentials')
                    <p class="mt-3 text-sm text-red-600">{{ $message }}</p>
                @enderror
                @error('credentials.*')
                    <p class="mt-3 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Message when no platform selected --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6 text-center" x-show="!platformId" x-transition>
                <div class="py-4">
                    <svg class="w-8 h-8 text-gray-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z" />
                    </svg>
                    <p class="text-sm text-gray-400">Sélectionnez une plateforme pour afficher les champs d'identifiants requis.</p>
                </div>
            </div>

            {{-- Submit --}}
            <div class="flex items-center gap-4">
                <button type="submit" class="inline-flex items-center gap-2 px-6 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Ajouter le compte
                </button>
                <a href="{{ route('accounts.index') }}" class="text-sm text-gray-500 hover:text-gray-700 transition-colors">Annuler</a>
            </div>
        </form>
    </div>
@endsection
