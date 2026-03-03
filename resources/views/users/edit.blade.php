@extends('layouts.app')

@section('title', 'Modifier ' . $user->name)

@section('content')
    <div class="max-w-2xl">
        <form method="POST" action="{{ route('users.update', $user) }}">
            @csrf
            @method('PUT')

            {{-- Informations --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6">
                <h2 class="text-base font-semibold text-gray-900 mb-6">Informations</h2>

                <div class="mb-5">
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-1.5">
                        Nom <span class="text-red-500">*</span>
                    </label>
                    <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}" required
                        class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm" />
                    @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="mb-5">
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1.5">
                        Email <span class="text-red-500">*</span>
                    </label>
                    <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}" required
                        class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm" />
                    @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="mb-5">
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1.5">Mot de passe</label>
                    <input type="password" id="password" name="password"
                        class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                        placeholder="Laisser vide pour ne pas changer" />
                    @error('password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="mb-5">
                    <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-1.5">Confirmer le mot de passe</label>
                    <input type="password" id="password_confirmation" name="password_confirmation"
                        class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                        placeholder="Laisser vide pour ne pas changer" />
                </div>

                <div>
                    <label for="default_language" class="block text-sm font-medium text-gray-700 mb-1.5">Langue par defaut</label>
                    <select name="default_language" id="default_language"
                        class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                        @foreach(['fr' => 'Francais', 'en' => 'Anglais', 'pt' => 'Portugais', 'es' => 'Espagnol', 'de' => 'Allemand', 'it' => 'Italien'] as $code => $label)
                            <option value="{{ $code }}" {{ old('default_language', $user->default_language) === $code ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- Social accounts --}}
            @include('users._accounts_checkboxes')

            {{-- Submit --}}
            <div class="flex items-center gap-4">
                <button type="submit" class="inline-flex items-center gap-2 px-6 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                    Mettre a jour
                </button>
                <a href="{{ route('users.index') }}" class="text-sm text-gray-500 hover:text-gray-700 transition-colors">Annuler</a>
            </div>
        </form>
    </div>
@endsection
