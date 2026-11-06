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

                {{-- Langues --}}
                <div class="mb-5">
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Langues de publication</label>
                    <p class="text-xs text-gray-400 mb-3">Le contenu sera traduit et publie dans les langues selectionnees.</p>
                    @php
                        $availableLanguages = [
                            'fr' => 'Francais',
                            'en' => 'Anglais',
                            'pt' => 'Portugais',
                            'es' => 'Espagnol',
                            'de' => 'Allemand',
                            'it' => 'Italien',
                        ];
                        $accountLanguages = old('languages', $account->languages ?? ['fr']);
                    @endphp
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                        @foreach($availableLanguages as $code => $label)
                            <label class="flex items-center gap-2 px-3 py-2 rounded-lg border border-gray-200 hover:bg-indigo-50/50 cursor-pointer transition-colors">
                                <input
                                    type="checkbox"
                                    name="languages[]"
                                    value="{{ $code }}"
                                    {{ in_array($code, $accountLanguages) ? 'checked' : '' }}
                                    class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                >
                                <span class="text-sm text-gray-700">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('languages')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                    @error('languages.*')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                @if($account->platform->slug === 'tiktok')
                    <p class="mt-5 text-xs {{ $account->browser_session_status === 'connected' ? 'text-green-600' : 'text-amber-600' }}">
                        @if($account->browser_session_status === 'connected')
                            Session enregistrée ({{ '@'.$account->browser_username }}), vérifiée {{ $account->browser_session_checked_at?->diffForHumans() }}.
                        @else
                            Aucune session active. Ouvre TikTok Studio dans ton Chrome avec ce compte, puis lance « TikTok — envoyer la session au VPS » dans Companion.
                        @endif
                    </p>
                @endif

                @if($account->platform->slug === 'twitter')
                    {{-- Mode de connexion --}}
                    <div class="mt-5">
                        <label for="driver" class="block text-sm font-medium text-gray-700 mb-1.5">Mode de connexion</label>
                        <select name="driver" id="driver"
                                class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                            <option value="api" {{ old('driver', $account->driver) === 'api' ? 'selected' : '' }}>API X (payante)</option>
                            <option value="browser" {{ old('driver', $account->driver) === 'browser' ? 'selected' : '' }}>Navigateur du VPS</option>
                        </select>
                        <label for="browser_profile" class="block text-sm font-medium text-gray-700 mt-3 mb-1.5">Profil navigateur</label>
                        <input type="text" name="browser_profile" id="browser_profile" value="{{ old('browser_profile', $account->browser_profile) }}" placeholder="wildycaro"
                               class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                        @error('browser_profile')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                        <p class="mt-1 text-xs text-gray-400">
                            En mode navigateur, les publications, réponses, stats et abonnés passent par le navigateur du VPS.
                            Les articles X restent sur l'API.
                            Les comptes qui ont le même profil passent chacun leur tour dans le même navigateur.
                        </p>
                        <p class="mt-1 text-xs {{ $account->browser_session_status === 'connected' ? 'text-green-600' : 'text-amber-600' }}">
                            @if($account->browser_session_status === 'connected')
                                Session connectée{{ $account->browser_username ? ' (@'.$account->browser_username.')' : '' }}{{ $account->browser_session_checked_at ? ', vérifiée '.$account->browser_session_checked_at->diffForHumans() : '' }}.
                            @elseif($account->browser_session_status === 'expired')
                                Session expirée : ouvrez X dans Chrome avec ce compte, l'extension la renverra.
                            @else
                                Aucune session : ouvrez X dans Chrome avec ce compte, puis lancez « X — envoyer la session au VPS ».
                            @endif
                        </p>
                        @error('driver')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                @endif

                {{-- Persona IA --}}
                <div class="mt-5">
                    <label for="persona_id" class="block text-sm font-medium text-gray-700 mb-1.5">Persona des réponses</label>
                    <select name="persona_id" id="persona_id"
                            class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                        <option value="">Aucun</option>
                        @foreach($personas as $persona)
                            <option value="{{ $persona->id }}" {{ old('persona_id', $account->persona_id) == $persona->id ? 'selected' : '' }}>
                                {{ $persona->name }}{{ $persona->tone ? ' — ' . $persona->tone : '' }}
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-gray-400">Utilisé pour répondre aux messages et commentaires, et pour poster des commentaires. Jamais pour les publications.</p>
                    @error('persona_id')
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
