@extends('layouts.app')

@section('title', 'Modifier le compte')

@section('content')
    <div class="max-w-2xl">
        <form method="POST" action="{{ route('accounts.update', $account) }}">
            @csrf
            @method('PUT')

            {{-- Section : Informations du compte --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6">
                <h2 class="text-base font-semibold text-gray-900 mb-6">Informations du compte</h2>

                {{-- Plateforme (disabled, display only) --}}
                <div class="mb-5">
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Plateforme</label>
                    <div class="flex items-center gap-3 px-4 py-2.5 bg-gray-50 rounded-xl border border-gray-200">
                        <x-platform-icon :platform="$account->platform" size="sm" />
                        <span class="text-sm text-gray-700 font-medium">{{ $account->platform->name }}</span>
                    </div>
                    <input type="hidden" name="platform_id" value="{{ $account->platform_id }}" />
                    <p class="mt-1 text-xs text-gray-400">La plateforme ne peut pas etre modifiée après la création.</p>
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
                        value="{{ old('name', $account->name) }}"
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
                        <option value="fr" {{ old('language', $account->language) === 'fr' ? 'selected' : '' }}>Francais</option>
                        <option value="en" {{ old('language', $account->language) === 'en' ? 'selected' : '' }}>Anglais</option>
                        <option value="both" {{ old('language', $account->language) === 'both' ? 'selected' : '' }}>Les deux</option>
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
                    >{{ old('branding', $account->branding) }}</textarea>
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
                            {{ old('show_branding', $account->show_branding) ? 'checked' : '' }}
                        />
                        <span class="text-sm text-gray-700">Afficher le branding dans les publications</span>
                    </label>
                    @error('show_branding')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            {{-- Section : Identifiants de connexion --}}
            @php
                $credentialFields = $account->platform->config['credential_fields'] ?? [];
            @endphp

            @if(count($credentialFields) > 0)
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6">
                    <h2 class="text-base font-semibold text-gray-900 mb-2">Identifiants de connexion</h2>
                    <p class="text-xs text-gray-400 mb-6">Ces informations sont chiffrées et stockées en toute sécurité.</p>

                    <div class="space-y-5">
                        @foreach($credentialFields as $field)
                            @php
                                $fieldKey = is_array($field) ? ($field['key'] ?? $field['name'] ?? '') : $field;
                                $fieldLabel = is_array($field) ? ($field['label'] ?? $fieldKey) : $field;
                                $fieldType = is_array($field) ? ($field['type'] ?? 'text') : 'text';
                                $isPassword = $fieldType === 'password';
                            @endphp
                            <div>
                                <label for="credential_{{ $fieldKey }}" class="block text-sm font-medium text-gray-700 mb-1.5">
                                    {{ $fieldLabel }}
                                </label>
                                <input
                                    type="{{ $fieldType }}"
                                    id="credential_{{ $fieldKey }}"
                                    name="credentials[{{ $fieldKey }}]"
                                    value="{{ old('credentials.' . $fieldKey, '') }}"
                                    class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                    placeholder="{{ $isPassword ? '••••••••' : $fieldLabel }}"
                                />
                                @if($isPassword)
                                    <p class="mt-1 text-xs text-gray-400">Laisser vide pour garder la valeur actuelle.</p>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    @error('credentials')
                        <p class="mt-3 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                    @error('credentials.*')
                        <p class="mt-3 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @endif

            {{-- Submit --}}
            <div class="flex items-center gap-4">
                <button type="submit" class="inline-flex items-center gap-2 px-6 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                    Mettre à jour
                </button>
                <a href="{{ route('accounts.index') }}" class="text-sm text-gray-500 hover:text-gray-700 transition-colors">Annuler</a>
            </div>
        </form>
    </div>
@endsection
