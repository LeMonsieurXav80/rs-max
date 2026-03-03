@extends('layouts.app')

@section('title', 'Nouvel utilisateur')

@section('content')
    <div class="max-w-2xl">
        <form method="POST" action="{{ route('users.store') }}">
            @csrf

            {{-- Informations --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6">
                <h2 class="text-base font-semibold text-gray-900 mb-6">Informations</h2>

                <div class="mb-5">
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-1.5">
                        Nom <span class="text-red-500">*</span>
                    </label>
                    <input type="text" id="name" name="name" value="{{ old('name') }}" required
                        class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                        placeholder="Nom de l'utilisateur" />
                    @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="mb-5">
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1.5">
                        Email <span class="text-red-500">*</span>
                    </label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" required
                        class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                        placeholder="email@exemple.com" />
                    @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="mb-5">
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1.5">
                        Mot de passe <span class="text-red-500">*</span>
                    </label>
                    <input type="password" id="password" name="password" required
                        class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm" />
                    @error('password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="mb-5">
                    <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-1.5">
                        Confirmer le mot de passe <span class="text-red-500">*</span>
                    </label>
                    <input type="password" id="password_confirmation" name="password_confirmation" required
                        class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm" />
                </div>

                <div>
                    <label for="default_language" class="block text-sm font-medium text-gray-700 mb-1.5">Langue par defaut</label>
                    <select name="default_language" id="default_language"
                        class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                        <option value="fr" {{ old('default_language', 'fr') === 'fr' ? 'selected' : '' }}>Francais</option>
                        <option value="en" {{ old('default_language') === 'en' ? 'selected' : '' }}>Anglais</option>
                        <option value="pt" {{ old('default_language') === 'pt' ? 'selected' : '' }}>Portugais</option>
                        <option value="es" {{ old('default_language') === 'es' ? 'selected' : '' }}>Espagnol</option>
                        <option value="de" {{ old('default_language') === 'de' ? 'selected' : '' }}>Allemand</option>
                        <option value="it" {{ old('default_language') === 'it' ? 'selected' : '' }}>Italien</option>
                    </select>
                </div>
            </div>

            {{-- Social accounts --}}
            @include('users._accounts_checkboxes', ['assignedIds' => old('accounts', [])])

            {{-- Submit --}}
            <div class="flex items-center gap-4">
                <button type="submit" class="inline-flex items-center gap-2 px-6 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Creer l'utilisateur
                </button>
                <a href="{{ route('users.index') }}" class="text-sm text-gray-500 hover:text-gray-700 transition-colors">Annuler</a>
            </div>
        </form>
    </div>
@endsection
